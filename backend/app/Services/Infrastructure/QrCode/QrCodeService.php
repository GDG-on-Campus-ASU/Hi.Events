<?php

namespace HiEvents\Services\Infrastructure\QrCode;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class QrCodeService
{
    public function generateRawPng(string $data, int $scale = 10): string
    {
        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
            'scale' => $scale,
            'imageBase64' => false,
        ]);

        return (new QRCode($options))->render($data);
    }

    public function generateBase64Image(string $data, int $scale = 10): string
    {
        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
            'scale' => $scale,
            'imageBase64' => true,
        ]);

        return (new QRCode($options))->render($data);
    }

    public function generateCidImgTag(string $cid, int $size = 200, string $alt = 'QR Code'): string
    {
        return sprintf(
            '<img src="cid:%s" alt="%s" width="%d" height="%d" style="display:block;" />',
            $cid,
            htmlspecialchars($alt, ENT_QUOTES, 'UTF-8'),
            $size,
            $size
        );
    }

    public function generateImgTag(string $data, int $size = 200, string $alt = 'QR Code'): string
    {
        $base64 = $this->generateBase64Image($data);

        return sprintf(
            '<img src="%s" alt="%s" width="%d" height="%d" style="display:block;" />',
            $base64,
            htmlspecialchars($alt, ENT_QUOTES, 'UTF-8'),
            $size,
            $size
        );
    }
}
