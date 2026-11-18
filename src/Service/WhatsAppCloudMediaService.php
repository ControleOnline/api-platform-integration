<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Connection;
use ControleOnline\Entity\File;
use ControleOnline\Entity\People;
use GuzzleHttp\Client;

/**
 * Media on the official WhatsApp Cloud API (app-community#1643).
 *
 * - inbound: the webhook only carries a media id; the bytes are fetched with the connection token
 *   (GET /{media-id} -> short-lived URL -> GET with the same token) and stored as a File;
 * - outbound: the interaction file is uploaded (POST /{phone-number-id}/media) and sent by id.
 */
class WhatsAppCloudMediaService
{
    private const MAX_BYTES = 25 * 1024 * 1024;

    /** Types Meta accepts per message type (others go as document when allowed). */
    private const IMAGE = ['image/jpeg', 'image/png'];
    private const VIDEO = ['video/mp4', 'video/3gpp'];
    private const AUDIO = ['audio/ogg', 'audio/mpeg', 'audio/aac', 'audio/amr', 'audio/mp4'];
    private const DOCUMENT = [
        'application/pdf', 'text/plain', 'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public function __construct(private FileService $fileService)
    {
    }

    /** @param array{id: string, mime?: string, filename?: string} $media */
    public function storeInbound(Connection $connection, ?People $owner, array $media): ?File
    {
        $cfg = $this->credentials($connection);
        $http = $this->http($cfg);
        $meta = json_decode((string) $http->get($cfg['version'] . '/' . rawurlencode($media['id']))->getBody(), true);
        $url = is_array($meta) ? (string) ($meta['url'] ?? '') : '';
        if ($url === '' || (int) ($meta['file_size'] ?? 0) > self::MAX_BYTES) {
            return null;
        }
        $content = (string) $http->get($url)->getBody();
        if ($content === '') {
            return null;
        }

        $mime = strtolower(trim(explode(';', (string) ($meta['mime_type'] ?? $media['mime'] ?? 'application/octet-stream'))[0]));
        [$fileType, $subtype] = array_pad(explode('/', $mime, 2), 2, 'octet-stream');
        $extension = match ($mime) {
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'image/jpeg' => 'jpg',
            'text/plain' => 'txt',
            'application/pdf' => 'pdf',
            default => preg_replace('/[^a-z0-9]/', '', explode('+', $subtype)[0]) ?: 'bin',
        };
        $filename = trim((string) ($media['filename'] ?? '')) ?: 'whatsapp-' . substr($media['id'], -8) . '.' . $extension;

        return $this->fileService->addFile($owner, $content, 'tasks', $filename, $fileType, $extension);
    }

    /**
     * Sends a stored file to a customer. The caption goes with image/video/document; audio does not
     * support captions, so the text is returned for the caller to send separately.
     *
     * @return array{success: bool, message?: string, code?: int, pending_text?: string}
     */
    public function sendFile(Connection $connection, string $to, File $file, string $caption): array
    {
        $mime = strtolower($file->getFileType() . '/' . $file->getExtension());
        $mime = match ($mime) {
            'image/jpg' => 'image/jpeg',
            'audio/mp3' => 'audio/mpeg',
            'audio/m4a' => 'audio/mp4',
            'application/txt' => 'text/plain',
            default => $mime,
        };
        $type = match (true) {
            in_array($mime, self::IMAGE, true) => 'image',
            in_array($mime, self::VIDEO, true) => 'video',
            in_array($mime, self::AUDIO, true) => 'audio',
            in_array($mime, self::DOCUMENT, true) => 'document',
            default => null,
        };
        if ($type === null) {
            return ['success' => false, 'message' => 'Formato de arquivo não aceito pelo WhatsApp (' . $mime . ').'];
        }

        $cfg = $this->credentials($connection);
        $http = $this->http($cfg);
        $filename = $file->getFileName() ?: ('arquivo.' . $file->getExtension());
        $upload = $http->post($cfg['version'] . '/' . $cfg['phone_number_id'] . '/media', [
            'multipart' => [
                ['name' => 'messaging_product', 'contents' => 'whatsapp'],
                ['name' => 'type', 'contents' => $mime],
                ['name' => 'file', 'contents' => $file->getContent(true), 'filename' => $filename, 'headers' => ['Content-Type' => $mime]],
            ],
        ]);
        $uploaded = json_decode((string) $upload->getBody(), true);
        if ($upload->getStatusCode() >= 300 || empty($uploaded['id'])) {
            return $this->failure($uploaded);
        }

        $body = ['id' => (string) $uploaded['id']];
        $caption = trim($caption);
        $pending = '';
        if ($type === 'audio') {
            $pending = $caption;
        } elseif ($caption !== '') {
            $body['caption'] = mb_substr($caption, 0, 1024);
        }
        if ($type === 'document') {
            $body['filename'] = $filename;
        }

        $response = $http->post($cfg['version'] . '/' . $cfg['phone_number_id'] . '/messages', ['json' => [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => preg_replace('/\D/', '', $to),
            'type' => $type,
            $type => $body,
        ]]);
        $decoded = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 300) {
            return $this->failure($decoded);
        }

        return ['success' => true, 'pending_text' => $pending];
    }

    /** @return array{success: false, message: string, code: int} */
    private function failure(mixed $decoded): array
    {
        $error = is_array($decoded) ? (array) ($decoded['error'] ?? []) : [];

        return [
            'success' => false,
            'message' => (string) ($error['error_data']['details'] ?? $error['message'] ?? 'Erro da API do WhatsApp'),
            'code' => (int) ($error['code'] ?? 0),
        ];
    }

    /** @return array{token: string, phone_number_id: string, version: string, host: string} */
    private function credentials(Connection $connection): array
    {
        $config = $connection->getChannelConfigArray();
        $cfg = [
            'token' => (string) ($config['access_token'] ?? $config['accessToken'] ?? ''),
            'phone_number_id' => (string) ($config['phone_number_id'] ?? $config['phoneNumberId'] ?? ''),
            'version' => (string) ($config['api_version'] ?? $config['apiVersion'] ?? 'v22.0'),
            'host' => rtrim((string) ($config['graph_host'] ?? 'https://graph.facebook.com'), '/'),
        ];
        if ($cfg['token'] === '' || $cfg['phone_number_id'] === '') {
            throw new \RuntimeException('Official WhatsApp connection is missing access_token or phone_number_id.');
        }

        return $cfg;
    }

    private function http(array $cfg): Client
    {
        return new Client([
            'base_uri' => $cfg['host'] . '/',
            'headers' => ['Authorization' => 'Bearer ' . $cfg['token']],
            'timeout' => 60,
            'http_errors' => false,
        ]);
    }
}
