<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Helper\IdHelper;
use HiEvents\Models\User;
use HiEvents\Services\Domain\Event\CreateEventService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Self-contained fixture for the Tier-2 payment-reversal smoke replay
 * (frontend/tests/smoke/payment-reversal.spec.ts).
 *
 * Builds a fresh account → user → organizer → event → product → offline
 * order graph in AWAITING_OFFLINE_PAYMENT, so the spec can record a cash
 * payment and reverse it without depending on incidental dev data. The
 * event (and its invariant-heavy event_settings) is created through the
 * real CreateEventService; the simpler tables are inserted directly.
 *
 * Idempotent: a fresh run (and --down) first deletes any prior fixture by
 * the marker email. Prints one FIXTURE_JSON= line the spec parses.
 */
class SmokeReversalFixtureCommand extends Command
{
    protected $signature = 'smoke:reversal-fixture {--down : Tear down the fixture and exit}';

    protected $description = 'Seed (or tear down) the self-contained order fixture for the payment-reversal smoke replay';

    private const MARKER_EMAIL = 'smoke-reversal@hi.events.test';

    private const PASSWORD = 'SmokeTest123!';

    public function handle(): int
    {
        $this->teardown();

        if ($this->option('down')) {
            $this->info('Fixture torn down.');

            return self::SUCCESS;
        }

        try {
            $fixture = DB::transaction(fn () => $this->build());
        } catch (Throwable $e) {
            $this->error('Fixture build failed: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());

            return self::FAILURE;
        }

        $this->line('FIXTURE_JSON='.json_encode($fixture));

        return self::SUCCESS;
    }

    private function build(): array
    {
        $user = User::factory()->password(self::PASSWORD)->withAccount()->create([
            'email' => self::MARKER_EMAIL,
            'first_name' => 'Smoke',
            'last_name' => 'Reversal',
        ]);
        $accountId = $user->accounts()->first()->id;

        // The Event model's creating hook reads auth()->user()->id.
        Auth::login($user);

        $organizerId = DB::table('organizers')->insertGetId([
            'account_id' => $accountId,
            'name' => 'Smoke Reversal Org',
            'email' => self::MARKER_EMAIL,
            'currency' => 'USD',
            'timezone' => 'America/New_York',
            'status' => 'DRAFT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organizer_settings')->insert([
            'organizer_id' => $organizerId,
            'homepage_visibility' => 'PUBLIC',
            'allow_search_engine_indexing' => true,
            'default_attendee_details_collection_method' => 'PER_TICKET',
            'default_show_marketing_opt_in' => true,
            'default_pass_platform_fee_to_buyer' => false,
            'default_allow_attendee_self_edit' => true,
            'tracking_consent_acknowledged' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventData = (new EventDomainObject)
            ->setTitle('Smoke Reversal Event')
            ->setOrganizerId($organizerId)
            ->setAccountId($accountId)
            ->setUserId($user->id)
            ->setStartDate('2026-06-01 18:00:00')
            ->setEndDate(null)
            ->setDescription('Smoke reversal fixture event')
            ->setTimezone('America/New_York')
            ->setCurrency('USD')
            ->setCategory('NIGHTLIFE')
            ->setStatus('LIVE')
            ->setLocationDetails(null)
            ->setAttributes(null);

        $event = app(CreateEventService::class)->createEvent($eventData);
        $eventId = $event->getId();

        $categoryId = DB::table('product_categories')->insertGetId([
            'event_id' => $eventId,
            'name' => 'General',
            'is_hidden' => false,
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = DB::table('products')->insertGetId([
            'title' => 'Smoke Ticket',
            'event_id' => $eventId,
            'product_category_id' => $categoryId,
            'type' => 'PAID',
            'product_type' => 'TICKET',
            'max_per_order' => 100,
            'min_per_order' => 1,
            'order' => 1,
            'sales_volume' => 0,
            'sales_tax_volume' => 0,
            'hide_before_sale_start_date' => false,
            'hide_after_sale_end_date' => false,
            'hide_when_sold_out' => false,
            'show_quantity_remaining' => false,
            'is_hidden_without_promo_code' => false,
            'is_hidden' => false,
            'start_collapsed' => false,
            'is_highlighted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productPriceId = DB::table('product_prices')->insertGetId([
            'product_id' => $productId,
            'price' => 1.57,
            'order' => 1,
            'quantity_sold' => 0,
            'is_hidden' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderPublicId = IdHelper::publicId(IdHelper::ORDER_PREFIX);
        $orderId = DB::table('orders')->insertGetId([
            'event_id' => $eventId,
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => $orderPublicId,
            'first_name' => 'Pat',
            'last_name' => 'Buyer',
            'email' => 'pat.buyer@example.com',
            'currency' => 'USD',
            'total_before_additions' => 1.57,
            'total_tax' => 0,
            'total_fee' => 0,
            'total_gross' => 1.57,
            'total_refunded' => 0,
            'taxes_and_fees_rollup' => '[]',
            'status' => 'AWAITING_OFFLINE_PAYMENT',
            'payment_status' => 'AWAITING_OFFLINE_PAYMENT',
            'payment_provider' => 'OFFLINE',
            'is_manually_created' => true,
            'locale' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_price_id' => $productPriceId,
            'item_name' => 'Smoke Ticket',
            'quantity' => 1,
            'price' => 1.57,
            'total_before_additions' => 1.57,
            'total_tax' => 0,
            'total_service_fee' => 0,
            'total_gross' => 1.57,
            'taxes_and_fees_rollup' => '[]',
            'product_type' => 'TICKET',
        ]);

        DB::table('attendees')->insert([
            'short_id' => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ATTENDEE_PREFIX),
            'first_name' => 'Pat',
            'last_name' => 'Buyer',
            'email' => 'pat.buyer@example.com',
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_price_id' => $productPriceId,
            'event_id' => $eventId,
            'status' => 'AWAITING_PAYMENT',
            'locale' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'email' => self::MARKER_EMAIL,
            'password' => self::PASSWORD,
            'eventId' => $eventId,
            'orderPublicId' => $orderPublicId,
        ];
    }

    private function teardown(): void
    {
        $userIds = DB::table('users')->where('email', self::MARKER_EMAIL)->pluck('id');
        if ($userIds->isEmpty()) {
            return;
        }

        $accountIds = DB::table('account_users')->whereIn('user_id', $userIds)->pluck('account_id');
        $eventIds = DB::table('events')->whereIn('account_id', $accountIds)->pluck('id');
        $orderIds = DB::table('orders')->whereIn('event_id', $eventIds)->pluck('id');
        $productIds = DB::table('products')->whereIn('event_id', $eventIds)->pluck('id');
        $organizerIds = DB::table('organizers')->whereIn('account_id', $accountIds)->pluck('id');

        DB::table('order_payments')->whereIn('order_id', $orderIds)->delete();
        DB::table('attendees')->whereIn('order_id', $orderIds)->delete();
        DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
        DB::table('orders')->whereIn('id', $orderIds)->delete();
        DB::table('product_prices')->whereIn('product_id', $productIds)->delete();
        DB::table('products')->whereIn('id', $productIds)->delete();
        DB::table('product_categories')->whereIn('event_id', $eventIds)->delete();
        DB::table('event_statistics')->whereIn('event_id', $eventIds)->delete();
        DB::table('event_settings')->whereIn('event_id', $eventIds)->delete();
        DB::table('events')->whereIn('id', $eventIds)->delete();
        DB::table('organizer_settings')->whereIn('organizer_id', $organizerIds)->delete();
        DB::table('organizers')->whereIn('id', $organizerIds)->delete();
        DB::table('account_users')->whereIn('user_id', $userIds)->delete();
        DB::table('accounts')->whereIn('id', $accountIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
    }
}
