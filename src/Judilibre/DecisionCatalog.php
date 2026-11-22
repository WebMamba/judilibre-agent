<?php

declare(strict_types=1);

namespace App\Judilibre;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Decisions by id, cached: a published decision rarely changes, and the same one is
 * read, pinned and shown as a card within a few seconds.
 */
final readonly class DecisionCatalog
{
    private const int TTL = 86400;

    public function __construct(
        private JudilibreClient $judilibre,
        private CacheInterface&CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JudilibreException
     */
    public function find(string $id): array
    {
        return $this->cache->get(self::key($id), function (ItemInterface $item) use ($id): array {
            $item->expiresAfter(self::TTL);

            return $this->judilibre->decision($id);
        });
    }

    /**
     * Several decisions, from the cache or else from Judilibre in parallel. A decision
     * Judilibre fails to give is left out.
     *
     * @param list<string> $ids
     *
     * @return array<string, array<string, mixed>> id => decision
     */
    public function findMany(array $ids): array
    {
        $found = [];
        $missing = [];
        foreach (array_unique($ids) as $id) {
            $item = $this->cache->getItem(self::key($id));
            if ($item->isHit()) {
                $found[$id] = $item->get();
            } else {
                $missing[] = $id;
            }
        }

        foreach ($this->judilibre->decisions($missing) as $id => $decision) {
            $item = $this->cache->getItem(self::key($id))->set($decision)->expiresAfter(self::TTL);
            $this->cache->save($item);
            $found[$id] = $decision;
        }

        return $found;
    }

    private static function key(string $id): string
    {
        return 'judilibre.decision.'.hash('xxh128', $id);
    }
}
