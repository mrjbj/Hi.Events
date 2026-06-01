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
    vs payments vs cash-expected, per-channel.
16. `refactor(orders): split ledger transaction_type/payment_method + drop legacy offline columns`
    (`b7b51df9`) — **the split + offline-column cleanup, landed as one unit** (see below). Splits
    `order_payments.type` into `transaction_type` (PAYMENT|DONATION|COMP|WRITE_OFF) + `payment_method`
    (CASH|CHECK|CREDIT_CARD|BANK_TRANSFER|OTHER, null for comp/write-off), renames enum
    `OrderPaymentType`→`PaymentTransactionType`, rewrites `EventReconciliationService` to derive
    channel from the ledger `payment_method`, and drops `orders.offline_payment_method`/
    `offline_payment_reference` + the dead `order_payment_adjustments` table.
17. `ops(sql): archive District11 brunch order-total recovery script` (`3297f60a`) — moved the
    one-off `2026-05-30_brunch-cleanup.sql` into `ops/sql/` as history. **Last committed work.**

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
  `ops/smoke/payment-reversal/`. (The `OrderPaymentAdjustment*` domain-object classes that used to
  regenerate are gone for good now that the table was dropped in `b7b51df9`.)
- **Migrations are schema-only** (operator preference) — data backfill/cleanup is manual:
  - Run `ops/sql/backfill_order_payments.sql` by hand on prod for the few pre-ledger
    pay-at-check-in orders (else their balance reads as fully outstanding).
  - (The `order_payment_adjustments` table is now dropped via migration `2026_06_01_000100` in
    `b7b51df9` — no longer a manual step. The type-split migration `2026_06_02_000000` *does* backfill
    `order_payments` rows automatically, the one exception to schema-only.)
- **Locked decisions:** refunds stay in their own lane (`order_refunds` + `orders.total_refunded`
  cached rollup; balance reads the rollup ONLY — never sums both); rich payment UX (comp/
  donation/arbitrary) is **authenticated Manage-Order only**, NOT the unauthenticated public
  check-in door; `PARTIALLY_PAID` deferred (partial is shown from the balance).
- **Verified:** full Unit suite 610 green; 125 Order tests across unit+feature; live browser
  smoke of record-payment + comp-remainder round trip.

## DONE: `order_payments` type-split + offline-column cleanup (landed `b7b51df9`, 2026-05-31)

**The two formerly-intermingled efforts landed together as one coherent commit (`b7b51df9`).** The
working tree is clean; nothing here is in-flight. What shipped:

1. **`order_payments` type-split.** The overloaded `order_payments.type` is now two orthogonal axes:
   **`transaction_type`** (PAYMENT|DONATION|COMP|WRITE_OFF, not null) + **`payment_method`**
   (CASH|CHECK|CREDIT_CARD|BANK_TRANSFER|OTHER, null for comp/write-off — the `OfflinePaymentMethod`
   enum is reused for this axis). Enum `OrderPaymentType` renamed to `PaymentTransactionType` (and the
   old CARD↔CREDIT_CARD conversion is gone). Migration `2026_06_02_000000_split_…`. Reversals copy
   both axes (negative amount + `reverses_payment_id`; **no** REFUND type); DONATION **requires** a
   `payment_method`. `EventReconciliationService` now derives channel from the ledger `payment_method`
   — that removed the last reader of the offline columns, which is what unblocked the drop (#2).

2. **Offline-column + adjustments-table cleanup.** Dropped fork-only `orders.offline_payment_method`
   + `offline_payment_reference` and the dead `order_payment_adjustments` table. Migrations
   `2026_06_01_000000_drop_offline_payment_columns…` + `2026_06_01_000100_drop_order_payment_adjustments…`.
   `OrderResource` / `OrderDetails` lost the 2 fields + display blocks; `MarkOrderAsPaidService` no
   longer writes the offline columns.

**Validation (combined tree):** zero `offline_payment_method`/`offline_payment_reference` refs in
`backend/app`; zero old `OrderPaymentType` refs; no `OrderPaymentAdjustment*` files linger; pint clean
on hand-written files (the two `Generated/` abstracts flag `class_attributes_separation`, a
generator-wide quirk — never hand-edit them); 67 focused Order/Check-in/Reconciliation units green +
the parallel session's full **634 Unit green** + `tsc` clean on this checkout. Locales re-extracted
(English populated, non-English left empty per convention).

**⚠️ NOT YET DEPLOYED — these are live schema migrations.** Prod deploy (the only remaining step):
backup → `migrate` (runs `2026_06_01_000000` drop-offline-columns, `2026_06_01_000100`
drop-`order_payment_adjustments` **incl. its 5 prod recovery rows — intended**, `2026_06_02_000000`
type-split + backfill of existing `order_payments` rows) → verify the reconciliation widget loads.
The split's backfill maps each existing `type` to the new two-axis pair, so post-migrate balances and
channel attribution should be unchanged.

**District11 prod data cleanup — DONE & verified (2026-05-31).** The 5 adjustment-mangled door orders
had totals restored to the canonical $15 (600/669/671/674 via hand SQL; 599 already $15), then those 5
plus 4 other offline orders were re-recorded into the ledger: **9 orders — CASH $190 + CARD $15
(order 600 = Square) + DONATION $10 (599, 671) + COMP $15 (674) = $215 collected**, all settled,
attendees ACTIVE. The `order_payment_adjustments` prod rows are kept only until cleanup #2 deploys
(they're the recovery source). **Separately flagged:** 80 manually-created Aug-2025 BBQ orders
($20,157) show phantom "owing" in the ledger — bulk-imported, never paid through the system;
display-only; leave-vs-backfill deferred (memory `district11-manually-created-paid-orders-aug2025`).

## Next

> **Feature backlog** (durable, not part of this snapshot): see [`docs/design/BACKLOG.md`](./BACKLOG.md), maintained via `/backlog`.

**Immediate: deploy the split + cleanup to prod** (`b7b51df9`) — see the deploy note in the "DONE"
section above (backup → `migrate` → verify reconciliation widget). It's the only step between the
committed work and prod. Everything below is lower-urgency follow-up.

Money-correction plan (agreed earlier) — **A → B → C**, A & B done, C deferred:

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
> `docs/design/SESSION-HANDOFF.md` and `docs/design/payment-at-checkin-ledger.md` first. **The
> `order_payments` type-split + offline-column cleanup are DONE and committed** as one coherent unit
> (`b7b51df9` — `transaction_type`/`payment_method` split, enum `OrderPaymentType`→
> `PaymentTransactionType`, reconciliation derives channel from the ledger, and
> `orders.offline_payment_method`/`offline_payment_reference` + the `order_payment_adjustments` table
> dropped via migrations `2026_06_01_000000`/`000100`/`2026_06_02_000000`). A follow-up commit
> (`3297f60a`) archived the District11 brunch recovery SQL into `ops/sql/`. The working tree is clean;
> full validation green (634 Unit + tsc + pint on hand-written files). **The one remaining step is the
> PROD DEPLOY of those live schema migrations** (backup → `migrate` → verify the reconciliation widget
> loads — note the `order_payment_adjustments` drop also removes its 5 prod recovery rows, intended).
> District11 prod data cleanup (9 door orders re-recorded, $215 + one comp) is DONE & verified; the 80
> Aug-2025 BBQ "owing" orders are an imported-data display artifact, deferred. **Confirm current state
> from `git log` AND `git status` before acting.** Prior context still applies: money-correction plan
> A & B done / C deferred; Phase 5 reporting parked; smoke testing has a two-tier model (see
> "Smoke-report capability").
