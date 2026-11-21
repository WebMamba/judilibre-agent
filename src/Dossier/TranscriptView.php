<?php

declare(strict_types=1);

namespace App\Dossier;

/**
 * A saved conversation, as the page shows it when a dossier is reopened: the same
 * turns the chat controller builds from the live events. A component the agent
 * rendered takes the place of its tool call's line; an interrupt still open keeps its
 * id, so its buttons answer it.
 */
final class TranscriptView
{
    private const array LABELS = [
        'ask_questions' => '❓ Questions',
        'propose_research_plan' => '📝 Fiche dossier et plan de recherche',
        'search_axis' => '🔎 Recherche et tri',
        'read_decision' => "📄 Lecture d'une décision",
        'present_findings' => '⚖️ Principes et décisions',
        'write_section' => '✍️ Rédaction',
        'set_filters' => '🎚️ Mise à jour des filtres',
        'list_taxonomy' => '📚 Consultation des listes Judilibre',
    ];

    /**
     * @param list<array<string, mixed>> $messages   the AG-UI messages of the dossier
     * @param list<array<string, mixed>> $interrupts the interrupts still open
     *
     * @return list<array{role: 'user', text: string}|array{role: 'assistant', items: list<array<string, mixed>>}>
     */
    public static function turns(array $messages, array $interrupts): array
    {
        $open = array_flip(array_column($interrupts, 'id'));
        $activities = [];
        $results = [];
        foreach ($messages as $message) {
            if ('activity' === ($message['role'] ?? null)) {
                $activities[(string) $message['id']] = $message;
            }
            if ('tool' === ($message['role'] ?? null)) {
                $results[(string) ($message['toolCallId'] ?? '')] = (string) ($message['content'] ?? '');
            }
        }

        $turns = [];
        $current = null;
        foreach ($messages as $message) {
            $role = $message['role'] ?? null;
            if ('user' === $role) {
                if (null !== $current) {
                    $turns[] = $current;
                    $current = null;
                }
                $turns[] = ['role' => 'user', 'text' => self::text($message['content'] ?? '')];
                continue;
            }
            if ('assistant' !== $role) {
                continue;
            }

            $current ??= ['role' => 'assistant', 'items' => []];
            $text = trim(self::text($message['content'] ?? ''));
            if ('' !== $text) {
                $current['items'][] = ['type' => 'text', 'markdown' => $text];
            }
            foreach ($message['toolCalls'] ?? [] as $call) {
                $id = (string) ($call['id'] ?? '');
                $activity = $activities['act_'.$id] ?? null;
                if (null !== $activity && 'twig_component' === ($activity['activityType'] ?? null)) {
                    $interruptId = $activity['content']['interruptId'] ?? null;
                    $current['items'][] = [
                        'type' => 'activity',
                        'id' => 'act_'.$id,
                        'html' => (string) ($activity['content']['html'] ?? ''),
                        'interruptId' => \is_string($interruptId) && isset($open[$interruptId]) ? $interruptId : null,
                        'answered' => \is_string($interruptId) && !isset($open[$interruptId]),
                    ];
                    continue;
                }
                $name = (string) ($call['function']['name'] ?? '');
                $current['items'][] = ['type' => 'tool', 'label' => self::label($name, $results[$id] ?? null)];
            }
        }
        if (null !== $current) {
            $turns[] = $current;
        }

        return $turns;
    }

    private static function label(string $name, ?string $result): string
    {
        $label = self::LABELS[$name] ?? '⚙️ '.$name;
        if (null === $result) {
            return $label;
        }
        $decoded = json_decode($result, true);
        if ('search_axis' === $name && \is_array($decoded) && isset($decoded['axis'])) {
            return \sprintf('%s — axe %s : %d décision(s) retenue(s) sur %d', $label, $decoded['axis'], \count($decoded['retained'] ?? []), $decoded['candidates'] ?? 0);
        }
        if ('read_decision' === $name && \is_array($decoded) && isset($decoded['citation'])) {
            return $label.' — '.$decoded['citation'];
        }
        if ('write_section' === $name) {
            return $label.' — '.strtok($result, "\n");
        }

        return $label;
    }

    private static function text(mixed $content): string
    {
        if (\is_string($content)) {
            return $content;
        }
        if (\is_array($content)) {
            return implode('', array_map(static fn (mixed $part): string => \is_array($part) ? (string) ($part['text'] ?? '') : '', $content));
        }

        return '';
    }
}
