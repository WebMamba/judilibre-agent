<?php

declare(strict_types=1);

namespace App\Twig\Components\Agent;

use App\Judilibre\Citation;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The principles of a round of research behind the `present_findings` interrupt, by
 * axis, each with its decisions; pin and exclude buttons, then the choice of what next.
 */
#[AsTwigComponent]
final class Findings
{
    public int $round = 1;

    public string $summary = '';

    /** @var list<array{axis: array{id: string, title: string}, principles: list<array<string, mixed>>}> */
    public array $groups = [];

    /** @var list<string> ids the agent gave that the triage had not kept */
    public array $ignored = [];

    public function url(string $id): string
    {
        return Citation::url($id);
    }
}
