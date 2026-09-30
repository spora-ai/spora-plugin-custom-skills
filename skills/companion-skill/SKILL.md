---
name: companion-skill
description: "Demo skill bundled by CompanionTool — fork-and-go example of #[Tool(recommendsSkills: ...)]."
---

# Companion skill

Provides the companion capability that CompanionTool expects to be available.
The slug declared on `CompanionTool::recommendsSkills` must match this directory
name; the runtime validator rejects a mismatch with `TOOLS_RECOMMENDS_SKILLS_MISSING`.