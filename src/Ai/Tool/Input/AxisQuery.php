<?php

declare(strict_types=1);

namespace App\Ai\Tool\Input;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * One Judilibre query of an axis.
 */
final readonly class AxisQuery
{
    public const array OPERATORS = ['or', 'and', 'exact'];
    public const array FIELDS = ['expose', 'moyens', 'motivations', 'dispositif', 'annexes', 'sommaire', 'titrage'];

    public function __construct(
        /** Les termes cherchés, en vocabulaire des juges : Judilibre cherche par mots, pas par sens. */
        public string $query = '',
        /** « and » : tous les termes ; « or » : l'un d'eux ; « exact » : l'expression exacte. */
        #[Schema(enum: self::OPERATORS)]
        public string $operator = 'and',
        /** @var list<string> Zones où chercher (motivations, sommaire, titrage…). Vide : toute la décision. */
        public array $fields = [],
    ) {
    }
}
