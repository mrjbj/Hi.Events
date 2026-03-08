# Git Workflow: Fork + Rebase

## Remotes

| Name       | Repo                                  | Purpose            |
|------------|---------------------------------------|--------------------|
| `origin`   | `git@github.com:mrjbj/Hi.Events.git` | Your fork (push)   |
| `upstream` | `git@github.com:HiEventsDev/Hi.Events.git` | Source repo (pull) |

## Sync & Work Cycle

```bash
# 1. Get latest upstream
git fetch upstream

# 2. Rebase your commits on top of it
git rebase upstream/develop

# 3. Make your changes, commit as usual
git add <files>
git commit -m "your message"

# 4. Push to your fork (force needed after rebase rewrites history)
git push --force-with-lease origin develop
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
