# Search Shape — Design Notes (in progress)

> **Status: in progress / provisional.** This is design work in motion, not a settled ADR
> and not a finished walkthrough. It exists because the Note walkthrough surfaced that the
> project has *no defined search shape*, and because the real `hmn` tool gave us concrete
> shapes to design against. It **feeds handoff-2** (query-shaped references are stored
> searches) and the eventual **ADR-013** (schema language). Pieces marked *provisional* are
> the ones most likely to move.

---

## 1. What exists today

There is no search request or result schema anywhere in the notes. Only adjacents:

- **ADR-003** says each provider implements `search` / `render` / `serialize-for-prompt` /
  `suggest` — names the capability, defines no shape. The `overview.md` glossary repeats it.
- **open-questions.md** has the *suggestion signal mix* (lexical / embedding / graph / LLM)
  and *embedding-pipeline location* — these are signals and infrastructure, not a shape.
- **handoff-2** introduces *query-shaped references* (a vault-slice: `tag=#x`,
  `folder=/y/`) that "resolve at attachment time." That's the nearest thing to search, but
  framed as a reference *kind*, not a general search surface.

So both the request shape and the result shape are open. This note proposes both, grounded
in `hmn`.

---

## 2. The real `hmn` surface

`hmn 0.7.1`. The CLI and the MCP surface (`hmn mcp`) are designed to mirror each other, so
the CLI is the authoritative shape. Three search modalities, each with a **different result
shape**:

**Shared request options** (all modalities): `<QUERY>`, `--prefix <PATH>` (restrict to a
vault subdirectory), `--limit <N>`, `--vaults <NAME_OR_ID>` (comma-separated; omit = all
active vaults), `--json`.

**`hmn search filesystem '<glob>'`** — glob over vault paths. No query mode, no score.

```jsonc
// trimmed live result
{ "results": [
  { "path": "Projects/Xcind/.../option-a-shared-xcind-server.md",
    "size": 13511,
    "mtime": "2026-05-08T03:08:10.876572Z",
    "content_hash": "sha256:03e7ffae…",
    "vault": "019e05a4-658c-7832-b7a1-7fe6696fcdbb",
    "vault_name": "claude" }
] }
```

**`hmn search content <q>`** — substring / regex / ranked BM25 (`--mode`). Line-level
matches.

```jsonc
{ "results": [
  { "path": "Conversations/2026-03/.../💬 Adding Xcind to flake.nix with overlays.md",
    "match_count": 56,
    "matches": [
      { "line": 2,  "text": "title: \"Adding Xcind to flake.nix with overlays\"" },
      { "line": 12, "text": "# Adding Xcind to flake.nix with overlays" }
    ],
    "vault": "019e05a4-658c-7832-b7a1-7fe6696fcdbb",
    "vault_name": "claude" }
] }
```

**`hmn search semantic <q>`** — NL embedding search. `--granularity document|chunk`,
`--chunks-per-document <N>`. Document mode groups evidence chunks under a parent doc:

```jsonc
{ "results": [
  { "score": 0.9729247,
    "file_path": "Projects/Xcind/💼 Xcind.md",
    "content_hash": "sha256:c9f2ce8e…",
    "chunks": [
      { "score": 0.9729247, "chunk_index": 0, "heading_path": ["Xcind"],
        "text": "# Xcind\n\n", "text_kind": "preview", "text_truncated": false }
    ],
    "vault": "019e05a4-658c-7832-b7a1-7fe6696fcdbb",
    "vault_name": "claude" }
  ],
  "truncated": true }
```

**Observations that drive the design:**

- **Every result carries `vault` (uuid) + `vault_name` + a path.** Those are exactly the
  components of the §0 identity URI: `hypomnema://<host>/vaults/<uuid>/<path>` (with
  `:vault_name` as the ignored debug suffix). Search results are the concrete *source* of
  reference identity.
- **The three shapes are not normalized:** `path` (filesystem/content) vs `file_path`
  (semantic); `content_hash` present for filesystem/semantic but absent for content;
  `truncated` only on semantic; `score` only on semantic.
- **What varies is the *match evidence*, not the reference.** filesystem → none; content →
  line snippets; semantic → scored chunks with heading breadcrumbs. The thing pointed *at*
  is always a Note.

---

## 3. Host-facing **request** shape (provisional)

Normalize the CLI options into one envelope. `modality` is the discriminator; `options` is
an open, modality-specific bag.

```json
{
  "provider": "hypomnema",
  "modality": "semantic",
  "query": "xcind",
  "scope": {
    "vaults": ["personal", "claude"],
    "prefix": "Projects/Xcind/"
  },
  "limit": 20,
  "options": {
    "granularity": "document",
    "chunks_per_document": 3
  }
}
```

- **`modality`** — `filesystem | content | semantic`. *Provisional:* an `auto` / hybrid
  value that fans out across modalities (and merges by score) is a likely future addition;
  for now the caller picks one, matching `hmn`.
- **`scope`** — `vaults` (names or ids; omit = all active) maps to `--vaults`; `prefix`
  maps to `--prefix`. Grouped because both narrow *where* to look.
- **`options`** — modality-specific, kept open so modalities can add params without
  changing the envelope: `mode` (content: substring/regex/ranked), `granularity` +
  `chunks_per_document` (semantic). filesystem needs none.

This same envelope is what a **query-shaped reference** stores (see §6).

---

## 4. Host-facing **result** shape (provisional)

One normalized shape across modalities — and, by design, across providers. Each result is
a **reference + optional score + tagged evidence + optional meta**.

```json
{
  "provider": "hypomnema",
  "modality": "semantic",
  "truncated": true,
  "results": [
    {
      "ref": {
        "provider": "hypomnema",
        "type": "hypomnema.note",
        "id": "hypomnema://localhost/vaults/019e05a4-658c-7832-b7a1-7fe6696fcdbb/Projects/Xcind/💼 Xcind.md",
        "label": "Xcind"
      },
      "score": 0.9729247,
      "evidence": {
        "kind": "chunks",
        "chunks": [
          {
            "chunk_index": 0,
            "heading_path": ["Xcind"],
            "text": "# Xcind\n\n",
            "text_kind": "preview",
            "text_truncated": false,
            "score": 0.9729247
          }
        ]
      },
      "meta": {
        "content_hash": "sha256:c9f2ce8e745bdf8b118baa023e576df0c84e8dc90d88c0dc3df01323e7a0aa39"
      }
    }
  ]
}
```

**The `evidence` tagged union** — `kind` discriminates; the reference stays identical
across all three:

```jsonc
// filesystem — no match evidence, just the file
{ "kind": "none" }

// content — line-level snippets
{ "kind": "matches",
  "match_count": 56,
  "matches": [ { "line": 2, "text": "title: \"Adding Xcind to flake.nix…\"" } ] }

// semantic — scored chunks with heading breadcrumb
{ "kind": "chunks",
  "chunks": [ { "chunk_index": 0, "heading_path": ["Xcind"], "text": "# Xcind\n\n",
                "text_kind": "preview", "text_truncated": false, "score": 0.97 } ] }
```

**Annotations.**

- **`ref`** reuses the Note walkthrough's `{ provider, type, id }` triple verbatim. The
  adapter builds `id` from the result's `vault` + path. This is why the triple was designed
  to carry a vault-scoped URI — search is its first heavy consumer.
- **`score`** is optional (present for semantic; absent for substring content and
  filesystem). When a modality has no global score, it's omitted rather than faked.
- **`meta`** is an open passthrough for provider extras that are neither identity nor
  evidence: `content_hash`, `mtime`, `size`. `content_hash` is worth keeping — it lets the
  host cache rendered results and detect staleness without re-fetching.
- **`truncated`** is promoted to the top level for every modality (today only `hmn`
  semantic emits it); the adapter sets it false when the source omits it. *Gap:* this is
  the only pagination signal — see §7.
- **`label`** is host-derived (filename stem / title), not a raw `hmn` field.

**Round-trip check** — every live `hmn` field has a home:

| `hmn` field | modality | normalized location |
|---|---|---|
| `path` / `file_path` | all | parsed into `ref.id` (vault + path → URI) |
| `vault` | all | `ref.id` (uuid segment) |
| `vault_name` | all | dropped from identity; only builds the `:name` debug suffix |
| `size`, `mtime` | filesystem | `meta` |
| `content_hash` | filesystem, semantic | `meta` |
| `match_count`, `matches[]` | content | `evidence.kind = "matches"` |
| `score` (document) | semantic | `result.score` |
| `chunks[]` (+ chunk fields) | semantic | `evidence.kind = "chunks"` |
| `truncated` | semantic | top-level `truncated` |

---

## 5. Rendering a result (sketch)

A result row = **the entity's card hints** (resolve `ref` → its type's presentation hints
for `title` / `icon` / `summary`) **+ an evidence overlay**:

- `matches` → highlighted lines with line numbers,
- `chunks` → excerpt(s) with the `heading_path` as a breadcrumb,
- `score` → an optional relevance badge,
- `none` → just the card.

*Gap:* the schema language (Note walkthrough §3 slots) has no "evidence presentation" hint
yet. For now treat evidence rendering as a host convention; revisit if it needs to be
declarable per type. Noted for ADR-013.

---

## 6. Wiring to the rest of the design

- **Query-shaped references (handoff-2).** A vault-slice *is* a stored search request: the
  §3 envelope with `modality` + `scope`, persisted on the reference. "Resolve at attachment
  time" = run it and return the §4 result list; "re-resolve on viewing" = run it again.
  A `folder=/Projects/Foo/` slice maps to `scope.prefix`. **This is the direct feeder for
  handoff-2** — the request shape is the query-reference payload, and the result shape is
  what `resolves_to` yields.
- **Suggestion signal mix (open-questions).** The signals named there *are* these
  modalities: content = lexical/BM25, semantic = embedding. Graph walks are a separate,
  relationship-based signal (not an `hmn` search modality). A suggestion is a ranked union
  of §4 results across modalities and providers — so this result shape doubles as the
  suggestion result shape, with the engine owning the merge/rank.
- **Identity (§0 / Note walkthrough).** Confirmed empirically: results carry exactly
  `vault` + `vault_name` + path, which is all the adapter needs to construct `ref.id`. The
  `vault_name` is debug sugar only — never used for matching.

---

## 7. Decisions & gaps

**Locked this session.**

- Search captured as this in-progress note (provisional), feeding handoff-2 + ADR-013.
- **Normalized result envelope:** universal `ref` + modality-tagged `evidence` union
  (`none | matches | chunks`). Provider adapter normalizes `hmn`'s per-modality JSON.

**Recommended (overridable).**

- Explicit `modality` discriminator on the request (with future `auto`/hybrid).
- `meta` passthrough for `content_hash` / `mtime` / `size`; keep `content_hash` for caching.
- Modality `options` and `scope` as shown; `score` and `evidence` payloads optional per
  modality.

**Gaps to carry forward.**

- **No tag modality in `hmn 0.7.1`** — only `--prefix` (folder) exists. handoff-2's
  `tag=#x` slices therefore have no backing search yet; they need either a future `tag`
  modality or a content/metadata convention. Flag for handoff-2.
- **Evidence-presentation hint** is not in the schema language (§5). Host convention for
  now; candidate ADR-013 slot.
- **Pure reference vs. hydrated snapshot** — results return references, not entity bodies.
  Whether a result may optionally embed a partial entity snapshot (to avoid a follow-up
  fetch for rendering) is open; interacts with the prompt-budget / transclusion design.
- **Pagination** — only `limit` + `truncated` today; no cursor. Fine for v0, but a
  cursor/offset will be needed for "load more."
- **Cross-provider merge & ranking** — once GitHub/Linear providers exist, scores aren't
  comparable across providers. That's a suggestion-engine concern, but the result shape
  must carry enough (`provider`, `score?`, `modality`) for the engine to merge sensibly.
