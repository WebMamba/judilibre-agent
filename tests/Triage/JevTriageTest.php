<?php

declare(strict_types=1);

namespace App\Tests\Triage;

use App\Research\Stance;
use App\Triage\Candidate;
use App\Triage\JevTriage;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\TypeSafe\Factory;
use Symfony\AI\Platform\Bridge\TypeSafe\Jev;
use Symfony\AI\Platform\Bridge\TypeSafe\ModelCatalog;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * The triage through the real TypeSafe platform, with only the HTTP client mocked: what
 * Jev is asked, and how its probabilities become verdicts.
 */
final class JevTriageTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $requests = [];

    public function testOneEvaluationAsksAChoiceQuestionPerDecisionAgainstTheCase(): void
    {
        $triage = $this->triage([[
            'd0' => ['favorable' => 0.7, 'defavorable' => 0.1, 'contexte' => 0.1, 'hors_sujet' => 0.1],
            'd1' => ['favorable' => 0.05, 'defavorable' => 0.05, 'contexte' => 0.1, 'hors_sujet' => 0.8],
            'd2' => ['favorable' => 0.1, 'defavorable' => 0.5, 'contexte' => 0.2, 'hors_sujet' => 0.2],
        ]]);

        $verdicts = $triage->sort('Partie défendue : salarié', [
            new Candidate('a', 'Cass. soc., 13 mars 2024', 'Sommaire : remplacement définitif'),
            new Candidate('b', 'Cass. civ. 3, 2 mai 2020', 'Sommaire : bail commercial'),
            new Candidate('c', 'Cass. soc., 1er juin 2022', 'Sommaire : désorganisation suffisante'),
        ]);

        self::assertCount(1, $this->requests);
        self::assertSame('jev-1.14.0', $this->requests[0]['model']);
        self::assertSame('Partie défendue : salarié', $this->requests[0]['state']);
        self::assertSame(['d0', 'd1', 'd2'], array_keys($this->requests[0]['questions']));
        self::assertSame('choice', $this->requests[0]['questions']['d0']['type']);
        self::assertSame(['favorable', 'defavorable', 'contexte', 'hors_sujet'], array_keys($this->requests[0]['questions']['d0']['criteria']));
        self::assertStringContainsString('Cass. soc., 13 mars 2024', $this->requests[0]['questions']['d0']['instructions']);

        self::assertSame(Stance::Favorable, $verdicts[0]->stance);
        self::assertEqualsWithDelta(0.778, $verdicts[0]->confidence, 0.001, 'The stance\'s share of the relevant probability.');
        self::assertFalse($verdicts[1]->retained(), 'Off topic above the threshold.');
        self::assertSame(Stance::Defavorable, $verdicts[2]->stance);
    }

    public function testManyDecisionsAreSentInSeveralEvaluations(): void
    {
        $candidates = array_map(static fn (int $i): Candidate => new Candidate('id'.$i, 'Décision '.$i, str_repeat('motivation ', 1000)), range(1, 40));
        $triage = $this->triage([]);

        $verdicts = $triage->sort('Dossier', $candidates);

        self::assertCount(2, $this->requests, 'Each decision is capped to 6,000 characters: 24 fit in an evaluation.');
        self::assertCount(24, $this->requests[0]['questions']);
        self::assertCount(16, $this->requests[1]['questions']);
        self::assertCount(40, $verdicts);
        self::assertSame('id40', $verdicts[39]->candidate->id);
    }

    /**
     * @param list<array<string, array<string, float>>> $batches the probabilities Jev answers, request by
     *                                                         request; past them, every question is favorable
     */
    private function triage(array $batches): JevTriage
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$batches): JsonMockResponse {
            self::assertStringEndsWith('/v1/systemone', $url);
            $this->requests[] = json_decode($options['body'], true, 512, \JSON_THROW_ON_ERROR);
            $request = end($this->requests);
            $answers = [];
            $batch = array_shift($batches) ?? array_fill_keys(array_keys($request['questions']), ['favorable' => 1.0, 'defavorable' => 0.0, 'contexte' => 0.0, 'hors_sujet' => 0.0]);
            foreach ($batch as $id => $probabilities) {
                arsort($probabilities);
                $answers[$id] = ['type' => 'choice', 'choice' => array_key_first($probabilities), 'confidence' => 0.9, 'probabilities' => $probabilities];
            }

            return new JsonMockResponse(['model' => 'jev-1.14.0', 'answers' => $answers, 'usage' => ['input_tokens' => 100, 'output_tokens' => 1]]);
        });

        return new JevTriage(Factory::createPlatform('key', $http, new ModelCatalog(['jev-1.14.0' => ['class' => Jev::class, 'capabilities' => []]])), 'jev-1.14.0', 0.5);
    }
}
