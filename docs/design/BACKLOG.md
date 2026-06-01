# Feature Backlog

A living list of features and improvements we want to build. Append ideas here as they
come up (`/backlog "<idea>"`); move them to **Shipped** when they land. This is the
durable list — the [session handoff](./SESSION-HANDOFF.md) is the *current* in-flight
snapshot and links here, but does not own this list.

Each entry: `- [ ] <title> — <one-line what> (<date added>)`. Where a fuller rationale
exists, the entry points at the deeper note.

## Open ideas

- [ ] **Contact merge** — merge two duplicate contacts into one canonical record, reassigning their orders/attendees/history to the survivor and retiring the dup. Should be silenceable (see admin email suppression). (2026-05-31)

- [ ] **Admin email suppression** — let admins stop outbound notifications when editing email addresses: a per-modal warning+toggle, and/or a global per-session "no emails" switch for bulk back-office cleanup. Checked at the SES send boundary, fail-safe (default = emails on). (2026-05-31)

- [ ] **Orders payment-type filter** — filter the Orders page by payment type (CASH / CARD / CHECK / COMP / DONATION / Stripe), drawing on the `order_payments` ledger. (2026-05-31)

- [ ] **Escape-clears-filters everywhere** — the check-in page already clears filters on `Esc`; factor that into a shared hook applied to Orders, Contacts, and Attendees too. (SSR — guard `document`/keyboard listeners.) (2026-05-31)

- [ ] **Guard the payment card when an order is fully settled** — on the "Manage orders" payment panel, stop operators from blindly entering new transactions once the order is fully settled. Suggested UX: settled state renders the add-transaction form *locked* (disabled inputs + lock icon + "Fully settled" badge); clicking the lock pops a confirm ("This order is fully settled — add another transaction anyway?") and only then unlocks the form, making a second transaction deliberate. Unsettled orders behave as today. (2026-05-31)

- [ ] **Check-in overpayment → donation prompt** — when an operator records a payment greater than `total_gross`, prompt "Record the extra as a donation?" and on yes auto-write a second `DONATION` ledger row. Schema prerequisite (`order_payments` type/method split) landed `b7b51df9`; hook into `RecordOrderPaymentService` / the check-in & Manage-Payments UI. (2026-05-31)

## Shipped

- [x] **`order_payments` type-split + offline-column cleanup** — split the overloaded `type` column into `transaction_type` + `payment_method`, dropped legacy offline columns. (`b7b51df9`, 2026-05-31)
