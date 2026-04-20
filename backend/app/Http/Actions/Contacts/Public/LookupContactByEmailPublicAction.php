<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Contacts\Public;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Contact\LookupContactByEmailRequest;
use HiEvents\Services\Application\Handlers\Contact\DTO\LookupContactByEmailPublicDTO;
use HiEvents\Services\Application\Handlers\Contact\LookupContactByEmailPublicHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class LookupContactByEmailPublicAction extends BaseAction
{
    public function __construct(
        private readonly LookupContactByEmailPublicHandler $handler,
    ) {}

    public function __invoke(LookupContactByEmailRequest $request, int $eventId): JsonResponse
    {
        if (!config('app.contact_lookup_enabled')) {
            abort(404);
        }

        $email = (string) $request->input('email');

        $result = $this->handler->handle(new LookupContactByEmailPublicDTO(
            eventId: $eventId,
            email: $email,
        ));

        Log::channel(config('app.contact_lookup_log_channel', 'stack'))->info('contact-lookup', [
            'event_id' => $eventId,
            'ip' => $request->ip(),
            'email_hash' => hash('sha256', strtolower(trim($email))),
            'found' => $result->found,
            'user_agent' => substr((string) $request->userAgent(), 0, 200),
        ]);

        return $this->jsonResponse($result->toArray());
    }
}
