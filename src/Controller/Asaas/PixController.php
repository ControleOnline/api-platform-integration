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

class PixController extends AbstractController
{
    public function __construct(
        protected EntityManagerInterface $manager,
        private HydratorService $hydratorService,
        private PixService $pixService
    ) {}

    #[Route('/pix', name: 'pix_generate', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $json = json_decode($request->getContent(), true);
            $invoiceId = $json['invoice'] ?? null;
            if (!$invoiceId) {
                throw new Exception('Invoice not found');
            }

            $invoice = $this->manager->getRepository(Invoice::class)->find($invoiceId);
            if (!$invoice) {
                throw new Exception('Invoice not found');
            }

            $result = $this->pixService->getPix($invoice);

            return new JsonResponse($this->hydratorService->result($result));
        } catch (Exception $e) {
            return new JsonResponse($this->hydratorService->error($e));
        }
    }
}
