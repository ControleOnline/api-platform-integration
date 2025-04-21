<?php

namespace ControleOnline\Controller\iFood;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use ControleOnline\Message\iFood\OrderMessage;

class iFoodController extends AbstractController
{
    #[Route('/ifood/webhook', name: 'ifood_webhook', methods: ['POST'])]
    public function handleIFoodWebhook(
        Request $request,
        LoggerInterface $logger,
        MessageBusInterface $bus
    ): Response {
        // Receber o payload bruto
        $rawInput = $request->getContent();
        $signature = $request->headers->get('X-IFood-Signature');

        // Validar a assinatura
        $secretKey = $_ENV['IFOOD_SECRET'];
        $expectedSignature = hash_hmac('sha256', $rawInput, $secretKey);

        if ($signature !== $expectedSignature) {
            $logger->error('Assinatura inválida', ['signature' => $signature]);
            return new Response('Invalid signature', Response::HTTP_UNAUTHORIZED);
        }

        // Decodificar o JSON
        $event = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $logger->error('Erro ao decodificar JSON', ['error' => json_last_error_msg()]);
            return new Response('Invalid JSON', Response::HTTP_BAD_REQUEST);
        }

        // Ignorar eventos de keepalive
        if (isset($event['code']) && $event['code'] === 'KEEPALIVE') {
            $logger->info('Evento keepalive ignorado', ['event' => $event]);
            return new Response('[accepted]', Response::HTTP_ACCEPTED);
        }

        // Enviar para a fila
        $bus->dispatch(new OrderMessage($event, $rawInput));
        $logger->info('Evento enviado para a fila', ['event' => $event]);

        // Responder imediatamente
        return new Response('[accepted]', Response::HTTP_ACCEPTED);
    }
}
