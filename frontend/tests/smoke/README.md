# Tier-2 smoke replays

Reproducible, committed `@playwright/test` specs that regenerate a smoke report's
screenshots deterministically on any laptop / in CI. The counterpart to the
agent-driven **Tier 1** smoke (see `.claude/skills/smoke-report/SKILL.md`): Tier 1 is
ephemeral and adaptive; Tier 2 is a self-contained test you can re-run forever.

Promote a flow to Tier 2 only when it's worth re-validating (e.g. money flows). Don't
make every smoke a maintained spec.

## Run

```bash
# dev stack must be up + migrated; restart frontend if a brand-new module was added
cd frontend
npm run test:smoke
```

Screenshots land in `ops/smoke/<name>/shots/` (gitignored). Rebuild the HTML report:

```bash
node ../ops/smoke/build-report.mjs \
  --manifest ../ops/smoke/payment-reversal/manifest.json \
  --out ../ops/smoke/payment-reversal/report.html
```

## What makes these deterministic (all four are required)

1. **Semantic locators, never refs** — `getByRole` / `getByText` / `getByLabel`. When a
   string can match a transient toast as well as the target (e.g. a "Payment reversed"
   toast vs. a "Reversed" badge), pin it with `{exact: true}` or a more specific locator.
2. **Own your seed data** — this repo has an empty `DatabaseSeeder` and no event/order
   factories, so a spec must build its own fixture and tear it down. The reference seeder
   is the artisan command `smoke:reversal-fixture` (backend), which prints a `FIXTURE_JSON=`
   line the spec parses, and `--down` to clean up. It drives the real `CreateEventService`
   for the invariant-heavy `event_settings` and inserts the simpler rows directly.
3. **Explicit waits, not `sleep`** — rely on auto-waiting locators + `expect(...).toBeVisible()`.
4. **Assert states, not pixels** — dates, relative times, and the random order `public_id`
   vary every run; never pixel-diff.

## Preconditions a spec does NOT own

- The docker dev stack is up and migrated.
- For a brand-new frontend module, the `frontend` container was restarted (Vite SSR
  module-graph cache).

## Reference

`payment-reversal.spec.ts` + `smoke:reversal-fixture` — the template for new Tier-2 specs.
