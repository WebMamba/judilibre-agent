<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Research\ResearchState;
use App\Research\SearchFilters;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsTool(
    name: 'set_filters',
    description: <<<'TXT'
Remplace les filtres de recherche, que l'utilisateur voit dans son panneau. Donnez tous
les filtres voulus : ceux omis sont vidés. Les valeurs sont des clés de taxonomie
(list_taxonomy). Ne changez les filtres que si la question l'exige ou si on vous le demande.
TXT,
)]
final readonly class SetFilters
{
    public function __construct(
        private ResearchState $state,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * @param list<string> $jurisdictions Juridictions : cc (Cour de cassation), ca (cours d'appel), tj (tribunaux judiciaires), tcom (tribunaux de commerce)
     * @param list<string> $chambers      Chambres de la Cour de cassation (soc, civ1, civ2, civ3, comm, cr…). Vide : toutes
     * @param list<string> $locations     Sièges des cours d'appel ou des tribunaux. Vide : tous
     * @param list<string> $publications  b (Bulletin), r (Rapport), l (Lettres de chambre), c (Communiqué). Vide : toutes, inédits compris
     * @param string|null  $dateStart     Début de période, AAAA-MM-JJ
     * @param string|null  $dateEnd       Fin de période, AAAA-MM-JJ
     */
    public function __invoke(
        array $jurisdictions,
        array $chambers = [],
        array $locations = [],
        array $publications = [],
        ?string $dateStart = null,
        ?string $dateEnd = null,
    ): string {
        $filters = new SearchFilters(
            array_values(array_unique($jurisdictions)),
            array_values(array_unique($chambers)),
            array_values(array_unique($locations)),
            array_values(array_unique($publications)),
            '' === $dateStart ? null : $dateStart,
            '' === $dateEnd ? null : $dateEnd,
        );

        $violations = $this->validator->validate($filters);
        if (\count($violations) > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = $violation->getPropertyPath().' : '.$violation->getMessage();
            }

            return "Filtres refusés, rien n'a changé. ".implode(' ', $messages);
        }

        $this->state->filters = $filters;

        return "Filtres mis à jour :\n".$filters->describe();
    }
}
