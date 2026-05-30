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

## Status

- **Phases 1–3 DONE and visually smoke-tested** (report under `ops/smoke/payment-panel/`).
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

## Next (Phase 5, not started)

- `collected`/`outstanding` columns in `ops/sql/orders_export.sql`.
- Optional dashboard "cash collected" card.
- Auto-create explicit `DONATION` rows for overpayment + donations-in-excess reporting.
- Optional cleanup: route `MarkOrderAsPaid` fully through `ApplyOrderBalanceStatusService`.

## Smoke-report capability (reusable)

New skill **`.claude/skills/smoke-report/SKILL.md`** + generator **`ops/smoke/build-report.mjs`**:
drive the app with `playwright-cli`, screenshot each step, write a `manifest.json`, generate a
self-contained HTML report. Dev login used: `playwright@hi-events.test` / `SmokeTest123!`
(password set this session). Frontend SSR caches new modules — restart the `frontend` container
after adding new files. Example artifact: `ops/smoke/payment-panel/`.

## Environment

Dev stack runs via `docker/development/docker-compose.dev.yml` (app at https://localhost:8443,
backend :1234). Prod is Elestio via `ssh district11` (see memory `elestio-deploy-flow`). Use npm
not yarn. `mix assets.build`/`ash.migrate` global notes do NOT apply here (Laravel + Vite).

---

## Revival prompt (paste after `/clear`)

> Resume the Hi.Events payments-ledger work on branch `jbj/local`. Read
> `docs/design/SESSION-HANDOFF.md` and `docs/design/payment-at-checkin-ledger.md` for full
> context. Phases 1–3 (ledger schema, record-payment endpoint + status reconciliation,
> Manage-Order payments panel) are committed and smoke-tested; migrations are schema-only and
> data backfill/cleanup is manual. I want to start **Phase 5**: add `collected`/`outstanding`
> columns to `ops/sql/orders_export.sql`, [and/or] a dashboard "cash collected" card, [and/or]
> auto `DONATION` rows for overpayment. Confirm the current state from git log first, then
> propose a plan for the Phase 5 piece I named.
