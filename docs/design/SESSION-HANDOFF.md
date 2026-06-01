# Session Handoff — Payments Ledger + Smoke-Report Capability

Snapshot for resuming after a context clear. Branch: **`jbj/local`** (unpushed).

## What this work is

Replacing the old "pay at check-in" behaviour (which **rewrote order totals** and
corrupted orders — see the O-JWATPQ5 incident) with a **payments ledger**: the order is an
immutable receivable (`total_gross`), and payments/comps are typed credit rows that offset
a *derived* balance. Full design: **`docs/design/payment-at-checkin-ledger.md`** (read this
first — §5 status matrix, §10 phase log).

## Commits on `jbj/local` (oldest→newest, all unpushed)

1. `refactor(orders): drop legacy payment-adjustment override` — removed the order-mangling
   code + the whole `order_payment_adjustments` slice (DB table retained for manual drop).
2. `feat(orders): add payments ledger foundation` — `order_payments` table, `OrderPaymentType`
   enum, `OrderBalanceService` + DTO, repo/model. Read model only.
3. `feat(orders): record-payment path + status reconciliation` — `RecordOrderPaymentService`,
   `ApplyOrderBalanceStatusService`, `POST /events/{id}/orders/{id}/payments`; `MarkOrderAsPaid`
   writes a full-settlement ledger row.
4. `docs: fix balance formula refund double-count`.
5. `feat(orders): expose order balance` (backend) — `payment_balance` + `payments` on
   `OrderResource` via `GetOrderAction`; backfill is a MANUAL `ops/sql/backfill_order_payments.sql`.
6. `feat(orders): Manage-Order payments panel` (frontend) — the `OrderPaymentManagement` panel.
7. `chore(smoke): reusable playwright→HTML smoke-report skill` — the `smoke-report` skill +
   `ops/smoke/build-report.mjs` generator (artifact `ops/smoke/payment-panel/`).
8. `feat(checkin,orders): door amount-received + required comp reason` (`d633765a`) — see below.
9. `feat(orders): reverse offline payment ledger entries — Phase B` (`ca613e25`) — see below.
10. `docs: refresh session handoff for Phase B payment reversal` (`debe5392`).
11. `chore(smoke): gitignore shots, document commit-vs-ignore policy + two-tier model` (`91e79489`)
    — smoke-report git policy + tiers (see "Smoke-report capability" below).
12. `test(smoke): Tier-2 reproducible payment-reversal replay (@playwright/test)` (`1ef80a50`)
    — first committed Tier-2 replay (see below).
13. `feat(orders): surface Stripe refund in the payments panel — Phase A` (`b33a7db4`) — money-
    correction Phase A (see "Next" below).
14. `skills update` (`6f566456`).
15. `feat(dashboard): Event Reconciliation widget with by-channel breakdown` (`a2c0ec34`) — gross
    vs payments vs cash-expected, per-channel. **Last committed work. NOTE: its
    `EventReconciliationService` still reads `orders.offline_payment_method` in raw SQL — this is
    the blocker described in the in-flight section below.**

## Status

- **Phases 1–3 DONE and visually smoke-tested** (report under `ops/smoke/payment-panel/`).
- **Door amount-received + required comp reason DONE (commit `d633765a`)** — the door's "record
  payment" now takes the **amount actually collected** (defaults to order total; method +
  reference + recorded IP = reconciliation memo). Boundary: **the door may add reconcilable
  money; only the authenticated manage-order surface may forgive it (comp).**
  `CreateAttendeeCheckInService::recordDoorPayment` branches on balance — settling (≥ balance) →
  `MarkOrderAsPaidService` (receipt/invoice/app-fee, activates attendee, overpay = donation);
  short → `RecordOrderPaymentService` (partial; order stays awaiting, attendee admitted not
  activated). Comp/write-off now **require a reason** (server-side `RecordOrderPaymentRequest` +
  UI modal; reason renders in the ledger table). Tests: `CreateAttendeeCheckInServiceTest`,
  `RecordOrderPaymentRequestTest`, `MarkOrderAsPaidServiceTest` (618 unit green). Smoke reports:
  `ops/smoke/checkin-payment/` (door pay-vs-comp boundary) + `ops/smoke/checkin-payment-amount-comp/`
  (amount-received + comp reason). Design doc §6, §9.1 (resolved), §10 Phase 3 update.
- **Payment reversal DONE (Phase B, commit `ca613e25`)** — correcting a mistakenly recorded
  offline payment books a **linked, negative-amount row of the same type** (`reverses_payment_id`
  → original) instead of deleting it: the original stays as the audit trail, and `OrderBalanceService`
  (sums signed amounts) restores the balance with **no order-total changes**. Reversals **require a
  reason**; guards reject reversing a reversal or double-reversing. **Stripe receipts are not ledger
  rows** and are corrected via a refund, never reversed here, so the per-row "Reverse" affordance
  only appears on offline ledger rows. Backend: migration `…193000_add_reverses_payment_id…`,
  `ReverseOrderPaymentService` (+ DTO/Handler/`ReverseOrderPaymentRequest`/Action), route
  `POST /events/{id}/orders/{id}/payments/{paymentId}/reverse`, `reverses_payment_id` on
  `OrderPaymentResource`. Frontend: reason modal, reversal rows red/negative, reversed originals
  struck-through + "Reversed" badge; `useReverseOrderPayment`. Also accentuated the low-emphasis
  "Comp remaining" control (dashed border + gift icon). Tests: `ReverseOrderPaymentServiceTest` (7),
  `ReverseOrderPaymentRequestTest` (2); full Unit suite **627 green**. Smoke (6 steps, all pass):
  `ops/smoke/payment-reversal/`. **Note:** running `generate-domain-objects` regenerated the
  `OrderPaymentAdjustment*` domain-object classes (table not yet dropped — §8.1); they were deleted
  from this commit and will keep reappearing until the table is dropped.
- **Migrations are schema-only** (operator preference) — data backfill/cleanup is manual:
  - Run `ops/sql/backfill_order_payments.sql` by hand on prod for the few pre-ledger
    pay-at-check-in orders (else their balance reads as fully outstanding).
  - Drop the `order_payment_adjustments` table by hand when ready (§8.1 of the design doc).
- **Locked decisions:** refunds stay in their own lane (`order_refunds` + `orders.total_refunded`
  cached rollup; balance reads the rollup ONLY — never sums both); rich payment UX (comp/
  donation/arbitrary) is **authenticated Manage-Order only**, NOT the unauthenticated public
  check-in door; `PARTIALLY_PAID` deferred (partial is shown from the balance).
- **Verified:** full Unit suite 610 green; 125 Order tests across unit+feature; live browser
  smoke of record-payment + comp-remainder round trip.

## In-flight: `order_payments` type-split + offline-column cleanup (DIRTY TREE — do NOT commit yet)

**As of 2026-05-31 the working tree mixes TWO intermingled, uncommitted efforts in one checkout.
Do not commit or stash until the split (#1) is complete and validated — a stash would capture
both, and a commit would ship a broken intermediate.**

1. **`order_payments` type-split (parallel session — the primary in-flight refactor).** Splits the
   overloaded `order_payments.type` into **`transaction_type`** (PAYMENT|DONATION|COMP|WRITE_OFF) +
   **`payment_method`** (CASH|CHECK|CARD|BANK_TRANSFER|OTHER, nullable for comp/write-off). Renames
   enum `OrderPaymentType` → `PaymentTransactionType`. Migration
   `2026_06_02_000000_split_order_payments_type_into_transaction_type_and_method.php`. Touches every
   ledger writer/reader: `OrderBalanceService`, `RecordOrderPaymentService`(+Action/Request/DTO/
   Handler), `ReverseOrderPaymentService`, `CreateAttendeeCheckInService`, `OrderPaymentResource`,
   `MarkOrderAsPaidService`, `EventReconciliationService`, `OrderPaymentManagement`, `types.ts`.
   Locked decisions: reversals stay negative-amount + `reverses_payment_id` (**no** REFUND type);
   DONATION **requires** a `payment_method`; full end-to-end build. **This rewrites
   `EventReconciliationService::ORDER_CHANNEL_SQL` to derive channel from the ledger `payment_method`
   instead of `orders.offline_payment_method` — which is exactly what unblocks the column drop (#2).**

2. **Offline-column + adjustments-table cleanup (this thread).** Drops fork-only
   `orders.offline_payment_method` + `offline_payment_reference` and the dead
   `order_payment_adjustments` table. Migrations `2026_06_01_000000_drop_offline_payment_columns_from_orders_table.php`
   + `2026_06_01_000100_drop_order_payment_adjustments_table.php` (**both already run in the dev DB**).
   Code edits: `OrderResource` (drop 2 fields), `MarkOrderAsPaidService` (drop the offline-column
   writes; rename `updateOrderStatusAndMethod`→`updateOrderStatusAndProvider`), `OrderDomainObjectAbstract`
   (regenerated — offline getters/consts gone), `MarkOrderAsPaidServiceTest` (assertions re-homed to
   the ledger row — 3/3 green), `OrderDetails/index.tsx` (drop helper + display blocks). The `types.ts`
   offline-field removal already rode into commit `a2c0ec34`. Orphaned `OrderPaymentAdjustment*`
   domain-object files deleted. Validation done in isolation: pint clean, unit test green, my two
   frontend files add zero `tsc` errors (frontend baseline has ~92 pre-existing dep/WIP errors).

**Why blocked / sequencing:** dropping `orders.offline_payment_method` **500s the reconciliation
widget** while `EventReconciliationService::ORDER_CHANNEL_SQL` (raw SQL, ~lines 38-44) still reads it.
The split (#1) removes that last reader. So **land + validate the split first, then the column drop is
safe.** Also `SmokeReversalFixtureCommand.php:175` still *writes* `offline_payment_method='CASH'` —
fix/remove before the drop. The `order_payment_adjustments` drop is independent and safe anytime.

**Remaining steps to finish the cleanup (after the split lands):**
1. `grep -rn 'offline_payment_method\|offline_payment_reference' backend/app` → must be **zero** (the
   reconciliation raw SQL + `SmokeReversalFixtureCommand` are the known holdouts; the
   `EventSetting` `offline_payment_instructions` field is a different, unrelated column — leave it).
2. Confirm `EventReconciliationService` channel + donation/comp breakdowns derive from
   `transaction_type`/`payment_method`; `EventReconciliationServiceTest` green.
3. Keep migrations `2026_06_01_000000` + `000100`; re-run `migrate` + `generate-domain-objects`;
   verify `OrderDomainObjectAbstract` has no offline fields and no `OrderPaymentAdjustment*` files
   reappear (they regenerate while the table exists — drop runs in `000100`).
4. `pint --test` + Unit suite green; frontend `tsc` (only that my files add no new errors).
5. Commit the cleanup **with or after** the split as one coherent unit. Deploy: backup → `migrate`
   (drops the 2 columns + `order_payment_adjustments` **incl. its 5 prod rows** — intended) → verify.

**District11 prod data cleanup — DONE & verified (2026-05-31).** The 5 adjustment-mangled door orders
had totals restored to the canonical $15 (600/669/671/674 via hand SQL; 599 already $15), then those 5
plus 4 other offline orders were re-recorded into the ledger: **9 orders — CASH $190 + CARD $15
(order 600 = Square) + DONATION $10 (599, 671) + COMP $15 (674) = $215 collected**, all settled,
attendees ACTIVE. The `order_payment_adjustments` prod rows are kept only until cleanup #2 deploys
(they're the recovery source). **Separately flagged:** 80 manually-created Aug-2025 BBQ orders
($20,157) show phantom "owing" in the ledger — bulk-imported, never paid through the system;
display-only; leave-vs-backfill deferred (memory `district11-manually-created-paid-orders-aug2025`).

## Next

Money-correction plan (agreed this session) — **A → B → C**, A & B done, C deferred:

- **A (DONE, `b33a7db4`) — relocate the existing Refund UX into the payments panel.** Surfaced
  `RefundOrderModal` (unchanged: partial + full + notify + cancel) inside `OrderPaymentManagement`
  as a panel-level "Refund" + per-Stripe-receipt affordance, same guard predicate as the OrdersTable
  kebab (Stripe provider, not free, not awaiting-offline, not fully refunded); kebab stays as a
  list-level shortcut. Panel refetches via `onUpdated()` on close. Partial refunds repeatable. Smoke:
  `ops/smoke/refund-in-panel/` (6 steps pass).
- **B (DONE, `ca613e25`) — offline payment reversal.** See status above.
- **C (DEFERRED, 2026-05-31) — offline-refund recording + `total_refunded` reconciler. NOT being
  built now.** Decided this is not needed yet. C's only unique value is **categorization** —
  recording "real offline money was returned" as a *refund* distinct from a *reversal* (Phase B =
  un-doing a receipt that never truly moved). It adds **no balance correctness**: reversing the
  offline payment already drops `collected` by the same amount. Skipping it avoids a confusing
  **third money verb** (two named "Refund") at the door. **Stopgap:** use **reversal** for the rare
  offline cash-return. **Footgun:** reversal re-opens the order as `AWAITING_OFFLINE_PAYMENT` (negative
  row → balance goes positive again), so a refunded order can resurface in unpaid lists as if it still
  owes — fine for a SUPERADMIN who knows, but conflates "refunded" with "voided" in reporting. The
  reconciler half is a no-op without offline refund rows (Stripe webhook already keeps `total_refunded`
  correct), so it travels with C. **Revisit when** offline cash-refunds get frequent, or reports must
  split "refunded" vs "voided". Full rationale: design doc §9 item 4. If/when built: keep the Stripe
  webhook bump as-is (idempotency-guarded by `refund_id`; high upstream-merge-risk hot path); write an
  `order_refunds` row (`provider=OFFLINE`) + bump `total_refunded` in one transaction; add a
  `total_refunded = SUM(order_refunds succeeded)` reconciler as a self-heal.

Parked Phase 5 reporting (lower urgency than correctness above):

- `collected`/`outstanding` columns in `ops/sql/orders_export.sql` (line-item-grained; add via a
  `LEFT JOIN LATERAL` over `order_payments`, mirroring `OrderBalanceService`).
- Optional dashboard "cash collected" card.
- Auto-create explicit `DONATION` rows for overpayment + donations-in-excess reporting.
- Optional cleanup: route `MarkOrderAsPaid` fully through `ApplyOrderBalanceStatusService`.

**Known small gap (door amount-received):** the door defaults "Amount received" to
`order_total_gross`, which equals true outstanding for fresh awaiting orders but reads high if a
partial was already recorded before the modal reopens (agent overrides it; server reconciles
correctly). Closing it = surface true outstanding on `AttendeeWithCheckInPublicResource` (costs a
per-row balance computation in the check-in list). Deferred — not worth the N+1 yet.

## Smoke-report capability (reusable) + two-tier model

Skill **`.claude/skills/smoke-report/SKILL.md`** + generator **`ops/smoke/build-report.mjs`**:
drive the app with `playwright-cli`, screenshot each step, write a `manifest.json`, generate a
self-contained HTML report. Dev login used: `playwright@hi-events.test` / `SmokeTest123!`
(password set this session). Frontend SSR caches new modules — restart the `frontend` container
after adding new files. Example artifact: `ops/smoke/payment-panel/`.

**Git policy (A, commit `91e79489`).** `ops/smoke/.gitignore` ignores `**/shots/` + `**/report.html`;
the 25 previously-tracked PNGs were untracked (kept on disk). **Committed = durable:** `SKILL.md`,
`build-report.mjs`, each run's `manifest.json`, and Tier-2 specs. **Ignored = local evidence:**
shots + report (rebuild `report.html` from `manifest.json` + shots while they're on disk). Honest
caveat baked into the skill: the manifest is the HTML *assembly spec*, **not** a browser replay
script — from a fresh clone, regenerating shots from scratch needs a Tier-2 spec.

**Two tiers (documented in the skill):**
- **Tier 1 — agent-driven smoke (default).** Ephemeral; recreate by re-running the agent. Lightweight.
- **Tier 2 — committed `@playwright/test` replay (opt-in, commit `1ef80a50`).** Self-seeding e2e spec
  that regenerates the report deterministically on any laptop / CI. Reference:
  **`frontend/tests/smoke/payment-reversal.spec.ts`** + the backend **`smoke:reversal-fixture`** artisan
  command (builds/idempotently tears down a self-contained account/user/event/offline-order graph —
  drives the real `CreateEventService` for `event_settings`, inserts simpler rows directly — and prints
  a `FIXTURE_JSON=` line). Run with `cd frontend && npm run test:smoke` (proven green 3× consecutively,
  ~2.2s each), then rebuild the report via `build-report.mjs`. The four determinism conditions
  (semantic locators, owned seed data, explicit waits, assert-states-not-pixels) live in
  `frontend/tests/smoke/README.md`. **Why a seeder, not factories:** `DatabaseSeeder` is empty and only
  4 factories exist (Account, AccountVatSetting, Order, User) — no event/order graph factories. Promote
  a flow to Tier 2 only when it's worth re-validating; don't make every smoke a maintained spec.
- **Lockfile note:** the repo tracks `yarn.lock` (now includes `@playwright/test`); a stray
  `package-lock.json` from local `npm install` is not committed and will reappear if you re-run npm.

## Environment

Dev stack runs via `docker/development/docker-compose.dev.yml` (app at https://localhost:8443,
backend :1234). Prod is Elestio via `ssh district11` (see memory `elestio-deploy-flow`). Use npm
not yarn. `mix assets.build`/`ash.migrate` global notes do NOT apply here (Laravel + Vite).

---

## Revival prompt (paste after `/clear`)

> Resume the Hi.Events payments-ledger work on branch `jbj/local`. Read
> `docs/design/SESSION-HANDOFF.md` — **especially the "In-flight: `order_payments` type-split +
> offline-column cleanup" section** — and `docs/design/payment-at-checkin-ledger.md` first.
> **The working tree is DIRTY with TWO intermingled, uncommitted efforts. Do NOT commit or stash
> until you confirm the split below is complete and validated.** (1) A parallel session is
> splitting `order_payments.type` into `transaction_type` + `payment_method` (renames enum
> `OrderPaymentType`→`PaymentTransactionType`, migration `2026_06_02_000000…`, and rewrites
> `EventReconciliationService` to derive channel from the ledger). (2) This thread drops fork-only
> `orders.offline_payment_method`/`offline_payment_reference` + the `order_payment_adjustments`
> table (migrations `2026_06_01_000000`/`000100`, already run in dev). The column drop is
> **sequenced behind** the split: dropping `offline_payment_method` 500s the reconciliation widget
> until the split removes the last raw-SQL reader of it (and `SmokeReversalFixtureCommand.php:175`,
> which still writes it). District11 prod data cleanup (9 door orders re-recorded into the ledger,
> $215 + one comp) is **DONE & verified**; the 80 Aug-2025 BBQ "owing" orders are an imported-data
> display artifact, deferred. **Confirm current state from `git log` AND `git status` before
> acting** (the tree changes as the parallel session works), then finish cleanup #2 via the
> "Remaining steps" checklist once the split has landed. Prior context still applies: money-
> correction plan A & B done / C deferred; Phase 5 reporting parked; smoke testing has a two-tier
> model (see "Smoke-report capability").
