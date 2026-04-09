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
    |     +-- jbj/local              (personal stuff, never PR'd)
```

| Branch | Rule |
|--------|------|
| `develop` | Always mirrors `upstream/develop`. Never commit directly. |
| `feature/*`, `fix/*` | Fork from `develop`. One branch per PR. Rebase before submitting. |
| `jbj/local` | Personal utilities (SQL scripts, cheatsheet, etc). Push to `origin` for backup only. |

### Current branches (as of 2026-04-09)

| Branch | Status |
|--------|--------|
| `feature/ses-bounce-handling` | SES bounce/complaint suppression. Needs SES simulator testing. |
| `fix/stripe-charge-refunded` | Stripe charge.refunded webhook handler. Nearly PR-ready. |
| `fix/image-resize-lost-on-save` | TipTap image resize fix. PR-ready. |
| `jbj/local` | Personal utilities. Never PR. |

## Daily Workflow

### Sync develop with upstream

```bash
git fetch upstream
git checkout develop
git merge --ff-only upstream/develop
git push origin develop
```

Use `--ff-only` so it fails if you accidentally committed to develop.

### Start new work

```bash
git checkout -b feature/my-thing develop
# ... make changes, commit ...
git push -u origin feature/my-thing
```

### Rebase a feature branch before PR

```bash
git checkout feature/my-thing
git rebase develop
git push --force-with-lease origin feature/my-thing
```

### Submit a PR to upstream

```bash
gh pr create --repo HiEventsDev/Hi.Events --base develop \
  --title "feat: My thing" --body "Description here"
```

### Keep personal branch up to date

```bash
git checkout jbj/local
git rebase develop
git push --force-with-lease origin jbj/local
```

### Access personal files while on another branch

```bash
# View a file from jbj/local without switching branches
git show jbj/local:sql/rebuild_stats.sql

# Or keep a persistent worktree (a second checkout of jbj/local)
git worktree add ../Hi.Events-personal jbj/local
```

## Handling Rebase Conflicts

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

## Why `--force-with-lease`?

- `--force` overwrites the remote branch unconditionally, even if someone else pushed commits you haven't seen.
- `--force-with-lease` only overwrites if the remote branch matches what you last fetched. If it's changed, the push fails instead of silently destroying work.

Force is needed after rebase because rebase rewrites commit hashes. Since you're the only one using this fork, the risk is minimal, but `--force-with-lease` is a good habit.
