<?php

declare(strict_types=1);

namespace NolvikoExt\Importer;

use Nolviko\Core\Http\CspNonce;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Http\TransportPolicy;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\SafeHtml;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Http\AdminGuard;
use Nolviko\Modules\Admin\Http\AdminNavigation;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminAudit;
use Nolviko\Modules\Importers\Infrastructure\ImportJobs;
use Nolviko\Modules\Users\Http\RequestSubject;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Admin > Import an old forum, served by the extension (ADR-038). The same screen as the beta.11 core page: the owner
 * adds the source (its password is never shown again), the page keeps running batches while it is open (JSON `run`;
 * without JavaScript one button press runs one request's worth), the finished import is opened with an explicit
 * "publish" decision, and the summary can be downloaded as JSON. Every change goes through the HTTPS/CSRF admin
 * boundary. The HTML is built here with every value escaped (extensions ship no templates).
 */
final readonly class AdminHandler implements RouteHandler
{
    public const ROUTE = 'nolviko.importer.admin';

    private const ACTIONS = ['add_source', 'remove_source', 'begin', 'step', 'run', 'publish'];

    private const SOURCE_FIELDS = ['engine', 'host', 'port', 'database', 'username', 'prefix', 'charset',
        'attachment_root'];

    private const KINDS = ['users', 'groups', 'categories', 'forums', 'threads', 'posts', 'attachments', 'polls',
        'poll_options', 'poll_votes', 'private_sent', 'private_inbox', 'private_files'];

    public function __construct(
        private Importer $importer,
        private ImportJobs $jobs,
        private RequestSubject $subjects,
        private AdminSession $admin,
        private CsrfTokens $csrf,
        private DbalAdminAudit $audit,
        private TemplateRenderer $views,
        private MessageTranslator $translator,
        private UrlGenerator $urls,
        private CspNonce $nonce,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        if ($subject->userId === null || !$subject->allows(AdminGuard::PERMISSION)) {
            return new Response('', 403, ['Cache-Control' => 'private, no-store']);
        }
        $input = $request->isMethod('POST') ? $request->request->all() : $request->query->all();
        $job = is_string($input['job'] ?? null) ? $input['job'] : null;
        $json = str_contains((string) $request->headers->get('Accept'), 'application/json');
        $error = null;
        $notice = null;
        $status = 200;
        $selected = null;
        $report = null;
        $recent = [];
        $sources = [];
        $preflight = null;
        $form = [];
        if ($request->isMethod('GET') && $job !== null && ($input['format'] ?? null) === 'json') {
            return $this->download($job);
        }
        try {
            if ($request->isMethod('POST')) {
                if (
                    !TransportPolicy::allows($request) || !$this->admin->isFresh()
                    || !is_string($input[CsrfTokens::FIELD] ?? null) || !$this->csrf->isValid($input[CsrfTokens::FIELD])
                ) {
                    throw new RuntimeException('import_denied');
                }
                $action = $input['action'] ?? null;
                if (!is_string($action) || !in_array($action, self::ACTIONS, true)) {
                    throw new RuntimeException('import_denied');
                }
                $quiet = $action === 'run' || $action === 'step';
                if (!$quiet) {
                    $this->audit->record($subject->userId, 'admin.import_' . $action . '_requested');
                }
                if ($action === 'add_source') {
                    foreach (self::SOURCE_FIELDS as $field) {
                        $form[$field] = is_string($input[$field] ?? null) ? $input[$field] : '';
                    }
                    $fields = $form;
                    $fields['password'] = is_string($input['password'] ?? null) ? $input['password'] : '';
                    $added = $this->importer->add($fields, $subject->userId);
                    $preflight = $added['preflight'];
                    if ($added['id'] !== null) {
                        $form = [];
                        $notice = 'source_added';
                    } else {
                        $status = 422;
                    }
                } elseif ($action === 'remove_source') {
                    $source = $input['source'] ?? null;
                    if (!is_string($source) || preg_match('/^panel-[a-f0-9]{16}$/D', $source) !== 1) {
                        throw new RuntimeException('import_source_missing');
                    }
                    $this->importer->removeSource($source);
                    $notice = 'source_removed';
                } elseif ($action === 'begin') {
                    if (!is_string($input['source'] ?? null)) {
                        throw new RuntimeException('import_source_missing');
                    }
                    $job = $this->importer->begin(
                        $input['source'],
                        $subject->userId,
                        ($input['acknowledged'] ?? null) === '1'
                    );
                } elseif ($action === 'publish') {
                    if ($job === null || ($input['confirm_publish'] ?? null) !== '1') {
                        throw new RuntimeException('import_confirmation_required');
                    }
                    $this->jobs->publish($job, ($input['activate_members'] ?? null) === '1');
                    $notice = 'import_published';
                } else {
                    $revision = $input['revision'] ?? null;
                    if ($job === null || !is_string($revision) || preg_match('/^[0-9]{1,18}$/D', $revision) !== 1) {
                        throw new RuntimeException('import_checkpoint_conflict');
                    }
                    $progress = $this->importer->run($job, (int) $revision);
                    if ($json) {
                        return $this->json($progress);
                    }
                }
                if (!$quiet) {
                    $this->audit->record($subject->userId, 'admin.import_' . $action . '_completed');
                }
            }
            $sources = $this->importer->sources();
            $recent = $this->jobs->recent();
            if ($job !== null) {
                $selected = $this->jobs->job($job);
                $report = $this->jobs->report($job, self::page($input['notes'] ?? null));
            }
        } catch (Throwable $failure) {
            $code = $failure->getMessage();
            if ($json && $job !== null && $code === 'import_checkpoint_conflict') {
                // Another tab ran the batch: carry on from the saved checkpoint.
                try {
                    $current = $this->jobs->job($job);
                    return $this->json(['revision' => (int) $current['revision'],
                        'processed' => (int) $current['processed'], 'verified' => (int) $current['verified'],
                        'complete' => $current['state'] === 'completed']);
                } catch (Throwable) {
                    // Fall through to the localized error.
                }
            }
            $status = $code === 'import_denied' ? 403 : 422;
            $error = $this->text(self::message($code));
            if ($json) {
                return new Response(json_encode(['ok' => false, 'error' => $error], JSON_THROW_ON_ERROR), $status, [
                    'Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store',
                ]);
            }
        }
        if ($status !== 403) {
            try {
                $sources = $sources === [] ? $this->importer->sources() : $sources;
                $recent = $recent === [] ? $this->jobs->recent() : $recent;
                if ($selected === null && $job !== null) {
                    $selected = $this->jobs->job($job);
                    $report = $this->jobs->report($job, self::page($input['notes'] ?? null));
                }
            } catch (Throwable) {
                // Keep the original localized error and expose no database or source details.
            }
        }
        $body = ($error === null ? '' : '<p role="alert">' . self::e($error) . '</p>')
            . ($notice === null ? '' : '<p role="status">' . self::e($this->text($notice)) . '</p>')
            . '<p>' . self::e($this->text('import_policy')) . '</p>'
            . ($selected === null ? '' : $this->progress($selected))
            . ($report === null ? '' : $this->results($report, (string) $selected['id']))
            . $this->sourcesSection($sources, $preflight, $form)
            . $this->recentSection($recent);
        return new Response($this->views->render('extensions/admin_page.twig', [
            'title' => $this->text('imports'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_IMPORTS),
            'body' => new SafeHtml($body),
        ]), $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer']);
    }

    private function progress(array $selected): string
    {
        $html = '<h2>' . self::e($this->text('progress')) . '</h2><dl><dt>' . self::e($this->text('processed'))
            . '</dt><dd>' . self::e($this->number((int) $selected['processed'])) . '</dd><dt>'
            . self::e($this->text('verified')) . '</dt><dd>' . self::e($this->number((int) $selected['verified']))
            . '</dd></dl>';
        if ($selected['state'] === 'completed') {
            return $html . '<p role="status">' . self::e($this->text('completed')) . '</p>';
        }
        return $html . '<p>' . self::e($this->text('run_auto')) . '</p>'
            . '<p data-import-status role="status" aria-live="polite" hidden data-running="'
            . self::e($this->text('running')) . '" data-paused="' . self::e($this->text('run_paused'))
            . '" data-done="' . self::e($this->text('run_done')) . '"></p>'
            . '<form method="post" action="' . self::e($this->self()) . '" data-import-run data-import-done="'
            . self::e($this->self(['job' => $selected['id']])) . '">' . $this->csrfField()
            . '<input type="hidden" name="job" value="' . self::e((string) $selected['id']) . '">'
            . '<input type="hidden" name="revision" value="' . self::e((string) $selected['revision']) . '">'
            . '<button name="action" value="step">' . self::e($this->text('continue')) . '</button></form>'
            . '<script nonce="' . self::e($this->nonce->value) . '">' . self::SCRIPT . '</script>';
    }

    private function results(array $report, string $job): string
    {
        $html = '';
        if ($report['complete']) {
            $html .= '<h2>' . self::e($this->text('results')) . '</h2><table><thead><tr>';
            foreach (['col_kind', 'col_processed', 'col_verified', 'col_skipped', 'col_review'] as $column) {
                $html .= '<th scope="col">' . self::e($this->text($column)) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($report['counts'] as $kind => $count) {
                $html .= '<tr><th scope="row">' . self::e($this->kind((string) $kind)) . '</th>';
                foreach (['processed', 'verified', 'skipped', 'review'] as $field) {
                    $html .= '<td>' . self::e($this->number((int) ($count[$field] ?? 0))) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            $estimates = '';
            foreach ($report['totals'] as $kind => $total) {
                if (is_int($total)) {
                    $estimates .= '<li>' . self::e($this->kind((string) $kind)) . ': ' . self::e($this->number($total))
                        . '</li>';
                }
            }
            if ($estimates !== '') {
                $html .= '<h3>' . self::e($this->text('col_source')) . '</h3><ul>' . $estimates . '</ul>';
            }
            $html .= '<p><a href="' . self::e($this->self(['job' => $job, 'format' => 'json'])) . '" download>'
                . self::e($this->text('download_report')) . '</a></p><h2>' . self::e($this->text('publish_heading'))
                . '</h2>';
            if ($report['published']) {
                $html .= '<p role="status">' . self::e($this->text('published')) . '</p>';
            } else {
                $html .= '<p>' . self::e($this->text('publish_intro')) . '</p><form method="post" action="'
                    . self::e($this->self()) . '" data-admin-progress>' . $this->csrfField()
                    . '<input type="hidden" name="job" value="' . self::e($job) . '">'
                    . '<p><label><input type="checkbox" name="activate_members" value="1" checked> '
                    . self::e($this->text('activate_members')) . '</label></p>'
                    . '<p><label><input type="checkbox" name="confirm_publish" value="1" required> '
                    . self::e($this->text('confirm_publish')) . '</label></p>'
                    . '<button name="action" value="publish">' . self::e($this->text('publish')) . '</button></form>';
            }
        }
        if ($report['notes'] !== []) {
            $html .= '<h2>' . self::e($this->text('reasons')) . '</h2><p>' . self::e($this->text('notes_total', [
                'count' => $report['notes_total'], 'page' => $report['notes_page'], 'pages' => $report['notes_pages'],
            ])) . '</p><ul>';
            foreach ($report['notes'] as $note) {
                $html .= '<li>' . self::e($this->kind((string) $note['entity'])) . ' / ' . self::e($note['source_id'])
                    . ': ' . self::e($this->text(self::note((string) $note['reason']))) . '</li>';
            }
            $html .= '</ul><p>';
            if ($report['notes_page'] > 1) {
                $html .= '<a href="' . self::e($this->self(['job' => $job, 'notes' => $report['notes_page'] - 1]))
                    . '">' . self::e($this->text('notes_previous')) . '</a> ';
            }
            if ($report['notes_page'] < $report['notes_pages']) {
                $html .= '<a href="' . self::e($this->self(['job' => $job, 'notes' => $report['notes_page'] + 1]))
                    . '">' . self::e($this->text('notes_next')) . '</a>';
            }
            $html .= '</p>';
        }
        $html .= '<details><summary>' . self::e($this->text('coverage')) . '</summary><ul>';
        foreach ($report['coverage'] as $table) {
            $mapped = in_array($table['mapping'] ?? '', ['mapped', 'core'], true);
            $html .= '<li>' . self::e((string) $table['table']) . ': '
                . self::e($this->text($mapped ? 'mapped' : 'excluded')) . '</li>';
        }
        return $html . '</ul></details>';
    }

    private function sourcesSection(array $sources, ?array $preflight, array $form): string
    {
        $form += ['host' => 'localhost', 'port' => '3306', 'charset' => 'UTF-8', 'engine' => '', 'database' => '',
            'username' => '', 'prefix' => '', 'attachment_root' => ''];
        $html = '<h2>' . self::e($this->text('sources')) . '</h2>';
        if ($sources === []) {
            $html .= '<p>' . self::e($this->text('import_setup')) . '</p>';
        }
        foreach ($sources as $source) {
            $id = (string) $source['id'];
            $version = $source['preflight']['version'] ?? null;
            $title = $source['engine'] . ' — ' . $source['database']
                . ($source['prefix'] !== '' ? ' (' . $source['prefix'] . ')' : '')
                . (is_string($version) ? ', ' . $version : '');
            $html .= '<section aria-labelledby="source-' . self::e($id) . '"><h3 id="source-' . self::e($id) . '">'
                . self::e($title) . '</h3>';
            $estimates = [];
            foreach ((array) ($source['preflight']['totals'] ?? []) as $kind => $total) {
                if (is_int($total)) {
                    $estimates[] = $this->kind((string) $kind) . ' ' . $this->number($total);
                }
            }
            if ($estimates !== []) {
                $html .= '<p>' . self::e($this->text('estimate')) . ': ' . self::e(implode(' · ', $estimates)) . '</p>';
            }
            foreach ((array) ($source['preflight']['problems'] ?? []) as $problem) {
                $html .= '<p>' . self::e($this->text(self::message((string) $problem))) . '</p>';
            }
            $html .= '<form method="post" action="' . self::e($this->self()) . '" data-admin-progress>'
                . $this->csrfField() . '<input type="hidden" name="source" value="' . self::e($id) . '">'
                . '<p><label><input type="checkbox" name="acknowledged" value="1" required> '
                . self::e($this->text('acknowledge')) . '</label></p><button name="action" value="begin">'
                . self::e($this->text('begin')) . '</button></form>';
            if ($source['panel']) {
                $html .= '<form method="post" action="' . self::e($this->self()) . '">' . $this->csrfField()
                    . '<input type="hidden" name="source" value="' . self::e($id) . '">'
                    . '<button name="action" value="remove_source">' . self::e($this->text('remove_source'))
                    . '</button></form>';
            }
            $html .= '</section>';
        }
        if ($preflight !== null) {
            $html .= '<h3>' . self::e($this->text('preflight')) . '</h3>';
            if ($preflight['engine'] !== null) {
                $html .= '<p>' . self::e($this->text('found', ['engine' => $preflight['engine'],
                    'version' => $preflight['version'] ?? '?', 'prefix' => $preflight['prefix'],
                    'charset' => $preflight['charset'] ?? '?'])) . '</p>';
            }
            $html .= '<ul>';
            foreach ($preflight['problems'] as $code) {
                $blocking = in_array($code, ImportPreflight::BLOCKING, true);
                $html .= '<li' . ($blocking ? ' role="alert"' : '') . '>' . self::e($this->text(self::message($code)))
                    . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '<details' . ($sources === [] || $preflight !== null ? ' open' : '') . '><summary>'
            . self::e($this->text('source_add')) . '</summary><p>' . self::e($this->text('source_intro')) . '</p>'
            . '<form method="post" action="' . self::e($this->self()) . '" data-admin-progress autocomplete="off">'
            . $this->csrfField() . '<p><label for="import-engine">' . self::e($this->text('engine'))
            . '</label><select id="import-engine" name="engine"><option value="">' . self::e($this->text('engine_auto'))
            . '</option>';
        foreach (ImportSources::ENGINES as $engine) {
            $html .= '<option value="' . self::e($engine) . '"' . ($form['engine'] === $engine ? ' selected' : '') . '>'
                . self::e($engine) . '</option>';
        }
        $html .= '</select></p>'
            . $this->field('import-host', 'host', 'db_host', $form['host'], ' required maxlength="253"')
            . $this->field(
                'import-port',
                'port',
                'db_port',
                $form['port'],
                ' required inputmode="numeric" pattern="[0-9]{1,5}"'
            )
            . $this->field('import-database', 'database', 'db_name', $form['database'], ' required maxlength="64"')
            . $this->field('import-username', 'username', 'db_user', $form['username'], ' required maxlength="128"')
            . '<p><label for="import-password">' . self::e($this->text('db_password')) . '</label>'
            . '<input id="import-password" name="password" type="password" maxlength="512" autocomplete="new-password">'
            . '</p>' . $this->field(
                'import-prefix',
                'prefix',
                'db_prefix',
                $form['prefix'],
                ' maxlength="48" pattern="[A-Za-z0-9_]*"'
            )
            . '<p><label for="import-charset">' . self::e($this->text('db_charset')) . '</label>'
            . '<select id="import-charset" name="charset">';
        foreach (ImportSources::CHARSETS as $charset) {
            $html .= '<option value="' . self::e($charset) . '"' . ($form['charset'] === $charset ? ' selected' : '')
                . '>' . self::e($charset) . '</option>';
        }
        return $html . '</select></p>'
            . $this->field(
                'import-attachments',
                'attachment_root',
                'attachment_root',
                $form['attachment_root'],
                ' maxlength="1024"'
            )
            . '<h4>' . self::e($this->text('before_start')) . '</h4><ul><li>'
            . self::e($this->text('before_permissions')) . '</li><li>' . self::e($this->text('before_passwords'))
            . '</li><li>' . self::e($this->text('before_links'))
            . '</li></ul><button name="action" value="add_source">' . self::e($this->text('check_source'))
            . '</button></form></details>';
    }

    private function recentSection(array $recent): string
    {
        $html = '<h2>' . self::e($this->text('recent')) . '</h2><ul>';
        foreach ($recent as $job) {
            $html .= '<li><a href="' . self::e($this->self(['job' => $job['id']])) . '">' . self::e($this->text('open'))
                . ' — ' . self::e($job['engine'] . ' ' . $job['source_version']) . '</a>'
                . ($job['published_at'] !== null ? ' (' . self::e($this->text('published')) . ')' : '') . '</li>';
        }
        return $html . '</ul>';
    }

    private function field(string $id, string $name, string $label, string $value, string $attributes): string
    {
        return '<p><label for="' . $id . '">' . self::e($this->text($label)) . '</label><input id="' . $id
            . '" name="' . $name . '" value="' . self::e($value) . '"' . $attributes . '></p>';
    }

    private function csrfField(): string
    {
        return '<input type="hidden" name="' . self::e(CsrfTokens::FIELD) . '" value="'
            . self::e($this->csrf->token()) . '">';
    }

    /** @param array<string, string|int> $query */
    private function self(array $query = []): string
    {
        return $this->urls->generate(self::ROUTE) . ($query === [] ? '' : '?' . http_build_query($query));
    }

    private function json(array $progress): Response
    {
        return new Response(json_encode(['ok' => true, 'revision' => $progress['revision'],
            'processed' => $progress['processed'], 'verified' => $progress['verified'],
            'complete' => $progress['complete']], JSON_THROW_ON_ERROR), 200, [
            'Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store',
        ]);
    }

    private function download(string $job): Response
    {
        try {
            $export = $this->jobs->export($job);
        } catch (Throwable) {
            return new Response('', 404, ['Cache-Control' => 'private, no-store']);
        }
        return new Response(
            json_encode($export, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            200,
            ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'private, no-store',
                'Content-Disposition' => 'attachment; filename="nolviko-import-' . $job . '.json"',
                'X-Content-Type-Options' => 'nosniff']
        );
    }

    /** @param array<string, string|int> $parameters */
    private function text(string $key, array $parameters = []): string
    {
        return $this->translator->translate('nolviko.importer.' . $key, $parameters);
    }

    private function kind(string $kind): string
    {
        return $this->text('kind_' . (in_array($kind, self::KINDS, true) ? $kind : 'private'));
    }

    private function number(int $value): string
    {
        return number_format($value, 0, '', $this->translator->locale() === 'tr' ? '.' : ',');
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function page(mixed $value): int
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{0,8}$/D', $value) === 1 ? (int) $value : 1;
    }

    private static function message(string $code): string
    {
        return match ($code) {
            'import_denied' => 'denied',
            'import_identity_collision', 'import_existing_user_map_denied' => 'collision',
            'import_confirmation_required' => 'import_confirm_required',
            'import_attachment_root_required' => 'source_attachments_required',
            'import_attachment_missing', 'import_attachment_invalid' => 'import_files_needed',
            'import_checkpoint_conflict' => 'import_reload',
            'import_parity_mismatch', 'import_count_mismatch', 'import_source_changed' => 'import_changed',
            'import_source_fields_invalid' => 'source_fields_invalid',
            'import_source_unavailable' => 'source_unavailable',
            'import_source_not_found' => 'source_not_found',
            'import_source_ambiguous' => 'source_ambiguous',
            'import_source_version_unsupported' => 'source_version_unsupported',
            'import_attachment_dir_missing' => 'source_attachments_missing',
            'import_charset_differs' => 'source_charset_differs',
            'import_source_writable' => 'source_writable',
            'import_not_complete' => 'import_not_complete',
            default => 'import_failed',
        };
    }

    private static function note(string $reason): string
    {
        return match ($reason) {
            'password_reset_required' => 'note_password',
            'existing_owner_explicitly_linked' => 'note_linked',
            'recipient_deleted', 'system_identity' => 'note_identity',
            'unowned_source_attachment' => 'note_unowned',
            'orphan_file_owner_archive' => 'note_orphan',
            'original_file_retained' => 'note_file',
            default => 'note_aggregate',
        };
    }

    /**
     * While the page is open the import keeps running, one request after another; each request runs several small
     * batches on the server and saves its place. When a response is lost the next request is answered with the saved
     * checkpoint, so nothing is copied twice. Without JavaScript the "Continue" button does the same.
     */
    private const SCRIPT = <<<'JS'
(() => {
    'use strict';
    const form = document.querySelector('form[data-import-run]');
    if (!form) return;
    const status = document.querySelector('[data-import-status]');
    const button = form.querySelector('button');
    const revisionField = form.querySelector('input[name=revision]');
    const number = new Intl.NumberFormat(document.documentElement.lang || undefined);
    let failures = 0;
    const show = text => { if (status) { status.hidden = false; status.textContent = text; } };
    const progress = result => (status?.dataset.running || '')
        .replace('{processed}', number.format(result.processed))
        .replace('{verified}', number.format(result.verified));
    const stop = text => { show(text); if (button) button.hidden = false; };
    const tick = async () => {
        const body = new FormData(form);
        body.set('action', 'run');
        body.set('revision', revisionField.value);
        let result = null;
        try {
            // getAttribute: the submit button is named "action", which hides form.action.
            const response = await fetch(form.getAttribute('action'), {
                method: 'POST', body, credentials: 'same-origin', headers: {Accept: 'application/json'},
            });
            result = await response.json();
        } catch (error) {
            result = null;
        }
        if (result && result.ok) {
            failures = 0;
            revisionField.value = String(result.revision);
            show(progress(result));
            if (result.complete) {
                show(status?.dataset.done || '');
                window.setTimeout(() => { window.location.href = form.dataset.importDone; }, 800);
                return;
            }
            window.setTimeout(tick, 50);
            return;
        }
        if (result === null && failures < 5) {
            failures += 1;
            window.setTimeout(tick, 2000 * failures);
            return;
        }
        stop(result && result.error ? result.error : (status?.dataset.paused || ''));
    };
    if (button) button.hidden = true;
    form.addEventListener('submit', event => {
        event.preventDefault();
        if (button) button.hidden = true;
        failures = 0;
        tick();
    });
    tick();
})();
JS;
}
