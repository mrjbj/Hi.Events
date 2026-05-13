<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Orders\Public;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Order\CreateOrderRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Order\OrderResourcePublic;
use HiEvents\Services\Application\Handlers\Order\CreateOrderHandler;
use HiEvents\Services\Application\Handlers\Order\DTO\CreateOrderPublicDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\ProductOrderDetailsDTO;
use HiEvents\Services\Application\Locale\LocaleService;
use HiEvents\Services\Domain\Order\OrderCreateRequestValidationService;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use Illuminate\Http\JsonResponse;
use Throwable;

class CreateOrderActionPublic extends BaseAction
{
    public function __construct(
        private readonly CreateOrderHandler                  $orderHandler,
        private readonly OrderCreateRequestValidationService $orderCreateRequestValidationService,
        private readonly CheckoutSessionManagementService    $sessionIdentifierService,
        private readonly LocaleService                        $localeService,

    )
    {
    }

    /**
     * @throws Throwable
     */
    public function __invoke(CreateOrderRequest $request, int $eventId): JsonResponse
    {
        $tStart = hrtime(true);
        $this->orderCreateRequestValidationService->validateRequestData($eventId, $request->all());
        $tValidate = hrtime(true);

        $sessionId = $this->sessionIdentifierService->getSessionId();

        $order = $this->orderHandler->handle(
            eventId: $eventId,
            createOrderPublicDTO: CreateOrderPublicDTO::fromArray([
                'is_user_authenticated' => $this->isUserAuthenticated(),
                'promo_code' => $request->input('promo_code'),
                'affiliate_code' => $request->input('affiliate_code'),
                'products' => ProductOrderDetailsDTO::collectionFromArray($request->input('products')),
                'session_identifier' => $sessionId,
                'order_locale' => $this->localeService->getLocaleOrDefault($request->getPreferredLanguage()),
            ])
        );
        $tHandle = hrtime(true);

        $order->setSessionIdentifier($sessionId);

        $response = $this->resourceResponse(
            resource: OrderResourcePublic::class,
            data: $order,
            statusCode: ResponseCodes::HTTP_CREATED,
        );
        $tSerialize = hrtime(true);

        $serverTiming = sprintf(
            'validate;dur=%.1f, handle;dur=%.1f, serialize;dur=%.1f, total;dur=%.1f',
            ($tValidate - $tStart) / 1e6,
            ($tHandle - $tValidate) / 1e6,
            ($tSerialize - $tHandle) / 1e6,
            ($tSerialize - $tStart) / 1e6,
        );

        return $response
            ->header('Server-Timing', $serverTiming)
            ->header('Timing-Allow-Origin', '*')
            ->withCookie(
                cookie: $this->sessionIdentifierService->getSessionCookie(),
            );
    }
}
