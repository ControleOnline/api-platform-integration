<?php

namespace ControleOnline\Controller\Webhook;

use ControleOnline\Service\DatabaseSwitchService;
use ControleOnline\Service\WebhookCaptureService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CaptureController extends AbstractController
{
    public function __construct(
        private readonly WebhookCaptureService $webhookCaptureService,
        private readonly DatabaseSwitchService $databaseSwitchService,
    ) {
    }

    #[Route('/oauth/mercadolivre/notifications', name: 'oauth_mercadolivre_notifications', methods: ['POST'])]
    #[Route('/{appDomain}/oauth/mercadolivre/notifications', name: 'oauth_mercadolivre_notifications_tenant', methods: ['POST'])]
    public function mercadoLivre(Request $request, ?string $appDomain = null): Response
    {
        if ($appDomain !== null && trim($appDomain) !== '') {
            $this->databaseSwitchService->switchDatabaseByDomain($appDomain);
            $capture = $this->webhookCaptureService->capture($request, 'MercadoLivre');
        } else {
            $capture = $this->webhookCaptureService->captureMercadoLivre($request);
        }

        return new JsonResponse([
            'accepted' => true,
            'id' => $capture?->getId(),
        ], Response::HTTP_OK);
    }

    #[Route('/oauth/github/notifications', name: 'oauth_github_notifications', methods: ['POST'])]
    #[Route('/webhook/github', name: 'webhook_github_notifications', methods: ['POST'])]
    public function github(Request $request): Response
    {
        $capture = $this->webhookCaptureService->capture($request, 'GitHub');

        return new JsonResponse([
            'accepted' => true,
            'id' => $capture->getId(),
        ], Response::HTTP_OK);
    }
}
