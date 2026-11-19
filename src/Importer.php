<?php

declare(strict_types=1);

namespace NolvikoExt\Importer;

use Nolviko\Modules\Importers\Infrastructure\ImportJobs;
use Nolviko\Modules\Importers\Infrastructure\ImportTarget;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * What the importer screen does (moved from the core in ADR-038): add and check sources, start a job, and run it a
 * request at a time. Jobs, reports and publishing stay in the core (ImportJobs, ImportTarget).
 */
final readonly class Importer
{
    /** Time one request spends on batches; well below the 30-second limit of most shared hosts. */
    public const RUN_SECONDS = 5.0;

    public function __construct(
        private ImportSources $sources,
        private ImportTarget $target,
        private ImportJobs $jobs,
    ) {
    }

    /** Sources, after taking over any that the beta.11 core panel stored. */
    public function sources(): array
    {
        $legacy = $this->jobs->legacySources();
        if ($legacy !== []) {
            $this->sources->adopt($legacy);
            $this->jobs->forgetLegacySources();
        }
        return $this->sources->catalogue();
    }

    /** Connects to a source that is not saved yet and reports what it holds. */
    public function check(#[SensitiveParameter] array $fields): array
    {
        $connection = $this->sources->connection($fields);
        try {
            return (new ImportPreflight($connection))->inspect($fields);
        } catch (RuntimeException $failure) {
            throw $failure;
        } catch (Throwable) {
            // Wrong host, user, password or database name: no driver text reaches the page.
            throw new RuntimeException('import_source_unavailable');
        } finally {
            $connection->close();
        }
    }

    /**
     * Checks a source and saves it when it is ready, with the forum, prefix and character set the check found.
     *
     * @return array{id: string|null, preflight: array}
     */
    public function add(#[SensitiveParameter] array $fields, int $actor): array
    {
        $preflight = $this->check($fields);
        if (!ImportPreflight::ready($preflight)) {
            return ['id' => null, 'preflight' => $preflight];
        }
        $fields['engine'] = $preflight['engine'];
        $fields['prefix'] = $preflight['prefix'];
        if ($preflight['charset'] !== null) {
            $fields['charset'] = $preflight['charset'];
        }
        return ['id' => $this->sources->save($fields, $preflight, $actor), 'preflight' => $preflight];
    }

    public function removeSource(string $id): void
    {
        $this->sources->remove($id);
    }

    public function begin(string $source, int $actor, bool $acknowledged): string
    {
        $totals = [];
        foreach ($this->sources() as $entry) {
            if ($entry['id'] === $source && is_array($entry['preflight']['totals'] ?? null)) {
                $totals = $entry['preflight']['totals'];
            }
        }
        return (new ImportRunner($this->target, $this->sources->open($source)))->begin($actor, $acknowledged, $totals);
    }

    public function step(string $job, int $revision): array
    {
        return $this->runner($job)->step($job, $revision);
    }

    /**
     * Runs batches until the job is complete or this request's time or memory share is used up. Each batch commits
     * its records and checkpoint together, so a request that is cut off loses at most the batch in progress.
     */
    public function run(string $job, int $revision, float $seconds = self::RUN_SECONDS): array
    {
        $runner = $this->runner($job);
        $until = microtime(true) + $seconds;
        $memory = self::memoryLimit();
        do {
            $status = $runner->step($job, $revision);
            $revision = $status['revision'];
        } while (
            !$status['complete'] && microtime(true) < $until
            && ($memory === null || memory_get_usage(true) < $memory * 0.7)
        );
        return $status;
    }

    private function runner(string $job): ImportRunner
    {
        $identity = $this->jobs->identity($job);
        return new ImportRunner($this->target, $this->sources->open((string) $identity['source_id']));
    }

    private static function memoryLimit(): ?int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return null;
        }
        $value = (int) $limit;
        return match (strtolower(substr($limit, -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
