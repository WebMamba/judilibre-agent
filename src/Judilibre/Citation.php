<?php

declare(strict_types=1);

namespace App\Judilibre;

/**
 * How a decision is cited, from what Judilibre returns with resolve_references=true.
 */
final class Citation
{
    private const array MONTHS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /**
     * "Cour de cassation, Chambre sociale, 13 mars 2024, n° 22-18.457".
     *
     * @param array<string, mixed> $decision
     */
    public static function of(array $decision): string
    {
        $parts = [];
        foreach (['jurisdiction', 'chamber'] as $key) {
            if (\is_string($decision[$key] ?? null) && '' !== $decision[$key]) {
                $parts[] = $decision[$key];
            }
        }
        if (\is_string($decision['decision_date'] ?? null) && '' !== $decision['decision_date']) {
            $parts[] = self::date($decision['decision_date']);
        }
        if (\is_string($decision['number'] ?? null) && '' !== $decision['number']) {
            $parts[] = 'n° '.$decision['number'];
        }

        return implode(', ', $parts);
    }

    /** "2024-03-13" → "13 mars 2024", "2022-02-01" → "1er février 2022". */
    public static function date(string $isoDate): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($isoDate, 0, 10));
        if (false === $date) {
            return $isoDate;
        }
        $day = (int) $date->format('j');

        return \sprintf('%s %s %s', 1 === $day ? '1er' : $day, self::MONTHS[(int) $date->format('n')], $date->format('Y'));
    }

    /** The public page of the decision. */
    public static function url(string $id): string
    {
        return 'https://www.courdecassation.fr/decision/'.rawurlencode($id);
    }
}
