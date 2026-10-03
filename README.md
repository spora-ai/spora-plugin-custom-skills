# spora-plugin-custom-skills

Principal-scoped custom skills — a two-pane admin panel for authoring skills by hand, plus the `manage_skill` tool so an agent can write them too.

Custom skills are **not** a second read tool. They are served by core's `skill` tool, provider-agnostically: a custom skill appears in the same `allowed_skills` multi-select as a shipped one, and the agent reads it with the same `skill(action: "read", …)` call. What this plugin adds is the *write* side, which core deliberately has no opinion about.

This plugin contributes the **Custom Skills** admin panel to the host's Apps dropdown at `/apps/custom-skills`. The panel is a pre-built Vue SPA delivered as a separate Composer package (`spora-ai/spora-plugin-custom-skills-frontend`, type `spora-plugin-frontend`). The two-package split lets the frontend evolve on its own release cadence and lets backend-only operators skip the bundle entirely.

## Install

```bash
composer require spora-ai/spora-plugin-custom-skills
```

The PHP package's `require` block pulls the frontend package in transitively — operators don't need to require it separately. The PHP package ships the migration, the provider, the CRUD services, the controller, and the `manage_skill` tool; the frontend package ships the Vue IIFE bundle the host SPA lazy-loads at runtime.

Requires `spora-ai/spora-core` **≥ 0.29.0** — the release that first ships `Spora\Skills\SkillProviderInterface`. `CustomSkillsPlugin` throws `PluginLoadFailedException` at boot on an older core rather than loading silently. That guard is load-bearing: `PluginLoader::dispatchWithTolerance()` swallows listener exceptions, so without it an old core would load the CRUD routes and admin panel happily while the agent could never see a single custom skill.

Mind the cost of that guard: `PluginLoader::boot()` has no per-plugin tolerance, so the throw is caught by `Kernel`, which skips plugin boot for the whole request. On an incompatible core this plugin therefore takes **every** installed plugin down with it — a warning in `storage/spora.log`, no plugins, no app tile. That is deliberate (a half-working skills store is worse than none), but an operator who hits it should expect to disable the plugin, not just this feature.

## What it does

- Ships a **skill provider** (`Spora\Plugins\CustomSkills\Providers\CustomSkillProvider`) that implements core's `Spora\Skills\SkillProviderInterface` and is registered declaratively through the `skillProviders()` hook. It is a *data* hook, not a PSR-14 event, mirroring `speechToTextProviders()`.
- Ships one migration, `custom-skills_000001_create_custom_skills_tables.php` (idempotent `hasTable` guard), and `schemaVersion(): 1`. Two tables: `custom_skills` (one row per skill, `unique(principal_id, name)`) and `custom_skill_files` (sidecars, cascading on delete). The `SKILL.md` is **synthesised on read** from the frontmatter columns + body; there is no file on disk.
- Ships the **`manage_skill` tool** with `create`, `update`, and `delete` operations. `create` and `update` are `enabledByDefault: true`; `delete` is `enabledByDefault: false` — the destructive path does not ride on the safe default. All three are `requiresApprovalByDefault: true`, and an approved write goes live: there is no draft/published workflow, because the approval card is the review.
- Ships the **Custom Skills** admin panel: a read-only pane of pre-shipped skills (from the host's `GET /api/v1/skills`, grouped by `source`) and a "my skills" pane with CRUD, per-pane search, inline `ValidationResult` errors, a warnings banner, a "last edited by agent" line, one-step restore, a "Duplicate" fork from any pre-shipped card, and a delete confirmation that **names the agents** whose allowlists will be scrubbed.

## Visibility

Custom skills belong to exactly one principal, and the read and write gates are deliberately asymmetric:

| Caller relation to principal | Read | Write |
| --- | --- | --- |
| Own user-principal | ✅ | ✅ |
| Group they belong to, any role | ✅ | ❌ |
| Group owner/admin | ✅ | ✅ |
| Unrelated principal | ❌ `404` | ❌ `403` |

Reading a group's custom skills needs only **membership**. Writing into a group's instruction set needs **owner or admin**. A member can read a group's skills but cannot author into them — a skill is instructions the model will follow, not shared notes.

Shipped skills sit outside this entirely: core's `FilesystemSkillProvider` ignores `$principalId`, because operator-authored content is identical for everyone and per-principal scoping would mean a copy per user of a file the operator already controls.

## Identity and precedence

Identity is the frontmatter `name`, not a directory slug. The writer forces `name === slug` at write time, so the two cannot diverge.

`SkillProviderRegistry` dedupes **first-provider-wins across providers**, and core's `FilesystemSkillProvider` is first in the static class list — so a custom skill can never shadow a shipped one, and installing this plugin can never change what an existing agent's `allowed_skills` resolves to. A write that collides with a shipped name is rejected up front (`409 SKILL_NAME_RESERVED`) rather than creating a row the model could never resolve.

## Deleting a custom skill

`DELETE /api/v1/custom-skills/{name}` also **scrubs the name from every `allowed_skills` array for that principal**, in the same transaction, and the response names the affected agents in `scrubbed_agents`. Without that, a deleted skill is silently dropped from the tool definition and every agent that used it loses a capability with no signal. The scrub is scoped to principal-owned custom skills — filesystem skills are not deletable through this plugin, so a shipped skill can never be removed from an agent's config.

## Caps

Enforced as **errors** with named codes, not warnings. `SkillValidator` emits a soft `SKILL_BODY_OVERSIZE` warning and `ValidationResult::isValid()` checks errors only, so validator reuse alone would leave a user-authored body unbounded.

| Cap | Limit | Code |
| --- | --- | --- |
| Skills per principal | 25 | `SKILL_LIMIT_REACHED` |
| Sidecar files per skill | 20 | `TOO_MANY_FILES` |
| Total bytes per skill | 200 000 | `TOTAL_SIZE_EXCEEDED` |
| Bytes per file | 50 000 | `FILE_TOO_LARGE` |
| `description` length | 1024 chars | `DESCRIPTION_TOO_LONG` |

The per-file read cap is core's `SkillProviderInterface::MAX_FILE_BYTES`, re-asserted by `SkillTool` on what a provider returns. It is enforced on the **write** side, by this plugin rather than by the provider: sidecars are checked as they are validated, and the synthesised `SKILL.md` is checked by composing it, because that file is built from columns and so never passes through the sidecar loop. The provider's own `getSkillFile()` does not cap it.

## API surface

After install, 9 endpoints appear under `/api/v1/custom-skills*`. All require `AuthMiddleware` + `CsrfMiddleware`, and every one honours `?principal_id=N` to pick the acting principal.

- `GET    /api/v1/custom-skills` — list the acting principal's custom skills, ordered by `name`.
- `GET    /api/v1/custom-skills/{name}` — one skill, full resource shape.
- `GET    /api/v1/custom-skills/{name}/files` — the file listing (`SKILL.md` first).
- `GET    /api/v1/custom-skills/{name}/files/{path}` — one file's content (path percent-encoded).
- `POST   /api/v1/custom-skills` — create.
- `PUT    /api/v1/custom-skills/{name}` — update; a name change is rejected, and `files` fully replaces the sidecar set.
- `DELETE /api/v1/custom-skills/{name}` — delete, and scrub the allowlist.
- `POST   /api/v1/custom-skills/{name}/restore` — restore `previous_snapshot` in one step (itself undoable).
- `GET    /api/v1/custom-skills/{name}/allowlist` — which agents resolve this skill via `allowed_skills`; the blast-radius preview behind the delete dialog. The tool's `describeAction` deliberately does **not** count agents, because only the model's arguments reach it and a count would be a cross-tenant disclosure.

Pre-shipped skills are **not** re-exposed here. They come from the host's `GET /api/v1/skills`.

## Uninstalling

`composer remove spora-ai/spora-plugin-custom-skills` removes the admin-panel metadata from the App Registry, drops the 9 routes, and the navbar tile disappears cleanly. The `custom_skills` and `custom_skill_files` tables are **preserved** — uninstalling does not drop them. Reinstalling is a no-op on the schema. This is intentional: data persists across plugin uninstall/reinstall cycles, the same as `spora-plugin-memories`.

Note that a leftover custom skill whose provider is gone becomes unresolvable: `allowed_skills` entries pointing at it are reported as `(unavailable: <name>)` in the tool definition rather than silently dropped.

## Reference

The canonical reference lives on the docs site:

- [Concepts → Skills](https://docs.spora-ai.com/reference/concepts/skills) — discovery, the `allowed_skills` allowlist, and custom skills
- [Plugin author guide → Skills](https://docs.spora-ai.com/develop/plugins/author-guide/skills) — shipping a directory vs. authoring a `SkillProviderInterface` provider
- [Tool system](https://docs.spora-ai.com/reference/concepts/tools) — the `skill` (read) vs. `manage_skill` (write) split
- [REST API reference](https://docs.spora-ai.com/reference/api) — the full endpoint list and the error registry

## License

MIT — see [LICENSE](LICENSE).
