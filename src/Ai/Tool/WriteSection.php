<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Research\Consultation;
use App\Research\Phase;
use App\Research\ResearchState;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * Writes or rewrites one section of the consultation (ADR 0005). Every decision cited
 * is checked: only the decisions of the dossier (retained by the triage, or pinned)
 * may be cited, and the pinned ones must be discussed before it is delivered.
 */
#[AsTool(
    name: 'write_section',
    description: <<<'TXT'
Rédige, ou réécrit, une section de la consultation juridique, en Markdown. Sections, dans
l'ordre : faits, problematique, reponse, discussion, risques, plaidoirie. L'annexe des
décisions citées est produite automatiquement. Citez chaque décision par son id entre
doubles crochets, ex. [[65f1c0a2b4e3d2001a7c9e11]] : il sera remplacé par sa citation.
Seules les décisions du dossier (retenues par le tri ou épinglées) peuvent être citées.
Pour une retouche demandée par l'utilisateur, réécrivez seulement la section concernée.
TXT,
)]
final readonly class WriteSection
{
    public function __construct(
        private ResearchState $state,
    ) {
    }

    /**
     * @param string $section  La section : faits, problematique, reponse, discussion, risques ou plaidoirie
     * @param string $markdown Le contenu de la section, en Markdown, sans son titre
     */
    public function __invoke(
        #[Schema(enum: ['faits', 'problematique', 'reponse', 'discussion', 'risques', 'plaidoirie'])] string $section,
        string $markdown,
    ): string {
        if (null !== $refusal = PhaseGuard::refuse($this->state, [Phase::Cadrage, Phase::Redaction, Phase::Livre], 'write_section')) {
            return $refusal;
        }
        if (!\array_key_exists($section, Consultation::SECTIONS)) {
            return \sprintf('Section inconnue « %s » : choisissez parmi %s.', $section, implode(', ', array_keys(Consultation::SECTIONS)));
        }
        $markdown = trim(preg_replace('/^#{1,2}\s+'.preg_quote(Consultation::SECTIONS[$section], '/').'\s*\n/u', '', trim($markdown)) ?? $markdown);
        if ('' === $markdown) {
            return 'La section est vide.';
        }

        $cited = Consultation::citationsIn($markdown);
        $unknown = array_diff($cited, $this->state->citable());
        $excluded = array_intersect($cited, $this->state->excluded);
        if ([] !== $unknown || [] !== $excluded) {
            return 'Section refusée, rien n\'a été enregistré.'
                .([] === $unknown ? '' : ' Ces ids ne sont pas des décisions du dossier : '.implode(', ', $unknown).'. Ne citez que les décisions retenues par le tri ou épinglées.')
                .([] === $excluded ? '' : ' Ces décisions ont été écartées par l\'utilisateur : '.implode(', ', $excluded).'.');
        }

        $consultation = $this->state->consultation ??= new Consultation();
        $rewrite = null !== $consultation->section($section);
        $consultation->write($section, $markdown);
        if (Phase::Cadrage === $this->state->phase) {
            $this->state->phase = Phase::Redaction;
        }

        $done = \sprintf('Section « %s » %s.', Consultation::SECTIONS[$section], $rewrite ? 'réécrite' : 'rédigée');
        if (!$consultation->complete()) {
            $missing = array_diff(array_keys(Consultation::SECTIONS), array_map(static fn ($s): string => $s->key, $consultation->sections));

            return $done.' Reste à rédiger : '.implode(', ', $missing).'.';
        }

        $pinned = array_map(static fn ($d): string => $d->id, $this->state->dossier);
        $undiscussed = array_values(array_diff($pinned, $consultation->citedIds()));
        if ([] !== $undiscussed) {
            $this->state->phase = Phase::Redaction;

            return $done.' La consultation ne peut pas être livrée : ces décisions épinglées par l\'utilisateur ne sont pas discutées : '.implode(', ', $undiscussed).'. Réécrivez la section discussion (ou une autre) pour les intégrer.';
        }

        $this->state->phase = Phase::Livre;
        $consultation->stale = false;

        return $done.' La consultation est complète et livrée : l\'utilisateur la lit dans la vue Document et peut l\'exporter en Word. Résumez-la en deux phrases dans le chat, sans la recopier.';
    }
}
