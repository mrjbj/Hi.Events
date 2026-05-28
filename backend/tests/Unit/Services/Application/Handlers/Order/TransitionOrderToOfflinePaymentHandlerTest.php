<?php

namespace Tests\Unit\Services\Application\Handlers\Order;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\TransitionOrderToOfflinePaymentHandler;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Tests\TestCase;

class TransitionOrderToOfflinePaymentHandlerTest extends TestCase
{
    private TransitionOrderToOfflinePaymentHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new TransitionOrderToOfflinePaymentHandler(
            Mockery::mock(ProductQuantityUpdateService::class),
            Mockery::mock(OrderRepositoryInterface::class),
            Mockery::mock(DatabaseManager::class),
            Mockery::mock(EventSettingsRepositoryInterface::class),
            Mockery::mock(DomainEventDispatcherService::class),
            Mockery::mock(CheckoutSessionManagementService::class),
        );
    }

    public function test_validate_passes_when_offline_provider_enabled(): void
    {
        $order = $this->reservedOrder();
        $settings = $this->settingsWithProviders([PaymentProviders::OFFLINE->value]);

        $this->handler->validateOfflinePayment($order, $settings);

        $this->assertTrue(true); // no exception means pass
    }

    public function test_validate_throws_when_offline_provider_disabled_and_no_override(): void
    {
        $order = $this->reservedOrder();
        $settings = $this->settingsWithProviders([PaymentProviders::STRIPE->value]);

        $this->expectException(UnauthorizedException::class);

        $this->handler->validateOfflinePayment($order, $settings);
    }

    public function test_validate_skips_provider_check_under_admin_override(): void
    {
        $order = $this->reservedOrder();
        $settings = $this->settingsWithProviders([PaymentProviders::STRIPE->value]);

        $this->handler->validateOfflinePayment(
            order: $order,
            settings: $settings,
            skipOfflineProviderCheck: true,
        );

        $this->assertTrue(true);
    }

    public function test_validate_still_blocks_non_reserved_order_even_with_override(): void
    {
        $order = (new OrderDomainObject())
            ->setStatus(OrderStatus::COMPLETED->name)
            ->setReservedUntil(Carbon::now()->addMinutes(10)->toDateTimeString());
        $settings = $this->settingsWithProviders([PaymentProviders::STRIPE->value]);

        $this->expectException(ResourceConflictException::class);

        $this->handler->validateOfflinePayment(
            order: $order,
            settings: $settings,
            skipOfflineProviderCheck: true,
        );
    }

    public function test_validate_still_blocks_expired_reservation_even_with_override(): void
    {
        $order = (new OrderDomainObject())
            ->setStatus(OrderStatus::RESERVED->name)
            ->setReservedUntil(Carbon::now()->subMinutes(1)->toDateTimeString());
        $settings = $this->settingsWithProviders([PaymentProviders::STRIPE->value]);

        $this->expectException(ResourceConflictException::class);

        $this->handler->validateOfflinePayment(
            order: $order,
            settings: $settings,
            skipOfflineProviderCheck: true,
        );
    }

    private function reservedOrder(): OrderDomainObject
    {
        return (new OrderDomainObject())
            ->setStatus(OrderStatus::RESERVED->name)
            ->setReservedUntil(Carbon::now()->addMinutes(10)->toDateTimeString());
    }

    private function settingsWithProviders(array $providers): EventSettingDomainObject
    {
        return (new EventSettingDomainObject())->setPaymentProviders($providers);
    }
}
