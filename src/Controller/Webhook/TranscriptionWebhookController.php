<?php

namespace ControleOnline\Controller\Webhook;

use ControleOnline\Service\AudioTranscriptionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Callback of api-whisper: {id: "file-<id>", domain, text}, authenticated by X-Whisper-Token. */
class TranscriptionWebhookController
{
    public function __construct(private AudioTranscriptionService $transcription) {}

    #[Route('/webhook/transcription', name: 'controleonline_webhook_transcription', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->transcription->isValidCallbackToken((string) $request->headers->get('X-Whisper-Token', ''))) {
            return new JsonResponse(['error' => 'invalid token'], 401);
        }
        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload) || empty($payload['id'])) {
            return new JsonResponse(['error' => 'invalid payload'], 400);
        }

        return new JsonResponse(['updated' => $this->transcription->apply((string) $payload['id'], (string) ($payload['text'] ?? ''))]);
    }
}
