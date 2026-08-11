<?php

namespace ControleOnline\Controller\Asaas;

use ControleOnline\Entity\Config;
use ControleOnline\Entity\People;
use ControleOnline\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\RequestPayloadService;
use Doctrine\ORM\EntityManagerInterface;

class AsaasWebhookController extends AbstractController
{
    protected static $logger;

    public function __construct(
        private EntityManagerInterface $manager,
        private LoggerService $loggerService,
        private RequestPayloadService $requestPayloadService,
    ) {
        self::$logger = $loggerService->getLogger('asaas');
    }

    #[Route('/webhook/asaas/return/{id}', name: 'asaas_webhook', methods: ['POST'])]
    public function __invoke(
        int $id,
        Request $request,
        EntityManagerInterface $manager,
        IntegrationService $integrationService
    ): JsonResponse {
        try {
            // Official header is asaas-access-token; some docs mention underscore variant
            $token = $request->headers->get('asaas-access-token')
                ?: $request->headers->get('asaas_access_token');
            if (!$token) {
                return new JsonResponse(['error' => 'You should not pass!!!'], 401);
            }

            $people = $this->manager->getRepository(People::class)->find($id);
            if (!$people) {
                return new JsonResponse(['error' => 'People not found'], 404);
            }

            // Webhook auth token is independent from API key (asaas-key).
            // Must match the "Token de autenticação" configured on Asaas webhook.
            $webhookTokenConfig = $manager->getRepository(Config::class)->findOneBy([
                'people' => $people,
                'configKey' => 'asaas-webhook-token',
            ]);
            $expectedToken = trim((string) ($webhookTokenConfig?->getConfigValue() ?? ''));
            if ($expectedToken === '') {
                self::$logger->warning('Asaas webhook: asaas-webhook-token config missing', ['peopleId' => $id]);
                return new JsonResponse(['error' => 'You should not pass!!!'], 401);
            }

            if (!hash_equals($expectedToken, $token)) {
                self::$logger->warning('Asaas webhook: invalid access token', ['peopleId' => $id]);
                return new JsonResponse(['error' => 'You should not pass!!!'], 401);
            }

            $json = $this->requestPayloadService->decodeJsonContent($request->getContent());

            $user = $manager->getRepository(User::class)->findOneBy(['apiKey' => $token]);

            $integrationService->addIntegration($request->getContent(), 'Asaas', null, $user, $people);

            self::$logger->info('Evento Asaas enviado para a fila', ['event' => $json]);

            return new JsonResponse(['status' => 'accepted'], 202);
        } catch (\Exception $e) {
            self::$logger->error('Erro no webhook Asaas', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
