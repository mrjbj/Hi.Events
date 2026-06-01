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
