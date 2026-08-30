# Runbook — prune wp.org SVN tags

**Type:** one-time / occasional operational task. No repo code.
**Why:** the "Previous Versions" dropdown on the wp.org plugin page is wp.org's
native widget listing **every SVN tag** — including old pre-release and legacy
builds. It can't be filtered from plugin code; only by removing SVN tags. This
keeps users from installing a stale or pre-release build from the dropdown.

> This is cosmetic/hygiene for the *dropdown*. The build the Aura gateway
> auto-installs is already constrained by the gateway version guards
> (downgrade + prerelease + URL allowlist, see Aura PR #272) and the plugin's
> own source allowlist + signed grant — pruning tags does **not** affect that.

## What to prune

As of 2.7.0 the wp.org tag list includes:

```
1.3.3, 1.3.4, 1.3.5, 2.0.0, 2.0.0-beta.1, 2.0.0-beta.2, 2.0.1, 2.0.2,
2.1.0, 2.2.1, 2.2.2, 2.2.3, 2.2.4, 2.3.0, 2.6.1, 2.7.0, trunk
```

1. **Pre-release tags — remove (they don't belong in a stable directory):**
   - `tags/2.0.0-beta.1`
   - `tags/2.0.0-beta.2`
2. **Legacy 1.3.x — optional**, to keep only the last ~5 stable minors + trunk:
   - `tags/1.3.3`, `tags/1.3.4`, `tags/1.3.5`

Keep `trunk`, the current stable `2.7.1`, and a reasonable tail of recent
stables for rollback.

## Preferred: the "Prune wp.org SVN tags" Action

No local SVN needed — the workflow uses the same `SVN_USERNAME` / `SVN_PASSWORD`
secrets as the deploy:

1. **Actions → "Prune wp.org SVN tags" → Run workflow.**
2. `tags` defaults to `2.0.0-beta.1 2.0.0-beta.2 1.3.3 1.3.4 1.3.5`; edit as needed.
3. Leave **`dry_run` = true** first — it lists what *would* be removed and commits
   nothing. Review the log.
4. Re-run with **`dry_run` = false** to actually `svn rm` + commit.

The workflow refuses to remove `trunk`, `assets`, or the current `Stable tag`,
and skips tags that don't exist — so a fat-fingered input can't drop a
load-bearing ref.

## Manual fallback (local SVN)

```bash
# 1. Checkout the plugin's wp.org SVN (not this git repo).
svn co https://plugins.svn.wordpress.org/digitizer-site-worker wporg-svn
cd wporg-svn

# 2. Remove the pre-release tags (and optionally the 1.3.x legacy tags).
svn rm tags/2.0.0-beta.1 tags/2.0.0-beta.2
# optional:
# svn rm tags/1.3.3 tags/1.3.4 tags/1.3.5

# 3. Confirm readme's Stable tag points at the current stable, never a beta.
grep -i "Stable tag" trunk/readme.txt        # must name the current stable release,
                                             # never a beta and never a pruned tag

# 4. Commit.
svn ci -m "Prune pre-release (and legacy) tags from the wp.org dropdown"
```

## Verify (after SVN propagates — minutes)

- Load `https://wordpress.org/plugins/digitizer-site-worker/advanced/` → the
  removed versions are gone from **Previous Versions**.
- `https://api.wordpress.org/plugins/info/1.0/digitizer-site-worker.json` →
  `versions` no longer lists the pruned tags.

## Notes

- Removing a tag does **not** un-publish anyone's installed copy; it only hides
  that version from the dropdown/API.
- Never point `Stable tag` at a pre-release. The release workflow already ships
  `Stable tag` from `readme.txt`, so keep that correct at release time and this
  prune stays a rare cleanup.
