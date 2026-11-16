<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Judilibre\DecisionCatalog;
use App\Judilibre\JudilibreClient;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Judilibre without the network: answers from tests/Fixtures/judilibre and records the
 * URLs asked for.
 */
final class FakeJudilibre
{
    /** @var list<string> */
    public array $requests = [];

    /** @var array<string, MockResponse|\Closure(): MockResponse> path => response */
    private array $responses = [];

    public function __construct()
    {
        $this->respond('search', self::fixture('search.json'));
        $this->respond('decision', self::fixture('decision.json'));
    }

    public static function fixture(string $name): MockResponse
    {
        return self::json((string) file_get_contents(__DIR__.'/../Fixtures/judilibre/'.$name));
    }

    public static function json(string $body, int $status = 200): MockResponse
    {
        return new MockResponse($body, ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }

    public function respond(string $path, MockResponse $response): self
    {
        $this->responses[$path] = $response;

        return $this;
    }

    public function client(): JudilibreClient
    {
        return new JudilibreClient(new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requests[] = $url;
            $path = (string) preg_replace('~^https://judilibre\.test/|\?.*$~', '', $url);
            $response = $this->responses[$path] ?? self::json('{"message":"not found"}', 404);

            // A MockResponse can be consumed once: hand out a copy.
            return clone $response;
        }, 'https://judilibre.test/'));
    }

    public function catalog(): DecisionCatalog
    {
        return new DecisionCatalog($this->client(), new ArrayAdapter());
    }

    /** The query of the n-th request, decoded (`chamber[]` → `chamber`). */
    public function query(int $index = 0): array
    {
        $query = (string) parse_url($this->requests[$index], \PHP_URL_QUERY);
        $parameters = [];
        foreach (explode('&', $query) as $pair) {
            [$name, $value] = array_map('rawurldecode', explode('=', $pair, 2));
            if (str_ends_with($name, '[]')) {
                $parameters[substr($name, 0, -2)][] = $value;
            } else {
                $parameters[$name] = $value;
            }
        }

        return $parameters;
    }
}
