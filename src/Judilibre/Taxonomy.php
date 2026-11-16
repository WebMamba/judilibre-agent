<?php

declare(strict_types=1);

namespace App\Judilibre;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Judilibre's taxonomies for the interface, cached for a week. When Judilibre is down,
 * an empty list: the filters panel still works, without the labels.
 */
final readonly class Taxonomy
{
    private const int TTL = 604800;

    public function __construct(
        private JudilibreClient $judilibre,
        private CacheInterface $cache,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array<string, string> key => label
     */
    public function labels(string $id, ?string $jurisdiction = null): array
    {
        try {
            return $this->cache->get(\sprintf('judilibre.taxonomy.%s.%s', $id, $jurisdiction ?? 'all'), function (ItemInterface $item) use ($id, $jurisdiction): array {
                $item->expiresAfter(self::TTL);
                $labels = $this->judilibre->taxonomy($id, $jurisdiction)['result'] ?? [];

                return array_map(strval(...), \is_array($labels) ? $labels : []);
            });
        } catch (JudilibreException $e) {
            $this->logger?->warning('Judilibre taxonomy "{id}" is unavailable: {message}', ['id' => $id, 'message' => $e->getMessage()]);

            return [];
        }
    }
}
