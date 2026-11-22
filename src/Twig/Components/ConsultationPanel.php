<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Dossier\ConsultationRenderer;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The Document view (ADR 0005): the consultation as it is written, section by section.
 * The research controller sets the consultation and the citations on every state
 * change; undoing a rewrite and resuming the research are its job too.
 */
#[AsLiveComponent]
final class ConsultationPanel
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $dossierId = '';

    /** @var array<string, mixed>|null */
    #[LiveProp(writable: true)]
    public ?array $consultation = null;

    /** @var array<string, string> id => citation */
    #[LiveProp(writable: true)]
    public array $citations = [];

    #[LiveProp(writable: true)]
    public string $phase = 'qualification';

    public function __construct(
        private readonly ConsultationRenderer $renderer,
    ) {
    }

    /** @return array{sections: list<array{key: string, title: string, html: string, undoable: bool}>, annex: list<array{id: string, citation: string, url: string}>, complete: bool, stale: bool}|null */
    public function document(): ?array
    {
        return null === $this->consultation ? null : $this->renderer->render($this->consultation, $this->citations);
    }

    public function footer(): string
    {
        return $this->renderer->footer();
    }
}
