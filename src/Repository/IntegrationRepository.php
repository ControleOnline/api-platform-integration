<?php

namespace ControleOnline\Repository;

use ControleOnline\Entity\Integration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Integration|null find($id, $lockMode = null, $lockVersion = null)
 * @method Integration|null findOneBy(array $criteria, array $orderBy = null)
 * @method Integration[]    findAll()
 * @method Integration[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class IntegrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Integration::class);
    }
}
