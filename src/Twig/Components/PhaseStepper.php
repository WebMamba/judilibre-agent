<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Research\Phase;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\Contracts\Translation\TranslatorInterface;

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

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** @return list<array{label: string, state: 'done'|'current'|'todo'}> */
    public function steps(): array
    {
        $current = Phase::tryFrom($this->phase) ?? Phase::Qualification;
        $index = array_search($current, Phase::cases(), true);

        return array_map(fn (Phase $phase, int $i): array => [
            'label' => $this->translator->trans('phase.'.$phase->value),
            'state' => $i < $index ? 'done' : ($i === $index ? 'current' : 'todo'),
        ], Phase::cases(), array_keys(Phase::cases()));
    }
}
