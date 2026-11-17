<?php

namespace ControleOnline\Message;

/** Push to the people involved in a request (relationship/support); handled async. */
class SendTicketPushMessage
{
    /** @param list<int> $peopleIds */
    public function __construct(
        public array $peopleIds,
        public string $title,
        public string $body,
        public int $taskId,
    ) {}
}
