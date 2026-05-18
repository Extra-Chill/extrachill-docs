# Docs Agent Runner Configs

This directory is the **single canonical home** for the configuration that controls how `Automattic/docs-agent` runs against every Extra-Chill plugin repository.

## Files

- **`writing-rules.md`** — the editorial voice rules from extrachill-docs issue #6, the literal text injected into every docs-agent prompt. Edit this one file and the next run of any plugin repo's docs-agent workflow picks up the change.
- **`platform-map.yml`** — maps each `Extra-Chill/*` plugin repo to its user-facing platform identity (platform name, parent slug on docs.extrachill.com, audience description). Read by the reusable workflow at run time and substituted into the prompt.
- **`prompt-template.md`** — the prompt scaffold. Combines `writing-rules.md`, flow-specific instructions, and per-repo context from `platform-map.yml`.

## How the pieces fit

```text
.github/workflows/docs-agent.yml (in each plugin repo)
        │
        │ calls
        ▼
Extra-Chill/extrachill-docs/.github/workflows/docs-agent-for-plugin.yml
        │
        │ checks out extrachill-docs at main
        │ reads writing-rules.md + platform-map.yml + prompt-template.md
        │ composes final prompt
        │
        │ calls
        ▼
Extra-Chill/homeboy-extensions/.github/workflows/datamachine-agent-ci.yml
        │
        │ spins up ephemeral WP + Data Machine in CI
        │ imports Automattic/docs-agent bundle
        │ runs the selected flow with our composed prompt
        │
        │ produces
        ▼
Pull request in the plugin repo with docs/user/*.md changes
```

## Editing rules

- **Editing `writing-rules.md`** affects every plugin repo's next docs-agent run. There is no per-repo override of the writing rules — that is intentional, the rules are platform-wide.
- **Editing `platform-map.yml`** adds new repos to the rotation or updates platform mappings. The reusable workflow refuses to run against a target repo that has no entry here.
- **Editing `prompt-template.md`** changes the scaffold structure. Be conservative — the substitution placeholders must stay intact.

## Adding a new plugin repo

1. Add an entry to `platform-map.yml` keyed by `OWNER/REPO`.
2. In the plugin repo, drop a `.github/workflows/docs-agent.yml` that calls `Extra-Chill/extrachill-docs/.github/workflows/docs-agent-for-plugin.yml@main` with `target_repo` set to the same `OWNER/REPO`.
3. Trigger one `user-docs-bootstrap-flow` run via `workflow_dispatch` and review the resulting PR for voice compliance.

See extrachill-docs issue #34 for the per-repo adoption tracker.

## Why these rules are not in upstream docs-agent

`Automattic/docs-agent` is intentionally generic. Its `user-docs` flows ship sensible defaults for general-purpose user-facing documentation. The Extra-Chill writing rules (no plugin / hook / slug naming, sixth-grade reading level, benefit-focused) are far stricter than the generic defaults and would be wrong to upstream.

This directory is where Extra-Chill's editorial voice lives, versioned with the docs site itself.
