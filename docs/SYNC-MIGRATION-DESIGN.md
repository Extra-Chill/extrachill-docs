# Docs Sync Migration Design

> **Status:** Design proposal. No code changes yet. Implementation gated on
> @chubes review of the recommendations below.
>
> **Refs:** Extra-Chill/extrachill-docs#7 (retire push pipeline) and #8 (wire
> WP-first generation flow).

## Goal

Flip the docs pipeline so **WordPress is the source of truth and GitHub is the
audit trail**. Authors and agents edit `ec_doc` posts directly in Gutenberg on
docs.extrachill.com; a Data Machine Code primitive (`GitSync`) renders each
saved post to markdown and commits it back to this repo. The historical
push-from-markdown pipeline (`scripts/sync.sh` + `.env` + application
password) is retired. Reverse sync is preserved as a *bootstrap* path for
one-time imports, not as a runtime mechanism.

## Current state

```
                ec_docs/*.md  (declared "Source of Truth")
                      │
                      │  ./scripts/sync.sh
                      │  reads .env (WP_SYNC_PASSWORD = app password)
                      ▼
     POST /wp-json/extrachill/v1/sync/doc  (extrachill-api)
                      │
                      ▼
              ec_doc CPT on docs.extrachill.com
```

Components in play:

- `scripts/sync.sh` — bash entry, loads `.env`, shells to PHP.
- `scripts/upload.php` — discovers `ec_docs/*/*.md`, derives title/slug/platform
  from filename + directory, posts to REST.
- `scripts/WordPressClient.php` — Basic Auth REST client (app password).
- `scripts/FileFinder.php` — recursive `*.md` discovery.
- `extrachill-api`: `POST /extrachill/v1/sync/doc` — controller upserts into
  `ec_doc`, assigns `ec_doc_platform`, hash-based change detection.
- `ec_docs/README.md` declares "do not edit in WordPress" — the inverse of
  where we want to land.

Current content footprint:

- `ec_docs/artist/` — 8 markdown files (2 are orphans; never reached WP)
- `ec_docs/community/` — 7 markdown files
- `ec_docs/events/` — 2 markdown files

The two orphans are
`ec_docs/artist/creating-your-artist-merch-store.md` and
`ec_docs/artist/managing-your-artist-profile.md`.

## Target state

```
              ┌──────────────────────────────────────┐
              │ Author / agent edits in WP (Gutenberg)│
              │ ec_doc CPT @ docs.extrachill.com     │
              └──────────────┬───────────────────────┘
                             │ save_post_ec_doc
                             ▼
         ┌──────────────────────────────────────────────┐
         │ DocSyncTask (SystemTask in extrachill-docs)  │
         │  1. render post_content → markdown           │
         │  2. write to bound dir (ec_docs/<plat>/...)  │
         │  3. enqueue GitSync submit (debounced)       │
         └──────────────┬───────────────────────────────┘
                        │ datamachine/gitsync-submit
                        ▼
         ┌──────────────────────────────────────────────┐
         │ data-machine-code GitSyncProposer            │
         │  → commits on gitsync/docs branch            │
         │  → opens / updates PR vs. main               │
         └──────────────┬───────────────────────────────┘
                        ▼
         Extra-Chill/extrachill-docs (GitHub)
              audit trail + reviewable diff
```

Components added:

- **`extrachill-docs` plugin** — gains an `inc/sync/` directory containing
  `DocSyncTask` (SystemTask) + a Gutenberg-to-markdown renderer + glue.
- **`data-machine-code` GitSync** — already shipped (v0.7.0+, see
  `inc/Abilities/GitSyncAbilities.php` and `inc/GitSync/`). Used as-is. No
  changes required to DMC.
- **A single GitSync binding**, bootstrapped on plugin activation:
  - `slug:        ec-docs`
  - `local_path:  wp-content/plugins/extrachill-docs/ec_docs`
  - `remote_url:  https://github.com/Extra-Chill/extrachill-docs.git`
  - `branch:      main`
  - `policy.write_enabled: true`, `policy.safe_direct_push: false`
    (force every commit through a PR; that PR is the audit trail).

Components retired:

- `scripts/sync.sh`, `scripts/upload.php`, `scripts/WordPressClient.php`,
  `scripts/FileFinder.php`
- `.env` workflow with `WP_SYNC_PASSWORD`
- `extrachill-api` route `POST /extrachill/v1/sync/doc` and its controller
  (separate PR in `extrachill-api`).

## Decision points

### 1. Generation source — agent surface vs. doc-specific ability?

**Options**

- **A.** Agents use the existing `extrachill` post-write abilities
  (`extrachill/upsert-post` style) targeting `ec_doc`.
- **B.** Add a new `extrachill/upsert-doc` ability scoped to `ec_doc`.
- **C.** Hybrid: keep generic upsert, plus a thin `extrachill/get-docs-info`
  read ability for agents that need platform-aware listings.

**Recommendation: C.**
The write path stays the same as the rest of the platform — agents already
know how to call `extrachill/upsert-post` with `post_type=ec_doc`,
`tax_input.ec_doc_platform=...`. Adding a doc-specific *write* ability is
ceremony. But agents discovering "which platforms exist, which docs already
exist" benefit from a read-only listing ability that returns the same shape
the homepage cards consume. That ability is cheap and lives next to the
post-type registration in `inc/core/`.

**Tradeoffs**

- Pro C: Smallest API surface change. Honors the AGENTS.md rule that
  business logic lives in abilities; the writes already do via the existing
  generic `upsert-post` path. The read ability is genuinely new value
  (taxonomy-aware doc listing).
- Con A: Requires no new ability but agents may struggle to *find* the right
  platform term without a listing helper.
- Con B: Duplicates `upsert-post` logic for one CPT. Encourages drift.

### 2. Sync trigger — `save_post_ec_doc` vs. scheduled flow vs. CLI?

**Options**

- **A.** `save_post_ec_doc` only — every publish triggers a sync.
- **B.** Scheduled flow only (e.g. nightly) — batch sync via Action Scheduler.
- **C.** Both — `save_post_ec_doc` enqueues; a scheduled sweeper catches
  anything that failed.
- **D.** Manual CLI only — `wp extrachill docs sync` on demand.

**Recommendation: C, with debouncing.**

- `save_post_ec_doc` (priority 20, post-publish only — skip autosaves,
  revisions, drafts, trashed) computes the markdown signature and queues a
  single Action Scheduler job per post via
  `as_enqueue_async_action('extrachill_docs_sync_post', [$post_id])`. The
  async hop coalesces rapid-fire saves — a Gutenberg session that hits Save
  three times in 30 seconds produces one commit, not three.
- The action handler instantiates `DocSyncTask` and calls `executeTask()`,
  which:
  1. Re-reads the post (catches edits that happened after enqueue),
  2. Renders post_content → markdown,
  3. Writes to `ec_docs/<platform>/<slug>.md`,
  4. Calls `wp_get_ability('datamachine/gitsync-submit')->execute([
       'slug' => 'ec-docs',
       'message' => "docs: update {$slug} (post #{$id})",
       'paths' => [ "<platform>/<slug>.md" ],
     ])`.
- A scheduled `extrachill_docs_sync_sweeper` daily action diffs `ec_docs/`
  against published `ec_doc` posts and fixes drift (failed jobs, manually
  rolled-back commits, etc.).
- A `wp extrachill docs sync [--post=<id>] [--all] [--dry-run]` CLI
  surfaces the same machinery for ops and recovery.

**Tradeoffs**

- Pro C: Real-time feedback to authors (they see the PR within seconds), with
  a safety net for failures.
- Con C: Two code paths to maintain. Mitigated because both call the same
  `DocSyncTask::executeTask()`.
- Con A-only: Silent failures rot the repo.
- Con B-only: PRs always lag a day; bad UX for an editor who just clicked
  Publish.

### 3. Conflict resolution — both sides edited

**Options**

- **A.** WP always wins. GitHub edits get clobbered on next sync.
- **B.** GitHub always wins. WP edits get clobbered on next pull.
- **C.** Last-write-wins by timestamp. Subtle, error-prone.
- **D.** WP wins for content; GitHub commits = audit trail. Detect upstream
  divergence and surface it as a notice; don't auto-resolve.

**Recommendation: D.**

The `gitsync-submit` ability already opens or updates a PR on
`gitsync/ec-docs`. If someone hand-edits `main`, the next submit's PR will
naturally diverge from `main` — GitHub renders the diff, a human resolves it
on the PR. The resolution path is "merge main into gitsync/ec-docs, fix
conflicts, re-submit". WP never silently overwrites a markdown file the
human edited; it always goes through review.

For drift in the *other* direction (someone hand-edits `ec_docs/*.md` on
disk in the deployed plugin without going through the binding), the daily
sweeper detects the local-vs-remote SHA mismatch in the binding's
`pulled_paths` and posts a `wp_admin_notice` rather than auto-clobbering.

**Tradeoffs**

- Pro D: Honors AGENTS.md "WP wins, GitHub commits = audit trail" while
  still giving humans a place to land out-of-band fixes. No silent data
  loss.
- Con D: Requires authors to understand "if you push to main directly,
  expect a follow-up PR conflict." Documented in CLAUDE.md.

### 4. Markdown export format — match current shape or add frontmatter?

**Options**

- **A.** Match current shape exactly: no frontmatter, H1 = title, slug from
  filename, platform from directory.
- **B.** Add YAML frontmatter (`title:`, `slug:`, `platform:`, `wp_post_id:`,
  `last_synced:`).
- **C.** Add frontmatter only for fields that *can't* be derived from
  filename/directory (e.g. `wp_post_id` for round-trip identity, nothing
  else).

**Recommendation: C, minimal frontmatter.**

Pure A loses `wp_post_id`, which means reverse-sync (markdown → WP) has to
re-derive the post by slug+platform every time and can't survive a slug
rename. Pure B is heavyweight and breaks compatibility with the 17 existing
files. C keeps the human-readable shape (H1 still = title, body still =
content) and adds exactly one machine-only field at the top:

```markdown
---
wp_post_id: 1234
---

# Getting Started

Intro paragraph...
```

The renderer omits frontmatter when `wp_post_id` is unknown (e.g. when an
agent generates a draft before publishing).

**Tradeoffs**

- Pro C: Round-trip identity survives slug renames. Backward-compatible —
  parsers that ignore frontmatter still work; a one-shot migration backfills
  IDs into the existing 14 published files.
- Con C: A tiny YAML parser dependency (or 20 lines of regex). Not a real
  cost.
- Con A: Slug renames break the binding. Painful.
- Con B: Loud, doesn't pull weight, breaks the "no frontmatter" rule that
  was a design choice.

### 5. Image handling — Gutenberg media URLs vs. portable markdown

**Options**

- **A.** Leave images as absolute WP URLs
  (`![alt](https://docs.extrachill.com/wp-content/uploads/...)`).
- **B.** Inline as base64 data URIs.
- **C.** Sync the WP-attached media into `assets/<platform>/<slug>/` in the
  repo and rewrite markdown to relative paths.

**Recommendation: A, with a future hatch toward C.**

The repo's purpose is **audit trail**, not "portable static-site source".
The markdown lives next to a custom post type that only renders on
docs.extrachill.com. Absolute URLs are fine — they resolve from any
GitHub viewer (preview renders the image), they don't bloat the repo, and
they're trivially re-derived when WP regenerates a thumbnail. B is
flatly wrong (binary blobs in markdown bloat the repo and break diffs).
C is correct *if* we ever decide to publish docs as a static site or
mirror them outside WP — but we aren't, and committing to C now means
building image diff/sync logic before we have a need.

The render step strips Gutenberg's `srcset` / `sizes` / class noise and
keeps only `<img src alt>` so the markdown is clean.

**Tradeoffs**

- Pro A: Simplest. Works immediately. No image-pipeline coupling.
- Con A: If docs.extrachill.com is ever offline, GitHub previews break.
  Acceptable for an audit trail.
- The existing 17 markdown files already use absolute URLs (or none) — A is
  a no-migration path.

### 6. Where does the flow live? Pipeline / CLI / SystemTask?

**Options**

- **A.** Data Machine pipeline (fetch → transform → publish-style).
- **B.** Standalone CLI command in `extrachill-docs`.
- **C.** SystemTask registered with DM via `datamachine_tasks` filter
  (mirrors the OG card pattern in `extrachill-multisite`).

**Recommendation: C. SystemTask.**

The work is single-input (one post), single-output (one markdown file +
commit), synchronous from the engine's perspective, with a clear undo
shape (revert the commit, delete the file). That's exactly the SystemTask
contract. We get the DM job table, undo support, the
`wp datamachine system run doc_sync --post_id=...` CLI surface, the admin
toggle, and audit logging for free — see
`extrachill-multisite/inc/og-cards/og-card-task.php` for the precedent.

A pipeline would be overkill — there's no fetch, no AI, no fan-out. A
plain CLI command would reinvent half of what SystemTask already provides.

**Tradeoffs**

- Pro C: Reuses the platform's task infrastructure. Inherits CLI, admin UI,
  undo, telemetry. Matches the OG-card precedent that @chubes already
  validated.
- Pro C: The Action Scheduler async hop (decision 2) trivially routes into
  the SystemTask via `executeTask()`.
- Con C: Lives in `extrachill-docs` and depends on Data Machine being
  network-active (it is — see SITE.md).

### 7. Reverse sync — keep, drop, or repurpose?

**Options**

- **A.** Drop reverse sync entirely. Markdown → WP is no longer supported.
- **B.** Keep reverse sync as a runtime path (`gitsync pull` → re-import).
- **C.** Keep reverse sync as a **bootstrap-only** path: a one-shot
  `wp extrachill docs import-markdown` CLI used during initial migration
  and never again.

**Recommendation: C.**

The reason reverse sync existed in the old model was that markdown was
canonical. Once WP is canonical, reverse sync becomes a foot-gun — running
it accidentally would clobber the canonical store. But we still need *one*
moment of "import all 17 existing markdown files into WP, including the 2
orphans if we want them" — that's the bootstrap moment. After it runs once
in production, the command is removed in a follow-up release.

The `extrachill-api` route `POST /extrachill/v1/sync/doc` is *not* needed
for the bootstrap (the CLI goes through `extrachill/upsert-post` directly)
and is removed in lockstep.

**Tradeoffs**

- Pro C: Clean canonical model post-bootstrap. No lingering attack surface.
- Con C: If we ever re-introduce manual md edits as canonical, we have to
  re-add this. Acceptable — that's a deliberate architectural reversal,
  not an accident.

### 8. Fate of the 2 orphan markdown files

The two files that never reached WP:

- `ec_docs/artist/creating-your-artist-merch-store.md`
- `ec_docs/artist/managing-your-artist-profile.md`

**Recommendation (defer final call to @chubes):** import them as **draft**
`ec_doc` posts during the bootstrap. They're unfinished but represent real
intent. Once in WP they live alongside the 14 published docs and any future
edits go through the normal flow. If on review the content is genuinely
abandoned, deleting from the WP admin (and the next sync removes them from
the repo) is a one-click action; recovering them after a hard delete from
the repo is much harder.

Two-line summary so @chubes can pick:

- **Import as drafts** (recommended): preserves intent, low cost.
- **Discard**: cleaner history, no clutter — fine if the content is dead.

### 9. Ability slug + hook name registry

For implementer reference; not new abilities (DMC abilities already exist):

| Slug                                      | Source plugin            | Used by docs flow? |
|-------------------------------------------|--------------------------|--------------------|
| `datamachine/gitsync-bind`                | data-machine-code        | Activation hook    |
| `datamachine/gitsync-submit`              | data-machine-code        | DocSyncTask        |
| `datamachine/gitsync-pull`                | data-machine-code        | Bootstrap CLI only |
| `datamachine/gitsync-status`              | data-machine-code        | Sweeper            |
| `extrachill/upsert-post`                  | extrachill-api/abilities | Agent writes       |
| `extrachill-docs/list-platforms` (NEW)    | extrachill-docs          | Agent reads        |

New WP hooks introduced by `extrachill-docs`:

- `extrachill_docs_sync_post` — Action Scheduler async action,
  `[ int $post_id ]`.
- `extrachill_docs_sync_sweeper` — Action Scheduler recurring action, no
  args.
- `extrachill_docs_render_block` — filter, lets other plugins customize how
  a single block renders to markdown
  (`apply_filters('extrachill_docs_render_block', $md, $block, $post)`).
- `extrachill_docs_markdown_export` — filter on the final markdown string
  before write
  (`apply_filters('extrachill_docs_markdown_export', $md, $post)`).

## Migration plan

Five discrete phases. Each is a separate PR; the docs site stays in a
known-good state at every phase boundary.

### Phase 0 — Land this design doc

- This PR.
- No code changes. No deletions. No new dependencies.
- @chubes reviews the recommendations, marks any decisions he wants flipped,
  approves.

### Phase 1 — Add `DocSyncTask` and bind GitSync (additive only)

- New file: `inc/sync/markdown-renderer.php` (Gutenberg-blocks → markdown).
- New file: `inc/sync/doc-sync-task.php` (`DocSyncTask` extends
  `DataMachine\Engine\AI\System\Tasks\SystemTask`).
- New file: `inc/sync/sync.php` (bootstrap: `register_activation_hook` calls
  `datamachine/gitsync-bind` for slug `ec-docs`; registers
  `datamachine_tasks` filter; wires `save_post_ec_doc` →
  `as_enqueue_async_action`).
- New file: `inc/sync/sweeper.php` (daily Action Scheduler).
- New CLI command: `wp extrachill docs sync` in `extrachill-cli`.
- New ability: `extrachill-docs/list-platforms` (read-only, taxonomy
  listing).
- **Old `scripts/` dir untouched.** Both pipelines coexist; the new one
  short-circuits whenever a save happens, the old one is documented as
  deprecated.
- Acceptance: edit a doc in WP, see a PR open on
  Extra-Chill/extrachill-docs within ~15s. Old `./scripts/sync.sh` still
  runs without erroring.

### Phase 2 — Backfill `wp_post_id` frontmatter on all 14 published docs

- Run `wp extrachill docs sync --all` in production. This is the first run
  through the new pipeline; it walks every published `ec_doc` and emits
  one PR adding the minimal `wp_post_id` frontmatter to all 14 files.
- Manual review + merge of that PR.
- Acceptance: every `ec_docs/*.md` for a published post has `wp_post_id` in
  frontmatter; the round-trip identity is now stable.

### Phase 3 — Decide and execute on the 2 orphan files

- @chubes picks: import as drafts or discard.
- If import: `wp extrachill docs import-markdown
  ec_docs/artist/{creating-your-artist-merch-store,managing-your-artist-profile}.md
  --status=draft`. The next sync run (triggered by save) writes them back
  with frontmatter on a PR.
- If discard: delete the two files in a `chore: drop abandoned doc drafts`
  PR.
- Acceptance: `ec_docs/` matches the published `ec_doc` set, modulo any
  intentional drafts.

### Phase 4 — Retire the old pipeline

This is the **destructive** phase. Do not start it until phases 1–3 have
shipped to production and the team has lived with the new flow for at
least a few days.

- Delete `scripts/sync.sh`, `scripts/upload.php`,
  `scripts/WordPressClient.php`, `scripts/FileFinder.php`.
- Delete `ec_docs/README.md` (the "do not edit in WordPress" rules).
- Update `README.md` and `CLAUDE.md` to describe the WP-first model.
- Remove any `.env` references / examples mentioning `WP_SYNC_PASSWORD`.
- Coordinate a paired PR in `extrachill-api` removing
  `inc/routes/docs-sync-routes.php` and
  `inc/controllers/class-docs-sync-controller.php` (the
  `/extrachill/v1/sync/doc` endpoint). The two PRs land together.
- Revoke the application password used by the old pipeline (manual, in
  WP admin).
- Acceptance: no references to `sync.sh` / `WP_SYNC_PASSWORD` /
  `/sync/doc` in either repo. Docs site continues to publish through
  the new pipeline.

### Phase 5 — Optional: collapse `ec_docs/` from "source dir" to "binding dir"

`ec_docs/` is currently a top-level repo directory. The GitSync binding
points there. Cosmetically nothing changes — the directory keeps its
shape (`ec_docs/<platform>/<slug>.md`) so existing GitHub bookmarks and
diffs survive. No move is required.

If we want to relocate it later (e.g. to `content/`) that's a one-line
change in the binding's `local_path` plus a git mv, scheduled separately.

### Rollback

- Phase 1 is additive — disabling the SystemTask via the DM admin toggle
  reverts to the old pipeline immediately.
- Phase 4 is the only irreversible step. If a critical regression shows up
  post-Phase-4, rollback = `git revert` the deletions in both repos,
  re-issue the application password, re-run `./scripts/sync.sh` once to
  re-sync. Total rollback time ~30 minutes, no data loss because WP
  remained canonical throughout.

## Open questions for @chubes

1. **Orphan files:** import as drafts (recommended) or discard? See
   decision 8.
2. **Application-password retirement timing:** revoke in Phase 4, or earlier
   once Phase 1 is verified? Earlier = tighter security, but means the
   old `./scripts/sync.sh` stops working before deletion lands.
3. **Sweeper cadence:** daily is the default proposal. Hourly is cheap if
   you'd rather see drift surface faster.
4. **Branch strategy:** `gitsync/ec-docs` is the sticky proposal branch the
   GitSync primitive uses. PRs always target `main`. OK to keep that
   default, or do you want a separate `docs-sync` branch convention?
5. **Permission ceiling:** `gitsync-submit` requires `manage_options`
   (`PermissionHelper::can_manage()`). Editors who write docs aren't
   admins. The async hop runs as the cron user (admin-equivalent), so
   editors don't need the cap directly — but verify this matches the
   intended trust model.
6. **Failed-sync surfacing:** post a `wp_admin_notice` on the post edit
   screen when the latest sync attempt errored, or just rely on the
   sweeper + DM jobs admin page? My recommendation: the post-screen
   notice; it's where the author lives.
