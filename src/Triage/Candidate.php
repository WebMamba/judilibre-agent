<?php

declare(strict_types=1);

namespace App\Triage;

/**
 * A decision found by a search, as the triage model reads it: its citation and what
 * Judilibre gives about it (summary, titles, highlighted excerpts), or the reasons of the
 * decision when it has no summary.
 */
final readonly class Candidate
{
    public function __construct(
        public string $id,
        public string $citation,
        public string $content,
        public ?string $summary = null,
        public ?string $date = null,
        public ?string $solution = null,
        /** @var list<string> */
        public array $publication = [],
    ) {
    }

    /** The content, capped: a question may not exceed the model's per-question budget. */
    public function text(int $maxChars): string
    {
        $text = $this->citation."\n".$this->content;

        return mb_strlen($text) > $maxChars ? mb_substr($text, 0, $maxChars).' […]' : $text;
    }
}
