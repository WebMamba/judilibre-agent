<?php

declare(strict_types=1);

namespace App\Twig\Components\Agent;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The form behind the `ask_questions` interrupt. Its button resumes the run with the
 * answers.
 */
#[AsTwigComponent]
final class Questions
{
    public string $intro = '';

    /** "qualification" or "cadrage". */
    public string $purpose = 'qualification';

    /** @var list<array{id: string, label: string, type: string, options: list<string>}> */
    public array $questions = [];
}
