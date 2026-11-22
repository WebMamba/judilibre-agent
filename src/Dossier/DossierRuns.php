<?php

declare(strict_types=1);

namespace App\Dossier;

use AgUi\Event\Event;
use AgUi\Event\MessagesSnapshotEvent;
use AgUi\Event\RunFinishedEvent;
use AgUi\Run\RunAgentInput;
use App\Entity\Dossier;
use App\Repository\DossierRepository;
use App\Research\ResearchState;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockExpiredException;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Binds AG-UI runs to their dossier (ADR 0002): the server is the source of truth.
 *
 * Before a run, the input is rebuilt from the dossier: its saved conversation plus the
 * user's new messages, its saved state with only the user's part (filters, pins,
 * exclusions) taken from the browser. A browser cannot forge a validated plan, a tool
 * result or a consultation. After the run, the conversation, the state and the open
 * interrupts are saved.
 */
final readonly class DossierRuns
{
    /**
     * The dossier's lock lives this long after the run's last event: a run that dies or
     * hangs frees its dossier. Longer than the longest silence of a live run (a model
     * turn, a triage).
     */
    public const float LOCK_TTL = 300.0;

    /** The lock is put off once it has run down this long, not at every text delta. */
    private const float REFRESH_AFTER = 30.0;

    public function __construct(
        private DossierRepository $dossiers,
        private NormalizerInterface $normalizer,
    ) {
    }

    public function prepare(Dossier $dossier, RunAgentInput $input): RunAgentInput
    {
        $client = json_decode(json_encode($input->toArray(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        $saved = $dossier->getMessages();
        $known = array_flip(array_column($saved, 'id'));
        $new = array_values(array_filter(
            $client['messages'] ?? [],
            static fn (array $message): bool => 'user' === ($message['role'] ?? null) && !isset($known[$message['id'] ?? ''])
        ));

        $state = [] === $dossier->getState() ? $this->normalizer->normalize(new ResearchState()) : $dossier->getState();
        $sent = \is_array($client['state'] ?? null) ? $client['state'] : [];
        foreach (ResearchState::USER_KEYS as $key) {
            if (\array_key_exists($key, $sent)) {
                $state[$key] = $sent[$key];
            }
        }

        return RunAgentInput::fromArray([
            ...$client,
            'messages' => [...$saved, ...$new],
            'state' => $state,
            // No frontend tools: everything the agent may do is on the server.
            'tools' => [],
        ]);
    }

    /**
     * The run's events, unchanged; the dossier is saved as they pass, the lock put off
     * while they come and released once the stream is over, however it ends.
     *
     * @param iterable<Event> $events
     *
     * @return \Generator<int, Event>
     */
    public function record(Dossier $dossier, iterable $events, ResearchState $state, LockInterface $lock): \Generator
    {
        try {
            foreach ($events as $event) {
                if ($event instanceof MessagesSnapshotEvent) {
                    // The state is still the run's here: the SDK resets it after RUN_FINISHED.
                    $dossier->recordState((array) $this->normalizer->normalize($state));
                    $dossier->recordMessages(self::plain($event->toArray())['messages'] ?? []);
                }
                if ($event instanceof RunFinishedEvent) {
                    $outcome = self::plain($event->toArray())['outcome'] ?? [];
                    $dossier->recordInterrupts('interrupt' === ($outcome['type'] ?? null) ? array_values($outcome['interrupts'] ?? []) : []);
                    $this->dossiers->save($dossier);
                }

                self::keep($lock);

                yield $event;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * A lock that expired anyway (a silence longer than its TTL) is not this run's to
     * take back: the run still ends, its dossier saved.
     */
    private static function keep(LockInterface $lock): void
    {
        if ($lock->getRemainingLifetime() > self::LOCK_TTL - self::REFRESH_AFTER) {
            return;
        }

        try {
            $lock->refresh(self::LOCK_TTL);
        } catch (LockConflictedException|LockExpiredException) {
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function plain(array $data): array
    {
        return json_decode(json_encode($data, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
    }
}
