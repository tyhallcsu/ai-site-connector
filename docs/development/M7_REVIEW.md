# M7 (#70) safe content update — review ledger

Second-pass adversarial reviews of `includes/class-content-update.php`.
Status values: **fixed** (code + regression test on the PR head),
**open** (must be resolved before merge), **accepted** (documented limitation).

## Review 1 — first draft (11 findings)

| # | Sev | Finding | Disposition | Test |
|---|-----|---------|-------------|------|
| 1.1 | P1 | Rollback did not re-check publish / assign_terms / image access → contributor could republish via rollback | fixed: `validate_restore()` re-runs update-time checks under current permissions | `review fixes: rollback re-checks privileges…` |
| 1.2 | P1 | `wp_update_post` re-sanitises every column (kses) → untouched content silently stripped; title/excerpt/content verification always "true" | redesigned: values pre-computed with `sanitize_post_field('db')`, exact verification, refusal when an untouched column would change — **see 2.1, the prediction was wrong for slashed input** | `…kses guard…` |
| 1.3 | P2 | One `wp_update_post` per field: publish hooks fire before later steps can fail; extra revisions | fixed: meta/terms/thumbnail first, then one `wp_update_post` with status last | `snapshot failure aborts; mid-operation and guard failures…` |
| 1.4 | P2 | Interrupted update (`pending` / `revert_incomplete`) not recoverable via rollback | fixed: field-by-field recovery of interrupted snapshots | open: dedicated test |
| 1.5 | P2 | Single meta array, read-modify-write → concurrent updates lose snapshots; finalize unverified | fixed: one non-autoloaded option per snapshot (`add_option`), per-post index rows, finalize verified (update reverted if it fails) | `snapshot failure aborts…` |
| 1.6 | P2 | Snapshot meta primed on every page view; unbounded size | fixed: options with autoload off; 1 MB cap per text field | open: cap test |
| 1.7 | P2 | Numeric term slugs treated as IDs | fixed: ints are IDs, strings are slugs | `…numeric slugs…` |
| 1.8 | P2 | Writes allowed on any `show_ui` type (orders, field groups) | fixed: public + `show_in_rest` types in a core status | `…strict typing` (`wp_block` rejected) |
| 1.9 | P3 | Garbage input silently coerced (featured_image "abc" → removal) | fixed: strict typing, 400 | `…strict typing` |
| 1.10 | P3 | Slug/date WordPress generates on publish not snapshotted | fixed: `_status_side` implicit snapshot — **conflict check missing, see 2.3** | `…drafts…` (publish → rollback) |
| 1.11 | P3 | Vacuous assertions, fragile fixtures | fixed: specific error codes/reasons, idempotent term fixtures — **kses test passed for the wrong reason, see 2.2** | — |

## Review 2 — redesign on `01431f2` (merged with main `d81b62d`)

| # | Sev | Finding | Disposition | Test |
|---|-----|---------|-------------|------|
| 2.1 | P1 | kses prediction ignores slashing (`Don't` predicted as `Don\'t`; any quote in content refused as "untouched would change"); mismatch trips the guard after publish hooks fired | open | — |
| 2.2 | P2 | kses test passes because of quotes, not kses; no successful write by a user without `unfiltered_html` | open | — |
| 2.3 | P2 | Rollback restores `_status_side` (slug/date) without checking they were not edited since | open | — |
| 2.4 | P2 | Rollback has no untouched-column check → guard trips after hooks fire | open | — |
| 2.5 | P2 | `future` posts can be changed but never rolled back | open | — |
| 2.6 | P2 | Snapshot options orphaned on post deletion; survive uninstall wipe | open | — |
| 2.7 | P3 | Guard's direct-row restore can overwrite a concurrent save | open | — |
| 2.8 | P3 | `prune()` can delete recovery (`pending`/`revert_incomplete`) snapshots | open | — |

## Guarantees (and non-guarantees)

To be finalised with the fixes; the tool must not claim atomicity it does not
have. WordPress has no transaction across posts, meta, terms and hooks; the
design restores on detected failure and reports exactly what it could not
restore.
