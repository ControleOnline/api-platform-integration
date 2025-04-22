<?php

namespace ControleOnline\Controller\Asaas;

use ControleOnline\Entity\People;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use ControleOnline\Message\Asaas\WebhookMessage;
use Doctrine\ORM\EntityManagerInterface;

class AsaasWebhookController extends AbstractController
{
    public function __construct(private EntityManagerInterface $manager) {}

    #[Route('/webhook/asaas/return/{id}', name: 'asaas_webhook', methods: ['POST'])]
    public function __invoke(
        int $id,
        Request $request,
        LoggerInterface $logger,
        MessageBusInterface $bus,
    ): JsonResponse {
        try {
            $data = $this->manager->getRepository(People::class)->find($id);
            if (!$data) {
                return new JsonResponse(['error' => 'People not found'], 404);
            }

            $json = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $logger->error('Erro ao decodificar JSON', ['error' => json_last_error_msg()]);
                return new JsonResponse(['error' => 'Invalid JSON'], 400);
            }

            $token = $request->headers->get('asaas-access-token');
            if (!$token) {
                $logger->error('Token não fornecido');
                return new JsonResponse(['error' => 'Token not provided'], 401);
            }

            $bus->dispatch(new WebhookMessage($json, $token, $data->getId()));
            $logger->info('Evento Asaas enviado para a fila', ['event' => $json]);

            return new JsonResponse(['status' => 'accepted'], 202);
        } catch (\Exception $e) {
            $logger->error('Erro no webhook Asaas', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
