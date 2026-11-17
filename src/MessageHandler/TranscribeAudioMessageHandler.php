<?php

namespace ControleOnline\MessageHandler;

use ControleOnline\Message\TranscribeAudioMessage;
use ControleOnline\Service\AudioTranscriptionService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class TranscribeAudioMessageHandler
{
    public function __construct(private AudioTranscriptionService $transcription) {}

    public function __invoke(TranscribeAudioMessage $message): void
    {
        $this->transcription->send($message->fileId, $message->domain, $message->callbackUrl);
    }
}
