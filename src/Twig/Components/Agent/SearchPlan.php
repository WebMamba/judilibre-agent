<?php

declare(strict_types=1);

namespace App\Twig\Components\Agent;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The question behind the `propose_search_plan` interrupt. Its buttons resume the run.
 */
#[AsTwigComponent]
final class SearchPlan
{
    /** @var list<array{query: string, operator: string, fields: list<string>, purpose: string}> */
    public array $searches = [];

    /** @var list<string> The filters in force, one line each. */
    public array $filters = [];
}
