<?php

declare(strict_types=1);

namespace App\Twig\Components\Agent;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The case brief and the axes behind the `propose_research_plan` interrupt: the user
 * edits them, then validates, or asks for another plan (ADR 0003).
 */
#[AsTwigComponent]
final class ResearchPlan
{
    public string $title = '';

    /** @var array{facts?: string, issue?: string, side?: string, goal?: string} */
    public array $brief = [];

    /** @var list<array{title: string, objective: string}> */
    public array $axes = [];

    /** @var list<string> the axes of the previous rounds, for a complementary plan */
    public array $previousAxes = [];

    public bool $complementary = false;

    /** @var list<string> the filters in force, one line each */
    public array $filters = [];
}
