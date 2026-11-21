<?php

declare(strict_types=1);

namespace App\Research;

use AgUi\Bridge\SymfonyAi\State\DescribesItself;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The AG-UI shared state of a dossier (ADR 0002): where the workflow stands, the case
 * brief, the plan, what the triage kept, the principles shown, the user's pins and
 * exclusions, and the consultation.
 *
 * Tools inject it like any service. The stream controller fills it from the dossier in
 * the database, with only the user's part (filters, pins, exclusions) taken from the
 * browser; the AG-UI SDK turns every change into a STATE_DELTA, and resets it after the
 * run. No get/is/has/can methods: the serializer would put them in the state.
 */
final class ResearchState implements DescribesItself, ResetInterface
{
    /** What the browser may change: the rest is the server's. */
    public const array USER_KEYS = ['filters', 'dossier', 'pinnedPrinciples', 'excluded'];

    /** Rounds of qualification questions before the agent must propose its plan. */
    public const int MAX_QUALIFICATION_ROUNDS = 3;

    public Phase $phase = Phase::Qualification;

    /** The dossier's name, given by the agent with its plan. */
    public string $title = '';

    #[Assert\Valid]
    public SearchFilters $filters;

    #[Assert\Valid]
    public ?CaseBrief $brief = null;

    #[Assert\Valid]
    public ?SearchPlan $plan = null;

    /** @var list<RetainedDecision> */
    public array $retained = [];

    /** @var list<string> ids of every decision triaged so far, kept or not */
    public array $seen = [];

    /** @var list<Principle> */
    public array $findings = [];

    /** @var list<PinnedDecision> */
    #[Assert\Valid]
    public array $dossier = [];

    /** @var list<string> ids of the principles the user pinned */
    #[Assert\All([new Assert\Type('string')])]
    public array $pinnedPrinciples = [];

    /** @var list<string> ids of the decisions the user excluded */
    #[Assert\All([new Assert\Type('string')])]
    public array $excluded = [];

    public int $qualificationRounds = 0;

    public ?Consultation $consultation = null;

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

    public function retainedDecision(string $id): ?RetainedDecision
    {
        foreach ($this->retained as $decision) {
            if ($decision->id === $id) {
                return $decision;
            }
        }

        return null;
    }

    public function principle(string $id): ?Principle
    {
        foreach ($this->findings as $principle) {
            if ($principle->id === $id) {
                return $principle;
            }
        }

        return null;
    }

    /** @return list<string> the ids the consultation may cite: the decisions retained, and those the user pinned */
    public function citable(): array
    {
        return array_values(array_unique([
            ...array_map(static fn (RetainedDecision $d): string => $d->id, $this->retained),
            ...array_map(static fn (PinnedDecision $d): string => $d->id, $this->dossier),
        ]));
    }

    /** @return array<string, string> id => citation, of every decision the dossier knows */
    public function citations(): array
    {
        $citations = [];
        foreach ($this->retained as $decision) {
            $citations[$decision->id] = $decision->citation;
        }
        foreach ($this->dossier as $pinned) {
            $citations[$pinned->id] = $pinned->title;
        }

        return $citations;
    }

    public function describeForAgent(): string
    {
        $parts = [\sprintf('Phase du dossier : %s (%s).', $this->phase->value, $this->phase->label())];

        if (Phase::Qualification === $this->phase) {
            $parts[] = \sprintf('Tours de questions de qualification déjà posés : %d sur %d.', $this->qualificationRounds, self::MAX_QUALIFICATION_ROUNDS);
        }

        $parts[] = "Filtres en vigueur (l'utilisateur peut les avoir modifiés ; toute recherche les applique) :\n".$this->filters->describe();

        $parts[] = null === $this->brief
            ? 'Pas encore de fiche dossier.'
            : "Fiche dossier validée par l'utilisateur :\n".$this->brief->describe();

        if (null !== $this->plan) {
            $parts[] = "Plan de recherche validé (axes) :\n".implode("\n", array_map(
                static fn (Axis $axis): string => \sprintf('- %s [tour %d%s] %s%s', $axis->id, $axis->round, $axis->searched ? ', cherché' : ', à chercher', $axis->title, '' === $axis->objective ? '' : ' — '.$axis->objective),
                $this->plan->axes,
            ));
        }

        if ([] !== $this->retained) {
            $parts[] = \sprintf('%d décisions retenues par le tri (citables), %d décisions déjà triées en tout.', \count($this->retained), \count($this->seen));
        }

        if ([] !== $this->findings) {
            $parts[] = "Principes déjà présentés :\n".implode("\n", array_map(
                fn (Principle $p): string => \sprintf('- %s (%s, %s)%s : %s', $p->id, $p->axisId, $p->stance->value, \in_array($p->id, $this->pinnedPrinciples, true) ? ' ÉPINGLÉ' : '', $p->statement),
                $this->findings,
            ));
        }

        $parts[] = [] === $this->dossier
            ? "L'utilisateur n'a épinglé aucune décision."
            : "Décisions épinglées par l'utilisateur (à discuter obligatoirement dans la consultation) :\n".implode("\n", array_map(
                static fn (PinnedDecision $d): string => \sprintf('- %s — %s%s', $d->id, $d->title, '' === $d->note ? '' : ' — '.$d->note),
                $this->dossier,
            ));

        if ([] !== $this->excluded) {
            $parts[] = "Décisions écartées par l'utilisateur (ne plus les proposer ni les citer) : ".implode(', ', $this->excluded);
        }

        if (null !== $this->consultation) {
            $written = array_map(static fn (ConsultationSection $s): string => $s->key, $this->consultation->sections);
            $parts[] = \sprintf(
                'Consultation : sections rédigées : %s.%s',
                [] === $written ? 'aucune' : implode(', ', $written),
                $this->consultation->stale ? " La recherche a repris depuis : mettez à jour les sections touchées par les nouveaux éléments." : '',
            );
        }

        return implode("\n\n", $parts);
    }

    public function reset(): void
    {
        $this->phase = Phase::Qualification;
        $this->title = '';
        $this->filters = new SearchFilters();
        $this->brief = null;
        $this->plan = null;
        $this->retained = [];
        $this->seen = [];
        $this->findings = [];
        $this->dossier = [];
        $this->pinnedPrinciples = [];
        $this->excluded = [];
        $this->qualificationRounds = 0;
        $this->consultation = null;
    }
}
