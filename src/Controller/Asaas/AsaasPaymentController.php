<?php

namespace ControleOnline\Controller\Asaas;

use ControleOnline\Entity\Order;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use ControleOnline\Service\AsaasService;
use ControleOnline\Service\LoggerService;
use Doctrine\ORM\EntityManagerInterface;

class AsaasPaymentController extends AbstractController
{
    protected static $logger;

    public function __construct(
        private EntityManagerInterface $manager,
        private LoggerService $loggerService,
        protected Security $security,
    ) {
        self::$logger = $loggerService->getLogger('asaas');
    }

    #[Route('/payment/asaas/{id}', name: 'payment_asaas', methods: ['POST'])]
    public function __invoke(
        int $id,
        Request $request,
        AsaasService $asaasService
    ): JsonResponse {
        try {

            $user = $this->security->getToken()->getUser();
            if (!$user)
                return new JsonResponse(['error' => 'You should not pass!!!'], 301);

            $order = $this->manager->getRepository(Order::class)->find($id);
            if (!$order)
                return new JsonResponse(['error' => 'Order not found'], 404);

            $json = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                self::$logger->error('Erro ao decodificar JSON', ['error' => json_last_error_msg()]);
                return new JsonResponse(['error' => 'Invalid JSON'], 400);
            }

            $asaasService->payWithCard($order,$json);

            self::$logger->info('Pagamento Asaas criado', ['event' => $json]);

            return new JsonResponse(['status' => 'accepted'], 202);
        } catch (\Exception $e) {
            self::$logger->error('Erro no pagamento Asaas', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
