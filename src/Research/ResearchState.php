<?php

declare(strict_types=1);

namespace App\Research;

use AgUi\Bridge\SymfonyAi\State\DescribesItself;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The AG-UI shared state (ADR 0002): the filters, the approved plan and the dossier.
 *
 * Tools inject it like any service. The AG-UI SDK fills it from the state the browser
 * sends, turns every change into a STATE_DELTA, and resets it after the run.
 * No get/is/has/can methods: the serializer would put them in the state.
 */
final class ResearchState implements DescribesItself, ResetInterface
{
    #[Assert\Valid]
    public SearchFilters $filters;

    #[Assert\Valid]
    public ?SearchPlan $plan = null;

    /** @var list<PinnedDecision> */
    #[Assert\Valid]
    public array $dossier = [];

    public function __construct()
    {
        $this->filters = new SearchFilters();
    }

    /** False when the decision is already in the dossier. */
    public function pin(string $id, string $title, string $note = ''): bool
    {
        foreach ($this->dossier as $pinned) {
            if ($pinned->id === $id) {
                return false;
            }
        }
        $this->dossier[] = new PinnedDecision($id, $title, $note);

        return true;
    }

    /** False when the decision was not in the dossier. */
    public function unpin(string $id): bool
    {
        foreach ($this->dossier as $i => $pinned) {
            if ($pinned->id === $id) {
                array_splice($this->dossier, $i, 1);

                return true;
            }
        }

        return false;
    }

    public function describeForAgent(): string
    {
        $parts = ["Filtres en vigueur (l'utilisateur peut les avoir modifiés ; toute recherche les applique) :\n".$this->filters->describe()];

        $parts[] = null === $this->plan
            ? 'Aucun plan de recherche approuvé : proposez-en un avec propose_search_plan avant de chercher.'
            : "Plan de recherche approuvé par l'utilisateur :\n".implode("\n", array_map(
                static fn (PlannedSearch $search): string => '- '.$search->describe(),
                $this->plan->searches,
            ));

        $parts[] = [] === $this->dossier
            ? 'Le dossier est vide.'
            : "Dossier de l'utilisateur (décisions épinglées) :\n".implode("\n", array_map(
                static fn (PinnedDecision $d): string => \sprintf('- %s — %s%s', $d->id, $d->title, '' === $d->note ? '' : ' — '.$d->note),
                $this->dossier,
            ));

        return implode("\n\n", $parts);
    }

    public function reset(): void
    {
        $this->filters = new SearchFilters();
        $this->plan = null;
        $this->dossier = [];
    }
}
