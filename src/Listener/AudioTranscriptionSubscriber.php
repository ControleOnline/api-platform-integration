<?php

namespace ControleOnline\Listener;

use ControleOnline\Entity\TaskInteration;
use ControleOnline\Event\EntityChangedEvent;
use ControleOnline\Message\TranscribeAudioMessage;
use ControleOnline\Service\AudioTranscriptionService;
use ControleOnline\Service\DomainService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;

/** A new message with a voice note is queued for transcription (only when api-whisper is configured). */
class AudioTranscriptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private AudioTranscriptionService $transcription,
        private MessageBusInterface $bus,
        private DomainService $domainService,
        private RequestStack $requestStack,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [EntityChangedEvent::class => 'onEntityChanged'];
    }

    public function onEntityChanged(EntityChangedEvent $event): void
    {
        $interaction = $event->getEntity();
        if (!$interaction instanceof TaskInteration || !in_array($event->getPhase(), ['postPersist', EntityChangedEvent::POST_PERSIST], true)) {
            return;
        }
        try {
            $file = $interaction->getFile();
            $request = $this->requestStack->getCurrentRequest();
            if (!$request || !$this->transcription->isEnabled() || !$this->transcription->isAudio($file)) {
                return;
            }
            // The text comes back to this same API (dev, staging or production) for this tenant.
            $this->bus->dispatch(new TranscribeAudioMessage(
                (int) $file->getId(),
                (string) $this->domainService->getDomain(),
                $request->getSchemeAndHttpHost() . '/webhook/transcription',
            ));
        } catch (\Throwable) {
            // Transcription is optional: never break the message.
        }
    }
}
