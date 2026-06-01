# Feature Backlog

A living list of features and improvements we want to build. Append ideas here as they
come up (`/backlog "<idea>"`); move them to **Shipped** when they land. This is the
durable list — the [session handoff](./SESSION-HANDOFF.md) is the *current* in-flight
snapshot and links here, but does not own this list.

Each entry: `- [ ] <title> — <one-line what> (<date added>)`. Where a fuller rationale
exists, the entry points at the deeper note. Designs for all open ideas live in
[backlog-designs.md](./backlog-designs.md).

## Open ideas

_Nothing open right now — everything designed in [backlog-designs.md](./backlog-designs.md) has shipped (see below)._

## Shipped

- [x] **Reconciliation: editable Expenses + Gain/(Loss)** — a per-event manual Expenses figure on the Event Reconciliation card with `Gain/(Loss) = net_to_bank − expenses`. New `event_reconciliation_settings` table + `PATCH /events/{id}/reconciliation/expenses`; editable input added to the waterfall (right-aligned with the column, accounting parens + red for a loss) with a dirty-gated Save. (`a9fa8b9a`, alignment polish `0e4e0393`, 2026-06-01)

- [x] **Contact merge** — `MergeContactsAction → Handler → Service`: reassign the duplicate's attendees to the survivor, survivor-wins/fill-gaps for name + attributes, union question-id sets, merge history with a `merge` marker, soft-delete the dup (freeing its email). Merge modal with a gap-fill preview; history panel renders the merge entry. ([design](./backlog-designs.md#6-contact-merge)) (`a4fb43db`, 2026-06-01)

- [x] **Admin email suppression (per-action toggle)** — a "notify the previous address" toggle (default on) on the public self-service Edit Attendee / Edit Order modals; the check-in door already had its flag. ([design](./backlog-designs.md#5-admin-email-suppression--per-action-toggle-scoped-down)) (`92e521da`, 2026-06-01)

- [x] **Persistent email-suppression flag (address-keyed)** — new `DO_NOT_CONTACT` reason suppressing all send types, plus a configurable placeholder-pattern check (`config('mail.suppressed_address_patterns')`), both independent of the SES flag. The three change-notification mail sites now honor suppression; superadmin screen + a Contact "Never email" toggle. ([design](./backlog-designs.md#8-persistent-email-suppression-flag-address-keyed)) (`92e521da`, 2026-06-01)

- [x] **Bug: kebab click injects a stray char into the Orders filter** — root cause was the 300ms-debounced URL-synced value clobbering the focused filter input; the `SearchBar` mirror now never overwrites a focused field (but still honors deliberate clears). ([design](./backlog-designs.md#7-bug-kebab-click-injects-a-stray-char-into-the-orders-filter)) (`1c955487`, 2026-06-01)

- [x] **Orders payment-type filter** — single combined "Payment type" multi-select on the Orders page (Cash / Check / Card / Bank transfer / Comp / Donation / Stripe); backend `OrderRepository::applyPaymentTypeFilter()` routes each value to the right ledger column / relation. _Note: shipped without the repository unit tests the design called for — coverage added separately._ ([design](./backlog-designs.md#4-orders-payment-type-filter)) (`42c26ad9`, 2026-05-31)

- [x] **Escape-clears-filters everywhere** — shared `useEscapeClearsFilters` hook now consumed by check-in, Orders, Attendees, and Contacts. ([design](./backlog-designs.md#1-escape-clears-filters-everywhere)) (`42c26ad9`, 2026-05-31)

- [x] **Check-in overpayment → donation prompt** — payment exceeding outstanding prompts to record the excess as a `DONATION` row with the same method; wired through both the check-in door and the Manage-Payments panel, with backend split + unit tests. ([design](./backlog-designs.md#3-check-in-overpayment--donation-prompt)) (`42c26ad9`, 2026-05-31)

- [x] **Guard the payment card when an order is fully settled** — the Manage-Payments add-transaction form locks once `payment_balance.isSettled`; an explicit confirm unlocks it so a second transaction is deliberate. ([design](./backlog-designs.md#2-guard-the-payment-card-when-an-order-is-fully-settled)) (`42c26ad9`, 2026-05-31)

- [x] **Dashboard card layout + Funds-by-Channel alignment polish** — reordered cards (charts → reconciliation pair → mini-cards), vertically centered + narrowed the editable Fees input, shortened Channel/Fees columns so all 7 columns fit, stopped reconciliation tile labels wrapping. (`6889e6ac`, 2026-06-01)

- [x] **`order_payments` type-split + offline-column cleanup** — split the overloaded `type` column into `transaction_type` + `payment_method`, dropped legacy offline columns. (`b7b51df9`, 2026-05-31)
