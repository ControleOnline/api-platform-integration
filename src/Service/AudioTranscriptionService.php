<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\File;
use ControleOnline\Entity\TaskInteration;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Voice notes in conversations are transcribed by api-whisper (w.controleonline.com) when it is
 * configured (WHISPER_URL, WHISPER_TOKEN, WHISPER_CALLBACK_TOKEN); otherwise nothing happens.
 * The text comes back on /webhook/transcription and is written on the message ("🎤 ..."), so people
 * and the AI can read what was said.
 */
class AudioTranscriptionService
{
    private const AUDIO_EXTENSIONS = ['webm', 'ogg', 'oga', 'opus', 'mp3', 'm4a', 'mp4', 'aac', 'wav', 'amr', '3gp'];

    public function __construct(private EntityManagerInterface $manager) {}

    public function isEnabled(): bool
    {
        return $this->env('WHISPER_URL') !== '' && $this->env('WHISPER_TOKEN') !== '';
    }

    public function isAudio(?File $file): bool
    {
        if (!$file instanceof File) {
            return false;
        }

        return strtolower($file->getFileType()) === 'audio'
            || in_array(strtolower($file->getExtension()), self::AUDIO_EXTENSIONS, true);
    }

    public function send(int $fileId, string $domain, string $callbackUrl): void
    {
        $file = $this->manager->getRepository(File::class)->find($fileId);
        if (!$this->isEnabled() || !$file instanceof File) {
            return;
        }
        $extension = strtolower($file->getExtension()) ?: 'webm';
        $tmp = tempnam(sys_get_temp_dir(), 'voice');
        file_put_contents($tmp, $file->getContent(true));
        try {
            $ch = curl_init(rtrim($this->env('WHISPER_URL'), '/') . '/transcrip');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['X-Whisper-Token: ' . $this->env('WHISPER_TOKEN')],
                CURLOPT_POSTFIELDS => [
                    'domain' => $domain,
                    'id' => 'file-' . $fileId,
                    'callback_url' => $callbackUrl,
                    'audio' => new \CURLFile($tmp, 'audio/' . $extension, 'voice.' . $extension),
                ],
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('api-whisper HTTP ' . $status . ': ' . mb_substr((string) $body, 0, 200));
            }
        } finally {
            @unlink($tmp);
        }
    }

    public function isValidCallbackToken(string $token): bool
    {
        $expected = $this->env('WHISPER_CALLBACK_TOKEN');

        return $expected !== '' && hash_equals($expected, $token);
    }

    /** Writes the transcription on every message that carries the file. */
    public function apply(string $id, string $text): int
    {
        if (!preg_match('/^file-(\d+)$/', $id, $m)) {
            return 0;
        }
        $text = trim($text);
        $rows = $this->manager->getRepository(TaskInteration::class)->findBy(['file' => (int) $m[1]]);
        foreach ($rows as $row) {
            $body = trim((string) $row->getBody());
            $label = '🎤 ' . ($text !== '' ? $text : '(áudio sem fala reconhecida)');
            $row->setBody($body === '' || str_starts_with($body, '📎') ? $label : $body . "\n\n" . $label);
        }
        $this->manager->flush();

        return count($rows);
    }

    private function env(string $key): string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) ? trim($value) : '';
    }
}
