<?php

declare(strict_types=1);

namespace App\Service;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Simple QR code generator service using endroid/qr-code
 */
class QRGenerator
{
    /**
     * Generate an SVG QR code
     *
     * @param string $data The data to encode (e.g., BOLT11 invoice, Lightning URI)
     * @param int $size QR code size in pixels
     * @return string SVG markup
     */
    public function svg(string $data, int $size = 300): string
    {
        $result = (new Builder())->build(
            data: $data,
            writer: new SvgWriter(),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Low,
            size: $size,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin
        );

        return $result->getString();
    }

    /**
     * Generate a data URI (for embedding in img src)
     *
     * @param string $data The data to encode
     * @param int $size QR code size in pixels
     * @return string Data URI
     */
    public function dataUri(string $data, int $size = 300): string
    {
        $result = (new Builder())->build(
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Low,
            size: $size,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin
        );

        return $result->getDataUri();
    }
}

