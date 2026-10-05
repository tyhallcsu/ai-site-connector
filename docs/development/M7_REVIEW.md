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
| 1.4 | P2 | Interrupted update (`pending` / `revert_incomplete`) not recoverable via rollback | fixed: field-by-field recovery of interrupted snapshots | `content-update review 2: …` (interrupted recovery) |
| 1.5 | P2 | Single meta array, read-modify-write → concurrent updates lose snapshots; finalize unverified | fixed: one non-autoloaded option per snapshot (`add_option`), per-post index rows, finalize verified (update reverted if it fails) | `snapshot failure aborts…` |
| 1.6 | P2 | Snapshot meta primed on every page view; unbounded size | fixed: options with autoload off; 1 MB cap per text field | `content-update review 2: …` (size cap) |
| 1.7 | P2 | Numeric term slugs treated as IDs | fixed: ints are IDs, strings are slugs | `…numeric slugs…` |
| 1.8 | P2 | Writes allowed on any `show_ui` type (orders, field groups) | fixed: public + `show_in_rest` types in a core status | `…strict typing` (`wp_block` rejected) |
| 1.9 | P3 | Garbage input silently coerced (featured_image "abc" → removal) | fixed: strict typing, 400 | `…strict typing` |
| 1.10 | P3 | Slug/date WordPress generates on publish not snapshotted | fixed: `_status_side` implicit snapshot — **conflict check missing, see 2.3** | `…drafts…` (publish → rollback) |
| 1.11 | P3 | Vacuous assertions, fragile fixtures | fixed: specific error codes/reasons, idempotent term fixtures — **kses test passed for the wrong reason, see 2.2** | — |

## Review 2 — redesign on `01431f2` (merged with main `d81b62d`)

| # | Sev | Finding | Disposition | Test |
|---|-----|---------|-------------|------|
| 2.1 | P1 | kses prediction ignores slashing (`Don't` predicted as `Don\'t`; any quote in content refused as "untouched would change"); mismatch trips the guard after publish hooks fired | fixed: `stored_value()` = `wp_unslash( sanitize_post_field( col, wp_slash( v ), id, 'db' ) )`, used for planning, untouched check and rollback | `content-update review 2: …` (author writes `Don't \\ stop`; mutation-verified) |
| 2.2 | P2 | kses test passes because of quotes, not kses; no successful write by a user without `unfiltered_html` | fixed: iframe fixture without quotes; successful author write with quoted content | `…kses guard…`, `content-update review 2: …` |
| 2.3 | P2 | Rollback restores `_status_side` (slug/date) without checking they were not edited since | fixed: side columns compared with their recorded post-update values; mismatch → `status` conflict | `content-update review 2: …` (human slug kept) |
| 2.4 | P2 | Rollback has no untouched-column check → guard trips after hooks fire | fixed: `validate_restore()` refuses when restored values or untouched columns would be altered by the caller's save filters | `review 2: rollback refused when save filters would alter content…` |
| 2.5 | P2 | `future` posts can be changed but never rolled back | fixed: status changes on scheduled posts refused (`asc_unsupported_transition`) | `content-update review 2: …` |
| 2.6 | P2 | Snapshot options orphaned on post deletion; survive uninstall wipe | fixed: `before_delete_post` removes them; uninstall wipe deletes `ai_site_connector_snapshot_%` options and index rows | `content-update review 2: …` (deletion) |
| 2.7 | P3 | Guard's direct-row restore can overwrite a concurrent save | fixed: restore is conditional on `post_modified_gmt` equal to the value this call wrote (captured from `wp_insert_post_data` at the last priority — a first attempt read it back after the concurrent write and was caught by the new test); otherwise nothing is restored and `restore_failed` includes `post_columns` | `review 2: …guard never clobbers a concurrent save` |
| 2.8 | P3 | `prune()` can delete recovery (`pending`/`revert_incomplete`) snapshots | fixed: prune skips them | `content-update review 2: …` (prune) |

## Review 3 — focused (rollback authz, concurrency, failure consistency, row recovery, honesty) on `dff1ef7`

| # | Sev | Finding | Disposition | Test |
|---|-----|---------|-------------|------|
| 3.1 | P1 | A side field spanning several taxonomies / meta keys that failed part-way was not in `written`, so it was never restored and the response claimed a clean revert | fixed: the field is recorded before it is applied, so a failure restores it in full from `before_raw` (update and rollback paths) | `content-update review 3: …` (category restored when post_tag fails) |
| 3.2 | P2 | Interrupted (`pending`) publish recovery restored the generated slug/date without a conflict check | fixed: without recorded post-update side values, recovery refuses (`status` conflict) unless the side columns are unchanged | `review 3: interrupted publish with an edited slug…` |
| 3.3 | P2 | Guard row restore matched only `post_modified_gmt` (1 s precision) — a same-second concurrent save could be overwritten; the 2.7 test faked a future timestamp | fixed: WHERE matches every guarded column as this call wrote it plus the modified time, captured from `wp_insert_post_data` for this post only (the earlier capture also caught the revision insert — found by the new test); test uses a same-second concurrent save | `review 2: …guard never clobbers a concurrent save`, `review 3: …dirty rollback…` |
| 3.4 | P2 | Rollback failure ignored `columns_dirty`, claimed `reapplied`, left the snapshot unrecoverable | fixed: `restore_failed: [post_columns]`, `reapplied: false`, snapshot set to `revert_incomplete` | `review 3: …dirty rollback reported honestly` |
| 3.5 | P2 | Rollback did not re-check slug uniqueness | fixed: restored slug (and restored side `post_name` when returning to publish/private) must be unique, else `asc_slug_conflict` | `content-update review 3: …` (slug reuse) |
| 3.6 | P3 | A `pending` snapshot whose update is still running was treated as interrupted | fixed: `pending` is recoverable only after a 300 s grace period (`asc_snapshot_in_progress`) | `content-update review 3: …` (fresh pending) |
| 3.7 | P3 | A successful guard row restore leaves traces (revision, `post_modified`, `_wp_old_slug`, plugin `save_post` side effects) | accepted: documented below | — |

## Guarantees (and non-guarantees)

What the implementation guarantees, and what it does not:

- **Dry-run never writes** — no post, meta, term or option changes, no snapshot.
- **Default off** — real writes need `write_content` (and `update_seo` for SEO);
  read-only mode and the disable switch block them.
- **Authorization is evaluated under current permissions** for both update and
  rollback (post edit rights, publish capability, `assign_terms`, image access).
- **No write without a stored snapshot.**
- **Detected failures are restored and reported.** Each step is verified by
  reading back; on failure the already-written fields are put back and the
  response lists `restored` and `restore_failed`. This is *not* a database
  transaction: hooks fired by `wp_update_post` (e.g. `save_post`, publish
  notifications by other plugins) cannot be undone, and a PHP fatal mid-update
  leaves the snapshot `pending` — recoverable via rollback, not automatic.
- **Later edits are never overwritten** by rollback (field-level conflict
  check incl. publish-generated slug/date) or by the guard's row restore
  (conditional on every guarded column still holding the value this call wrote).
- **No silent side effects on untouched columns** from the caller's save
  filters: such updates and rollbacks are refused up front.

Not covered: changing scheduled (`future`) posts' status; trash/delete;
creating terms; concurrent writers that bypass WordPress APIs.

Known traces after a detected-and-restored failure (3.7): the revision
created by the failed save, the bumped `post_modified`, `_wp_old_slug` /
`_wp_old_date` meta and any changes other plugins made in their own
`save_post` hooks remain. Interrupted publishes cannot restore the generated
slug/date automatically when they have been edited since (reported as a
conflict). A `pending` snapshot is treated as interrupted only after 300 s.
