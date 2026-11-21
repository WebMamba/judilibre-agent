<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dossier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Dossier>
 */
final class DossierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Dossier::class);
    }

    /** @return list<Dossier> the user's dossiers, the most recent first */
    public function ownedBy(string $owner): array
    {
        return $this->findBy(['owner' => $owner], ['updatedAt' => 'DESC']);
    }

    public function save(Dossier $dossier): void
    {
        $this->getEntityManager()->persist($dossier);
        $this->getEntityManager()->flush();
    }
}
