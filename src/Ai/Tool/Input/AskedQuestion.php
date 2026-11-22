<?php

declare(strict_types=1);

namespace App\Ai\Tool\Input;

/**
 * A question of ask_questions, as the user's form shows it.
 */
final readonly class AskedQuestion
{
    public const array TYPES = ['single', 'multiple', 'text'];

    public function __construct(
        /** Identifiant court, ex. « partie ». */
        public string $id = '',
        /** La question, posée simplement. */
        public string $label = '',
        /** « single » : un choix ; « multiple » : plusieurs choix ; « text » : réponse libre courte. */
        public string $type = 'text',
        /** @var list<string> Les choix proposés, pour single et multiple. */
        public array $options = [],
    ) {
    }
}
