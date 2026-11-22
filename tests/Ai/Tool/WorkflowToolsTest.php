<?php

declare(strict_types=1);

namespace App\Tests\Ai\Tool;

use AgUi\Bridge\SymfonyAi\Interrupt\Interrupt;
use AgUi\Run\ResumeEntry;
use App\Ai\Tool\AskQuestions;
use App\Ai\Tool\Input\AskedQuestion;
use App\Ai\Tool\Input\AxisQuery;
use App\Ai\Tool\Input\ProposedAxis;
use App\Ai\Tool\Input\ProposedPrinciple;
use App\Ai\Tool\PresentFindings;
use App\Ai\Tool\ProposeResearchPlan;
use App\Ai\Tool\SearchAxis;
use App\Ai\Tool\WriteSection;
use App\Research\Axis;
use App\Research\CaseBrief;
use App\Research\FoundDecision;
use App\Research\Phase;
use App\Research\ResearchState;
use App\Research\RetainedDecision;
use App\Research\SearchPlan;
use App\Research\Stance;
use App\Tests\Support\FakeJudilibre;
use App\Tests\Support\FakeTriage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The tools of the consultation workflow, called as the agent calls them, with the
 * services of the test kernel (Judilibre and the triage faked).
 */
final class WorkflowToolsTest extends KernelTestCase
{
    private const string PUBLISHED = '65f1c0a2b4e3d2001a7c9e11';
    private const string UNPUBLISHED = '60794a6e9ba5988459c4a2f0';

    private ResearchState $state;

    protected function setUp(): void
    {
        FakeTriage::reset();
        self::bootKernel();
        $this->state = self::getContainer()->get(ResearchState::class);
        $this->state->reset();
    }

    public function testQuestionsAreAskedInAFormAndTheAnswersBecomeTheResult(): void
    {
        $tool = self::getContainer()->get(AskQuestions::class);

        $interrupt = $tool([new AskedQuestion('partie', 'Qui défendez-vous ?', 'single', ['Le salarié', "L'employeur"]), new AskedQuestion('', 'Depuis quand ?')], 'Pour cerner le dossier');

        self::assertInstanceOf(Interrupt::class, $interrupt);
        self::assertStringContainsString('Qui défendez-vous ?', $interrupt->activity?->content['html'] ?? '');
        self::assertSame(1, $this->state->qualificationRounds);

        $result = $tool->resume(
            ['questions' => [['id' => 'partie', 'label' => 'Qui défendez-vous ?'], ['label' => 'Depuis quand ?']]],
            ResumeEntry::fromArray(['interruptId' => 'i', 'status' => 'resolved', 'payload' => ['answers' => ['partie' => 'Le salarié', 'q2' => ''], 'comment' => 'Arrêt de 8 mois']]),
        );

        self::assertStringContainsString('- Qui défendez-vous ? → Le salarié', $result);
        self::assertStringContainsString('- Depuis quand ? → (sans réponse)', $result);
        self::assertStringContainsString('Précision libre → Arrêt de 8 mois', $result);
    }

    public function testTheQualificationStopsAfterThreeRounds(): void
    {
        $this->state->qualificationRounds = 3;

        $result = self::getContainer()->get(AskQuestions::class)([new AskedQuestion('q', 'Encore ?')]);

        self::assertIsString($result);
        self::assertStringContainsString('propose_research_plan', $result);
    }

    public function testTheUserValidatesTheEditedBriefAndAxes(): void
    {
        $tool = self::getContainer()->get(ProposeResearchPlan::class);
        $arguments = ['title' => 'Licenciement et maladie', 'brief' => ['facts' => 'F', 'issue' => 'Q', 'side' => 'Salarié'], 'axes' => [['title' => 'Axe proposé']]];

        $interrupt = $tool('Licenciement et maladie', new CaseBrief('F', 'Q', 'Salarié'), [new ProposedAxis('Axe proposé', 'But')]);
        self::assertInstanceOf(Interrupt::class, $interrupt);
        self::assertSame(Phase::Plan, $this->state->phase);
        self::assertStringContainsString('Axe proposé', $interrupt->activity?->content['html'] ?? '');

        $result = $tool->resume($arguments, ResumeEntry::fromArray(['interruptId' => 'i', 'status' => 'resolved', 'payload' => [
            'approved' => true,
            'plan' => [
                'brief' => ['facts' => 'Salarié absent huit mois', 'issue' => 'Licenciement justifié ?', 'side' => 'Salarié demandeur', 'goal' => 'Indemnités'],
                'axes' => [['title' => 'Désorganisation', 'objective' => 'La règle'], ['title' => 'Remplacement définitif', 'objective' => '']],
            ],
        ]]));

        self::assertStringContainsString('Plan validé', $result);
        self::assertSame(Phase::Recherche, $this->state->phase);
        self::assertSame('Licenciement et maladie', $this->state->title);
        self::assertSame('Salarié demandeur', $this->state->brief?->side);
        self::assertSame(['a1', 'a2'], array_map(static fn (Axis $a): string => $a->id, $this->state->plan->axes ?? []));
        self::assertSame('Remplacement définitif', $this->state->plan?->axis('a2')?->title, 'The user renamed and added axes.');
    }

    public function testAnIncompleteBriefIsNotValidated(): void
    {
        $tool = self::getContainer()->get(ProposeResearchPlan::class);
        $this->state->phase = Phase::Plan;

        $result = $tool->resume(['axes' => [['title' => 'A']]], ResumeEntry::fromArray(['interruptId' => 'i', 'status' => 'resolved', 'payload' => [
            'approved' => true, 'plan' => ['brief' => ['facts' => 'F', 'issue' => '', 'side' => ''], 'axes' => [['title' => 'A']]],
        ]]));

        self::assertStringContainsString('incomplète', $result);
        self::assertNull($this->state->plan);
    }

    public function testNothingIsSearchedBeforeThePlanIsValidated(): void
    {
        $result = self::getContainer()->get(SearchAxis::class)('a1', [new AxisQuery('licenciement')]);

        self::assertIsString($result);
        self::assertStringContainsString('impossible en phase « Qualification »', $result);
        self::assertSame([], $this->judilibre()->requests);
    }

    public function testAnAxisIsSearchedThenTriagedAndOnlyTheKeptDecisionsReachTheAgent(): void
    {
        $this->inResearch();
        FakeTriage::$verdicts = [self::UNPUBLISHED => null];

        $result = self::getContainer()->get(SearchAxis::class)('a1', [new AxisQuery('licenciement arrêt maladie', 'and', ['motivations']), new AxisQuery('remplacement définitif')]);

        self::assertIsArray($result);
        self::assertSame(2, $result['candidates'], 'The two queries found the same decisions: they are merged.');
        self::assertSame(1, $result['rejected_by_triage']);
        self::assertSame([self::PUBLISHED], array_column($result['retained'], 'id'));
        self::assertSame('favorable', $result['retained'][0]['stance']);

        $query = $this->judilibre()->query();
        self::assertSame('50', $query['page_size']);
        self::assertSame(['soc'], $query['chamber'], 'The filters of the state apply.');
        self::assertStringContainsString('decision?id='.self::UNPUBLISHED, implode("\n", $this->judilibre()->requests), 'A decision without summary is read in full.');

        self::assertStringContainsString('Partie défendue : Salarié', FakeTriage::$calls[0]['context']);
        self::assertSame([self::PUBLISHED, self::UNPUBLISHED], $this->state->seen);
        self::assertSame(self::PUBLISHED, $this->state->retained[0]->id);
        self::assertTrue($this->state->plan?->axis('a1')?->searched);
    }

    public function testDecisionsAlreadyTriagedOrExcludedDoNotComeBack(): void
    {
        $this->inResearch();
        $this->state->seen = [self::PUBLISHED];
        $this->state->excluded = [self::UNPUBLISHED];

        $result = self::getContainer()->get(SearchAxis::class)('a1', [new AxisQuery('licenciement')]);

        self::assertSame(0, $result['candidates']);
        self::assertSame([], FakeTriage::$calls);
    }

    public function testPrinciplesOnlyCiteDecisionsTheTriageKept(): void
    {
        $this->inResearch(searched: true);
        $this->state->retained = [new RetainedDecision(self::PUBLISHED, 'a1', 'Cour de cassation, Chambre sociale, 13 mars 2024, n° 22-18.457', Stance::Favorable, 0.9, 'Sommaire', '2024-03-13', 'cassation', ['b'])];
        $tool = self::getContainer()->get(PresentFindings::class);

        $interrupt = $tool([new ProposedPrinciple('a1', 'Le remplacement doit être définitif.', 'favorable', [new FoundDecision(self::PUBLISHED, 'Principe'), new FoundDecision('invented123', 'Inventée')])], 'Synthèse');

        self::assertInstanceOf(Interrupt::class, $interrupt);
        self::assertSame(Phase::Selection, $this->state->phase);
        self::assertCount(1, $this->state->findings[0]->decisions, 'An id the triage did not keep is dropped.');
        $html = $interrupt->activity?->content['html'] ?? '';
        self::assertStringContainsString('Cour de cassation, Chambre sociale, 13 mars 2024, n° 22-18.457', $html);
        self::assertStringContainsString('data-pin-principle="p1"', $html);
        self::assertStringContainsString('data-ag-ui-action-param="write"', $html);

        $result = $tool->resume([], ResumeEntry::fromArray(['interruptId' => 'i', 'status' => 'resolved', 'payload' => ['action' => 'write', 'instruction' => 'Ton prudent']]));
        self::assertSame(Phase::Cadrage, $this->state->phase);
        self::assertStringContainsString('Ton prudent', $result);
    }

    public function testFindingsWaitForEveryAxisOfTheRound(): void
    {
        $this->inResearch();

        $result = self::getContainer()->get(PresentFindings::class)([new ProposedPrinciple('a1', 'P', 'favorable', [new FoundDecision(self::PUBLISHED)])]);

        self::assertIsString($result);
        self::assertStringContainsString('Axes pas encore cherchés : a1', $result);
    }

    public function testContinuingTheResearchProposesComplementaryAxes(): void
    {
        $this->inResearch(searched: true);
        $this->state->phase = Phase::Selection;
        $tool = self::getContainer()->get(ProposeResearchPlan::class);

        $interrupt = $tool('', new CaseBrief('x', 'y', 'z'), [new ProposedAxis('Obligation de reclassement')]);
        self::assertInstanceOf(Interrupt::class, $interrupt);
        self::assertSame(Phase::Selection, $this->state->phase);

        $tool->resume(['axes' => [['title' => 'Obligation de reclassement']]], ResumeEntry::fromArray(['interruptId' => 'i', 'status' => 'resolved', 'payload' => ['approved' => true, 'plan' => ['brief' => [], 'axes' => [['title' => 'Obligation de reclassement']]]]]));

        self::assertSame(Phase::Recherche, $this->state->phase);
        self::assertSame('Salarié', $this->state->brief?->side, 'The brief of the first round is kept.');
        self::assertSame(2, $this->state->plan?->axis('a2')?->round);
        self::assertSame(['a2'], array_map(static fn (Axis $a): string => $a->id, $this->state->plan->pending()));
    }

    public function testTheConsultationOnlyCitesDecisionsOfTheDossierAndIsDeliveredOnceComplete(): void
    {
        $this->state->phase = Phase::Cadrage;
        $this->state->retained = [new RetainedDecision(self::PUBLISHED, 'a1', 'Cass. soc., 13 mars 2024')];
        $this->state->pin(self::UNPUBLISHED, 'Cass. soc., 2021');
        $tool = self::getContainer()->get(WriteSection::class);

        self::assertStringContainsString('Section refusée', $tool('discussion', 'Voir [[abcdef123456]].'));
        self::assertNull($this->state->consultation);

        self::assertStringContainsString('Reste à rédiger', $tool('faits', "## Faits\nLe salarié…"));
        self::assertSame(Phase::Redaction, $this->state->phase);
        self::assertSame('Le salarié…', $this->state->consultation?->section('faits')?->markdown, 'The title the model repeats is dropped.');

        foreach (['problematique', 'reponse', 'risques', 'plaidoirie'] as $section) {
            $tool($section, 'Texte citant [['.self::PUBLISHED.']].');
        }
        $result = $tool('discussion', 'Seulement [['.self::PUBLISHED.']].');
        self::assertStringContainsString('ne sont pas discutées : '.self::UNPUBLISHED, $result, 'A pinned decision must be discussed.');
        self::assertSame(Phase::Redaction, $this->state->phase);

        $result = $tool('discussion', 'D\'une part [['.self::PUBLISHED.']], d\'autre part [['.self::UNPUBLISHED.']].');
        self::assertStringContainsString('livrée', $result);
        self::assertSame(Phase::Livre, $this->state->phase);
        self::assertNotNull($this->state->consultation->section('discussion')?->previous);
    }

    private function inResearch(bool $searched = false): void
    {
        $this->state->phase = Phase::Recherche;
        $this->state->filters->chambers = ['soc'];
        $this->state->brief = new CaseBrief('Salarié absent huit mois', 'Licenciement justifié ?', 'Salarié');
        $this->state->plan = new SearchPlan([new Axis('a1', 'Désorganisation', 'La règle', 1, $searched)]);
    }

    private function judilibre(): FakeJudilibre
    {
        return self::getContainer()->get(FakeJudilibre::class);
    }
}
