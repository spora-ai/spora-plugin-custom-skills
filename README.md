# Spora Plugin Skeleton

Skeleton for a [Spora](https://github.com/spora-ai/spora-core) plugin.

Use this repository as a template for any new `spora-plugin`:

1. Click **Use this template** → **Create a new repository** on GitHub.
2. Rename the package in `composer.json` (e.g. `spora-ai/spora-plugin-tavily`).
3. Rename the namespace (`Spora\Plugins\Skeleton` → `Spora\Plugins\<YourPlugin>`)
   in every PHP file.
4. Update `plugin.json`'s `slug`, `description`, `class`, and `icon`.
5. Replace `src/Tools/EchoTool.php` with your real tool(s); add more files
   under `src/Tools/` and list them in `src/SkeletonPlugin.php::tools()`.
6. If your plugin needs database tables, add Laravel migrations under
   `database/migrations/` and bump `SkeletonPlugin::schemaVersion()`.

## Authoring guidelines

Framework-level conventions — which classes are plugin-stable, what's
framework-internal, schema versioning, deprecation policy — live in the
[Spora docs → Plugin system](https://docs.spora-ai.com/reference/concepts/plugins-system).
The driver / history value-object layer is **framework-internal**:
route plugin logic through `AgentOrchestrator` and `TaskService`.

> **Skills feature note.** The skeleton's `skillPaths()` override
> requires `spora-core ≥ 0.12.0` at runtime (the `skillPaths()` hook
> was added in v0.12). Older spora-core versions will throw a fatal
> when the loader fails to resolve the missing method. The skeleton's
> `composer.json` still requires `>=0.3.0 <1.0.0` for compatibility
> with existing installations; plugin authors using the Skills
> feature should pin to `^0.12`.

## Layout

```
.
├── composer.json          # name=spora-ai/spora-plugin-<x>, type=spora-plugin
├── plugin.json            # manifest the PluginLoader reads at boot
├── src/
│   ├── SkeletonPlugin.php # PluginInterface implementation (FQCN matches plugin.json `class`)
│   └── Tools/
│       └── EchoTool.php   # one tool per file (replace this one)
├── skills/                # skills shipped with the plugin (one folder per skill)
├── agent-templates/       # agent-template files shipped with the plugin
├── tests/                 # Pest unit tests
│   ├── Pest.php
│   └── Unit/
└── .github/workflows/
    └── ci.yml             # pest + phpstan + cs-fixer
```

`skills/` and `agent-templates/` are present in the template (with
`.gitkeep`) so the directory references in `SkeletonPlugin::skillPaths()`
and `SkeletonPlugin::agentTemplatePaths()` resolve out of the box.
Plugin authors can leave them empty if the plugin ships none, or delete
the methods in `SkeletonPlugin.php` to drop the hook entirely.

## Bundled skills (recommended pattern)

`src/CompanionTool.php` declares `recommendsSkills: ['companion-skill']`
on its `#[Tool]` attribute, and `skills/companion-skill/SKILL.md` ships
the matching skill directory. Together they are the fork-and-go example
of spora-core's recommended-skill contract:

- The attribute bundles the skill for the operator — agents can pick it
  from the Skill tool's `allowed_skills` multi-select without any extra
  wiring on your part.
- The runtime validator in spora-core ≥ 0.29.0 enforces strict mode:
  if any slug in `recommendsSkills` is not on disk, `GET /api/v1/tools`
  returns HTTP 500 with code `TOOLS_RECOMMENDS_SKILLS_MISSING` for the
  whole plugin — not just the offending tool. Ship the directory or
  remove the slug; the attribute cannot stay half-declared.
- `tests/Unit/CompanionToolValidationTest.php` is the recommended
  plugin-author pattern: build a `ToolConfigNameResolver` seeded with
  your tool class, hand it to a `ToolsRecommendsSkillsValidator`
  alongside a synthesised `SkillScanner`, and assert `validate()` returns
  an empty list. Copy that test verbatim — it's the build-time gate
  that catches a missing bundled skill before it reaches the tools page.

Delete `CompanionTool`, its companion skill, and the two tests when you
fork the repo into a real plugin; keep `skillPaths()` wired up if your
plugin ships any skills of its own.

## Local development

Clone the repo, install dependencies, and run the tests:

```bash
composer install
./vendor/bin/pest
```

## Publishing

1. Tag the release: `git tag v0.1.0 && git push --tags`.
2. (Optional) Configure Packagist to auto-pull from the GitHub repo.

There's nothing to bump in `plugin.json` or `composer.json` — the runtime reads the version from the git tag via `Composer\InstalledVersions::getPrettyVersion()`, so the tag is the single source of truth.

## CI

Three parallel jobs run on every push to `main`, on `v*` tags, and on
pull requests:

- `test` — Pest on PHP 8.4 + 8.5
- `static-analysis` — PHPStan level 5
- `code-style` — php-cs-fixer dry-run (same ruleset as Spora core)

External actions are pinned to full commit SHAs per the project's supply-chain
policy.
