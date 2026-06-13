# Handoff — Gap Batch Consolidation (step 3)

Clear the small loose ends the walkthroughs deposited in one deliberate pass, rather than
letting them drift across sessions. Three sub-batches: schema-language amendments,
ADR-018 closure, and the one gap with real functional teeth — the missing `hmn` tag modality.

## Scope

Draft the ADR-013 amendments, write the ADR-018 vocabulary/event/reporting contracts, and
record the tag-slice decision with rationale. Each is small individually; the value is
doing them together so the schema language and the prompt/budget contracts stabilize at once.

## Inputs to load

- `design-notes/note-walkthrough.md` §6 (gaps to carry forward).
- `design-notes/search-shape-notes.md` §5, §7 (evidence presentation; tag-modality gap).
- ADR-018 "Open questions surfaced" footer; ADR-016 (budget reporting target).
- ADR-013 (schema language); Hypomnema `hmn 0.7.1` capability surface.

## Batch A — Schema-language amendments (ADR-013)

1. **Evidence-presentation hint.** Search results overlay `matches` / `chunks` / `score`
   on an entity card; today that's a host convention (search-shape §5). Decide: a thin,
   optional per-type hint, or leave it host-side?
   *Lean:* a thin optional hint — declarable but not required; host convention is the default.
2. **Temporal-field semantics.** `created` / `modified` ride in `card_fields` /
   `detail_fields` as bare pointers, so a renderer can't tell they deserve relative-time
   display ("3 days ago"). Decide: a per-field `format` hint, or a dedicated `timestamps` slot?
   *Lean:* a per-field `format` hint — more general, composes with other field types.
3. **Field labeling.** `card_fields` / `detail_fields` are bare pointers with no human
   label; fine for `tags`/`modified`, awkward for `/frontmatter/some_key`. Decide: keep
   bare-and-derive, or add a `{ field, label }` form?
   *Lean:* optional `{ field, label }`; bare pointer still allowed as the common case.

## Batch B — ADR-018 closure

1. **Context-grant vocabulary.** Produce the actual enumerations:
   - sensitivity: `user_input`, `conversation_metadata`, `entity_reference`, `preference`, …
   - scope: `per_turn`, `per_thread`, `per_user`, `per_deployment`
   - handle resolution: which host methods a granted handle authorizes.
2. **Render-failure semantics.** A required prompt (`empty_behavior: "error"`) must fail
   request construction; an optional one (`"omit"`) must degrade with an observable
   omitted-prompt event. Define the event shape, retry policy, and timeout treatment
   relative to ADR-014's operation-execution model.
3. **Token-budget reporting.** Define the standard contract by which a renderer reports
   truncation, omitted sections, and estimated token count back to the ADR-016 planner so
   the planner can attribute overruns and choose next-turn degradations.

## Batch C — The `hmn` tag-modality gap (has teeth)

`hmn 0.7.1` exposes only `--prefix` (folder); there is **no tag search**. Query-shaped
`tag=#x` vault slices therefore have nothing backing them today. This is a functional
limitation, not bookkeeping. Decide:

- (i) wait for a future `hmn` tag modality;
- (ii) adopt a content/metadata convention now (e.g. tag scan via content search);
- (iii) ship folder-slices only in v0, mark tag-slices unsupported, with a clean upgrade path.

*Lean:* (iii) for v0 — folder-slices work today, tag-slices are explicitly "not yet,"
and the query-reference schema already carries enough (`modality`, `scope`) to add a `tag`
modality later without a breaking change.

## Downstream handoffs

- ADR-013 revision (Batch A).
- ADR-018 closure (Batch B) — and the budget-reporting contract feeds ADR-016.
- `open-questions.md` cleanup — remove the items resolved here.
- Step 5 (vertical slice) benefits: the context-grant vocabulary is exercised by the
  slice's prompt assembly, so settling it here de-risks the slice.

## Definition of done

- [ ] ADR-013 amendments drafted (evidence hint, `format` hint, field labeling).
- [ ] ADR-018 context-grant enums, render-failure event shape, and budget-reporting
      contract written.
- [ ] Tag-slice decision recorded with rationale and upgrade path.
- [ ] `open-questions.md` updated.
