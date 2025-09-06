<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Invoice;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\Writer\PngWriter;

class BitcoinService
{
    public function getBitcoin(Invoice $invoice): array
    {
        $walletAddress = 'BC1QQDMP6P903LNVLQRJ7QWMSGWX7D8YLTQURZDMPL';
        $amount = $invoice->getPrice();
        $payload = "bitcoin:{$walletAddress}?amount={$amount}";

        $builder = new Builder(
            writer: new PngWriter(),
            writerOptions: [],
            validateResult: false,
            data: $payload,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevelHigh::class, // nível alto, seguro
            size: 300,
            margin: 10,
            roundBlockSizeMode: \Endroid\QrCode\RoundBlockSizeMode::Margin
        );

        $result = $builder->build();
        $encodedImage = base64_encode($result->getString());

        return [
            'payload' => $payload,
            'encodedImage' => $encodedImage,
        ];
    }
}
