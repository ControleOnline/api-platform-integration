<?php

namespace ControleOnline\Message\Asaas;

class WebhookMessage
{
    private array $event;
    private string $token;
    private int $receiverId;

    public function __construct(array $event, string $token, int $receiverId)
    {
        $this->event = $event;
        $this->token = $token;
        $this->receiverId = $receiverId;
    }

    public function getEvent(): array
    {
        return $this->event;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getReceiverId(): int
    {
        return $this->receiverId;
    }
}