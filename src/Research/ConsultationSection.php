<?php

declare(strict_types=1);

namespace App\Research;

/**
 * One section of the consultation, in Markdown. Decisions are cited as [[id]], which the
 * server checked and renders as their citation.
 */
final class ConsultationSection
{
    public function __construct(
        public string $key = '',
        public string $title = '',
        public string $markdown = '',
        /** The version before the last rewrite, to undo it. */
        public ?string $previous = null,
    ) {
    }
}
