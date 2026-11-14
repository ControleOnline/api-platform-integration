<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Service\Email\MimeMessageParser;
use PHPUnit\Framework\TestCase;

class MimeMessageParserTest extends TestCase
{
    public function testMultipartPrefersPlainTextAndDropsQuotedReply(): void
    {
        $raw = "From: =?UTF-8?B?Sm/Do28gU2lsdmE=?= <Joao@Example.com>\r\n"
            . "Subject: =?UTF-8?Q?Or=C3=A7amento?=\r\n"
            . "Message-ID: <abc@mail>\r\n"
            . "In-Reply-To: <prev@mail>\r\n"
            . "Content-Type: multipart/alternative; boundary=\"b1\"\r\n\r\n"
            . "--b1\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . "Quero um or=C3=A7amento para 3 lojas.\r\n\r\nEm seg, 10 de nov, Loja escreveu:\r\n> mensagem antiga\r\n"
            . "--b1\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>HTML</p>\r\n--b1--\r\n";

        $parsed = MimeMessageParser::parse($raw);

        self::assertSame('joao@example.com', $parsed['from_email']);
        self::assertSame('João Silva', $parsed['from_name']);
        self::assertSame('Orçamento', $parsed['subject']);
        self::assertSame('<abc@mail>', $parsed['message_id']);
        self::assertSame('<prev@mail>', $parsed['in_reply_to']);
        self::assertSame('Quero um orçamento para 3 lojas.', $parsed['text']);
    }

    public function testHtmlOnlyBase64Latin1(): void
    {
        $html = mb_convert_encoding('<div>Olá, preciso de <b>suporte</b></div><br>Obrigado', 'ISO-8859-1', 'UTF-8');
        $raw = "From: cliente@x.com\nSubject: Ajuda\nContent-Type: text/html; charset=ISO-8859-1\n"
            . "Content-Transfer-Encoding: base64\n\n" . chunk_split(base64_encode($html), 20, "\n");

        $parsed = MimeMessageParser::parse($raw);

        self::assertSame('cliente@x.com', $parsed['from_email']);
        self::assertSame("Olá, preciso de suporte\nObrigado", $parsed['text']);
    }

    public function testNestedMultipartWithAttachment(): void
    {
        $raw = "From: a@b.com\nContent-Type: multipart/mixed; boundary=outer\n\n"
            . "--outer\nContent-Type: multipart/alternative; boundary=inner\n\n"
            . "--inner\nContent-Type: text/plain\n\nTexto principal\n--inner--\n"
            . "--outer\nContent-Type: text/plain\nContent-Disposition: attachment; filename=a.txt\n\nANEXO\n--outer--\n";

        self::assertSame('Texto principal', MimeMessageParser::parse($raw)['text']);
    }
}
