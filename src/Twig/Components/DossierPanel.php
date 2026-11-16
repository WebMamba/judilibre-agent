<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Judilibre\Citation;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The dossier of the shared research state: the pinned decisions. The research
 * controller sets the `dossier` prop on every state change; unpinning is its job too.
 */
#[AsLiveComponent]
final class DossierPanel
{
    use DefaultActionTrait;

    /** @var list<array{id: string, title: string, note?: string}> */
    #[LiveProp(writable: true)]
    public array $dossier = [];

    public function url(string $id): string
    {
        return Citation::url($id);
    }
}
