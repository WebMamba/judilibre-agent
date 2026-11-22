<?php

declare(strict_types=1);

namespace App\Dossier;

use App\Judilibre\Citation;
use App\Research\Consultation;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * The consultation as HTML (ADR 0005): each [[id]] becomes the decision's citation,
 * linked to it, and the annex lists the decisions cited. The model's Markdown is never
 * trusted as HTML.
 */
final class ConsultationRenderer
{
    public const string FOOTER = "Document d'aide à la décision généré par une intelligence artificielle à partir de Judilibre. Il ne remplace pas l'analyse d'un avocat : vérifiez chaque décision citée avant de l'invoquer.";

    private GithubFlavoredMarkdownConverter $markdown;

    public function __construct()
    {
        $this->markdown = new GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    /**
     * @param array<string, mixed>  $consultation the normalized Consultation of the state
     * @param array<string, string> $citations    id => citation
     *
     * @return array{sections: list<array{key: string, title: string, html: string, undoable: bool}>, annex: list<array{id: string, citation: string, url: string}>, complete: bool, stale: bool}
     */
    public function render(array $consultation, array $citations): array
    {
        $sections = [];
        $cited = [];
        $order = array_keys(Consultation::SECTIONS);
        foreach ((array) ($consultation['sections'] ?? []) as $section) {
            $markdown = (string) ($section['markdown'] ?? '');
            $cited = [...$cited, ...Consultation::citationsIn($markdown)];
            $sections[] = [
                'key' => (string) ($section['key'] ?? ''),
                'title' => (string) ($section['title'] ?? ''),
                'html' => $this->html($markdown, $citations),
                'undoable' => null !== ($section['previous'] ?? null),
            ];
        }
        usort($sections, static fn (array $a, array $b): int => array_search($a['key'], $order, true) <=> array_search($b['key'], $order, true));

        return [
            'sections' => $sections,
            'annex' => array_map(static fn (string $id): array => [
                'id' => $id,
                'citation' => $citations[$id] ?? $id,
                'url' => Citation::url($id),
            ], array_values(array_unique($cited))),
            'complete' => [] === array_diff($order, array_column($sections, 'key')),
            'stale' => (bool) ($consultation['stale'] ?? false),
        ];
    }

    /** @param array<string, string> $citations */
    public function html(string $markdown, array $citations): string
    {
        $linked = preg_replace_callback(Consultation::CITATION, static fn (array $m): string => \sprintf(
            '[%s](%s)',
            str_replace(['[', ']'], ['(', ')'], $citations[$m[1]] ?? $m[1]),
            Citation::url($m[1]),
        ), $markdown) ?? $markdown;

        return (string) $this->markdown->convert($linked);
    }
}
