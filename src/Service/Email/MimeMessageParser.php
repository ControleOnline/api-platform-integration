<?php

namespace ControleOnline\Service\Email;

/**
 * Minimal RFC 822/2045 parser for customer e-mails: sender, subject, ids and the new text
 * of the message (prefers text/plain, falls back to stripped HTML, drops quoted replies).
 */
final class MimeMessageParser
{
    /**
     * @return array{message_id: string, in_reply_to: string, from_email: string, from_name: string, subject: string, text: string}
     */
    public static function parse(string $raw): array
    {
        [$headers, $body] = self::split($raw);
        [$fromName, $fromEmail] = self::address($headers['from'] ?? '');

        return [
            'message_id' => trim($headers['message-id'] ?? ''),
            'in_reply_to' => trim($headers['in-reply-to'] ?? ''),
            'from_email' => strtolower($fromEmail),
            'from_name' => $fromName,
            'subject' => self::decodeHeader($headers['subject'] ?? ''),
            'text' => self::stripQuoted(self::bodyText($headers, $body)),
        ];
    }

    /** @return array{0: array<string, string>, 1: string} */
    private static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");
        $head = $pos === false ? $raw : substr($raw, 0, $pos);
        $body = $pos === false ? '' : substr($raw, $pos + 2);

        $headers = [];
        $current = null;
        foreach (explode("\n", $head) as $line) {
            if ($current !== null && ($line[0] ?? '') !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                $headers[$current] .= ' ' . trim($line);
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $current = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            // Keep the first occurrence (Received headers etc. repeat).
            $headers[$current] = isset($headers[$current]) && $current !== 'received' ? $headers[$current] : $value;
        }

        return [$headers, $body];
    }

    private static function bodyText(array $headers, string $body): string
    {
        $type = strtolower($headers['content-type'] ?? 'text/plain');
        if (str_starts_with($type, 'multipart/')) {
            $boundary = self::param($headers['content-type'] ?? '', 'boundary');
            if ($boundary === '') {
                return '';
            }
            $plain = null;
            $html = null;
            foreach (explode('--' . $boundary, $body) as $part) {
                $part = ltrim($part, "\n");
                if ($part === '' || str_starts_with($part, '--')) {
                    continue;
                }
                [$partHeaders, $partBody] = self::split($part);
                $partType = strtolower($partHeaders['content-type'] ?? 'text/plain');
                $disposition = strtolower($partHeaders['content-disposition'] ?? '');
                if (str_contains($disposition, 'attachment')) {
                    continue;
                }
                if (str_starts_with($partType, 'multipart/')) {
                    $nested = self::bodyText($partHeaders, $partBody);
                    $plain ??= $nested !== '' ? $nested : null;
                } elseif (str_starts_with($partType, 'text/plain')) {
                    $plain ??= self::decodeBody($partHeaders, $partBody);
                } elseif (str_starts_with($partType, 'text/html')) {
                    $html ??= self::decodeBody($partHeaders, $partBody);
                }
            }

            return $plain ?? ($html !== null ? self::htmlToText($html) : '');
        }

        $text = self::decodeBody($headers, $body);

        return str_starts_with($type, 'text/html') ? self::htmlToText($text) : $text;
    }

    private static function decodeBody(array $headers, string $body): string
    {
        $encoding = strtolower(trim($headers['content-transfer-encoding'] ?? ''));
        $body = match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body)),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
        $charset = self::param($headers['content-type'] ?? '', 'charset') ?: 'UTF-8';
        if (strtoupper($charset) !== 'UTF-8') {
            $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
            $body = is_string($converted) ? $converted : $body;
        }

        return trim($body);
    }

    private static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(br|/p|/div|/li)[^>]*>#i', "\n", $html);
        $html = preg_replace('#<(style|script)[^>]*>.*?</\1>#is', '', (string) $html);
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n\s*\n+/", "\n", (string) $text));
    }

    /** Keep only what the customer wrote now: drop "> quoted" lines and the "On ... wrote:" tail. */
    private static function stripQuoted(string $text): string
    {
        $out = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line) {
            if (preg_match('/^(On .+wrote:|Em .+escreveu:|-----Original Message-----|De: .+)$/iu', trim($line))) {
                break;
            }
            if (str_starts_with(ltrim($line), '>')) {
                continue;
            }
            $out[] = rtrim($line);
        }

        return trim(implode("\n", $out));
    }

    /** @return array{0: string, 1: string} name, email */
    private static function address(string $value): array
    {
        $value = self::decodeHeader($value);
        if (preg_match('/^(.*)<([^>]+)>/', $value, $m)) {
            return [trim($m[1], " \"'"), trim($m[2])];
        }

        return ['', trim($value)];
    }

    private static function decodeHeader(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return trim($value);
        }
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return trim(is_string($decoded) ? $decoded : $value);
    }

    private static function param(string $header, string $name): string
    {
        return preg_match('/' . $name . '\s*=\s*"?([^";]+)"?/i', $header, $m) ? trim($m[1]) : '';
    }
}
