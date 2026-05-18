<?php

namespace HiEvents\Services\Application\Handlers\Attendee\DTO;

use HiEvents\DataTransferObjects\BaseDTO;
use Illuminate\Http\UploadedFile;

class ImportAttendeesDTO extends BaseDTO
{
    public function __construct(
        public readonly int          $event_id,
        public readonly bool         $send_confirmation_email,
        public readonly UploadedFile $file,
    )
    {
    }
}
