---
name: smoke-report
description: Drive the running app with playwright-cli through a defined flow, capture a screenshot at each step, and assemble a single self-contained HTML report (screenshots embedded) for human visual validation. Use when asked to "smoke test", "visually verify", "capture screenshots into a report", or validate a UI change end-to-end without the human replicating it manually.
---

# Smoke Report (playwright-cli → self-contained HTML)

Produce a portable HTML report a human can open to visually validate a UI flow you
drove. Builds on the `playwright-cli` skill for browser control.

## Output

`ops/smoke/<name>/report.html` — one self-contained file with all screenshots
embedded as base64 (no external image deps; safe to open or email). Generated from a
`manifest.json` + PNGs by `ops/smoke/build-report.mjs`.

## Routine

1. **Confirm the app is up and your code is live.** Check the dev stack is running and
   reachable (e.g. `https://localhost:8443`). If you added NEW frontend files, the Vite
   **SSR** server caches its module graph — restart the frontend container so new modules
   load: `docker compose -f docker/development/docker-compose.dev.yml restart frontend`,
   then wait for HTTP 200. (Edits to existing files HMR fine; brand-new files often need
   the restart.)
2. **Get an auth path that doesn't rabbit-hole.** Use the stable smoke login instead of
   hunting for a dev user or resetting passwords by hand:
   `docker compose -f docker/development/docker-compose.dev.yml exec -T backend php artisan smoke:admin`
   It idempotently ensures a **SUPERADMIN** user — `smoke-admin@hi.events.test` /
   `SmokeAdmin123!` — so login and permissions are never the blocker. (Local-only: the
   command refuses to run outside the `local` env, so the fixed password can't reach prod.)
   Log in at `/auth/login` with those creds. Don't invent flows. Only fall back to a
   tinker password reset if you specifically need a *different* user's data.
3. **Seed deterministic test data** so the UI shows the states you want to capture
   (e.g. flip an order to AWAITING_OFFLINE_PAYMENT). **Capture the original values first
   and RESTORE them at the end** — leave dev as you found it.
4. **Make an output dir:** `mkdir -p ops/smoke/<name>/shots`.
5. **Drive the flow**, screenshotting each meaningful state to an ABSOLUTE path:
   `playwright-cli screenshot --filename=/abs/path/ops/smoke/<name>/shots/NN-step.png`.
   - Refs (`e123`) change on every snapshot — re-snapshot before each interaction, or use
     role/text locators (`"getByText('O-00H9VYK')"`, `"getByRole('textbox',{name:'…'})"`).
   - Resize for consistent shots: `playwright-cli resize 1440 1000`.
   - It's fine to `sleep 2-3` for spinners/toasts before a screenshot. Don't loop forever
     on env problems — if the app won't cooperate after a restart + one retry, stop and
     report what blocked you rather than burning tokens.
6. **Write `manifest.json`** (schema below), then generate:
   `node ops/smoke/build-report.mjs --manifest ops/smoke/<name>/manifest.json --out ops/smoke/<name>/report.html`
7. **Restore mutated data, `playwright-cli close`, and `open` the report** (macOS) so the
   human can review.

## manifest.json schema

```json
{
  "title": "Smoke Test — <feature>",
  "subtitle": "one line of context",
  "meta": { "App URL": "...", "Flow": "...", "Branch": "...", "...": "..." },
  "steps": [
    { "title": "...", "description": "what to look for", "status": "pass|fail|info",
      "screenshot": "shots/01-step.png", "notes": "optional callout" }
  ]
}
```

`status: "fail"` anywhere flips the report's overall badge to FAIL. `screenshot` paths are
relative to the manifest's directory.

## Gotchas (learned the hard way)

- **New frontend file not showing?** Vite SSR module-graph cache → restart the frontend
  container (step 1). A passing `tsc` + the file existing in the container is NOT proof the
  running server loaded it.
- **Element not appearing despite correct code?** Check the data actually feeds the
  condition — e.g. an accordion gated on `order.is_payment_required` silently hides when the
  API resource never returns that field. Visual smoke catches this; type-checks don't.
- **Screenshots save to CWD** unless you pass an absolute `--filename`.
- **Always restore** any dev rows you mutated; capture originals before changing them.

## Reusable generator

`ops/smoke/build-report.mjs` is generic — point it at any manifest. It assembles
`manifest.json` + the PNGs in `shots/` into a self-contained `report.html`. It does NOT
drive a browser — the shots must already exist on disk.

## Git policy — what's committed vs. ignored

Three artifacts, three shelf lives. `ops/smoke/.gitignore` enforces this:

- **Commit (durable):** `SKILL.md`, `build-report.mjs`, and each run's `manifest.json`.
  The manifest is the text record of *what story was validated and what each step should
  show* — it diffs in PRs and ages gracefully. Tier-2 replay specs (below) are committed too.
- **Gitignore, keep on disk (`**/shots/`, `**/report.html`):** screenshots and the
  assembled report are heavy, binary, and go stale the moment the UI changes. They're local
  review evidence, not history. **Do not delete them** — while they sit on disk you can
  rebuild `report.html` any time with `build-report.mjs`.

Consequence to be honest about: once `shots/` are gone (fresh clone, new laptop), the report
is **not** recreatable from the manifest alone — the manifest references PNGs that no longer
exist. From-scratch regeneration requires either re-running the Tier-1 agent flow or a
committed Tier-2 replay spec. The manifest is the assembly spec for the HTML, **not** a
browser replay script.

## Two tiers

- **Tier 1 — agent-driven smoke (this skill's default).** You drive the live app ad hoc,
  capture, build the report, human reviews. Ephemeral by nature; recreate by re-running.
  Lightweight and adaptive — don't over-engineer it.
- **Tier 2 — committed reproducible replay (opt-in, per flow worth re-validating).** A
  checked-in `@playwright/test` spec that regenerates the shots → report deterministically on
  any laptop / in CI. Promote a flow to Tier 2 deliberately; do not make it mandatory for
  every smoke. Reference: `frontend/tests/smoke/` + its seeder (see that dir's README).

### What makes a Tier-2 replay actually deterministic

Learned building the first one — all four are required, or it rots:

1. **Semantic locators, never refs.** `getByRole`/`getByText`/`getByLabel`. The interactive
   `eNNN` refs from Tier-1 change every snapshot and are not replayable.
2. **Own your seed data.** This repo has an **empty `DatabaseSeeder` and no event/order
   factories**, so a replay must build its own fixture (account/user/event/order graph) via
   the app's domain services or a dedicated seeder, then tear it down. Do NOT depend on
   incidental dev rows — they don't exist on a fresh DB. This is the hard part, not the clicks.
   For the **login** half specifically, `php artisan smoke:admin` gives every run the same
   stable SUPERADMIN (`smoke-admin@hi.events.test` / `SmokeAdmin123!`) — use it so auth/role
   is never the variable; specs that need domain data still build + tear down their own graph
   (see `smoke:reversal-fixture`).
3. **Explicit waits, not `sleep`.** Use auto-waiting locators + `expect(...).toBeVisible()`.
4. **Determinism = same *states*, not same *pixels*.** Dates, random `public_id`s, and
   relative times ("5 days ago") vary every run — assert on roles/text, never pixel-diff.

Preconditions a replay does NOT own: the dev stack must be up + migrated, and (for brand-new
frontend modules) the frontend container restarted. Document these; don't script them.
