<?php

declare(strict_types=1);

namespace AdultCore\Support;

/** Release sign-off checklist. Until signed-off externally, no 1.0 production claim. */
final class ReleaseReadiness
{
    /** @return list<string> */
    public static function blockers(): array
    {
        // One canonical list of ten uncompleted launch workstreams. An
        // automated or local green CI run cannot remove an external gate.
        return array_map(
            static fn(array $item): string => $item['name'] . ': ' . $item['remaining'],
            ReleaseMilestones::all()
        );
    }

    public static function productionReady(): bool
    {
        return self::blockers() === [];
    }
}
