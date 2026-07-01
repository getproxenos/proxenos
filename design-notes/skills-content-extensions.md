# Skills as Content Extensions — Design Note

> **Tracked in Linear:** [BDS-43 — Skills as content packages (ADR-015)](https://linear.app/beausimensen/issue/BDS-43) · epic [BDS-35 — external-provider boundary](https://linear.app/beausimensen/issue/BDS-35).

Skills are treated as packaged prompt content that extensions may ship, not as a
new top-level extension primitive. The host indexes skill declarations, exposes
available skills to the model, and pins activated skill content to a thread.

**Source session:** `reference/iris/iris-deeper-dive-session-06.md`.
**Anchored by:** ADR-010 (host-mediated extension surface), ADR-014 (Operation
Registry), `design-notes/prompt-declaration-walkthrough.md`.

---

## 1. Reference read

Iris uses the Anthropic-style `SKILL.md` package shape:

- A configured filesystem directory contains one subdirectory per skill.
- Each skill directory has a `SKILL.md` with YAML frontmatter (`name`,
  `description`, optional `allowed-tools` (normalized to `allowed_tools` in the host catalog),
  license/compatibility metadata) plus markdown instructions.
- A loader scans the directory and parses skills into an in-memory value object.
- An `activate_skill` tool loads a named skill, returns its instructions
  immediately, and pins the skill name into thread settings.
- A `deactivate_skill` tool removes the pinned name from the thread.
- Prompt producers list available skills and inject the full content of pinned
  skills as system messages on later turns.

That shape works because skills are content with activation semantics. They do not
need their own runtime protocol separate from the host's existing prompt, tool, and
thread-state machinery.

## 2. Position

For this project, a **Skill Package** is a content artifact with a stable identity:

```json
{
  "kind": "skill",
  "id": "hypomnema.vault-curator",
  "version": "1.0.0",
  "provider": "hypomnema",
  "format": "skill-md",
  "entrypoint": "SKILL.md",
  "metadata": {
    "name": "vault-curator",
    "description": "Use when organizing Obsidian vault notes.",
    "allowed_tools": ["hypomnema.search", "hypomnema.read_note"]
  }
}
```

The host owns the catalog and activation state:

- **Discovery:** extensions declare skill packages during ADR-010 capability
  negotiation, or the host indexes configured local skill roots for first-party
  packages.
- **Validation:** the host parses frontmatter, checks required fields, records
  source/provider/version, and enforces size and tool-allowlist constraints.
- **Activation:** activating a skill creates a thread-scoped pin to a skill
  version. Pinned skills are part of thread state and therefore flow through the
  event log/projection model.
- **Prompting:** skill availability and pinned skill content are prompt
  declarations owned by the host, not ad-hoc injection by the extension.
- **Tools:** a skill may request tool access in metadata, but that does not grant
  access. Tool grants still come from the host's capability/trust policy.

## 3. Multi-user implications

A global filesystem scan is acceptable for a single-user local app but does not
generalize as the only mechanism in a multi-user workspace. The multi-user version
needs explicit scope:

- `source_scope`: `core`, `tenant`, `user`, or `extension`.
- `visibility`: which tenants/users can see and activate the skill.
- `version`: pinned activation should resolve to the same content across future
  turns unless the user/admin upgrades the pin.
- `source_uri`: where the host can reload the package (`file://` for local
  first-party packages, extension-owned URI for out-of-process providers).
- `content_hash`: to detect mutation and support audit/debug views.

The v0 personal build can scan a configured local directory, but it should index
the result into the same catalog shape used by extension-declared skills. That keeps
the cheap local path from becoming a dead-end.

## 4. Registry stance

Use a **host-local catalog**, not a marketplace-style registry, for v0.

A catalog adds real value:

- stable ids and versions for thread pins;
- visibility and trust decisions for multi-user use;
- deduplication when multiple sources expose the same package;
- audit/debug visibility into what instructions entered a model request.

A remote registry or marketplace adds distribution, reviews, signing, and update
policy before there is a real need. The design should leave room for one later, but
the near-term registry is just the host's local index of first-party and
extension-declared packages.

## 5. Relationship to existing primitives

Skills reuse existing primitives:

- **Prompt Declaration:** available-skill list and pinned-skill injection are prompt
  declarations rendered by the host.
- **Thread state:** activation is a thread-scoped pin, like attached context but
  targeting a skill package/version rather than an entity reference.
- **Tools/MCP:** a skill can describe expected tools, but tool exposure remains MCP
  or host-tool policy.
- **Operation Registry:** no new operation category is needed. Model-adjacent work
  a skill triggers should already be modeled as tools, prompt declarations, or
  operations.

Promotion to a first-class extension primitive should require evidence that skills
need lifecycle or runtime behavior not covered by catalog + prompt + thread pins.
