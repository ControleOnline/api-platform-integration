<?php

namespace ControleOnline\Service;

/**
 * Pure parser for Meta WhatsApp Cloud API webhook payloads.
 *
 * Only inbound customer messages are returned; delivery/read statuses are ignored.
 * Non-text messages become a readable placeholder so the conversation keeps context.
 */
final class WhatsAppCloudPayloadParser
{
    /**
     * @param array<string, mixed> $payload
     * @return list<array{wamid: string, phone_number_id: string, display_phone: string, from: string, name: string, type: string, text: string, timestamp: int}>
     */
    public static function messages(array $payload): array
    {
        if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
            return [];
        }

        $out = [];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? '') !== 'messages') {
                    continue;
                }
                $value = (array) ($change['value'] ?? []);
                $metadata = (array) ($value['metadata'] ?? []);
                $names = self::contactNames((array) ($value['contacts'] ?? []));

                foreach ((array) ($value['messages'] ?? []) as $message) {
                    $from = preg_replace('/\D/', '', (string) ($message['from'] ?? ''));
                    $wamid = (string) ($message['id'] ?? '');
                    if ($from === '' || $wamid === '') {
                        continue;
                    }
                    $type = (string) ($message['type'] ?? 'text');
                    $out[] = [
                        'wamid' => $wamid,
                        'phone_number_id' => (string) ($metadata['phone_number_id'] ?? ''),
                        'display_phone' => preg_replace('/\D/', '', (string) ($metadata['display_phone_number'] ?? '')),
                        'from' => $from,
                        'name' => $names[$from] ?? '',
                        'type' => $type,
                        'text' => self::text($message, $type),
                        'timestamp' => (int) ($message['timestamp'] ?? time()),
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * Meta signs the raw body with the app secret: "sha256=" . hex(hmac).
     */
    public static function isValidSignature(string $rawBody, ?string $header, string $appSecret): bool
    {
        if ($appSecret === '' || !$header || !str_starts_with($header, 'sha256=')) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);

        return hash_equals($expected, trim($header));
    }

    /** @return array<string, string> */
    private static function contactNames(array $contacts): array
    {
        $names = [];
        foreach ($contacts as $contact) {
            $waId = preg_replace('/\D/', '', (string) ($contact['wa_id'] ?? ''));
            if ($waId !== '') {
                $names[$waId] = trim((string) ($contact['profile']['name'] ?? ''));
            }
        }

        return $names;
    }

    private static function text(array $message, string $type): string
    {
        return match ($type) {
            'text' => trim((string) ($message['text']['body'] ?? '')),
            'button' => trim((string) ($message['button']['text'] ?? '')),
            'interactive' => trim((string) (
                $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? ''
            )),
            'image', 'video', 'document' => trim('[' . $type . '] ' . (string) ($message[$type]['caption'] ?? '')),
            'location' => sprintf(
                '[location] %s,%s',
                $message['location']['latitude'] ?? '',
                $message['location']['longitude'] ?? ''
            ),
            default => '[' . $type . ']',
        };
    }
}
