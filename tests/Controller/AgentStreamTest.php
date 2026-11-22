<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Dossier;
use App\Repository\DossierRepository;
use App\Security\EnvUserProvider;
use App\Tests\Support\FakeJudilibre;
use App\Tests\Support\FakeTriage;
use App\Tests\Support\ScriptedPlatform;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Lock\LockFactory;

/**
 * End to end: the controller, the AG-UI bundle and bridge, the real agent and its tools,
 * the dossier in the database, with only the model scripted and Judilibre and the
 * triage faked.
 */
final class AgentStreamTest extends WebTestCase
{
    private const string PUBLISHED = '65f1c0a2b4e3d2001a7c9e11';
    private const string UNPUBLISHED = '60794a6e9ba5988459c4a2f0';

    private KernelBrowser $client;
    private string $thread;

    protected function setUp(): void
    {
        ScriptedPlatform::reset();
        FakeTriage::reset();
        $this->client = self::createClient();
        $this->client->loginUser(self::getContainer()->get(EnvUserProvider::class)->loadUserByIdentifier('juriste@example.com'));
        $this->thread = 'dossier_'.bin2hex(random_bytes(6));
    }

    public function testAWholeDossierFromTheProblemToTheConsultation(): void
    {
        // 1. Qualification: the agent asks its questions in a form.
        ScriptedPlatform::$rounds = [[new ToolCallComplete([new ToolCall('c1', 'ask_questions', [
            'intro' => 'Pour cerner le dossier',
            'questions' => [['id' => 'partie', 'label' => 'Qui défendez-vous ?', 'type' => 'single', 'options' => ['Le salarié', "L'employeur"]]],
        ])])]];
        $events = $this->stream(messages: [['id' => 'u1', 'role' => 'user', 'content' => 'Mon client a été licencié pendant un arrêt maladie de huit mois.']]);

        self::assertStringContainsString('Qui défendez-vous ?', self::first($events, 'ACTIVITY_SNAPSHOT')['content']['html']);
        $dossier = $this->dossier();
        self::assertSame('juriste@example.com', $dossier->getOwner(), 'The dossier is born with its first message.');
        self::assertCount(1, $dossier->getInterrupts());

        // 2. The answers; the agent proposes the brief and the axes.
        ScriptedPlatform::$rounds = [[new ToolCallComplete([new ToolCall('c2', 'propose_research_plan', [
            'title' => 'Licenciement pendant un arrêt maladie',
            'brief' => ['facts' => 'Salarié absent huit mois, licencié pour désorganisation.', 'issue' => 'Le licenciement est-il justifié ?', 'side' => 'Salarié demandeur', 'goal' => 'Indemnités'],
            'axes' => [['title' => 'Désorganisation et remplacement définitif', 'objective' => 'La règle de principe']],
        ])])]];
        $events = $this->resume($events, ['answers' => ['partie' => 'Le salarié']]);

        self::assertStringContainsString('Le salarié', self::first($events, 'TOOL_CALL_RESULT')['content']);
        self::assertStringContainsString('Désorganisation et remplacement définitif', self::last($events, 'ACTIVITY_SNAPSHOT')['content']['html']);
        self::assertSame('plan', $this->dossier()->getPhase());

        // 3. The user validates; the agent searches the axis, Jev sorts, the principles are shown.
        FakeTriage::$verdicts = [self::UNPUBLISHED => null];
        ScriptedPlatform::$rounds = [
            [new ToolCallComplete([new ToolCall('c3', 'search_axis', ['axisId' => 'a1', 'queries' => [['query' => 'licenciement absence prolongée remplacement définitif', 'operator' => 'and']]])])],
            [new ToolCallComplete([new ToolCall('c4', 'present_findings', [
                'summary' => 'Une jurisprudence constante et favorable.',
                'principles' => [['axisId' => 'a1', 'statement' => 'Le remplacement du salarié absent doit être définitif.', 'stance' => 'favorable', 'decisions' => [['id' => self::PUBLISHED, 'relevance' => 'Arrêt de principe']]]],
            ])])],
        ];
        $events = $this->resume($events, ['approved' => true, 'plan' => [
            'brief' => ['facts' => 'Salarié absent huit mois, licencié pour désorganisation.', 'issue' => 'Le licenciement est-il justifié ?', 'side' => 'Salarié demandeur', 'goal' => 'Indemnités'],
            'axes' => [['title' => 'Désorganisation et remplacement définitif', 'objective' => 'La règle de principe']],
        ]]);

        self::assertStringContainsString('Plan validé', self::first($events, 'TOOL_CALL_RESULT')['content']);
        $search = json_decode(self::nth($events, 'TOOL_CALL_RESULT', 1)['content'], true);
        self::assertSame([self::PUBLISHED], array_column($search['retained'], 'id'), 'The agent only reads what the triage kept.');
        self::assertStringContainsString('Le remplacement du salarié absent doit être définitif.', self::last($events, 'ACTIVITY_SNAPSHOT')['content']['html']);
        $dossier = $this->dossier();
        self::assertSame('Licenciement pendant un arrêt maladie', $dossier->getTitle());
        self::assertSame('selection', $dossier->getPhase());

        // 4. The user pins the decision and goes to the writing: the framing questions.
        ScriptedPlatform::$rounds = [[new ToolCallComplete([new ToolCall('c5', 'ask_questions', [
            'questions' => [['id' => 'destinataire', 'label' => 'Pour qui ?', 'type' => 'single', 'options' => ['Le client', "L'avocat plaidant"]]],
        ])])]];
        $events = $this->resume($events, ['action' => 'write', 'instruction' => ''], state: [
            'dossier' => [['id' => self::PUBLISHED, 'title' => 'Cass. soc., 13 mars 2024', 'note' => 'Principe']],
            'pinnedPrinciples' => ['p1'],
        ]);
        self::assertStringContainsString('passe à la rédaction (1 décision(s) épinglée(s) ; principes épinglés : p1)', self::first($events, 'TOOL_CALL_RESULT')['content']);
        self::assertSame('cadrage', $this->dossier()->getPhase());

        // 5. The answers; the agent writes the consultation, section by section.
        $sections = [];
        foreach (['faits', 'problematique', 'reponse', 'discussion', 'risques', 'plaidoirie'] as $i => $section) {
            $sections[] = [new ToolCallComplete([new ToolCall('w'.$i, 'write_section', ['section' => $section, 'markdown' => 'Analyse de la section '.$section.', voir [['.self::PUBLISHED.']].'])])];
        }
        ScriptedPlatform::$rounds = [...$sections, [new TextDelta('La consultation est prête.')]];
        $events = $this->resume($events, ['answers' => ['destinataire' => "L'avocat plaidant"]]);

        self::assertSame(['type' => 'success'], self::last($events, 'RUN_FINISHED')['outcome']);
        self::assertStringContainsString('livrée', self::last($events, 'TOOL_CALL_RESULT')['content']);
        $dossier = $this->dossier();
        self::assertSame('livre', $dossier->getPhase());
        self::assertCount(6, $dossier->getState()['consultation']['sections']);
        self::assertSame([], $dossier->getInterrupts());

        // The Word export.
        $this->client->request('GET', '/dossiers/'.$this->thread.'/consultation.docx');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        self::assertStringStartsWith('PK', $this->client->getInternalResponse()->getContent(), 'A .docx is a zip.');
    }

    public function testTheBrowserOnlyChangesTheUsersPartOfTheState(): void
    {
        ScriptedPlatform::$rounds = [
            [new ToolCallComplete([new ToolCall('c1', 'search_axis', ['axisId' => 'a1', 'queries' => [['query' => 'bail']]])])],
            [new TextDelta('Voici.')],
        ];

        $events = $this->stream(state: [
            'phase' => 'recherche',
            'plan' => ['axes' => [['id' => 'a1', 'title' => 'Forgé', 'objective' => '', 'round' => 1, 'searched' => false]]],
            'filters' => ['jurisdictions' => ['cc', 'ca'], 'chambers' => [], 'locations' => [], 'publications' => [], 'dateStart' => null, 'dateEnd' => null],
        ]);

        self::assertStringContainsString('impossible en phase « Qualification »', self::first($events, 'TOOL_CALL_RESULT')['content']);
        self::assertSame([], self::getContainer()->get(FakeJudilibre::class)->requests);
        $state = self::first($events, 'STATE_SNAPSHOT')['snapshot'];
        self::assertSame('qualification', $state['phase']);
        self::assertNull($state['plan']);
        self::assertSame(['cc', 'ca'], $state['filters']['jurisdictions'], 'The filters are the user\'s.');
    }

    public function testTheModelReadsTheDossier(): void
    {
        ScriptedPlatform::$rounds = [[new TextDelta('Bonjour.')]];

        $this->stream(state: ['dossier' => [['id' => self::PUBLISHED, 'title' => 'Cass. soc., 13 mars 2024', 'note' => 'Principe']]]);

        $sent = json_encode(ScriptedPlatform::$calls[0]['input'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('Phase du dossier : qualification', $sent);
        self::assertStringContainsString(self::PUBLISHED.' — Cass. soc., 13 mars 2024 — Principe', $sent);
        self::assertSame(32000, ScriptedPlatform::$calls[0]['options']['max_tokens'] ?? null);
    }

    public function testAnotherUsersDossierIsForbidden(): void
    {
        self::getContainer()->get(DossierRepository::class)->save(new Dossier($this->thread, 'autre@example.com'));

        $this->post(['threadId' => $this->thread, 'runId' => 'r', 'messages' => [['id' => 'u1', 'role' => 'user', 'content' => 'Bonjour']]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testOneRunAtATimePerDossier(): void
    {
        $lock = self::getContainer()->get(LockFactory::class)->createLock('dossier-'.$this->thread);
        $lock->acquire();

        try {
            $this->post(['threadId' => $this->thread, 'runId' => 'r', 'messages' => [['id' => 'u1', 'role' => 'user', 'content' => 'Bonjour']]]);
            self::assertResponseStatusCodeSame(409);
        } finally {
            $lock->release();
        }
    }

    /** A run that died or hangs without releasing its lock: the dossier is freed once its TTL is over. */
    public function testTheLockOfAnAbandonedRunExpires(): void
    {
        $abandoned = self::getContainer()->get(LockFactory::class)->createLock('dossier-'.$this->thread, 1.0, autoRelease: false);
        self::assertTrue($abandoned->acquire());

        $this->post(['threadId' => $this->thread, 'runId' => 'r', 'messages' => [['id' => 'u1', 'role' => 'user', 'content' => 'Bonjour']]]);
        self::assertResponseStatusCodeSame(409);

        sleep(2);
        ScriptedPlatform::$rounds = [[new TextDelta('Bonjour.')]];
        $events = $this->stream();

        self::assertSame('RUN_FINISHED', end($events)['type']);
    }

    public function testAStateThatIsNotOneIsRejected(): void
    {
        $this->post(['threadId' => $this->thread, 'runId' => 'r', 'messages' => [], 'state' => ['filters' => ['jurisdictions' => ['cour-supreme']]]]);

        self::assertResponseStatusCodeSame(422);
    }

    private function dossier(): Dossier
    {
        $repository = self::getContainer()->get(DossierRepository::class);
        $repository->getEntityManager()->clear();

        return $repository->find($this->thread) ?? self::fail('The dossier was not saved.');
    }

    /**
     * Answers the interrupt the previous run finished on. The browser sends no message:
     * the server has the conversation.
     *
     * @param list<array<string, mixed>> $previous
     * @param array<string, mixed>       $payload
     * @param array<string, mixed>|null  $state
     *
     * @return list<array<string, mixed>>
     */
    private function resume(array $previous, array $payload, ?array $state = null): array
    {
        $outcome = self::last($previous, 'RUN_FINISHED')['outcome'];
        self::assertSame('interrupt', $outcome['type']);

        return $this->stream(messages: [], resume: [['interruptId' => $outcome['interrupts'][0]['id'], 'status' => 'resolved', 'payload' => $payload]], state: $state);
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
            'threadId' => $this->thread,
            'runId' => 'run_'.\count(ScriptedPlatform::$calls),
            'resume' => $resume,
            'state' => $state,
        ], static fn (mixed $v): bool => null !== $v && [] !== $v);
        $input['messages'] = $messages ?? [['id' => 'u1', 'role' => 'user', 'content' => 'Peut-on licencier un salarié en arrêt maladie ?']];

        ob_start();
        $this->post($input);
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

    /** @param array<string, mixed> $input */
    private function post(array $input): void
    {
        $this->client->request('POST', '/agent/stream', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($input, \JSON_THROW_ON_ERROR));
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
