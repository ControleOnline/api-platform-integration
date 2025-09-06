<?php

namespace ControleOnline\Controller\Asaas;

use ControleOnline\Entity\Document;
use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Status;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\PixService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class AsaasPixController extends AbstractController
{
    public function __construct(
        protected EntityManagerInterface $manager,
        private HydratorService $hydratorService,
        private PixService $pixService
    ) {}


    public function __invoke(Invoice $invoice): JsonResponse
    {
        try {
            $result = $this->pixService->getPix($invoice);
            return new JsonResponse($this->hydratorService->result($result));
        } catch (Exception $e) {
            return new JsonResponse($this->hydratorService->error($e));
        }
    }
}
