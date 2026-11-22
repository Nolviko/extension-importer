<?php

declare(strict_types=1);

namespace NolvikoExt\Importer;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * The check before an import (beta.11): which forum and version the copy holds, under which table prefix and
 * character set, roughly how much it contains, whether the account could write to it and whether the attachment
 * folder is there. Reads table names and server statistics only, never rows with member data.
 *
 * Problems are codes: `blocking` ones stop the start, the others are shown as advice.
 */
final readonly class ImportPreflight
{
    /** Tables that identify each forum; the first one is used to find the prefix. */
    private const SIGNATURES = [
        'smf' => ['messages', 'topics', 'boards', 'members', 'settings'],
        'mybb' => ['posts', 'threads', 'forums', 'users', 'datacache'],
        'phpbb' => ['posts', 'topics', 'forums', 'users', 'config'],
    ];

    /** Source tables behind each counted kind of record. */
    private const TOTALS = [
        'smf' => ['users' => 'members', 'forums' => 'boards', 'threads' => 'topics', 'posts' => 'messages',
            'attachments' => 'attachments', 'polls' => 'polls', 'private' => 'personal_messages'],
        'mybb' => ['users' => 'users', 'forums' => 'forums', 'threads' => 'threads', 'posts' => 'posts',
            'attachments' => 'attachments', 'polls' => 'polls', 'private' => 'privatemessages'],
        'phpbb' => ['users' => 'users', 'forums' => 'forums', 'threads' => 'topics', 'posts' => 'posts',
            'attachments' => 'attachments', 'polls' => 'poll_options', 'private' => 'privmsgs'],
    ];

    private const SUPPORTED = ['smf' => '2.1', 'mybb' => '1.8', 'phpbb' => '3.3'];

    public const BLOCKING = ['import_source_not_found', 'import_source_ambiguous', 'import_source_version_unsupported',
        'import_attachment_root_required', 'import_attachment_dir_missing'];

    /** Counting queries per engine and kind: [table, condition]. */
    private const EXACT = [
        'smf' => ['users' => ['members', '1 = 1'], 'categories' => ['categories', '1 = 1'],
            'forums' => ['boards', '1 = 1'], 'threads' => ['topics', '1 = 1'], 'posts' => ['messages', '1 = 1'],
            'attachments' => ['attachments', 'attachment_type = 0 AND id_msg > 0'],
            'polls' => ['topics', 'id_poll > 0'], 'private' => ['personal_messages', '1 = 1']],
        'mybb' => ['users' => ['users', '1 = 1'], 'categories' => ['forums', "type = 'c'"],
            'forums' => ['forums', "type = 'f'"], 'threads' => ['threads', '1 = 1'], 'posts' => ['posts', '1 = 1'],
            'attachments' => ['attachments', 'pid > 0'], 'polls' => ['polls', '1 = 1'],
            'private' => ['privatemessages', 'folder <> 2']],
        'phpbb' => ['users' => ['users', 'user_type <> 2'], 'categories' => ['forums', 'forum_type = 0'],
            'forums' => ['forums', 'forum_type <> 0'], 'threads' => ['topics', '1 = 1'],
            'posts' => ['posts', '1 = 1'], 'attachments' => ['attachments', 'in_message = 0'],
            'polls' => ['topics', "poll_start > 0 AND poll_title <> ''"], 'private' => ['privmsgs', '1 = 1']],
    ];

    public function __construct(private Connection $source)
    {
    }

    /**
     * SMF upload folders the panel did not name, as found on this server: the source's own path, or a folder of
     * the same name next to the first given folder (a copied forum).
     *
     * @param array<string, string> $given
     * @return array<string, string>
     */
    private function smfFolders(string $prefix, array $given): array
    {
        return SqlImportSource::smfFolders($this->source, $prefix, $given);
    }

    /** @return array<string, int> Kinds whose exact count could be read. */
    private function exact(string $engine, string $prefix): array
    {
        $counts = [];
        foreach (self::EXACT[$engine] ?? [] as $kind => [$table, $condition]) {
            try {
                $counts[$kind] = (int) $this->source->fetchOne('SELECT COUNT(*) FROM '
                    . $this->source->quoteIdentifier($prefix . $table) . ' WHERE ' . $condition);
            } catch (Throwable) {
                continue;
            }
        }
        return $counts;
    }

    /**
     * @param array{engine?: string, prefix?: string, charset?: string, attachment_root?: string} $wanted
     *     An empty engine or prefix is found automatically.
     */
    public function inspect(array $wanted): array
    {
        $engine = (string) ($wanted['engine'] ?? '');
        $prefix = (string) ($wanted['prefix'] ?? '');
        $tables = $this->tables($prefix);
        $found = [];
        foreach (self::SIGNATURES as $candidate => $signature) {
            if ($engine !== '' && $engine !== $candidate) {
                continue;
            }
            foreach (array_keys($tables) as $name) {
                if (!str_ends_with($name, $signature[0])) {
                    continue;
                }
                $guess = substr($name, 0, -strlen($signature[0]));
                if (($prefix !== '' && $guess !== $prefix) || !str_starts_with($guess, $prefix)) {
                    continue;
                }
                $complete = true;
                foreach ($signature as $table) {
                    $complete = $complete && isset($tables[$guess . $table]);
                }
                if ($complete) {
                    $found[] = ['engine' => $candidate, 'prefix' => $guess];
                }
            }
        }
        $result = ['engine' => null, 'prefix' => null, 'version' => null, 'supported' => self::SUPPORTED,
            'charset' => null, 'totals' => [], 'candidates' => array_slice($found, 0, 8), 'problems' => []];
        if ($found === []) {
            $result['problems'][] = 'import_source_not_found';
            return $result;
        }
        if (count($found) > 1) {
            $result['problems'][] = 'import_source_ambiguous';
            return $result;
        }
        ['engine' => $engine, 'prefix' => $prefix] = $found[0];
        $result['engine'] = $engine;
        $result['prefix'] = $prefix;
        $source = new SqlImportSource($this->source, $engine, $prefix, 'preflight-check');
        $version = $source->version();
        $result['version'] = $version;
        if ($version === null || !str_starts_with($version, self::SUPPORTED[$engine] . '.')) {
            $result['problems'][] = 'import_source_version_unsupported';
        }
        $collation = (string) ($tables[$prefix . self::TOTALS[$engine]['posts']]['collation'] ?? '');
        $result['charset'] = str_starts_with($collation, 'utf8') ? 'UTF-8'
            : (str_starts_with($collation, 'latin1') ? 'Windows-1252' : null);
        $wantedCharset = (string) ($wanted['charset'] ?? 'UTF-8');
        if ($result['charset'] !== null && $result['charset'] !== $wantedCharset) {
            $result['problems'][] = 'import_charset_differs';
        }
        foreach (self::TOTALS[$engine] as $kind => $table) {
            $result['totals'][$kind] = isset($tables[$prefix . $table]) ? $tables[$prefix . $table]['rows'] : null;
        }
        // 1.1.0: exact counts of what will be imported (categories apart from forums, messages instead of mailbox
        // copies, post attachments only). Plain COUNT queries; the table estimates above stay as the fallback.
        $result['totals'] = $this->exact($engine, $prefix) + $result['totals'];
        $roots = ImportSources::roots((string) ($wanted['attachment_root'] ?? ''));
        if ($engine === 'smf') {
            // SMF keeps its upload folders in settings.attachmentUploadDir (a path, or JSON for several).
            $roots += $this->smfFolders($prefix, $roots);
        }
        if ($roots === [] && ($result['totals']['attachments'] ?? 0) > 0) {
            $result['problems'][] = 'import_attachment_root_required';
        }
        foreach ($roots as $root) {
            if (!is_dir($root) || !is_readable($root)) {
                $result['problems'][] = 'import_attachment_dir_missing';
                break;
            }
        }
        $result['attachment_folders'] = count($roots);
        if ($engine !== 'phpbb') {
            // Stock SMF 2.1 and MyBB 1.8 have no private-message attachments; said here so nothing seems lost.
            $result['problems'][] = 'import_private_files_none';
        }
        if ($this->writable()) {
            $result['problems'][] = 'import_source_writable';
        }
        return $result;
    }

    public static function ready(array $preflight): bool
    {
        return $preflight['engine'] !== null && array_intersect($preflight['problems'], self::BLOCKING) === [];
    }

    /** @return array<string, array{rows: int, collation: string}> */
    private function tables(string $prefix): array
    {
        $rows = $this->source->fetchAllAssociative(
            'SELECT TABLE_NAME AS name, TABLE_ROWS AS row_estimate, TABLE_COLLATION AS collation '
                . "FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ? ESCAPE '!' "
                . 'ORDER BY table_name LIMIT 4000',
            [SqlImportSource::like($prefix)]
        );
        $tables = [];
        foreach ($rows as $row) {
            $tables[(string) $row['name']] = ['rows' => (int) $row['row_estimate'],
                'collation' => (string) $row['collation']];
        }
        return $tables;
    }

    /** Whether the account may change the copy; a read-only account is advised, not required. */
    private function writable(): bool
    {
        try {
            $changes = '/^GRANT\s.*\b(ALL PRIVILEGES|INSERT|UPDATE|DELETE|DROP|ALTER)\b.*\sON\s/i';
            foreach ($this->source->fetchFirstColumn('SHOW GRANTS FOR CURRENT_USER()') as $grant) {
                if (preg_match($changes, (string) $grant) === 1) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }
        return false;
    }
}
