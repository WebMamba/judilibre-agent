<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\FakeJudilibre;
use App\Tests\Support\ScriptedPlatform;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End to end: the controller, the AG-UI bundle and bridge, the real agent and its tools,
 * with only the model scripted and Judilibre faked.
 */
final class AgentStreamTest extends WebTestCase
{
    private const string ID = '65f1c0a2b4e3d2001a7c9e11';
    private const array PLAN = ['searches' => [['query' => 'licenciement arrêt maladie', 'operator' => 'and', 'fields' => ['motivations'], 'purpose' => 'La règle de principe']]];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        ScriptedPlatform::reset();
        $this->client = self::createClient();
    }

    public function testTheUserApprovesThePlanThenGetsDecisionCards(): void
    {
        ScriptedPlatform::$rounds = [[new ToolCallComplete([new ToolCall('c1', 'propose_search_plan', self::PLAN)])]];

        $events = $this->stream();

        $outcome = self::first($events, 'RUN_FINISHED')['outcome'];
        self::assertSame('interrupt', $outcome['type']);
        $plan = self::first($events, 'ACTIVITY_SNAPSHOT');
        self::assertStringContainsString('licenciement arrêt maladie', $plan['content']['html']);
        self::assertSame([], $this->judilibre()->requests, 'Nothing is searched before the user approves.');

        // The user approves; the agent searches, then shows a card.
        ScriptedPlatform::$rounds = [
            [new ToolCallComplete([new ToolCall('c2', 'search_decisions', ['query' => 'licenciement arrêt maladie', 'operator' => 'and'])])],
            [new ToolCallComplete([new ToolCall('c3', 'render_decision_list', ['title' => 'Décisions retenues', 'decisions' => [['id' => self::ID, 'relevance' => 'La règle de principe.']]])])],
            [new TextDelta('La Cour de cassation exige un remplacement définitif.')],
        ];
        $events = $this->stream(
            messages: self::first($events, 'MESSAGES_SNAPSHOT')['messages'],
            resume: [['interruptId' => $outcome['interrupts'][0]['id'], 'status' => 'resolved', 'payload' => ['approved' => true]]],
        );

        self::assertSame(['type' => 'success'], self::last($events, 'RUN_FINISHED')['outcome']);
        self::assertStringContainsString('Plan approuvé', self::first($events, 'TOOL_CALL_RESULT')['content']);
        self::assertContains('/plan', array_column(self::first($events, 'STATE_DELTA')['delta'], 'path'), 'The approved plan joins the shared state.');
        self::assertStringContainsString('22-18.457', self::nth($events, 'TOOL_CALL_RESULT', 1)['content']);
        self::assertStringContainsString('Cour de cassation, Chambre sociale, 13 mars 2024, n° 22-18.457', self::last($events, 'ACTIVITY_SNAPSHOT')['content']['html']);
    }

    public function testTheSearchAlwaysAppliesTheFiltersOfTheState(): void
    {
        ScriptedPlatform::$rounds = [
            [new ToolCallComplete([new ToolCall('c1', 'search_decisions', ['query' => 'bail commercial'])])],
            [new TextDelta('Voici.')],
        ];

        $this->stream(state: [
            'filters' => ['jurisdictions' => ['cc'], 'chambers' => ['civ3'], 'locations' => [], 'publications' => ['b'], 'dateStart' => '2018-01-01', 'dateEnd' => null],
            'plan' => self::PLAN,
            'dossier' => [],
        ]);

        $query = $this->judilibre()->query();
        self::assertSame('bail commercial', $query['query']);
        self::assertSame(['civ3'], $query['chamber']);
        self::assertSame(['b'], $query['publication']);
        self::assertSame('2018-01-01', $query['date_start']);
    }

    public function testTheModelReadsTheFiltersAndTheDossier(): void
    {
        ScriptedPlatform::$rounds = [[new TextDelta('Bonjour.')]];

        $this->stream(state: [
            'filters' => ['jurisdictions' => ['cc', 'ca'], 'chambers' => [], 'locations' => [], 'publications' => [], 'dateStart' => null, 'dateEnd' => null],
            'plan' => null,
            'dossier' => [['id' => self::ID, 'title' => 'Cass. soc., 13 mars 2024', 'note' => 'Principe']],
        ]);

        $sent = json_encode(ScriptedPlatform::$calls[0]['input'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('Juridictions : cc, ca', $sent);
        self::assertStringContainsString('Aucun plan de recherche approuvé', $sent);
        self::assertStringContainsString(self::ID.' — Cass. soc., 13 mars 2024 — Principe', $sent);
    }

    public function testTheModelOptionsReachTheModel(): void
    {
        ScriptedPlatform::$rounds = [[new TextDelta('Bonjour.')]];

        $this->stream();

        $options = ScriptedPlatform::$calls[0]['options'];
        self::assertSame(16000, $options['max_tokens'] ?? null);
        self::assertSame(['effort' => 'high'], $options['output_config'] ?? null);
    }

    public function testAStateThatIsNotOneIsRejected(): void
    {
        $this->client->request('POST', '/agent/stream', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'threadId' => 't', 'runId' => 'r', 'messages' => [],
            'state' => ['filters' => ['jurisdictions' => ['cour-supreme']]],
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    private function judilibre(): FakeJudilibre
    {
        return self::getContainer()->get(FakeJudilibre::class);
    }

    /**
     * @param list<array<string, mixed>>|null $messages
     * @param list<array<string, mixed>>      $resume
     * @param array<string, mixed>|null       $state
     *
     * @return list<array<string, mixed>>
     */
    private function stream(?array $messages = null, array $resume = [], ?array $state = null): array
    {
        $input = array_filter([
            'threadId' => 'thread_1',
            'runId' => 'run_'.\count(ScriptedPlatform::$calls),
            'messages' => $messages ?? [['id' => 'u1', 'role' => 'user', 'content' => 'Peut-on licencier un salarié en arrêt maladie ?']],
            'resume' => $resume,
            'state' => $state,
        ], static fn (mixed $v): bool => null !== $v && [] !== $v);

        ob_start();
        $this->client->request('POST', '/agent/stream', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($input, \JSON_THROW_ON_ERROR));
        $body = ob_get_clean().$this->client->getInternalResponse()->getContent();

        self::assertResponseIsSuccessful();

        $events = [];
        foreach (explode("\n\n", trim($body)) as $frame) {
            if (str_starts_with($frame, 'data: ')) {
                $events[] = json_decode(substr($frame, 6), true, 512, \JSON_THROW_ON_ERROR);
            }
        }

        $last = end($events);
        self::assertNotSame('RUN_ERROR', $last['type'] ?? null, $last['message'] ?? '');

        return $events;
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return array<string, mixed>
     */
    private static function first(array $events, string $type): array
    {
        return self::nth($events, $type, 0);
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return array<string, mixed>
     */
    private static function last(array $events, string $type): array
    {
        $matching = array_values(array_filter($events, static fn (array $e): bool => $type === $e['type']));
        if ([] === $matching) {
            self::fail(\sprintf('No %s event in: %s', $type, implode(', ', array_column($events, 'type'))));
        }

        return end($matching);
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return array<string, mixed>
     */
    private static function nth(array $events, string $type, int $n): array
    {
        $matching = array_values(array_filter($events, static fn (array $e): bool => $type === $e['type']));

        return $matching[$n] ?? self::fail(\sprintf('No %s event #%d in: %s', $type, $n, implode(', ', array_column($events, 'type'))));
    }
}
