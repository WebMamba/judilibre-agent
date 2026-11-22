<?php

declare(strict_types=1);

namespace App\Twig;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Twig\Attribute\AsTwigFilter;

/**
 * The model's Markdown, as HTML: its own HTML is escaped, and unsafe links dropped. The
 * live chat sanitizes the same way with DOMPurify.
 */
final class SafeMarkdown
{
    private ?GithubFlavoredMarkdownConverter $converter = null;

    #[AsTwigFilter('safe_markdown', isSafe: ['html'])]
    public function toHtml(string $markdown): string
    {
        $this->converter ??= new GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);

        return (string) $this->converter->convert($markdown);
    }
}
