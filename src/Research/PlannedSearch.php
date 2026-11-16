<?php

declare(strict_types=1);

namespace App\Research;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One search of a search plan, as the user approved it.
 */
final class PlannedSearch
{
    public const array OPERATORS = ['or', 'and', 'exact'];
    public const array FIELDS = ['expose', 'moyens', 'motivations', 'dispositif', 'annexes', 'sommaire', 'titrage'];

    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $query = '',
        #[Assert\Choice(choices: self::OPERATORS)]
        public string $operator = 'and',
        /** @var list<string> Zones searched; empty for the whole decision. */
        #[Assert\All([new Assert\Choice(choices: self::FIELDS)])]
        public array $fields = [],
        /** Why this search: what it should find. */
        public string $purpose = '',
    ) {
    }

    public function describe(): string
    {
        $how = $this->operator.([] === $this->fields ? '' : ', champs : '.implode(', ', $this->fields));

        return \sprintf('« %s » (%s)%s', $this->query, $how, '' === $this->purpose ? '' : ' — '.$this->purpose);
    }
}
