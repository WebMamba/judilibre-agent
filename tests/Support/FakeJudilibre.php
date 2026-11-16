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
 * URLs asked for. In the test kernel it is the HttpClient's mock response factory.
 */
final class FakeJudilibre
{
    /** @var list<string> */
    public array $requests = [];

    /** @var array<string, MockResponse> endpoint (search, decision…) => response */
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

    public function respond(string $endpoint, MockResponse $response): self
    {
        $this->responses[$endpoint] = $response;

        return $this;
    }

    /** The mock response factory: answers by the last segment of the path. */
    public function __invoke(string $method, string $url): MockResponse
    {
        $this->requests[] = $url;
        $endpoint = basename((string) parse_url($url, \PHP_URL_PATH));

        // A MockResponse is consumed once: hand out a copy.
        return clone ($this->responses[$endpoint] ?? self::json('{"message":"Not found"}', 404));
    }

    public function client(): JudilibreClient
    {
        return new JudilibreClient(new MockHttpClient($this, 'https://judilibre.test/'));
    }

    public function catalog(): DecisionCatalog
    {
        return new DecisionCatalog($this->client(), new ArrayAdapter());
    }

    /**
     * The query of the n-th request, decoded (`chamber[]` → `chamber`).
     *
     * @return array<string, string|list<string>>
     */
    public function query(int $index = 0): array
    {
        $parameters = [];
        foreach (explode('&', (string) parse_url($this->requests[$index], \PHP_URL_QUERY)) as $pair) {
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
