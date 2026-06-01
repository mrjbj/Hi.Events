---
name: backlog
description: Add, list, or triage feature ideas in the project backlog (docs/design/BACKLOG.md). Use when the user says "jot this down", "add to the backlog", "feature idea:", "note an idea", "what's on the backlog", or "mark <X> shipped". The backlog is the durable list of things to build; it outlives any single session and is separate from the session handoff.
---

# Feature Backlog

Maintain **`docs/design/BACKLOG.md`** — the durable list of features/improvements we want
to build. This is distinct from the session handoff (`SESSION-HANDOFF.md`), which is an
ephemeral snapshot of *current* work and merely links here.

## Modes

The user's phrasing picks the mode:

- **Add** ("jot this down", "feature idea: …", "add X to the backlog") — append a new
  entry under **## Open ideas**.
- **List** ("what's on the backlog?", "show the backlog") — read the file and summarize
  the open ideas; don't edit.
- **Ship** ("mark X shipped", "X landed") — move that entry from **Open ideas** to
  **Shipped**, flip `- [ ]` to `- [x]`, and append the commit hash if known.
- **Triage / design** ("design the backlog", "flesh these out", "review the list") — read
  the open ideas and produce a design for each. See **Triage/design mode** below.

## Triage/design mode

These entries were *jotted down mid-flow* — quick captures, not deliberative specs. Treat
them more skeptically than a normal prompt: the user has explicitly said some ideas may be
half-formed, quirky, or plain bad, and wants you to push back, not just comply.

1. **Map before you design.** Read each idea's linked `project-*` memory, then verify the
   premise against the actual code (fan out `Explore` agents over the subsystems each idea
   touches). Quick captures often assume something the codebase contradicts — find that gap
   *before* writing a design.
2. **Challenge the premise.** For each idea ask: does the problem it describes actually
   exist in the code? Is there a simpler fix, or an existing mechanism that already covers
   it? Is it a footgun (a blunt global switch, a silent data mutation, an irreversible
   merge)? Say so plainly — "this may be unnecessary because …" is a valid design outcome.
   Recommend *not* building, or building smaller, when that's the honest call.
3. **Ask the blocking forks, default the rest.** Where an answer materially changes scope
   (conflict-resolution depth, global-vs-targeted, UI shape), ask via `AskUserQuestion`
   (lead with your recommended option). Where a sensible default exists, take it and note it
   — don't interrogate.
4. **Write designs to a doc, not the backlog.** Keep `BACKLOG.md` a terse list. Put the
   per-feature designs in `docs/design/backlog-designs.md` (problem · decision · approach
   backend/frontend · files to touch · edge cases · test plan · rough effort). Link each
   `BACKLOG.md` entry to its design section once written.
5. **Respect the house rules** (`CLAUDE.md`): Action→Handler→Service→Repository, no Eloquent
   outside repositories, `BaseAction` response helpers, `__()` on strings, DTOs extend
   `BaseDataObject`, `mix ash.migrate`-style generated migrations are N/A here (this is
   Laravel — use `php artisan make:migration` + `generate-domain-objects`), unit tests in
   `backend/tests/Unit/`. A design that violates these is wrong on arrival.

## Adding an entry

1. **Read `docs/design/BACKLOG.md` first** so you append in place and don't duplicate an
   existing idea (if it's a near-duplicate, sharpen the existing entry instead of adding).
   If the file doesn't exist yet, create it with `## Open ideas` and `## Shipped` sections.
2. Append under **## Open ideas** in the house format:
   `- [ ] **<short title>** — <one-line what; the why if non-obvious> (<today's date>)`
   Use the real current date — don't guess it.
3. **Capture enough to act on later, not a spec.** One or two sentences. If the user gives
   real depth (the why, where it hooks in, prerequisites), and it's worth surfacing in
   future sessions automatically, also write a `project-*` memory and link it — but the
   backlog entry itself stays terse.
4. Keep entries newest-relevant, not strictly chronological; group obviously-related ideas.

## Gotchas

- **The backlog is durable; the handoff is not.** Never fold the backlog into the handoff
  doc — the handoff skill rewrites itself on every refresh and would clobber the list.
- **Don't bloat entries.** The backlog is a list, not a design doc. Push detail into a
  linked `project-*` memory or a design doc; keep the line scannable.
- **Offer to commit** after editing (this doc is checked in), but don't commit unprompted.
- A quick capture should be *quick* — when the user is mid-flow jotting an idea, add it and
  confirm in one line; don't interrogate them for detail they didn't volunteer.
