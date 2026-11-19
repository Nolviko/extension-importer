<?php

declare(strict_types=1);

/*
 * Nolviko Importer extension (ADR-038, 2026-11-20): moves an SMF 2.1, MyBB 1.8 or phpBB 3.3 forum to Nolviko from the
 * admin panel. Install it from Admin > Extensions, import, publish, then remove it: what the import created (members,
 * forums, topics, posts, private archives, files, polls, old-address redirects) belongs to the core and stays.
 *
 * The extension reads the old forum and converts its records (contract 1); the core's import target writes them
 * into the forum's tables with its own connection. The extension's guarded connection writes only its sources table
 * (sealed passwords), whose rows "Remove" deletes.
 */

use Doctrine\DBAL\Connection;
use Nolviko\Core\Application\CompositionRoot;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Extensions\ExtensionContext;
use Nolviko\Core\Extensions\ExtensionFailure;
use Nolviko\Core\Extensions\ExtensionProvider;
use Nolviko\Core\Http\CspNonce;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\RouteDefinition;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminAudit;
use Nolviko\Modules\Importers\Infrastructure\ImportJobs;
use Nolviko\Modules\Importers\Infrastructure\ImportTarget;
use Nolviko\Modules\Permissions\Domain\PermissionDefinition;
use Nolviko\Modules\Permissions\Domain\PermissionType;
use Nolviko\Modules\Users\Http\RequestSubject;
use NolvikoExt\Importer\AdminHandler;
use NolvikoExt\Importer\Importer;
use NolvikoExt\Importer\ImportSources;

foreach (
    ['ImportSource', 'SourceText', 'SourceSchema', 'SourceAuxRows', 'LocalImportAsset', 'SqlImportSource',
    'SourceRecords', 'ImportPreflight', 'ImportSources', 'ImportRunner', 'Importer', 'AdminHandler'] as $class
) {
    require_once __DIR__ . '/' . $class . '.php';
}

return new class implements ExtensionProvider {
    public function register(ExtensionContext $context): void
    {
        if (ImportTarget::CONTRACT !== 1) {
            // The core speaks another import contract: refuse to boot rather than write wrong records.
            throw new ExtensionFailure('extension_incompatible');
        }
        $table = $context->table('sources');
        $context->service(
            'nolviko.importer.importer',
            static fn(string $root, ?Config $config, Connection $db, ImportTarget $target, ImportJobs $jobs): Importer
                => new Importer(
                    new ImportSources(
                        $root,
                        $config?->appSecret ?? throw new ExtensionFailure('extension_config_missing'),
                        $db,
                        $table,
                    ),
                    $target,
                    $jobs,
                ),
            [CompositionRoot::ROOT, CompositionRoot::CONFIG, Connection::class, ImportTarget::class, ImportJobs::class],
        );

        // Administrators only (checked in the handler); the permission lets the route exist for every group.
        $context->permission(new PermissionDefinition('nolviko.importer.manage', PermissionType::Bool, default: true));
        $context->route(
            new RouteDefinition(
                AdminHandler::ROUTE,
                '/ext/nolviko/importer/admin',
                ['GET', 'POST'],
                requiresHttps: true,
            ),
            'nolviko.importer.manage',
            static fn(
                Importer $importer,
                ImportJobs $jobs,
                RequestSubject $subjects,
                AdminSession $admin,
                CsrfTokens $csrf,
                Connection $db,
                TemplateRenderer $views,
                MessageTranslator $translator,
                Router $router,
                CspNonce $nonce,
            ): AdminHandler => new AdminHandler(
                $importer,
                $jobs,
                $subjects,
                $admin,
                $csrf,
                new DbalAdminAudit($db),
                $views,
                $translator,
                $router,
                $nonce,
            ),
            [
                'nolviko.importer.importer', ImportJobs::class, RequestSubject::class, AdminSession::class,
                CsrfTokens::class, Connection::class, TemplateRenderer::class, MessageTranslator::class, Router::class,
                CspNonce::class,
            ],
        );
        $context->adminPage(AdminHandler::ROUTE);
    }
};
