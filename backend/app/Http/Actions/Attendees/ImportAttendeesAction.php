<?php

namespace HiEvents\Http\Actions\Attendees;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Attendee\ImportAttendeesRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Attendee\DTO\ImportAttendeesDTO;
use HiEvents\Services\Application\Handlers\Attendee\ImportAttendeesHandler;
use Illuminate\Http\JsonResponse;

class ImportAttendeesAction extends BaseAction
{
    public function __construct(
        private readonly ImportAttendeesHandler $importAttendeesHandler,
    )
    {
    }

    public function __invoke(ImportAttendeesRequest $request, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $result = $this->importAttendeesHandler->handle(
            ImportAttendeesDTO::fromArray([
                'event_id' => $eventId,
                'send_confirmation_email' => (bool)$request->input('send_confirmation_email'),
                'file' => $request->file('file'),
            ])
        );

        return $this->jsonResponse(
            data: [
                'successful' => $result['successful'],
                'failed' => $result['failed'],
                'errors' => $result['errors'],
            ],
            statusCode: ResponseCodes::HTTP_OK,
        );
    }
}
