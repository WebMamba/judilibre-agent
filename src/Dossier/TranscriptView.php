<?php

declare(strict_types=1);

namespace App\Dossier;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A saved conversation, as the page shows it when a dossier is reopened: the same
 * turns the chat controller builds from the live events. A component the agent
 * rendered takes the place of its tool call's line; an interrupt still open keeps its
 * id, so its buttons answer it.
 */
final class TranscriptView
{
    /**
     * @param list<array<string, mixed>> $messages   the AG-UI messages of the dossier
     * @param list<array<string, mixed>> $interrupts the interrupts still open
     *
     * @return list<array{role: 'user', text: string}|array{role: 'assistant', items: list<array<string, mixed>>}>
     */
    public static function turns(array $messages, array $interrupts, TranslatorInterface $translator): array
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
                    $answered = \is_string($interruptId) && !isset($open[$interruptId]);
                    // An answered form shows the answers, not empty fields again.
                    if ($answered && 'ask_questions' === ($call['function']['name'] ?? null) && isset($results[$id])) {
                        $current['items'][] = ['type' => 'answers', 'text' => $results[$id]];
                        continue;
                    }
                    $current['items'][] = [
                        'type' => 'activity',
                        'id' => 'act_'.$id,
                        'html' => (string) ($activity['content']['html'] ?? ''),
                        'interruptId' => \is_string($interruptId) && isset($open[$interruptId]) ? $interruptId : null,
                        'answered' => $answered,
                    ];
                    continue;
                }
                $name = (string) ($call['function']['name'] ?? '');
                $arguments = json_decode((string) ($call['function']['arguments'] ?? ''), true);
                $current['items'][] = ['type' => 'tool', 'label' => self::label($translator, $name, \is_array($arguments) ? $arguments : [], $results[$id] ?? null)];
            }
        }
        if (null !== $current) {
            $turns[] = $current;
        }

        return $turns;
    }

    /** @param array<string, mixed> $arguments */
    private static function label(TranslatorInterface $translator, string $name, array $arguments, ?string $result): string
    {
        $label = match ($name) {
            'ask_questions', 'propose_research_plan', 'search_axis', 'present_findings' => $translator->trans('tool.'.$name.'_done'),
            'read_decision', 'write_section', 'set_filters', 'list_taxonomy' => $translator->trans('tool.'.$name),
            default => '⚙️ '.$name,
        };
        $decoded = null === $result ? null : json_decode($result, true);
        if ('search_axis' === $name && \is_array($decoded) && isset($decoded['axis'])) {
            return \sprintf('%s — %s : %s', $label, $translator->trans('tool.axis', ['%axis%' => $decoded['axis']]), $translator->trans('tool.retained', ['%count%' => \count($decoded['retained'] ?? []), '%candidates%' => $decoded['candidates'] ?? 0]));
        }
        if ('read_decision' === $name && \is_array($decoded) && isset($decoded['citation'])) {
            return $label.' — '.$decoded['citation'];
        }
        if ('write_section' === $name && \is_string($arguments['section'] ?? null)) {
            return $translator->trans('tool.write_section_of', ['%section%' => $translator->trans('consultation.section.'.$arguments['section'])]);
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
