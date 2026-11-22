<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The case brief the user validated, and the axes of the plan. The research controller
 * sets both on every state change.
 */
#[AsLiveComponent]
final class BriefPanel
{
    use DefaultActionTrait;

    /** @var array{facts?: string, issue?: string, side?: string, goal?: string}|null */
    #[LiveProp(writable: true)]
    public ?array $brief = null;

    /** @var list<array{id: string, title: string, objective?: string, round?: int, searched?: bool}> */
    #[LiveProp(writable: true)]
    public array $axes = [];
}
