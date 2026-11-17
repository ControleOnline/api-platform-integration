<?php

namespace ControleOnline\Message;

/** Voice note to transcribe by api-whisper (w.controleonline.com); handled async. */
class TranscribeAudioMessage
{
    public function __construct(
        public int $fileId,
        public string $domain,
        public string $callbackUrl,
    ) {}
}
