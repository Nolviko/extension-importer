<?php

declare(strict_types=1);

namespace NolvikoExt\Importer;

use Nolviko\Modules\Importers\Infrastructure\ImportTarget;
use RuntimeException;

/**
 * One import step on the source side (ADR-038): ask the core import target where to read, read that batch from the
 * source (outside the target's lock), convert it to contract-1 records and hand it over; the target writes it with
 * its checkpoint in one transaction of the core's own connection.
 */
final readonly class ImportRunner
{
    public function __construct(private ImportTarget $target, private ImportSource $source)
    {
    }

    /** @param array<string, int|null> $totals The check's estimate of the source's records, shown in the report. */
    public function begin(int $actor, bool $acknowledgedScope, array $totals = []): string
    {
        if ($actor < 1 || !$acknowledgedScope) {
            throw new RuntimeException('import_confirmation_required');
        }
        return $this->target->begin(
            $this->source->identity(),
            $this->source->coverage(),
            $this->source->phases(),
            $actor,
            $acknowledgedScope,
            $totals,
        );
    }

    public function step(string $job, int $revision, int $limit = 25, int $milliseconds = 2000): array
    {
        if ($limit < 1 || $limit > 25) {
            throw new RuntimeException('import_batch_invalid');
        }
        $identity = $this->source->identity();
        $phases = $this->source->phases();
        $next = $this->target->next($job, $identity, $phases);
        if ($next['revision'] !== $revision) {
            throw new RuntimeException('import_checkpoint_conflict');
        }
        if ($next['complete']) {
            return $this->target->read($job);
        }
        return $this->target->apply(
            $job,
            $revision,
            $identity,
            $phases,
            SourceRecords::normalize(
                (string) $identity['engine'],
                $next['phase'],
                $this->source->rows($next['phase'], $next['cursor'], $limit),
            ),
            $this->source->asset(...),
            $milliseconds,
        );
    }

    public function read(string $id): array
    {
        return $this->target->read($id);
    }
}
