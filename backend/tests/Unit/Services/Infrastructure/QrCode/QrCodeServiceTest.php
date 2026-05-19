<?php

namespace Tests\Unit\Services\Infrastructure\QrCode;

use HiEvents\Services\Infrastructure\QrCode\QrCodeService;
use Tests\TestCase;

class QrCodeServiceTest extends TestCase
{
    private QrCodeService $qrCodeService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->qrCodeService = new QrCodeService();
    }

    public function test_generates_raw_png(): void
    {
        $result = $this->qrCodeService->generateRawPng('TEST-DATA');

        $this->assertNotEmpty($result);
        // PNG magic bytes
        $this->assertStringStartsWith("\x89PNG", $result);
    }

    public function test_generates_base64_image(): void
    {
        $result = $this->qrCodeService->generateBase64Image('TEST-DATA');

        $this->assertStringStartsWith('data:image/png;base64,', $result);
    }

    public function test_generates_cid_img_tag(): void
    {
        $result = $this->qrCodeService->generateCidImgTag('ticket-qr-123', 200, 'Test QR');

        $this->assertStringContainsString('<img', $result);
        $this->assertStringContainsString('src="cid:ticket-qr-123"', $result);
        $this->assertStringContainsString('alt="Test QR"', $result);
        $this->assertStringContainsString('width="200"', $result);
        $this->assertStringContainsString('height="200"', $result);
    }

    public function test_generates_img_tag(): void
    {
        $result = $this->qrCodeService->generateImgTag('TEST-DATA', 200, 'Test QR');

        $this->assertStringContainsString('<img', $result);
        $this->assertStringContainsString('data:image/png;base64,', $result);
        $this->assertStringContainsString('alt="Test QR"', $result);
        $this->assertStringContainsString('width="200"', $result);
        $this->assertStringContainsString('height="200"', $result);
    }

    public function test_escapes_alt_text(): void
    {
        $result = $this->qrCodeService->generateImgTag('TEST-DATA', 200, '<script>alert("xss")</script>');

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function test_escapes_alt_text_in_cid_img_tag(): void
    {
        $result = $this->qrCodeService->generateCidImgTag('test-cid', 200, '<script>alert("xss")</script>');

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }
}
