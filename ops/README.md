# Git Workflow: Fork + Rebase

## Remotes

| Name       | Repo                                  | Purpose            |
|------------|---------------------------------------|--------------------|
| `origin`   | `git@github.com:mrjbj/Hi.Events.git` | Your fork (push)   |
| `upstream` | `git@github.com:HiEventsDev/Hi.Events.git` | Source repo (pull) |

## Branch Strategy

```
upstream/develop  (source of truth)
    |
    +-- develop  (local mirror of upstream - never commit directly)
    |     |
    |     +-- feature/*  or  fix/*   (PR branches, one per feature/fix)
    |     |
    |     +-- jbj/local              (production branch - everything you want)
```

| Branch | Rule |
|--------|------|
| `develop` | Always mirrors `upstream/develop`. Never commit directly. Only advances via `merge --ff-only`. |
| `feature/*`, `fix/*` | Fork from `develop`. One branch per PR. Keep atomic — one fix or feature per branch. |
| `jbj/local` | **Your production branch.** Rebased on develop. Includes everything: upstream's work, your pending/rejected PRs, and personal utilities. Deploy from here. |

### Why jbj/local exists

`develop` only contains what upstream has accepted. Your development will always be ahead of
upstream — you'll have fixes and features that are pending review, rejected, or personal. You
need a branch that has *all* of it.

`jbj/local` is that branch. It sits on top of develop and includes:
- Everything upstream has merged (comes in automatically via rebase onto develop)
- Your pending PRs, squash-merged in so you don't wait for upstream approval
- Rejected PRs you still want (same — they stay in jbj/local permanently)
- Personal utilities that will never be PR'd (SQL scripts, cheatsheet, etc.)

`jbj/local` is always the most complete branch and is what you deploy to production.

### Where to commit

| What you're doing | Where |
|---|---|
| Fix or feature you want upstream to consider | Branch from `develop`, work there, PR, then squash-merge into `jbj/local` |
| Personal stuff (scripts, docs, utilities) | Commit directly to `jbj/local` |
| Never | Commit to `develop` directly |

---

## Core Workflow: Build, PR, Ship, Move On

This is the common pattern. You work atomically — one fix or feature per branch — and
squash-merge each into jbj/local for your production without waiting for upstream.

### 1. Start a new fix or feature

```bash
git checkout -b fix/some-bug develop
# ... work, test, commit (as many commits as needed) ...
git push -u origin fix/some-bug
```

### 2. Submit PR to upstream

```bash
# Sync develop first, then rebase your branch to be current
git fetch upstream
git checkout develop && git merge --ff-only upstream/develop
git checkout fix/some-bug
git rebase develop
git push --force-with-lease origin fix/some-bug

# Submit via GitHub UI:
# https://github.com/HiEventsDev/Hi.Events/compare/develop...mrjbj:Hi.Events:fix/some-bug
```

### 3. Squash-merge into jbj/local (don't wait for upstream)

```bash
git checkout jbj/local
git merge --squash fix/some-bug
git commit -m "fix: some bug (pending upstream PR)"
git push --force-with-lease origin jbj/local
```

`--squash` collapses all the feature branch commits into a single clean commit on jbj/local.
This is better than cherry-picking individual commits — one command regardless of how many
commits the feature branch has.

### 4. Move on to the next thing

```bash
git checkout -b feature/next-thing develop
# ... repeat the cycle ...
```

You can have multiple feature branches in flight at once, each with a pending PR,
and jbj/local has all of them squash-merged in for your production.

---

## Lifecycle: What Happens to PRs Over Time

### Upstream merges your PR

```bash
# Start-of-session sync brings it into develop
git fetch upstream
git checkout develop && git merge --ff-only upstream/develop && git push origin develop

# Rebase jbj/local — the squashed copy may conflict with the now-merged version
git checkout jbj/local
git rebase develop
# If conflict on your squashed commit (because develop now has the same changes):
git rebase --skip    # drop YOUR copy, develop's version is now the official one
git push --force-with-lease origin jbj/local

# Clean up the feature branch
git branch -d fix/some-bug
git push origin --delete fix/some-bug
```

After this, jbj/local has the feature via develop (the upstream version) and your
redundant squashed copy is gone. Clean.

### Upstream rejects your PR (but you still want the changes)

No action needed — the squash-merged commit is already in jbj/local from step 3.
It stays there permanently through future rebases since develop never gets a
conflicting version. Just delete the feature branch:

```bash
git branch -d fix/rejected-thing
git push origin --delete fix/rejected-thing
```

### PR still pending (most common)

Do nothing. The feature branch stays open for the PR. Your squash-merged copy
in jbj/local means your production already has it. Move on.

---

## Start of Session — Sync Everything

Run this at the start of each session to stay current:

```bash
# 1. Sync develop with upstream
git fetch upstream
git checkout develop
git merge --ff-only upstream/develop
git push origin develop

# 2. Rebase jbj/local on latest develop
git checkout jbj/local
git rebase develop
# Resolve any conflicts (use --skip for squashed commits that upstream has now merged)
git push --force-with-lease origin jbj/local

# 3. Rebase any active feature branches (optional, only if stale)
git checkout feature/active-thing
git rebase develop
git push --force-with-lease origin feature/active-thing
```

---

## Quick Reference

### Access personal files from any branch

```bash
git show jbj/local:ops/sql/rebuild_stats.sql
git show jbj/local:ops/sql/rebuild_stats.sql | psql -d your_db
```

### Handling rebase conflicts

```bash
# Normal conflict — edit files, then:
git add <resolved-files>
git rebase --continue

# Squashed commit that upstream already merged — skip your copy:
git rebase --skip

# Something went wrong — abort and start over:
git rebase --abort
```

### Why `--force-with-lease`?

- `--force` overwrites the remote unconditionally.
- `--force-with-lease` only overwrites if the remote matches what you last fetched.

Force is needed after rebase because rebase rewrites commit hashes. Since you're the
only one using this fork, the risk is minimal, but `--force-with-lease` is a good habit.

### Why `--ff-only` on develop?

Ensures develop stays a clean mirror. If it can't fast-forward, something was
accidentally committed to develop — the command fails so you can fix it.

### Why `--squash` into jbj/local?

Squash collapses a multi-commit feature branch into one clean commit. This keeps
jbj/local history readable and makes it easy to `--skip` during rebase when upstream
eventually merges the same work.

### Current branches (as of 2026-04-09)

| Branch | Status |
|--------|--------|
| `feature/ses-bounce-handling` | SES bounce/complaint suppression. Needs SES simulator testing. |
| `fix/stripe-charge-refunded` | Stripe charge.refunded webhook handler. Nearly PR-ready. |
| `fix/image-resize-lost-on-save` | TipTap image resize fix. PR submitted to upstream. |
| `jbj/local` | Production branch. Personal utilities + all unmerged work. |
