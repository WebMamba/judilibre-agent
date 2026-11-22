<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Research\Phase;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Where the dossier stands (ADR 0004). The research controller sets the phase on every
 * state change.
 */
#[AsLiveComponent]
final class PhaseStepper
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $phase = 'qualification';

    /** @return list<array{label: string, state: 'done'|'current'|'todo'}> */
    public function steps(): array
    {
        $current = Phase::tryFrom($this->phase) ?? Phase::Qualification;
        $index = array_search($current, Phase::cases(), true);

        return array_map(static fn (Phase $phase, int $i): array => [
            'label' => $phase->label(),
            'state' => $i < $index ? 'done' : ($i === $index ? 'current' : 'todo'),
        ], Phase::cases(), array_keys(Phase::cases()));
    }
}
