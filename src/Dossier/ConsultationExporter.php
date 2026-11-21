<?php

declare(strict_types=1);

namespace App\Dossier;

use App\Entity\Dossier;
use App\Research\ResearchState;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * The consultation as a Word document (ADR 0005): built from the same HTML as the
 * Document view, with the house styles, the annex and the footer.
 */
final readonly class ConsultationExporter
{
    public function __construct(
        private ConsultationRenderer $renderer,
        private DenormalizerInterface $denormalizer,
    ) {
    }

    /** The .docx bytes; null when the dossier has no consultation yet. */
    public function docx(Dossier $dossier): ?string
    {
        $state = $this->denormalizer->denormalize($dossier->getState(), ResearchState::class);
        \assert($state instanceof ResearchState);
        if (null === $state->consultation) {
            return null;
        }
        $rendered = $this->renderer->render((array) ($dossier->getState()['consultation'] ?? []), $state->citations());

        $word = new PhpWord();
        $word->setDefaultFontName('Georgia');
        $word->setDefaultFontSize(11);
        $word->addTitleStyle(0, ['size' => 20, 'bold' => true, 'color' => '1E1B4B']);
        $word->addTitleStyle(1, ['size' => 14, 'bold' => true, 'color' => '312E81'], ['spaceBefore' => 240, 'spaceAfter' => 120]);
        $word->addTitleStyle(2, ['size' => 12, 'bold' => true], ['spaceBefore' => 160, 'spaceAfter' => 80]);
        $word->getDocInfo()->setTitle($dossier->getTitle())->setCreator('Judilibre Agent');

        $section = $word->addSection(['marginTop' => 1200, 'marginBottom' => 1200, 'marginLeft' => 1300, 'marginRight' => 1300]);
        $section->addFooter()->addText(ConsultationRenderer::FOOTER, ['size' => 8, 'italic' => true, 'color' => '64748B'], ['alignment' => Jc::CENTER]);

        $section->addTitle('Consultation juridique', 0);
        $section->addText('' === $dossier->getTitle() ? 'Dossier' : $dossier->getTitle(), ['size' => 13, 'color' => '475569']);
        $section->addText('Le '.(new \IntlDateFormatter('fr_FR', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE))->format(new \DateTimeImmutable()), ['size' => 9, 'color' => '64748B']);

        foreach ($rendered['sections'] as $i => $part) {
            $section->addTitle(\sprintf('%s. %s', self::roman($i + 1), $part['title']), 1);
            Html::addHtml($section, self::wordSafe($part['html']));
        }

        if ([] !== $rendered['annex']) {
            $section->addTitle('Annexe — Décisions citées', 1);
            foreach ($rendered['annex'] as $decision) {
                $section->addListItemRun(0)->addLink($decision['url'], htmlspecialchars($decision['citation'], \ENT_XML1), ['color' => '4338CA', 'underline' => 'single']);
            }
        }

        $file = tempnam(sys_get_temp_dir(), 'consultation');
        try {
            IOFactory::createWriter($word, 'Word2007')->save($file);

            return (string) file_get_contents($file);
        } finally {
            @unlink($file);
        }
    }

    /** PhpWord's HTML reader wants well-formed XHTML, without what Word cannot hold. */
    private static function wordSafe(string $html): string
    {
        $html = preg_replace(['#<h[1-6]>#', '#</h[1-6]>#'], ['<p><strong>', '</strong></p>'], $html) ?? $html;
        $html = str_replace(['<hr>', '<hr />', '<br>'], ['', '', '<br/>'], $html);

        return $html;
    }

    private static function roman(int $n): string
    {
        return ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'][$n] ?? (string) $n;
    }
}
