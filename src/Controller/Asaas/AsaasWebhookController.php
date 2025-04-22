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

class AsaasWebhookController extends AbstractController
{
    #[Route('/webhook/asaas/return/{data}', name: 'asaas_webhook', methods: ['POST'], options: ['expose' => false])]
    public function __invoke(
        Request $request,
        People $data,
        LoggerInterface $logger,
        MessageBusInterface $bus
    ): JsonResponse {
        try {
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
