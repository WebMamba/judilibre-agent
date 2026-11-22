<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use AgUi\Event\RunStartedEvent;
use App\Dossier\DossierRuns;
use App\Entity\Dossier;
use App\Research\ResearchState;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\Exception\LockExpiredException;
use Symfony\Component\Lock\LockInterface;

/**
 * The dossier's lock expires (DossierRuns::LOCK_TTL): a live run puts it off as its
 * events come, without a write at every text delta.
 */
final class DossierRunsTest extends KernelTestCase
{
    public function testALiveRunPutsItsLockOffAndReleasesItAtTheEnd(): void
    {
        // Fresh, run down past the refresh threshold, then fresh again.
        $lock = new SpyLock([DossierRuns::LOCK_TTL, DossierRuns::LOCK_TTL - 60, DossierRuns::LOCK_TTL]);

        iterator_to_array($this->record($lock, 3));

        self::assertSame([DossierRuns::LOCK_TTL], $lock->refreshes);
        self::assertTrue($lock->released);
    }

    public function testARunWhoseLockExpiredStillEnds(): void
    {
        $lock = new SpyLock([0.0, 0.0], expired: true);

        self::assertCount(2, iterator_to_array($this->record($lock, 2)));
        self::assertTrue($lock->released);
    }

    /** @return \Generator<int, RunStartedEvent> */
    private function record(LockInterface $lock, int $events): \Generator
    {
        $runs = self::getContainer()->get(DossierRuns::class);
        $stream = array_map(static fn (int $i): RunStartedEvent => new RunStartedEvent('t', 'r'.$i), range(1, $events));

        return $runs->record(new Dossier('dossier_runs', 'juriste@example.com'), $stream, new ResearchState(), $lock);
    }
}

/** A lock whose remaining lifetime is scripted, one value per event. */
final class SpyLock implements LockInterface
{
    /** @var list<float|null> */
    public array $refreshes = [];
    public bool $released = false;

    /** @param list<float> $lifetimes */
    public function __construct(private array $lifetimes, private readonly bool $expired = false)
    {
    }

    public function acquire(bool $blocking = false): bool
    {
        return true;
    }

    public function refresh(?float $ttl = null): void
    {
        $this->refreshes[] = $ttl;
        if ($this->expired) {
            throw new LockExpiredException('Expired.');
        }
    }

    public function isAcquired(): bool
    {
        return true;
    }

    public function release(): void
    {
        $this->released = true;
    }

    public function isExpired(): bool
    {
        return $this->expired;
    }

    public function getRemainingLifetime(): ?float
    {
        return array_shift($this->lifetimes);
    }
}
