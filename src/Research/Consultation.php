<?php

declare(strict_types=1);

namespace App\Research;

/**
 * The deliverable (ADR 0005): a legal consultation in fixed sections. The annex, the
 * list of the decisions cited, is not written by the model: it is built from the
 * citations of the other sections.
 */
final class Consultation
{
    /** The sections the agent writes, in order: key => title. */
    public const array SECTIONS = [
        'faits' => 'Faits',
        'problematique' => 'Problématique',
        'reponse' => 'Réponse synthétique',
        'discussion' => 'Discussion',
        'risques' => 'Évaluation des risques',
        'plaidoirie' => 'Arguments de plaidoirie recommandés',
    ];

    public const string CITATION = '/\[\[([A-Za-z0-9]{8,40})\]\]/';

    public function __construct(
        /** @var list<ConsultationSection> */
        public array $sections = [],
        /** True when the research went on after it was written. */
        public bool $stale = false,
    ) {
    }

    public function section(string $key): ?ConsultationSection
    {
        foreach ($this->sections as $section) {
            if ($section->key === $key) {
                return $section;
            }
        }

        return null;
    }

    /** Writes a section, keeping the version it replaces; sections stay in their order. */
    public function write(string $key, string $markdown): void
    {
        $existing = $this->section($key);
        if (null !== $existing) {
            $existing->previous = $existing->markdown;
            $existing->markdown = $markdown;

            return;
        }

        $this->sections[] = new ConsultationSection($key, self::SECTIONS[$key], $markdown);
        $order = array_flip(array_keys(self::SECTIONS));
        usort($this->sections, static fn (ConsultationSection $a, ConsultationSection $b): int => $order[$a->key] <=> $order[$b->key]);
    }

    /** False when the section has no previous version. */
    public function undo(string $key): bool
    {
        $section = $this->section($key);
        if (null === $section?->previous) {
            return false;
        }
        [$section->markdown, $section->previous] = [$section->previous, null];

        return true;
    }

    public function complete(): bool
    {
        return [] === array_diff(array_keys(self::SECTIONS), array_map(static fn (ConsultationSection $s): string => $s->key, $this->sections));
    }

    /** @return list<string> the ids of the decisions cited, in order of first citation */
    public function citedIds(): array
    {
        $ids = [];
        foreach ($this->sections as $section) {
            $ids = [...$ids, ...self::citationsIn($section->markdown)];
        }

        return array_values(array_unique($ids));
    }

    /** @return list<string> */
    public static function citationsIn(string $markdown): array
    {
        preg_match_all(self::CITATION, $markdown, $matches);

        return array_values(array_unique($matches[1]));
    }
}
