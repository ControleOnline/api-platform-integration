<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Invoice;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class BitcoinService
{
    public function getBitcoin(Invoice $invoice): array
    {
        $walletAddress = 'BC1QQDMP6P903LNVLQRJ7QWMSGWX7D8YLTQURZDMPL';
        $amount = $invoice->getPrice();
        $payload = "bitcoin:{$walletAddress}?amount={$amount}";

        $qrCode = new QrCode($payload);
        $qrCode->setSize(300);

        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        $encodedImage = base64_encode($result->getString());

        return [
            'payload' => $payload,
            'encodedImage' => $encodedImage,
        ];
    }
}
