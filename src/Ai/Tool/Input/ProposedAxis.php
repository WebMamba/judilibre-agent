<?php

declare(strict_types=1);

namespace App\Ai\Tool\Input;

/**
 * An axis of research the agent proposes.
 */
final readonly class ProposedAxis
{
    public function __construct(
        /** L'intitulé de l'axe, en langage juridique clair. */
        public string $title = '',
        /** Ce que l'axe doit établir pour le dossier, en une phrase. */
        public string $objective = '',
    ) {
    }
}
