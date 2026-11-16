<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Judilibre\Taxonomy;
use App\Research\SearchFilters;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The filters of the shared research state (ADR 0002), as the user edits them.
 *
 * Two writers: the agent's set_filters reaches it through the research controller, which
 * sets the `filters` prop; the user's edits go through LiveActions, which announce the
 * new filters with research:filters-changed. They apply from the next run on.
 */
#[AsLiveComponent]
final class FiltersPanel
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    public const array JURISDICTIONS = ['cc' => 'Cour de cassation', 'ca' => "Cours d'appel", 'tj' => 'Tribunaux judiciaires', 'tcom' => 'Tribunaux de commerce'];
    public const array PUBLICATIONS = ['b' => 'Bulletin', 'r' => 'Rapport', 'l' => 'Lettres de chambre', 'c' => 'Communiqué'];
    private const array LISTS = ['jurisdictions', 'chambers', 'locations', 'publications'];

    /** @var array{jurisdictions: list<string>, chambers: list<string>, locations: list<string>, publications: list<string>, dateStart: ?string, dateEnd: ?string} */
    #[LiveProp(writable: true, onUpdated: ['dateStart' => 'dateStartChanged', 'dateEnd' => 'dateEndChanged'])]
    public array $filters;

    public function __construct(
        private readonly Taxonomy $taxonomy,
    ) {
        $this->filters = (array) new SearchFilters();
    }

    #[LiveAction]
    public function toggle(#[LiveArg] string $field, #[LiveArg] string $value): void
    {
        if (!\in_array($field, self::LISTS, true) || !\array_key_exists($value, $this->options($field))) {
            return;
        }

        $values = $this->filters[$field] ?? [];
        $values = \in_array($value, $values, true)
            ? array_values(array_diff($values, [$value]))
            : [...$values, $value];

        if ('jurisdictions' === $field && [] === $values) {
            return;
        }

        $this->filters[$field] = $values;
        $this->changed();
    }

    /** The date inputs are bound with data-model: a value that is not a date is undone. */
    public function dateStartChanged(?string $previous): void
    {
        $this->dateChanged('dateStart', $previous);
    }

    public function dateEndChanged(?string $previous): void
    {
        $this->dateChanged('dateEnd', $previous);
    }

    public function searchesIn(string $jurisdiction): bool
    {
        return \in_array($jurisdiction, $this->filters['jurisdictions'] ?? [], true);
    }

    public function selected(string $field, string $value): bool
    {
        return \in_array($value, $this->filters[$field] ?? [], true);
    }

    /**
     * @return array<string, string> key => label
     */
    public function options(string $field): array
    {
        return match ($field) {
            'jurisdictions' => self::JURISDICTIONS,
            'publications' => self::PUBLICATIONS,
            'chambers' => $this->taxonomy->labels('chamber', 'cc'),
            'locations' => $this->taxonomy->labels('location', 'ca'),
            default => [],
        };
    }

    private function dateChanged(string $field, ?string $previous): void
    {
        $value = $this->filters[$field] ?? null;
        if (null === $value || '' === $value) {
            $this->filters[$field] = null;
        } elseif (false === \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value)) {
            $this->filters[$field] = $previous;

            return;
        }

        $this->changed();
    }

    private function changed(): void
    {
        $this->dispatchBrowserEvent('research:filters-changed', ['filters' => $this->filters]);
    }
}
