# Design: Payment-at-Check-in as a Ledger (stop mutating the order)

Status: **Draft for review** · Owner: Jason · Scope: `jbj/local` fork · Date: 2026-05-30

## 1. Problem

"Pay at check-in" currently forces the **order** (a receivable) to equal the **cash typed in**
(a receipt). When a door agent enters an amount that differs from the order total,
`MarkOrderAsPaidService::applyAmountOverrideIfNeeded` + `rescaleOrderItems`:

- overwrite `orders.total_gross` and `total_before_additions`,
- rescale every `order_items` row (corrupting per-unit `price`),
- zero `total_tax` / `total_fee`,
- write an `order_payment_adjustments` row used as a *rewrite log*.

Concrete incident: order **O-JWATPQ5** (id 662, a $100 / 10-seat sponsor table) was rewritten to
**$15** when the agent typed $15 instead of $100. The order now lies about what was sold.

Root cause: **there is no payment ledger.** The system has nowhere to record "cash received"
except by mutating the thing owed. There is also no concept of a payment *transaction* that
offsets a balance.

### Secondary finding: `stripe_payments` is an intent table, not a receipts table

A `stripe_payments` row (a PaymentIntent) is minted **when the customer reaches the payment page**
(`CreatePaymentIntentHandler`), *before* they pick a method. Offline orders therefore carry a
dangling intent with `amount_received = NULL` and no `charge_id`. No money moved, but it means:

> **"Has a `stripe_payments` row" ≠ "paid online."** The reliable signal is
> `amount_received > 0` / `charge_id` present.

This is a design constraint for balance computation (below) and an optional hygiene item
(cancelling dangling intents), not a financial bug.

## 2. Decisions (locked)

| Decision | Choice |
|---|---|
| Phase 1 scope | **Offline / at-door only.** Stripe receipts stay in `stripe_payments`; unify rails later. |
| Dashboard "sales" basis | **Gross bookings** = `order.total_gross` (unchanged). |
| Order & payment status | **Derived from outstanding balance** (recomputed atomically). |
| Overpayment | Recorded as cash; excess **derived** (`overpaid`) for now. **Auto-create an explicit `DONATION` ledger row later** — it may be a *refundable* amount, and we want to **report donations-in-excess on the dashboard**. |
| Partial-pay attendee activation | **Governed by the existing door-admission setting** (`allow_orders_awaiting_offline_payment_to_check_in`), not by partial payment. |
| `NO_PAYMENT_REQUIRED` | **Keep** (already in prod; UI treats as settled). |
| `PARTIALLY_PAID` | **Defer** — "partial" is derived from the ledger balance; no new enum value yet. |
| Manage-order sidebar | Must **review both payments and adjustments**, with order status shown as a function of outstanding balance. |
| `order_payment_adjustments` table | **Keep for now** (frozen history). Remove only the *write* path in code; retire the table later per §8.1 checklist. |
| `orders_export.sql` | **Add `collected` / `outstanding` columns now.** |

## 3. Target model

Two separated concepts, **neither mutating the order**:

- **Order = receivable.** `total_gross` and line items are the authoritative value of what was
  sold. Immutable after placement except by an explicit refund. Drives the dashboard (unchanged).
- **Ledger = credits against the balance.** Every money event (cash, check, card, comp, write-off,
  refund) is a typed row. Balance is **derived**, never stored by rewriting the order.

```
amount_owed   = order.total_gross                       (immutable receivable)
credits       = Σ confirmed receipts + Σ comps/write-offs − Σ refunds
balance       = amount_owed − credits                   (derived)
payment_status, order.status = f(balance)               (recomputed atomically, in lockstep)
```

A `$15` cash entry against a `$100` table becomes a `$15` ledger row → balance `$85`. The order
still says `$100`. Because the dashboard reads `order.total_gross`, **it stays correct with zero
changes** — the moment we stop rewriting the order, the aggregate is right by construction.

### Credit types (single ledger, typed rows)

| `type` | Economic meaning | Counts as cash collected? | Settles balance? |
|---|---|---|---|
| `CASH` / `CHECK` / `CARD` / `BANK_TRANSFER` / `OTHER` | Money received at door / offline | Yes | Yes |
| `COMP` / `WRITE_OFF` | Deliberate forgiveness of the remainder (non-cash) | No | Yes |
| `DONATION` | Cash received **in excess** of owed | Yes (as donation) | n/a (excess) |
| `REFUND` (negative) | Money returned | Reduces collected | Reverses |

> **Overpay vs. comp are opposite directions.** A **comp** is a non-cash credit that settles the
> balance without money. A **donation/overpay** is *cash beyond owed*. Donation is therefore a
> **payment-ledger** row, **not** an `order_payment_adjustment` (which was a reduction of owed).
> Simplest representation: record the full cash receipt; `overpaid = max(0, Σcash − owed)` is
> derived and surfaced as "Overpaid / donation $X." Optionally split the excess into an explicit
> `DONATION` row if a donations report needs it.

## 4. Data model

### New: `order_payments` (the ledger)

```
id                  bigint identity
order_id            bigint     not null  -- FK orders
type                varchar(20) not null -- CASH|CHECK|CARD|BANK_TRANSFER|OTHER|COMP|WRITE_OFF|DONATION|REFUND
amount              numeric(14,2) not null -- credit against balance; REFUND negative
currency            varchar(3)  not null
reference           varchar(255)          -- check #, txn id, free-form
note                text
recorded_by_user_id bigint                -- operator (door agent / admin)
recorded_by_ip      varchar(45)
created_at          timestamptz not null
updated_at          timestamptz
deleted_at          timestamptz
```

### Existing tables — relationship

- `stripe_payments` — **online receipts**, read as confirmed only (`amount_received > 0`). Stays the
  Stripe source of truth in Phase 1 (not duplicated into `order_payments`).
- `order_refunds` — **refunds**, stays as-is; read as negative credits.
- `order_payment_adjustments` — **deprecate its rewrite role.** Its data is migrated/retired;
  going forward, comps/write-offs are `order_payments` rows. (Keep the table read-only for history,
  or drop after backfill — see §8.)

### `OrderBalanceService` (new — single read model)

```
owed       = order.total_gross
receipts   = Σ(order_payments where type in CASH,CHECK,CARD,BANK_TRANSFER,OTHER,DONATION)
           + Σ(stripe_payments.amount_received)/100        -- confirmed online
comps      = Σ(order_payments where type in COMP,WRITE_OFF)
refunds    = Σ(order_refunds where status='succeeded')
           + |Σ(order_payments where type=REFUND)|
collected  = receipts − refunds                            -- cash report
balance    = owed − receipts − comps + refunds
overpaid   = max(0, receipts − refunds − owed)             -- donation surfaced
```

## 5. Derived status rules

Recompute both statuses atomically whenever the ledger changes (preserves the existing no-drift
invariant — they are always written together):

| Condition | `payment_status` | `order.status` | Attendees |
|---|---|---|---|
| `owed == 0` | `NO_PAYMENT_REQUIRED` | `COMPLETED` | ACTIVE |
| `balance <= 0`, `owed > 0` | `PAYMENT_RECEIVED` | `COMPLETED` | ACTIVE |
| `0 < balance < owed` (partial) | `AWAITING_OFFLINE_PAYMENT` | `AWAITING_OFFLINE_PAYMENT` | **AWAITING_PAYMENT** |
| `balance == owed` (nothing paid) | `AWAITING_OFFLINE_PAYMENT` / `AWAITING_PAYMENT` | `AWAITING_OFFLINE_PAYMENT` | AWAITING_PAYMENT |
| Stripe failure | `PAYMENT_FAILED` | (unchanged) | — |

- **No `PARTIALLY_PAID`.** Partial is the middle row above; the badge shows "$X of $Y due" from the
  ledger. `payment_status` stays settled/not-settled.
- **Attendee activation** flips to ACTIVE only when `balance <= 0` (full settlement or full comp).
  Partial payment does **not** activate attendees; admitting a partially/un-paid guest at the door
  is the existing door-admission setting's job (records a check-in without changing status).
- **Overpay** (`balance < 0`) is still `PAYMENT_RECEIVED` + `COMPLETED`, with `overpaid` surfaced.

## 6. Code changes (backend)

| Area | Change |
|---|---|
| `MarkOrderAsPaidService` | **Delete** `applyAmountOverrideIfNeeded` + `rescaleOrderItems`. Order totals/line items are never rewritten. |
| "Mark as paid" (no amount) | Record one `order_payments` receipt = current balance → recompute status. Preserves one-click full settle. |
| **New** `RecordOrderPayment` (Action → Handler → Service) | Insert a ledger row (amount, type, reference, operator, ip); recompute status via `OrderBalanceService`. Used by door + admin. |
| **New** `OrderBalanceService` | Read model in §4; consumed by status recompute, resources, reports. |
| Check-in "pay at check-in" | Record a receipt for the entered amount; if short, prompt **Leave outstanding** vs **Comp remainder** (writes a `COMP` row). Never touch totals. |
| Stats | **No change** — increment stays on `order.total_gross` at order creation. |
| Resources (`AdminOrderResource`, `OrderResource`) | Add derived `amount_paid`, `balance`, `overpaid`, and the `payments[]` ledger. |
| Manage-order sidebar (frontend) | Show **Payments** (new ledger) alongside the existing **Adjustments** (`PaymentAdjustmentList`); render order status as a function of outstanding balance. Eventually repoint the adjustments view onto `order_payments` (`COMP`/`WRITE_OFF` rows). |

## 7. Downstream consumers (blast radius — from code audit)

Low-risk (no change needed):
- `payment_status` serialization in resources — additive only.
- `OrderStatusBadge` — already renders non-settled states; partial shows via derived balance text.
- Dashboard revenue — reads `total_gross`; untouched.

Touch carefully:
- Dashboard "paid orders" count filters `payment_status = PAYMENT_RECEIVED` — confirm partial
  (`AWAITING_OFFLINE_PAYMENT`) is *intended* to be excluded (it is).
- `PaymentIntentSucceededHandler` `in_array([AWAITING_PAYMENT, PAYMENT_FAILED])` — only matters if a
  partially-paid order can still pay the rest via Stripe. Out of scope Phase 1 (offline remainder),
  revisit if mixing rails.
- Check-in eligibility query (`o.status IN ('COMPLETED','AWAITING_OFFLINE_PAYMENT')`) — unchanged;
  partial orders remain `AWAITING_OFFLINE_PAYMENT` and admittable per the door setting.

## 8. Migration / backfill

1. **Create `order_payments`** (migration; integer id per project convention).
2. **Backfill receipts** for already-settled offline orders: for each order with
   `payment_status = PAYMENT_RECEIVED AND payment_provider = OFFLINE`, insert one `order_payments`
   row `type = offline_payment_method`, `amount = total_gross`, `reference = offline_payment_reference`.
   (Keeps balances at 0 under the new model.)
3. **Migrate `order_payment_adjustments`**: each real adjustment becomes the appropriate ledger
   row(s); then retire the rewrite path. Drop the table after verification, or keep read-only.
4. **Fix O-JWATPQ5 (id 662)** independently: restore `orders.total_gross`/`total_before_additions`
   to `100.00` and `order_items` 672 to `price 10.00 / totals 100.00` (canonical, matches orders
   539/661), then record one `CASH` receipt of `$100` (sponsor paid in full) → balance 0. Drop the
   bogus adjustment row (id 1). DB backup first.
5. **Optional hygiene**: cancel dangling Stripe intents on offline orders (no `amount_received`).
6. **Do not** run `rebuild_stats.sql` — stats already reflect gross bookings correctly; a global
   rebuild only risks other events.

### 8.1 `order_payment_adjustments` retirement — DONE in code (table retained)

The full code slice was removed (2026-05-30). The **DB table is intentionally left in place** for
manual deletion later. Along with it, the buggy amount-override path was removed: `mark as paid` /
`pay at check-in` now records a full settlement and **never rewrites order totals or line items**.

- [x] `MarkOrderAsPaidService` — removed `applyAmountOverrideIfNeeded` + `rescaleOrderItems` + the
      adjustment write + the `OrderItemRepository`/`OrderPaymentAdjustmentRepository` deps.
- [x] `MarkOrderAsPaidDTO` / `MarkOrderAsPaidAction` / `MarkOrderAsPaidRequest` / `Handler` —
      dropped `collectedAmount` / `adjustedByUserId` / `adjustedByIp` / `collected_amount`.
- [x] Check-in path — `AttendeeAndActionDTO`, `CreateAttendeeCheckInPublicRequest`,
      `CreateAttendeeCheckInService` no longer carry/pass a collected amount.
- [x] `OrderResource::payment_adjustments` field + `OrderPaymentAdjustmentResource` (deleted).
- [x] `Order::order_payment_adjustments()` relation + `GetOrderAction` `loadRelation(...)`.
- [x] `RepositoryServiceProvider` binding, `OrderPaymentAdjustmentRepository(Interface)`, `Model`,
      `OrderPaymentAdjustmentDomainObject` + generated `Abstract`, `OrderDomainObject` property/getter.
- [x] Frontend — deleted `PaymentAdjustmentList`, its `ManageOrderModal` accordion item, the
      check-in modal's amount input, and the `OrderPaymentAdjustment` type + `payment_adjustments` field.

**Remaining to fully retire:** drop the `order_payment_adjustments` DB table (manual), and note the
generated domain-object classes will reappear if `generate-domain-objects` runs while the table
still exists (they read tables, not models) — they clear permanently once the table is dropped and
domain objects are regenerated. When the ledger lands (Phase 1–3), the sidebar regains a Payments
view; legacy adjustment history is not migrated (only one prod row ever existed — the O-JWATPQ5 bug,
already corrected).

## 9. Open questions for review

1. Should `COMP` / `WRITE_OFF` require a reason string (fundraising audit)?
2. Donation handling timing — confirmed: derive `overpaid` in Phase 1; auto-create an explicit
   refundable `DONATION` row + dashboard "donations in excess" reporting in a later phase. Any
   constraint on when an excess becomes a donation vs. an expected over-collection?
3. When the manage-order sidebar shows "outstanding," should a non-zero balance also surface a
   one-click "record remaining payment / comp remainder" action inline?

## 10. Rollout phases

1. **Schema + read model** — `order_payments`, `OrderBalanceService`, resource fields. No behavior change.
   - **DONE (2026-05-30):** `order_payments` table + migration; `OrderPaymentType` enum; `OrderPayment`
     model + generated domain objects; `OrderPaymentRepository(Interface)` + provider binding;
     `OrderBalanceService` + `OrderBalanceDTO` (balance = owed − cash − stripe − comps + refunds, with
     `collected`/`overpaid`/`isSettled`); `OrderBalanceServiceTest` (7 cases: unpaid, exact, partial,
     comp, overpay, stripe, refund). **Deferred to Phase 4:** exposing balance on `OrderResource` —
     held until the receipts backfill (§8, step 2) lands, so existing offline-paid orders (no ledger
     rows yet) never surface a wrong balance.
2. **Record-payment path** — new action/handler; refactor `MarkOrderAsPaid` to write a ledger row;
   remove the rewrite. Status recompute.
3. **Check-in UX** — entered-amount → receipt; short-payment prompt (outstanding vs comp); overpay
   surfacing.
4. **Backfill + data fix** — §8 steps; retire `order_payment_adjustments` rewrite.
5. **Reporting** — collected/outstanding in exports; optional dashboard "cash collected" card.
