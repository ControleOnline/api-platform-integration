<?php

namespace ControleOnline\Service\Bitcoin;

use ControleOnline\Entity\Invoice;
use \Endroid\QrCode\Builder\Builder;
use \Endroid\QrCode\Encoding\Encoding;
use \Endroid\QrCode\Writer\PngWriter;

class BitcoinService
{
    public function getBitcoin(Invoice $invoice): array
    {
        $walletAddress = 'BC1QQDMP6P903LNVLQRJ7QWMSGWX7D8YLTQURZDMPL';
        $amount = $invoice->getPrice(); // Valor em BTC
        $payload = "bitcoin:{$walletAddress}?amount={$amount}";

        $qrCode = Builder::create()
            ->writer(new PngWriter())
            ->data($payload)
            ->encoding(new Encoding('UTF-8'))
            ->size(300)
            ->build();

        $encodedImage = base64_encode($qrCode->getString());

        return [
            'payload' => $payload,
            'encodedImage' => $encodedImage,
        ];
    }
}
