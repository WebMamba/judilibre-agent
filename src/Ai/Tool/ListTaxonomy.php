<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Judilibre\JudilibreClient;
use App\Judilibre\JudilibreException;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

#[AsTool(
    name: 'list_taxonomy',
    description: <<<'TXT'
Donne les valeurs possibles d'un filtre Judilibre, clé => libellé : par exemple les
chambres de la Cour de cassation, ou les cours d'appel (location, juridiction "ca").
Utilisez les clés dans set_filters.
TXT,
)]
final readonly class ListTaxonomy
{
    public function __construct(
        private JudilibreClient $judilibre,
    ) {
    }

    /**
     * @param string      $id           Le filtre : chamber, location, publication, solution, type, formation, theme, jurisdiction
     * @param string|null $jurisdiction La juridiction dont on veut les valeurs : cc ou ca
     *
     * @return array<string, mixed>|string
     */
    public function __invoke(
        #[Schema(enum: ['chamber', 'location', 'publication', 'solution', 'type', 'formation', 'theme', 'jurisdiction'])] string $id,
        #[Schema(enum: ['cc', 'ca'])] ?string $jurisdiction = null,
    ): array|string {
        try {
            return $this->judilibre->taxonomy($id, $jurisdiction)['result'] ?? [];
        } catch (JudilibreException $e) {
            return 'La taxonomie Judilibre est indisponible : '.$e->getMessage();
        }
    }
}
