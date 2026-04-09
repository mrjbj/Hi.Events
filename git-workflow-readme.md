# Git Workflow: Fork + Rebase

## Remotes

| Name       | Repo                                  | Purpose            |
|------------|---------------------------------------|--------------------|
| `origin`   | `git@github.com:mrjbj/Hi.Events.git` | Your fork (push)   |
| `upstream` | `git@github.com:HiEventsDev/Hi.Events.git` | Source repo (pull) |

## Branch Strategy

```
upstream/develop  (source of truth - never commit here)
    |
    +-- develop  (local, tracks upstream - kept clean/synced)
    |     |
    |     +-- feature/*  or  fix/*   (PR branches, one per feature/fix)
    |     |
    |     +-- jbj/local              (your working copy - everything you want)
```

| Branch | Rule |
|--------|------|
| `develop` | Always mirrors `upstream/develop`. Never commit directly. |
| `feature/*`, `fix/*` | Fork from `develop`. One branch per PR. Rebase before submitting. |
| `jbj/local` | Your working copy. Rebased on develop. Has everything: merged PRs (via develop), rejected/pending PRs (cherry-picked in), and personal utilities. Push to `origin` for backup. |

### Why jbj/local exists

`develop` only contains what upstream has accepted. If you submit a PR and upstream rejects it
(or just hasn't merged it yet), that work disappears from `develop` on your next sync.

`jbj/local` is your **complete working copy** — it sits on top of develop and includes:
- Everything upstream has merged (via rebase onto develop)
- PRs that are pending or rejected but you still want (cherry-pick them in)
- Personal utilities that will never be PR'd (SQL scripts, cheatsheet, etc.)

This means `jbj/local` is always the most complete branch. Use it when you want
"everything I care about in one place."

### Current branches (as of 2026-04-09)

| Branch | Status |
|--------|--------|
| `feature/ses-bounce-handling` | SES bounce/complaint suppression. Needs SES simulator testing. |
| `fix/stripe-charge-refunded` | Stripe charge.refunded webhook handler. Nearly PR-ready. |
| `fix/image-resize-lost-on-save` | TipTap image resize fix. PR submitted to upstream. |
| `jbj/local` | Personal utilities + any unmerged work you want locally. |

---

## Scenarios

### Start of session — sync everything

```bash
# 1. Sync develop with upstream
git fetch upstream
git checkout develop
git merge --ff-only upstream/develop
git push origin develop

# 2. Rebase personal branch on top of latest develop
git checkout jbj/local
git rebase develop
git push --force-with-lease origin jbj/local
```

### Start new work

```bash
git checkout -b feature/my-thing develop
# ... make changes, commit ...
git push -u origin feature/my-thing
```

### Ready to submit a PR

```bash
# First, sync develop and rebase your branch
git checkout develop && git fetch upstream && git merge --ff-only upstream/develop
git checkout feature/my-thing
git rebase develop
git push --force-with-lease origin feature/my-thing

# Submit (or use the GitHub UI if gh token doesn't support cross-fork PRs)
gh pr create --repo HiEventsDev/Hi.Events --base develop \
  --title "feat: My thing" --body "Description here"

# GitHub UI alternative:
# https://github.com/HiEventsDev/Hi.Events/compare/develop...mrjbj:Hi.Events:feature/my-thing
```

### After upstream merges your PR

```bash
# Sync develop — your merged work now appears here
git fetch upstream
git checkout develop
git merge --ff-only upstream/develop
git push origin develop

# Delete the merged feature branch (it's in develop now)
git branch -d feature/my-thing
git push origin --delete feature/my-thing

# Rebase personal branch — it picks up the merged work automatically
git checkout jbj/local
git rebase develop
git push --force-with-lease origin jbj/local
```

### After upstream rejects your PR (but you still want the changes)

```bash
# Sync develop as usual
git fetch upstream
git checkout develop && git merge --ff-only upstream/develop && git push origin develop

# Cherry-pick the rejected work into your personal branch
git checkout jbj/local
git rebase develop
git cherry-pick <commit-hash>    # from the rejected feature branch
git push --force-with-lease origin jbj/local

# Optionally delete the rejected feature branch
git branch -d feature/rejected-thing
git push origin --delete feature/rejected-thing
```

Now the rejected work lives in `jbj/local` and stays with you even though
upstream didn't take it.

### Access personal files while on another branch

```bash
# View a file from jbj/local without switching branches
git show jbj/local:sql/rebuild_stats.sql

# Pipe a SQL script directly to psql
git show jbj/local:sql/rebuild_stats.sql | psql -d your_db
```

---

## Reference

### Handling rebase conflicts

If git pauses during rebase with conflicts:

```bash
# Edit the conflicted files, then:
git add <resolved-files>
git rebase --continue
```

To abort and return to pre-rebase state:

```bash
git rebase --abort
```

### Why `--force-with-lease`?

- `--force` overwrites the remote branch unconditionally, even if someone else pushed commits you haven't seen.
- `--force-with-lease` only overwrites if the remote branch matches what you last fetched. If it's changed, the push fails instead of silently destroying work.

Force is needed after rebase because rebase rewrites commit hashes. Since you're the only one using this fork, the risk is minimal, but `--force-with-lease` is a good habit.

### Why `--ff-only` on develop?

Fast-forward only merge ensures develop stays a clean mirror of upstream. If the merge
can't fast-forward, it means something was accidentally committed to develop — the merge
fails instead of silently creating a merge commit, so you can fix the mistake.
