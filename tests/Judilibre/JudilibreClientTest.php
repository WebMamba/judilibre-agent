<?php

declare(strict_types=1);

namespace App\Tests\Judilibre;

use App\Judilibre\JudilibreClient;
use App\Judilibre\JudilibreException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class JudilibreClientTest extends TestCase
{
    private const string BASE_URI = 'https://judilibre.test/v1.0/';

    public function testSearchSendsArraysAsRepeatedBracketParametersAndBooleansAsWords(): void
    {
        $requests = [];
        $client = $this->client(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = [$method, $url];

            return $this->fixture('search.json');
        });

        $result = $client->search([
            'query' => 'licenciement arrêt maladie',
            'operator' => 'and',
            'jurisdiction' => ['cc'],
            'chamber' => ['soc', 'civ1'],
            'date_start' => null,
            'publication' => [],
            'resolve_references' => false,
        ]);

        self::assertSame('GET', $requests[0][0]);
        self::assertSame(
            self::BASE_URI.'search?query=licenciement%20arr%C3%AAt%20maladie&operator=and&jurisdiction%5B%5D=cc&chamber%5B%5D=soc&chamber%5B%5D=civ1&resolve_references=false',
            $requests[0][1],
        );
        self::assertSame(2, $result['total']);
        self::assertSame('22-18.457', $result['results'][0]['number']);
    }

    public function testDecisionIsFetchedByIdWithResolvedReferences(): void
    {
        $url = null;
        $client = $this->client(function (string $method, string $requested) use (&$url): MockResponse {
            $url = $requested;

            return $this->fixture('decision.json');
        });

        $decision = $client->decision('65f1c0a2b4e3d2001a7c9e11');

        self::assertSame(self::BASE_URI.'decision?id=65f1c0a2b4e3d2001a7c9e11&resolve_references=true', $url);
        self::assertSame('Chambre sociale', $decision['chamber']);
    }

    public function testTaxonomyCanBeScopedToAJurisdiction(): void
    {
        $url = null;
        $client = $this->client(function (string $method, string $requested) use (&$url): MockResponse {
            $url = $requested;

            return new MockResponse('{"id":"chamber","result":{"soc":"Chambre sociale"}}');
        });

        self::assertSame(['soc' => 'Chambre sociale'], $client->taxonomy('chamber', 'cc')['result']);
        self::assertSame(self::BASE_URI.'taxonomy?id=chamber&context_value=cc', $url);
    }

    public function testAnErrorStatusBecomesAJudilibreExceptionWithTheBody(): void
    {
        $client = $this->client(new MockResponse('{"message":"Décision introuvable."}', ['http_code' => 404]));

        $this->expectException(JudilibreException::class);
        $this->expectExceptionMessage('HTTP 404');
        $this->expectExceptionMessage('Décision introuvable.');

        $client->decision('nope');
    }

    public function testATransportFailureBecomesAJudilibreException(): void
    {
        $client = $this->client(new MockResponse('', ['error' => 'Connection refused']));

        $this->expectException(JudilibreException::class);
        $this->expectExceptionMessage('Connection refused');

        $client->search(['query' => 'bail']);
    }

    private function client(callable|MockResponse $responses): JudilibreClient
    {
        return new JudilibreClient(new MockHttpClient($responses, self::BASE_URI));
    }

    private function fixture(string $name): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__.'/../Fixtures/judilibre/'.$name), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
