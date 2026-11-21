<?php

declare(strict_types=1);

namespace App\Tests\Research;

use AgUi\Bridge\SymfonyAi\Exception\InvalidStateException;
use AgUi\Bridge\SymfonyAi\State\SharedState;
use App\Research\Axis;
use App\Research\CaseBrief;
use App\Research\Consultation;
use App\Research\FoundDecision;
use App\Research\Phase;
use App\Research\PinnedDecision;
use App\Research\Principle;
use App\Research\ResearchState;
use App\Research\RetainedDecision;
use App\Research\SearchFilters;
use App\Research\SearchPlan;
use App\Research\Stance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class ResearchStateTest extends TestCase
{
    public function testADossierStartsInQualificationOnTheCourDeCassation(): void
    {
        $state = new ResearchState();

        self::assertSame(Phase::Qualification, $state->phase);
        self::assertSame(['cc'], $state->filters->jurisdictions);
        self::assertNull($state->plan);
        self::assertNull($state->consultation);
    }

    public function testFiltersBecomeJudilibreSearchParameters(): void
    {
        $filters = new SearchFilters(jurisdictions: ['cc', 'ca'], chambers: ['soc'], publications: ['b'], dateStart: '2015-01-01');

        self::assertSame([
            'jurisdiction' => ['cc', 'ca'],
            'chamber' => ['soc'],
            'location' => [],
            'publication' => ['b'],
            'date_start' => '2015-01-01',
            'date_end' => null,
        ], $filters->searchParameters());
    }

    public function testADecisionIsPinnedOnce(): void
    {
        $state = new ResearchState();

        self::assertTrue($state->pin('abc', 'Cass. soc., 13 mars 2024, n° 22-18.457', 'Remplacement définitif'));
        self::assertFalse($state->pin('abc', 'Cass. soc., 13 mars 2024, n° 22-18.457'));
        self::assertCount(1, $state->dossier);
    }

    public function testOnlyTheDecisionsRetainedOrPinnedAreCitable(): void
    {
        $state = new ResearchState();
        $state->retained = [new RetainedDecision('kept', 'a1', 'Cass. soc., 1er mars 2024')];
        $state->pin('pinned', 'Cass. civ. 1, 2 mai 2020');

        self::assertSame(['kept', 'pinned'], $state->citable());
        self::assertSame(['kept' => 'Cass. soc., 1er mars 2024', 'pinned' => 'Cass. civ. 1, 2 mai 2020'], $state->citations());
    }

    public function testTheModelReadsTheWholeDossierInPlainFrench(): void
    {
        $state = new ResearchState();
        $state->phase = Phase::Selection;
        $state->brief = new CaseBrief('Salarié licencié après huit mois d\'arrêt maladie.', 'Le licenciement est-il justifié ?', 'Salarié demandeur');
        $state->plan = new SearchPlan([new Axis('a1', 'Désorganisation et remplacement définitif', 'La règle de principe', 1, true)]);
        $state->retained = [new RetainedDecision('abc', 'a1', 'Cass. soc., 13 mars 2024', Stance::Favorable)];
        $state->seen = ['abc', 'def'];
        $state->findings = [new Principle('p1', 'a1', 1, 'Le remplacement doit être définitif.', Stance::Favorable, [new FoundDecision('abc', 'Principe')])];
        $state->pinnedPrinciples = ['p1'];
        $state->pin('abc', 'Cass. soc., 13 mars 2024', 'Remplacement définitif');
        $state->excluded = ['def'];

        $description = $state->describeForAgent();

        self::assertStringContainsString('Phase du dossier : selection (Sélection)', $description);
        self::assertStringContainsString('Partie défendue : Salarié demandeur', $description);
        self::assertStringContainsString('- a1 [tour 1, cherché] Désorganisation et remplacement définitif — La règle de principe', $description);
        self::assertStringContainsString('1 décisions retenues par le tri (citables), 2 décisions déjà triées', $description);
        self::assertStringContainsString('p1 (a1, favorable) ÉPINGLÉ : Le remplacement doit être définitif.', $description);
        self::assertStringContainsString('abc — Cass. soc., 13 mars 2024 — Remplacement définitif', $description);
        self::assertStringContainsString('écartées par l\'utilisateur (ne plus les proposer ni les citer) : def', $description);
    }

    public function testAConsultationKeepsItsSectionsInOrderAndCanUndoARewrite(): void
    {
        $consultation = new Consultation();
        $consultation->write('discussion', 'Voir [[abc12345]].');
        $consultation->write('faits', 'Les faits.');
        $consultation->write('discussion', 'Voir [[abc12345]] et [[def67890]].');

        self::assertSame(['faits', 'discussion'], array_map(static fn ($s): string => $s->key, $consultation->sections));
        self::assertSame(['abc12345', 'def67890'], $consultation->citedIds());
        self::assertFalse($consultation->complete());

        self::assertTrue($consultation->undo('discussion'));
        self::assertSame('Voir [[abc12345]].', $consultation->section('discussion')?->markdown);
        self::assertFalse($consultation->undo('discussion'), 'Only the last rewrite can be undone.');
    }

    public function testResetForgetsEverything(): void
    {
        $state = new ResearchState();
        $state->phase = Phase::Livre;
        $state->filters = new SearchFilters(jurisdictions: ['ca']);
        $state->plan = new SearchPlan([new Axis('a1', 'Bail')]);
        $state->consultation = new Consultation();
        $state->pin('abc', 'A');

        $state->reset();

        self::assertEquals(new ResearchState(), $state);
    }

    public function testTheStateRoundTripsThroughTheAgUiSharedState(): void
    {
        $state = new ResearchState();
        $state->phase = Phase::Livre;
        $state->title = 'Licenciement et arrêt maladie';
        $state->brief = new CaseBrief('Faits', 'Question', 'Salarié');
        $state->plan = new SearchPlan([new Axis('a1', 'Axe', 'Objectif', 1, true)]);
        $state->retained = [new RetainedDecision('abc', 'a1', 'Cass. soc.', Stance::Defavorable, 0.75, 'Sommaire', '2024-03-13', 'cassation', ['b'])];
        $state->findings = [new Principle('p1', 'a1', 1, 'Principe', Stance::Defavorable, [new FoundDecision('abc', 'Pertinence')])];
        $state->consultation = new Consultation();
        $state->consultation->write('faits', 'Les faits.');
        $state->pin('abc', 'Cass. soc.', 'n');

        $data = $this->sharedState($state)->snapshot();
        $copy = new ResearchState();
        $shared = $this->sharedState($copy);
        $shared->assertValid($data);
        $shared->load($data);

        self::assertEquals($state, $copy);
        self::assertSame('livre', $data['phase']);
        self::assertSame('defavorable', $data['retained'][0]['stance']);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('invalidStates')]
    public function testAStateThatBreaksTheConstraintsIsRejected(array $data): void
    {
        $this->expectException(InvalidStateException::class);

        $this->sharedState(new ResearchState())->assertValid($data);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidStates(): iterable
    {
        yield 'unknown jurisdiction' => [['filters' => ['jurisdictions' => ['cour-supreme']]]];
        yield 'no jurisdiction' => [['filters' => ['jurisdictions' => []]]];
        yield 'unknown publication' => [['filters' => ['publications' => ['z']]]];
        yield 'not a date' => [['filters' => ['dateStart' => '13/03/2024']]];
        yield 'unknown phase' => [['phase' => 'plaidoirie']];
        yield 'pinned without id' => [['dossier' => [['id' => '', 'title' => 'A']]]];
    }

    private function sharedState(ResearchState $state): SharedState
    {
        return new SharedState($state, validator: Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
    }
}
