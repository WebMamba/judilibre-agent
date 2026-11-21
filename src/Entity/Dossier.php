<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DossierRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A dossier: one AG-UI thread, its conversation and its research state, owned by one
 * user (ADR 0002). The server is the source of truth: the browser only adds the user's
 * new messages and the user's part of the state.
 */
#[ORM\Entity(repositoryClass: DossierRepository::class)]
#[ORM\Index(name: 'dossier_owner', columns: ['owner', 'updated_at'])]
class Dossier
{
    /** An AG-UI thread id: what the browser generates for a new dossier. */
    public const string ID_PATTERN = '[A-Za-z0-9_-]{8,64}';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(length: 160)]
    private string $title = '';

    #[ORM\Column(length: 32)]
    private string $phase = 'qualification';

    /** @var array<string, mixed> the normalized ResearchState */
    #[ORM\Column(type: Types::JSON)]
    private array $state = [];

    /** @var list<array<string, mixed>> the AG-UI messages, as the last MESSAGES_SNAPSHOT gave them */
    #[ORM\Column(type: Types::JSON)]
    private array $messages = [];

    /** @var list<array<string, mixed>> the interrupts the last run finished on */
    #[ORM\Column(type: Types::JSON)]
    private array $interrupts = [];

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 64)]
        private string $id,
        #[ORM\Column(length: 180)]
        private string $owner,
    ) {
        if (1 !== preg_match('/^'.self::ID_PATTERN.'$/', $id)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a dossier id.', $id));
        }
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOwner(): string
    {
        return $this->owner;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function rename(string $title): void
    {
        $this->title = mb_substr(trim($title), 0, 160);
        $this->touch();
    }

    public function getPhase(): string
    {
        return $this->phase;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return array<string, mixed> */
    public function getState(): array
    {
        return $this->state;
    }

    /**
     * The state after a run. The agent names the dossier with its plan; a name the user
     * gave is kept.
     *
     * @param array<string, mixed> $state
     */
    public function recordState(array $state): void
    {
        $this->state = $state;
        $this->phase = \is_string($state['phase'] ?? null) ? $state['phase'] : $this->phase;
        if ('' === $this->title && \is_string($state['title'] ?? null) && '' !== trim($state['title'])) {
            $this->title = mb_substr(trim($state['title']), 0, 160);
        }
        $this->touch();
    }

    /** @return list<array<string, mixed>> */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /** @param list<array<string, mixed>> $messages */
    public function recordMessages(array $messages): void
    {
        $this->messages = $messages;
        $this->touch();
    }

    /** @return list<array<string, mixed>> */
    public function getInterrupts(): array
    {
        return $this->interrupts;
    }

    /** @param list<array<string, mixed>> $interrupts */
    public function recordInterrupts(array $interrupts): void
    {
        $this->interrupts = $interrupts;
        $this->touch();
    }

    public function isEmpty(): bool
    {
        return [] === $this->messages;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
