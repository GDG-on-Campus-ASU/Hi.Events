<?php

namespace HiEvents\Services\Domain\Email\DTO;

class RenderedEmailTemplateDTO
{
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
        public readonly ?array $cta = null,
        public readonly array $inlineAttachments = [],
    )
    {
    }
}