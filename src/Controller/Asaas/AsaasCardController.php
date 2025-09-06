<?php

namespace ControleOnline\Controller\Asaas;

use ControleOnline\Entity\Invoice;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use ControleOnline\Service\AsaasService;
use ControleOnline\Service\CardService;
use ControleOnline\Service\LoggerService;
use Doctrine\ORM\EntityManagerInterface;

class AsaasCardController extends AbstractController
{
    protected static $logger;

    public function __construct(
        private EntityManagerInterface $manager,
        private LoggerService $loggerService,
        protected Security $security,
        protected AsaasService $asaasService,
        protected CardService $cardService
    ) {
        self::$logger = $loggerService->getLogger('asaas');
    }


    public function __invoke(
        Invoice $invoice,
        Request $request
    ): JsonResponse {
        try {


            $json = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                self::$logger->error('Erro ao decodificar JSON', ['error' => json_last_error_msg()]);
                return new JsonResponse(['error' => 'Invalid JSON'], 400);
            }

            $card = $this->cardService->findCardById($json['card_id']);

            $this->asaasService->payWithCard($invoice, $card);

            self::$logger->info('Pagamento Asaas criado', ['event' => $json]);

            return new JsonResponse(['status' => 'accepted'], 202);
        } catch (\Exception $e) {
            self::$logger->error('Erro no pagamento Asaas', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
