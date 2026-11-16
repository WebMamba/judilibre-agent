<?php

declare(strict_types=1);

namespace App\Twig\Components\Agent;

use AgUi\Bundle\Attribute\AgentIgnore;
use AgUi\Bundle\Attribute\AsAgentComponent;
use App\Judilibre\Citation;
use App\Judilibre\DecisionCatalog;
use App\Judilibre\JudilibreException;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
#[AsAgentComponent(
    description: <<<'TXT'
Affiche des décisions sous forme de cartes : citation exacte, solution, publication, lien
vers la décision et bouton pour l'épingler au dossier. Donnez seulement les ids trouvés
par search_decisions et une phrase de pertinence pour chacune : le reste vient de Judilibre.
TXT,
    tool: 'render_decision_list',
    forModel: "Décisions affichées à l'utilisateur sous forme de cartes.",
)]
final class DecisionList
{
    private const array PUBLICATIONS = ['b' => 'Bulletin', 'r' => 'Rapport', 'l' => 'Lettres de chambre', 'c' => 'Communiqué'];

    /** Titre court au-dessus des cartes, ex. « Décisions de principe ». */
    public string $title = '';

    /** @var list<DecisionItem> Les décisions, dans l'ordre de pertinence. */
    public array $decisions = [];

    public function __construct(
        #[AgentIgnore] private readonly DecisionCatalog $catalog,
    ) {
    }

    /**
     * @return list<array{id: string, relevance: string, found: bool, citation?: string, url?: string, solution?: ?string, publications?: list<string>, summary?: ?string}>
     */
    public function cards(): array
    {
        $cards = [];
        foreach ($this->decisions as $item) {
            $item = \is_array($item) ? new DecisionItem(...$item) : $item;
            try {
                $decision = $this->catalog->find($item->id);
            } catch (JudilibreException) {
                $cards[] = ['id' => $item->id, 'relevance' => $item->relevance, 'found' => false];
                continue;
            }

            $cards[] = [
                'id' => $item->id,
                'relevance' => $item->relevance,
                'found' => true,
                'citation' => Citation::of($decision),
                'url' => Citation::url($item->id),
                'solution' => $decision['solution'] ?? null,
                'publications' => array_values(array_map(
                    static fn (string $key): string => self::PUBLICATIONS[$key] ?? $key,
                    $decision['publication'] ?? [],
                )),
                'summary' => $decision['summary'] ?? null,
            ];
        }

        return $cards;
    }
}
