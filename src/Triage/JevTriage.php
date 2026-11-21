<?php

declare(strict_types=1);

namespace App\Triage;

use App\Research\Stance;
use Symfony\AI\Platform\Bridge\Decision\Answer\Answers;
use Symfony\AI\Platform\Bridge\Decision\Evaluation;
use Symfony\AI\Platform\Bridge\Decision\Question\ChoiceQuestion;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The triage by Jev, TypeSafe's decision model (ADR 0004): one evaluation per batch,
 * whose state is the anonymized case and whose questions are the decisions, one choice
 * question each. Jev reads the state once and answers every question in parallel.
 *
 * A decision is kept when the probability that it is off topic is under the threshold;
 * its stance is the most probable of the three others.
 */
#[AsAlias(Triage::class)]
final readonly class JevTriage implements Triage
{
    /** Jev takes 64k tokens per request: about 4 characters a token, with room to spare. */
    private const int BATCH_CHARS = 160_000;
    private const int CANDIDATE_CHARS = 6_000;
    private const string OFF_TOPIC = 'hors_sujet';

    private const array CRITERIA = [
        'favorable' => 'La décision soutient la position de la partie défendue sur la question de droit : elle peut être invoquée à son profit.',
        'defavorable' => "La décision va contre la position de la partie défendue : l'adversaire peut l'opposer, il faudra la discuter.",
        'contexte' => 'La décision porte sur la question de droit sans trancher pour ou contre : règle générale, définition, procédure, cadre applicable.',
        self::OFF_TOPIC => 'La décision ne porte pas sur la question de droit du dossier, ou seulement de loin.',
    ];

    public function __construct(
        #[Autowire(service: 'ai.platform.typesafe')]
        private PlatformInterface $platform,
        #[Autowire(env: 'TRIAGE_MODEL')]
        private string $model,
        #[Autowire(env: 'float:TRIAGE_THRESHOLD')]
        private float $threshold,
    ) {
    }

    public function sort(string $context, array $candidates): array
    {
        // Every batch is sent before any answer is read: the HTTP client runs them together.
        $pending = [];
        foreach ($this->batches($candidates) as $batch) {
            $questions = [];
            foreach ($batch as $i => $candidate) {
                $questions['d'.$i] = new ChoiceQuestion(
                    "Cette décision de justice est-elle utile au dossier décrit dans l'état, et dans quel sens ?\n\n".$candidate->text(self::CANDIDATE_CHARS),
                    self::CRITERIA,
                );
            }
            $pending[] = [$batch, $this->platform->invoke($this->model, new Evaluation($context, $questions))];
        }

        $verdicts = [];
        foreach ($pending as [$batch, $result]) {
            $answers = $result->asObject();
            \assert($answers instanceof Answers);
            foreach ($batch as $i => $candidate) {
                $verdicts[] = $this->verdict($candidate, $answers->getChoice('d'.$i)->getProbabilities());
            }
        }

        return $verdicts;
    }

    /** @param array<array-key, float> $probabilities */
    private function verdict(Candidate $candidate, array $probabilities): Verdict
    {
        $offTopic = (float) ($probabilities[self::OFF_TOPIC] ?? 0.0);
        if ($offTopic >= $this->threshold) {
            return new Verdict($candidate, null, $offTopic, $offTopic);
        }

        $relevant = array_intersect_key($probabilities, array_flip(array_column(Stance::cases(), 'value')));
        arsort($relevant);
        $stance = (string) array_key_first($relevant);
        $total = array_sum($relevant);

        return new Verdict($candidate, Stance::from($stance), $offTopic, $total > 0 ? round($relevant[$stance] / $total, 3) : 0.0);
    }

    /**
     * @param list<Candidate> $candidates
     *
     * @return iterable<list<Candidate>>
     */
    private function batches(array $candidates): iterable
    {
        $batch = [];
        $size = 0;
        foreach ($candidates as $candidate) {
            $length = min(mb_strlen($candidate->text(self::CANDIDATE_CHARS)), self::CANDIDATE_CHARS) + 600;
            if ([] !== $batch && $size + $length > self::BATCH_CHARS) {
                yield $batch;
                $batch = [];
                $size = 0;
            }
            $batch[] = $candidate;
            $size += $length;
        }
        if ([] !== $batch) {
            yield $batch;
        }
    }
}
