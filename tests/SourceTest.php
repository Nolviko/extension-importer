<?php

declare(strict_types=1);

namespace NolvikoExt\Importer\Tests;

use InvalidArgumentException;
use Nolviko\Modules\Importers\Domain\ImportCursor;
use Nolviko\Modules\Importers\Infrastructure\ImportFileStore;
use Nolviko\Modules\Importers\Infrastructure\InlineAttachments;
use Nolviko\Tests\Support\TemporaryDirectory;
use NolvikoExt\Importer\LocalImportAsset;
use NolvikoExt\Importer\SourceRecords;
use NolvikoExt\Importer\SourceText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Source side of the importer (no database). Moved from the core with ADR-038 (2026-11-20). Run from the workspace:
 *   php dev/vendor/bin/phpunit --bootstrap dev/vendor/autoload.php extensions-src/nolviko/importer/tests
 */
final class SourceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        foreach (['ImportSource', 'SourceText', 'SourceRecords', 'LocalImportAsset'] as $class) {
            require_once dirname(__DIR__) . '/src/' . $class . '.php';
        }
    }

    /**
     * Contract 1: the fingerprint is taken from the raw row (the value the core computed before, so earlier jobs
     * continue), text arrives converted, and inline attachments become neutral markers the core resolves.
     */
    public function testSourceRecordsFingerprintRawRowsAndConvertTextToNeutralMarkers(): void
    {
        $raw = ['id' => 7, '_key' => ImportCursor::key([7]), 'thread' => 1, 'body' => 'A &amp; [attach]12[/attach]'];
        [$smf] = SourceRecords::normalize('smf', 'posts', [$raw]);
        self::assertSame(hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR)), $smf['_hash']);
        self::assertSame('A & [nolviko-attachment]12[/nolviko-attachment]', $smf['body']);
        [$mybb] = SourceRecords::normalize('mybb', 'posts', [['body' => 'x [attachment=5] y'] + $raw]);
        self::assertSame('x [nolviko-attachment]5[/nolviko-attachment] y', $mybb['body']);
        [$phpbb] = SourceRecords::normalize('phpbb', 'posts', [
            ['body' => '[b:abc12345]Hi[/b:abc12345] [attachment=0:abc12345]ç.png[/attachment:abc12345]'] + $raw,
        ]);
        self::assertSame('[b]Hi[/b] [nolviko-attachment-name]ç.png[/nolviko-attachment-name]', $phpbb['body']);
        self::assertTrue(InlineAttachments::has($phpbb['body']));
        self::assertFalse(InlineAttachments::has('[attach]12[/attach]'));
        [$user] = SourceRecords::normalize('smf', 'users', [['id' => 2, '_key' => ImportCursor::key([2]),
            'signature' => 'İmza &amp; Çığ']]);
        self::assertSame('İmza & Çığ', $user['signature']);
        $this->expectExceptionMessage('import_text_invalid');
        SourceRecords::normalize('smf', 'private_inbox', [['body' => "bad\0byte"] + $raw]);
    }

    public function testExplicitCharsetPreservesTurkishAndEntitiesWithoutExecutingMarkup(): void
    {
        self::assertSame('İstanbul & Çığ', SourceText::body('İstanbul &amp; Çığ', 'smf', 'UTF-8'));
        self::assertSame('café', SourceText::decode("caf\xe9", 'Windows-1252'));
        self::assertSame('[b]Hello[/b] <script>x</script>', SourceText::body(
            '<r><B><s>[b]</s>Hello<e>[/b]</e></B> &lt;script&gt;x&lt;/script&gt;</r>',
            'phpbb',
            'UTF-8'
        ));
        self::assertSame('[b]Hello[/b]', SourceText::body('[b:abc12345]Hello[/b:abc12345]', 'phpbb', 'UTF-8'));
    }

    #[DataProvider('badText')]
    public function testInvalidOrExternalEntityTextStops(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);
        SourceText::body($text, 'phpbb', 'UTF-8');
    }

    public static function badText(): array
    {
        return [["bad\0text"], ["\xff"], ['<r><b></r>'],
            ['<r><!DOCTYPE x [<!ENTITY x SYSTEM "file:///etc/passwd">]>&x;</r>'],
            ['<r>&unknown;</r>'], [str_repeat('a', 50001)]];
    }

    /** A long phpBB s9e document converts within the post limit (formerly the core's import benchmark). */
    public function testLongPhpbbDocumentStaysWithinThePostLimit(): void
    {
        $source = '<r>' . str_repeat('<B><s>[b]</s>Çığ<e>[/b]</e></B> ', 500) . '</r>';
        $started = hrtime(true);
        $text = SourceText::body($source, 'phpbb', 'UTF-8');
        self::assertLessThan(50000, mb_strlen($text, 'UTF-8'));
        self::assertLessThan(1.0, (hrtime(true) - $started) / 1e9);
    }

    public function testOriginalFileRetryAndChangedSourceAreNeverSilentlyOverwritten(): void
    {
        $root = new TemporaryDirectory();
        $bytes = "\0\xfforiginal\r\n";
        $source = $root->write('source/file.dat', $bytes);
        $root->write('storage/.keep', '');
        $root->write('public/.keep', '');
        chmod($root->path . '/storage', 0700);
        try {
            $asset = new LocalImportAsset($root->path . '/source', 'file.dat', strlen($bytes));
            $store = new ImportFileStore($root->path . '/storage', $root->path . '/public');
            $first = $store->retain(str_repeat('a', 32), 1, $asset);
            self::assertSame($first, $store->retain(str_repeat('a', 32), 1, $asset));
            file_put_contents($source, str_repeat('x', strlen($bytes)));
            $this->expectException(RuntimeException::class);
            $store->retain(
                str_repeat('a', 32),
                1,
                new LocalImportAsset($root->path . '/source', 'file.dat', strlen($bytes))
            );
        } finally {
            $root->remove();
        }
    }

    public function testTraversalCannotReadSiblingSourceFile(): void
    {
        $root = new TemporaryDirectory();
        $root->write('source/.keep', '');
        $root->write('secret', 'secret');
        try {
            $this->expectException(RuntimeException::class);
            new LocalImportAsset($root->path . '/source', '../secret', 6);
        } finally {
            $root->remove();
        }
    }

    public function testOversizeOriginalIsRejectedBeforeCopy(): void
    {
        $root = new TemporaryDirectory();
        $root->write('source/file.dat', 'x');
        try {
            $this->expectException(RuntimeException::class);
            new LocalImportAsset($root->path . '/source', 'file.dat', 33554433);
        } finally {
            $root->remove();
        }
    }
}
