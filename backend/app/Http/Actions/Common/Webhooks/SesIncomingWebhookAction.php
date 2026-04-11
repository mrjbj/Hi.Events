<?php

namespace HiEvents\Http\Actions\Common\Webhooks;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Email\Ses\DTO\SesWebhookDTO;
use HiEvents\Services\Application\Handlers\Email\Ses\IncomingSesWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class SesIncomingWebhookAction extends BaseAction
{
    public function __invoke(Request $request): Response
    {
        try {
            $payload = $request->getContent();

            dispatch(static function (IncomingSesWebhookHandler $handler) use ($payload) {
                $handler->handle(new SesWebhookDTO(
                    payload: $payload,
                ));
            })->catch(function (Throwable $exception) use ($payload) {
                logger()->error(__('Failed to handle incoming SES webhook'), [
                    'exception' => $exception,
                    'payload' => $payload,
                ]);
            });

        } catch (Throwable $exception) {
            logger()?->error($exception->getMessage(), $exception->getTrace());
            return $this->noContentResponse(ResponseCodes::HTTP_BAD_REQUEST);
        }

        return $this->noContentResponse();
    }
}
