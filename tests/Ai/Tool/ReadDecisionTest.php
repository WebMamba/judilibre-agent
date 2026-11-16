<?php

declare(strict_types=1);

namespace App\Tests\Ai\Tool;

use App\Ai\Tool\ReadDecision;
use App\Tests\Support\FakeJudilibre;
use PHPUnit\Framework\TestCase;

final class ReadDecisionTest extends TestCase
{
    private const string ID = '65f1c0a2b4e3d2001a7c9e11';

    public function testItReadsTheReasoningAndTheRulingByDefault(): void
    {
        $result = new ReadDecision(new FakeJudilibre()->catalog())(self::ID);

        self::assertIsArray($result);
        self::assertSame('Cour de cassation, Chambre sociale, 13 mars 2024, n° 22-18.457', $result['citation']);
        self::assertSame('https://www.courdecassation.fr/decision/'.self::ID, $result['url']);
        self::assertSame('ECLI:FR:CCASS:2024:SO00321', $result['ecli']);
        self::assertSame(['b'], $result['publication']);
        self::assertSame('Cassation', $result['solution']);
        self::assertSame(['Article L. 1132-1 du code du travail'], $result['applied_texts']);
        self::assertSame(['Soc., 18 octobre 2007, pourvoi n° 06-44.251, Bull. 2007, V, n° 167'], $result['related_decisions']);
        self::assertSame('Cour d\'appel de Lyon, 20 mai 2022', $result['contested_decision']);
        self::assertSame(['motivations' => 'MOTIFS.', 'dispositif' => 'DISPOSITIF.'], $result['zones']);
    }

    public function testItReadsTheZonesAskedFor(): void
    {
        $result = new ReadDecision(new FakeJudilibre()->catalog())(self::ID, ['expose', 'moyens']);

        self::assertIsArray($result);
        self::assertSame(['expose' => 'FAITS.', 'moyens' => 'MOYENS.'], $result['zones']);
    }

    public function testADecisionWithoutZonesGivesItsFullText(): void
    {
        $judilibre = new FakeJudilibre()->respond('decision', FakeJudilibre::json('{"id":"x","jurisdiction":"Cour d\'appel de Paris","decision_date":"2023-01-05","text":"Tout le texte."}'));

        $result = new ReadDecision($judilibre->catalog())('x');

        self::assertIsArray($result);
        self::assertSame(['texte_integral' => 'Tout le texte.'], $result['zones']);
    }

    public function testAnUnknownDecisionIsToldToTheModel(): void
    {
        $judilibre = new FakeJudilibre()->respond('decision', FakeJudilibre::json('{"message":"Décision introuvable."}', 404));

        $result = new ReadDecision($judilibre->catalog())('nope');

        self::assertIsString($result);
        self::assertStringContainsString('introuvable', $result);
    }
}
