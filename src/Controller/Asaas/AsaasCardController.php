<?php

namespace ControleOnline\Controller\Asaas;

use ControleOnline\Entity\Invoice;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use ControleOnline\Service\AsaasService;
use ControleOnline\Service\LoggerService;

class AsaasCardController extends AbstractController
{
    protected static $logger;

    public function __construct(
        private LoggerService $loggerService,
        protected Security $security,
        protected AsaasService $asaasService
    ) {
        self::$logger = $loggerService->getLogger('asaas');
    }


    public function __invoke(
        Invoice $invoice,
        Request $request
    ): JsonResponse {
        try {
            $this->asaasService->payWithCardFromContent($invoice, $request->getContent());
            self::$logger->info('Pagamento Asaas criado', ['invoice' => $invoice->getId()]);

            return new JsonResponse(['status' => 'accepted'], 202);
        } catch (\Exception $e) {
            self::$logger->error('Erro no pagamento Asaas', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
