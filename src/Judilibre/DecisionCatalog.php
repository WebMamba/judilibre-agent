<?php

declare(strict_types=1);

namespace App\Judilibre;

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
        private CacheInterface $cache,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JudilibreException
     */
    public function find(string $id): array
    {
        return $this->cache->get('judilibre.decision.'.hash('xxh128', $id), function (ItemInterface $item) use ($id): array {
            $item->expiresAfter(self::TTL);

            return $this->judilibre->decision($id);
        });
    }
}
