<?php

declare(strict_types=1);

namespace App\Judilibre;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Judilibre API of the Cour de cassation, through PISTE.
 *
 * The scoped client `judilibre.client` (config/packages/http_client.yaml) carries the base
 * URL and the `KeyId` header. Responses are returned as decoded, as the OpenAPI spec
 * describes them: https://github.com/Cour-de-cassation/judilibre-search/blob/master/public/JUDILIBRE-public.json
 */
final readonly class JudilibreClient
{
    /** `page_size` cannot exceed it; and `page × page_size` cannot exceed 10 000. */
    public const int MAX_PAGE_SIZE = 50;

    public function __construct(
        private HttpClientInterface $judilibreClient,
    ) {
    }

    /**
     * GET /search. Lists (jurisdiction, chamber…) go as `name[]=value`; empty values are left out.
     *
     * @param array<string, scalar|list<scalar>|null> $parameters
     *
     * @return array<string, mixed>
     */
    public function search(array $parameters): array
    {
        return $this->get('search', $parameters);
    }

    /**
     * GET /decision: the full text, its zones, the applied texts and related decisions.
     *
     * @return array<string, mixed>
     */
    public function decision(string $id): array
    {
        return $this->get('decision', ['id' => $id, 'resolve_references' => true]);
    }

    /**
     * GET /taxonomy: the values a filter takes (`chamber`, `publication`, `solution`…).
     *
     * @return array<string, mixed>
     */
    public function taxonomy(string $id, ?string $jurisdiction = null): array
    {
        return $this->get('taxonomy', ['id' => $id, 'context_value' => $jurisdiction]);
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $parameters
     *
     * @return array<string, mixed>
     */
    private function get(string $path, array $parameters): array
    {
        $query = self::queryString($parameters);

        try {
            $response = $this->judilibreClient->request('GET', '' === $query ? $path : $path.'?'.$query);

            if (($status = $response->getStatusCode()) >= 400) {
                throw new JudilibreException(\sprintf('Judilibre /%s answered HTTP %d: %s', $path, $status, mb_substr($response->getContent(false), 0, 500)));
            }

            return $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new JudilibreException(\sprintf('Judilibre /%s failed: %s', $path, $e->getMessage()), previous: $e);
        }
    }

    /**
     * Built by hand: http_build_query() would index the lists (`chamber[0]=`).
     *
     * @param array<string, scalar|list<scalar>|null> $parameters
     */
    private static function queryString(array $parameters): string
    {
        $pairs = [];
        foreach ($parameters as $name => $value) {
            if (null === $value || '' === $value || [] === $value) {
                continue;
            }
            $key = \is_array($value) ? $name.'[]' : $name;
            foreach ((array) $value as $item) {
                $pairs[] = rawurlencode($key).'='.rawurlencode(\is_bool($item) ? ($item ? 'true' : 'false') : (string) $item);
            }
        }

        return implode('&', $pairs);
    }
}
