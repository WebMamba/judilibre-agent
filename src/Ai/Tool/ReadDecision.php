<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Judilibre\Citation;
use App\Judilibre\DecisionCatalog;
use App\Judilibre\JudilibreException;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsTool(
    name: 'read_decision',
    description: <<<'TXT'
Lit une décision par son id Judilibre : sa citation, son lien, ses textes appliqués, les
décisions rapprochées et le texte des zones demandées. Par défaut, les motivations et le
dispositif ; demandez l'exposé ou les moyens pour les faits et les arguments.
TXT,
)]
final readonly class ReadDecision
{
    public const array ZONES = ['introduction', 'expose', 'moyens', 'motivations', 'dispositif', 'annexes'];

    public function __construct(
        private DecisionCatalog $catalog,
        /** Cites in the page's language; in French without it. */
        private ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * @param string       $id    L'id Judilibre de la décision
     * @param list<string> $zones Zones à lire : introduction, expose, moyens, motivations, dispositif, annexes
     *
     * @return array<string, mixed>|string
     */
    public function __invoke(string $id, array $zones = ['motivations', 'dispositif']): array|string
    {
        try {
            $decision = $this->catalog->find($id);
        } catch (JudilibreException $e) {
            return \sprintf('Décision %s introuvable ou illisible dans Judilibre : %s', $id, $e->getMessage());
        }

        return [
            'id' => $id,
            'citation' => Citation::of($decision, $this->translator),
            'url' => Citation::url($id),
            'ecli' => $decision['ecli'] ?? null,
            'publication' => $decision['publication'] ?? [],
            'solution' => $decision['solution'] ?? null,
            'summary' => $decision['summary'] ?? null,
            'themes' => $decision['themes'] ?? [],
            'applied_texts' => self::titles($decision['visa'] ?? []),
            'related_decisions' => self::titles($decision['rapprochements'] ?? []),
            'contested_decision' => $decision['contested']['title'] ?? null,
            'zones' => self::zones($decision, array_values(array_intersect($zones, self::ZONES)) ?: ['motivations', 'dispositif']),
        ];
    }

    /**
     * Zones are offsets into the text; a decision without zones gives its whole text.
     *
     * @param array<string, mixed> $decision
     * @param list<string>         $wanted
     *
     * @return array<string, string>
     */
    private static function zones(array $decision, array $wanted): array
    {
        $text = (string) ($decision['text'] ?? '');
        if ([] === ($decision['zones'] ?? [])) {
            return ['texte_integral' => $text];
        }

        $read = [];
        foreach ($wanted as $zone) {
            $segments = $decision['zones'][$zone] ?? [];
            if ([] === $segments) {
                continue;
            }
            $read[$zone] = trim(implode("\n", array_map(
                static fn (array $segment): string => mb_substr($text, (int) $segment['start'], (int) $segment['end'] - (int) $segment['start']),
                $segments,
            )));
        }

        return $read;
    }

    /**
     * @param list<array{title?: string}> $links
     *
     * @return list<string>
     */
    private static function titles(array $links): array
    {
        return array_values(array_filter(array_map(static fn (array $link): string => (string) ($link['title'] ?? ''), $links)));
    }
}
