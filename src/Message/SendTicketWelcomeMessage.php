<?php

namespace ControleOnline\Message;

/** Welcome message of a request attended by people first (the AI-first welcome is sent by McpChat). */
class SendTicketWelcomeMessage
{
    public function __construct(public int $taskId)
    {
    }
}
