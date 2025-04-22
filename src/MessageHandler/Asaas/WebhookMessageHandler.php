<?php

namespace ControleOnline\MessageHandler\Asaas;

use ControleOnline\Entity\People;
use ControleOnline\Message\Asaas\WebhookMessage;
use ControleOnline\Service\Asaas\AsaasService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class WebhookMessageHandler
{
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private AsaasService $asaasService;

    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        AsaasService $asaasService
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->asaasService = $asaasService;
    }

    public function __invoke(WebhookMessage $message)
    {
        $event = $message->getEvent();
        $token = $message->getToken();
        $receiverId = $message->getReceiverId();

        try {
            // Buscar a entidade People (receiver)
            $receiver = $this->entityManager->getRepository(People::class)->find($receiverId);
            if (!$receiver) {
                $this->logger->error('Receiver not found', ['receiverId' => $receiverId]);
                return;
            }

            // Processar o evento
            $this->asaasService->returnWebhook($receiver, $event, $token);
            $this->logger->info('Evento Asaas processado com sucesso', ['event' => $event]);
        } catch (\Exception $e) {
            $this->logger->error('Erro ao processar evento Asaas', [
                'error' => $e->getMessage(),
                'event' => $event,
            ]);
        }
    }
}