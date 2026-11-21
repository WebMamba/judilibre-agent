<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Research\Stance;
use App\Triage\Candidate;
use App\Triage\Triage;
use App\Triage\Verdict;

/**
 * Stands in for Jev in the test environment: a decision is kept as favorable unless the
 * test says otherwise. Records what it was asked. Static, like ScriptedPlatform: the
 * test client reboots the kernel between requests.
 */
final class FakeTriage implements Triage
{
    /** @var array<string, Stance|null> id => stance; null for off topic */
    public static array $verdicts = [];

    /** @var list<array{context: string, ids: list<string>}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$verdicts = [];
        self::$calls = [];
    }

    public function sort(string $context, array $candidates): array
    {
        self::$calls[] = ['context' => $context, 'ids' => array_map(static fn (Candidate $c): string => $c->id, $candidates)];

        return array_map(static function (Candidate $candidate): Verdict {
            $stance = \array_key_exists($candidate->id, self::$verdicts) ? self::$verdicts[$candidate->id] : Stance::Favorable;

            return new Verdict($candidate, $stance, null === $stance ? 0.9 : 0.05, 0.8);
        }, $candidates);
    }
}
