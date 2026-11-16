<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use AgUi\Bridge\SymfonyAi\Activity\Activity;
use App\Tests\Support\FakeJudilibre;
use App\Tests\Support\ResearcherToolbox;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Through the toolbox, with JSON arguments as the model sends them: it proves the
 * generated schema and the component agree.
 */
final class DecisionListTest extends KernelTestCase
{
    use ResearcherToolbox;

    private const string ID = '65f1c0a2b4e3d2001a7c9e11';

    public function testACardShowsWhatJudilibreSaysNotWhatTheModelSays(): void
    {
        $activity = $this->renderDecisionList([['id' => self::ID, 'relevance' => 'Exige le remplacement définitif du salarié.']]);
        $html = $activity->content['html'];

        self::assertStringNotContainsString('<', (string) $activity, 'The model gets a sentence, not the markup.');
        self::assertStringContainsString('Décisions retenues', $html);
        self::assertStringContainsString('Cour de cassation, Chambre sociale, 13 mars 2024, n° 22-18.457', $html);
        self::assertStringContainsString('Exige le remplacement définitif du salarié.', $html);
        self::assertStringContainsString('Cassation', $html);
        self::assertStringContainsString('Bulletin', $html);
        self::assertStringContainsString('href="https://www.courdecassation.fr/decision/'.self::ID.'"', $html);
        self::assertStringContainsString('data-action="research#pin"', $html);
        self::assertStringContainsString('data-research-id-param="'.self::ID.'"', $html);
    }

    public function testADecisionJudilibreDoesNotKnowIsShownAsMissing(): void
    {
        self::getContainer()->get(FakeJudilibre::class)->respond('decision', FakeJudilibre::json('{"message":"Décision introuvable."}', 404));

        $html = $this->renderDecisionList([['id' => 'inconnue', 'relevance' => 'Inventée ?']])->content['html'];

        self::assertStringContainsString('introuvable', $html);
        self::assertStringNotContainsString('research#pin', $html);
    }

    /**
     * @param list<array{id: string, relevance: string}> $decisions
     */
    private function renderDecisionList(array $decisions): Activity
    {
        $result = self::researcherToolbox()->execute(new ToolCall('call_1', 'render_decision_list', [
            'title' => 'Décisions retenues',
            'decisions' => $decisions,
        ]))->getResult();

        self::assertInstanceOf(Activity::class, $result);

        return $result;
    }
}
