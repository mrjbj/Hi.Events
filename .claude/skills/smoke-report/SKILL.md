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
2. **Get an auth path that doesn't rabbit-hole.** Reuse an existing dev/test user; set a
   known password via tinker if needed (`Hash::make(...)`, note it). Don't invent flows.
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

`ops/smoke/build-report.mjs` is generic — point it at any manifest. The report is
regenerable from `shots/` + `manifest.json`, so the large `report.html` need not be
committed (gitignored); the screenshots + manifest + generator are the durable artifact.
