<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use AgUi\Bridge\SymfonyAi\Interrupt\Interrupt;
use AgUi\Bridge\SymfonyAi\Interrupt\ResumableTool;
use AgUi\Bundle\Component\ComponentActivityFactory;
use AgUi\Run\ResumeEntry;
use AgUi\Run\ResumeStatus;
use App\Ai\Tool\Input\AskedQuestion;
use App\Research\Phase;
use App\Research\ResearchState;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * The questions of the qualification and of the framing, in a form (ADR 0004). The
 * user answers some or all of them; the answers are the tool's result.
 */
#[AsTool(
    name: 'ask_questions',
    description: <<<'TXT'
Pose à l'utilisateur un groupe de questions dans un formulaire : choix unique, choix
multiples ou réponse libre courte. En qualification, pour comprendre le contexte, le
problème et la partie défendue (3 tours au plus) ; en cadrage, pour lever les ambiguïtés
sur la rédaction de la consultation. Groupez vos questions : 2 à 6 par appel.
TXT,
)]
final readonly class AskQuestions implements ResumableTool
{
    public function __construct(
        private ResearchState $state,
        private ComponentActivityFactory $activities,
    ) {
    }

    /**
     * @param list<AskedQuestion> $questions Les questions, de 1 à 6
     * @param string              $intro     Une phrase d'introduction au formulaire
     */
    public function __invoke(array $questions, string $intro = ''): Interrupt|string
    {
        if (null !== $refusal = PhaseGuard::refuse($this->state, [Phase::Qualification, Phase::Cadrage], 'ask_questions')) {
            return $refusal;
        }
        if (Phase::Qualification === $this->state->phase && $this->state->qualificationRounds >= ResearchState::MAX_QUALIFICATION_ROUNDS) {
            return \sprintf('Vous avez déjà posé %d tours de questions : proposez maintenant la fiche dossier et le plan avec propose_research_plan.', ResearchState::MAX_QUALIFICATION_ROUNDS);
        }

        $questions = array_values(array_filter($questions, static fn (AskedQuestion $q): bool => '' !== trim($q->label)));
        if ([] === $questions) {
            return 'Posez au moins une question.';
        }
        if (\count($questions) > 6) {
            return 'Six questions au plus par formulaire : regroupez ou gardez les plus utiles.';
        }

        if (Phase::Qualification === $this->state->phase) {
            ++$this->state->qualificationRounds;
        }

        $props = [
            'intro' => $intro,
            'purpose' => $this->state->phase->value,
            'questions' => array_map(static fn (AskedQuestion $q, int $i): array => [
                'id' => '' === $q->id ? 'q'.($i + 1) : $q->id,
                'label' => $q->label,
                'type' => \in_array($q->type, AskedQuestion::TYPES, true) ? $q->type : 'text',
                'options' => array_values(array_map('strval', $q->options)),
            ], $questions, array_keys($questions)),
        ];

        return Interrupt::inputRequired('Répondez aux questions de l\'assistant.')
            ->withActivity($this->activities->create('Agent:Questions', $props, "Formulaire de questions affiché à l'utilisateur."));
    }

    public function resume(array $arguments, ResumeEntry $resume): string
    {
        $payload = \is_array($resume->payload) ? $resume->payload : [];
        $answers = \is_array($payload['answers'] ?? null) ? $payload['answers'] : [];

        if (ResumeStatus::Resolved !== $resume->status) {
            return "L'utilisateur n'a pas répondu au formulaire. Continuez avec ce que vous savez, ou demandez-lui en texte ce qui manque.";
        }

        $lines = [];
        foreach ((array) ($arguments['questions'] ?? []) as $i => $question) {
            if (!\is_array($question)) {
                continue;
            }
            $id = '' === (string) ($question['id'] ?? '') ? 'q'.($i + 1) : (string) $question['id'];
            $answer = $answers[$id] ?? null;
            $text = match (true) {
                \is_array($answer) => implode(', ', array_map('strval', $answer)),
                null === $answer => '',
                default => trim((string) $answer),
            };
            $lines[] = \sprintf('- %s → %s', (string) ($question['label'] ?? $id), '' === $text ? '(sans réponse)' : $text);
        }

        $comment = trim((string) ($payload['comment'] ?? ''));
        if ('' !== $comment) {
            $lines[] = '- Précision libre → '.$comment;
        }

        return "Réponses de l'utilisateur :\n".implode("\n", $lines);
    }
}
