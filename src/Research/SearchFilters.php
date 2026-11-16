<?php

declare(strict_types=1);

namespace App\Research;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The filters every search obeys (ADR 0002). Values are Judilibre's taxonomy keys.
 *
 * No get/is/has/can methods: the AG-UI serializer would put them in the shared state.
 */
final class SearchFilters
{
    public const array JURISDICTIONS = ['cc', 'ca', 'tj', 'tcom'];
    public const array PUBLICATIONS = ['b', 'r', 'l', 'c'];

    public function __construct(
        /** @var list<string> */
        #[Assert\Count(min: 1, minMessage: 'Choisissez au moins une juridiction.')]
        #[Assert\All([new Assert\Choice(choices: self::JURISDICTIONS)])]
        public array $jurisdictions = ['cc'],
        /** @var list<string> Chambers of the Cour de cassation (`soc`, `civ1`…). */
        #[Assert\All([new Assert\NotBlank()])]
        public array $chambers = [],
        /** @var list<string> Courts of appeal or judicial courts, by their taxonomy key. */
        #[Assert\All([new Assert\NotBlank()])]
        public array $locations = [],
        /** @var list<string> */
        #[Assert\All([new Assert\Choice(choices: self::PUBLICATIONS)])]
        public array $publications = [],
        #[Assert\Date]
        public ?string $dateStart = null,
        #[Assert\Date]
        public ?string $dateEnd = null,
    ) {
    }

    /**
     * @return array{jurisdiction: list<string>, chamber: list<string>, location: list<string>, publication: list<string>, date_start: ?string, date_end: ?string}
     */
    public function searchParameters(): array
    {
        return [
            'jurisdiction' => $this->jurisdictions,
            'chamber' => $this->chambers,
            'location' => $this->locations,
            'publication' => $this->publications,
            'date_start' => $this->dateStart,
            'date_end' => $this->dateEnd,
        ];
    }

    public function describe(): string
    {
        $lines = ['Juridictions : '.implode(', ', $this->jurisdictions)];
        $lines[] = 'Chambres : '.([] === $this->chambers ? 'toutes' : implode(', ', $this->chambers));
        if ([] !== $this->locations) {
            $lines[] = 'Sièges : '.implode(', ', $this->locations);
        }
        $lines[] = 'Publications : '.([] === $this->publications ? 'toutes, inédits compris' : implode(', ', $this->publications));
        $lines[] = match (true) {
            null === $this->dateStart && null === $this->dateEnd => 'Période : toutes dates',
            null === $this->dateEnd => \sprintf('Période : du %s à aujourd\'hui', $this->dateStart),
            null === $this->dateStart => \sprintf('Période : jusqu\'au %s', $this->dateEnd),
            default => \sprintf('Période : du %s au %s', $this->dateStart, $this->dateEnd),
        };

        return implode("\n", $lines);
    }
}
