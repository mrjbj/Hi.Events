---
name: handoff
description: Write or refresh a session-handoff document with a paste-ready rehydration prompt, so the user can /clear context and resume a long-running effort in a fresh session without losing state. Use when asked to "update the handoff", "write a handoff", "snapshot state before I clear", "make a rehydration/revival prompt", or to capture where a multi-session task stands.
---

# Session Handoff (+ rehydration prompt)

Produce a single Markdown doc a future session can read to resume work exactly where
it stands — committed code, locked decisions, what's next — capped with a **rehydration
prompt** the user pastes right after `/clear`.

## Output

A handoff doc, by default at **`docs/design/SESSION-HANDOFF.md`**. If the repo already
has one (or a design-doc dir with a different convention), **update that file in place** —
do not create a duplicate. If unsure where it belongs, ask once, then remember the answer.

## Routine

1. **Gather state — don't guess it.** Run, and fold the real output into the doc:
   - `git rev-parse --abbrev-ref HEAD` — current branch.
   - `git log --oneline -15` — recent commits (call out which are unpushed: `git log @{u}..` if an upstream exists).
   - `git status --short` — uncommitted/untracked work (flag it; a handoff over a dirty tree should say so).
   - Skim the governing design/spec docs the work refers to, so the "read these first" pointers are accurate.
2. **Confirm "what's next" with the user** if it isn't obvious from the work — the next step is the one thing you can't reliably infer from git.
3. **Write the doc** using the structure below. Keep it tight: a returning session needs
   the *decisions and pointers*, not a replay of everything.
4. **Refresh, don't append.** When updating an existing handoff, rewrite the stale sections
   (commits, status, next, rehydration prompt) rather than stacking new ones.
5. **Offer to commit it** (the user usually wants the handoff itself committed before they clear).

## Structure

```markdown
# Session Handoff — <effort name>

Snapshot for resuming after a context clear. Branch: **`<branch>`** (<pushed?>).

## What this work is
<2-4 sentences: the goal, the approach, the governing design doc to read first.>

## Commits (oldest→newest[, unpushed])
<numbered list of the relevant commits with one-line whats; include short hashes.>

## Status
- What's DONE and **verified** (name the test suites / smoke reports that prove it).
- Migrations/data caveats (e.g. schema-only, manual backfill).
- **Locked decisions** — the choices a fresh session must not relitigate, and why.

## Next (<phase/area>, not started)
<the concrete next pieces; note known gaps and why they're deferred.>

## Environment
<how to run the stack/tests; project-specific gotchas (e.g. npm-not-yarn, docker exec).>

---

## Rehydration prompt (paste after `/clear`)

> <One blockquoted paragraph the user can paste verbatim. It MUST: name the branch;
> point at the handoff doc + key design docs to read first; summarize what's done and
> what's next in 1-2 sentences; and instruct the new session to **confirm current state
> from git log before acting**. Keep it self-contained — the new session has no memory
> of this one.>
```

## Gotchas

- **The rehydration prompt is the payload.** It's what the user actually pastes, so it must
  stand alone: branch + docs-to-read + done/next + "verify from git first." Everything above
  it is reference the *next* session reads after pasting.
- **Pull git state live every time** — a handoff with stale commit hashes or a wrong branch is
  worse than none. Never carry forward the previous run's git numbers without re-checking.
- **Don't bloat it.** Link to design docs for detail; the handoff is a map, not the territory.
- **The backlog is a separate, durable file** (`docs/design/BACKLOG.md`, owned by `/backlog`).
  Don't copy its feature ideas into the handoff — the handoff gets rewritten each refresh and
  would clobber them. Just add a one-line pointer to it so a rehydrating session knows it's there.
- **A dirty tree is a fact, not a problem to hide** — if there's uncommitted work, say so and
  list it, so the next session doesn't assume a clean slate.
