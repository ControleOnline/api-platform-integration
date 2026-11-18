<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Service\WhatsAppCloudPayloadParser;
use PHPUnit\Framework\TestCase;

class WhatsAppCloudPayloadParserTest extends TestCase
{
    private function payload(array $messages, array $extraValue = []): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA',
                'changes' => [[
                    'field' => 'messages',
                    'value' => array_merge([
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '15550001111', 'phone_number_id' => '123456'],
                        'contacts' => [['wa_id' => '5511999998888', 'profile' => ['name' => 'Maria']]],
                        'messages' => $messages,
                    ], $extraValue),
                ]],
            ]],
        ];
    }

    public function testParsesTextMessageWithContactName(): void
    {
        $out = WhatsAppCloudPayloadParser::messages($this->payload([[
            'from' => '5511999998888', 'id' => 'wamid.A', 'timestamp' => '1700000000',
            'type' => 'text', 'text' => ['body' => ' Oi, quero um orçamento '],
        ]]));

        self::assertCount(1, $out);
        self::assertSame('wamid.A', $out[0]['wamid']);
        self::assertSame('123456', $out[0]['phone_number_id']);
        self::assertSame('15550001111', $out[0]['display_phone']);
        self::assertSame('5511999998888', $out[0]['from']);
        self::assertSame('Maria', $out[0]['name']);
        self::assertSame('Oi, quero um orçamento', $out[0]['text']);
    }

    public function testNonTextMessagesBecomePlaceholders(): void
    {
        $out = WhatsAppCloudPayloadParser::messages($this->payload([
            ['from' => '5511999998888', 'id' => 'wamid.B', 'type' => 'image', 'image' => ['caption' => 'nota']],
            ['from' => '5511999998888', 'id' => 'wamid.C', 'type' => 'audio', 'audio' => ['id' => 'x']],
            ['from' => '5511999998888', 'id' => 'wamid.D', 'type' => 'interactive',
                'interactive' => ['button_reply' => ['title' => 'Suporte']]],
        ]));

        self::assertSame(['[image] nota', '[audio]', 'Suporte'], array_column($out, 'text'));
    }

    public function testMediaMessagesCarryMediaIdAndCaption(): void
    {
        $out = WhatsAppCloudPayloadParser::messages($this->payload([
            ['from' => '5511999998888', 'id' => 'wamid.V', 'type' => 'audio', 'audio' => ['id' => 'MEDIA1', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]],
            ['from' => '5511999998888', 'id' => 'wamid.D', 'type' => 'document', 'document' => ['id' => 'MEDIA2', 'mime_type' => 'application/pdf', 'filename' => 'nota.pdf', 'caption' => ' segue ']],
            ['from' => '5511999998888', 'id' => 'wamid.T', 'type' => 'text', 'text' => ['body' => 'oi']],
        ]));

        self::assertSame(['id' => 'MEDIA1', 'mime' => 'audio/ogg; codecs=opus', 'filename' => ''], $out[0]['media']);
        self::assertSame('[audio]', $out[0]['text']);
        self::assertSame('', $out[0]['caption']);
        self::assertSame('nota.pdf', $out[1]['media']['filename']);
        self::assertSame('segue', $out[1]['caption']);
        self::assertNull($out[2]['media']);
    }

    public function testStatusCallbacksAndOtherObjectsAreIgnored(): void
    {
        $statusOnly = $this->payload([], ['statuses' => [['id' => 'wamid.X', 'status' => 'read']]]);
        unset($statusOnly['entry'][0]['changes'][0]['value']['messages']);

        self::assertSame([], WhatsAppCloudPayloadParser::messages($statusOnly));
        self::assertSame([], WhatsAppCloudPayloadParser::messages(['object' => 'page']));
    }

    public function testSignatureValidation(): void
    {
        $body = '{"object":"whatsapp_business_account"}';
        $good = 'sha256=' . hash_hmac('sha256', $body, 'secret');

        self::assertTrue(WhatsAppCloudPayloadParser::isValidSignature($body, $good, 'secret'));
        self::assertFalse(WhatsAppCloudPayloadParser::isValidSignature($body . ' ', $good, 'secret'));
        self::assertFalse(WhatsAppCloudPayloadParser::isValidSignature($body, $good, 'other'));
        self::assertFalse(WhatsAppCloudPayloadParser::isValidSignature($body, null, 'secret'));
        // No configured secret must never accept a request.
        self::assertFalse(WhatsAppCloudPayloadParser::isValidSignature($body, $good, ''));
    }
}
