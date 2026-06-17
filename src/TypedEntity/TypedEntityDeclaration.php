<?php

declare(strict_types=1);

namespace App\TypedEntity;

/**
 * In-process declaration for a typed entity (ADR-013 envelope + ADR-017 host
 * storage contract). One implementation per `provider.type` — e.g.
 * `core.document` — produces the ADR-013 envelope shape as plain PHP data so
 * the resolver, the schema-driven renderer, and (later) the operation
 * registry can dispatch on `provider+type` without baking provider-specific
 * code paths into the host.
 *
 * The shape is registry-adoptable later (ADR-014 / F2): when the registry
 * lands, the registry simply iterates over the declarations and emits the
 * same envelopes; declarations do not need to change.
 *
 * The host is "core" while ADR-010 is closed; `provider()` returns `"core"`
 * for any host-native type. External providers (Hypomnema, …) will return
 * their own namespace in part 2.
 */
interface TypedEntityDeclaration
{
    /**
     * Routing field: the provider that owns this type. The envelope uses
     * this for resolver and renderer dispatch (and, later, for the operation
     * registry's executor selection).
     */
    public function provider(): string;

    /**
     * Routing field: the namespaced type id (e.g. `core.document`).
     * Convention is `<provider>.<short>` so a type id is self-describing in
     * the event log / reference envelope.
     */
    public function type(): string;

    /**
     * The full ADR-013 envelope (`type`, `type_version`, `provider`,
     * `envelope_version`, `schema`, `presentation`, `capabilities`).
     *
     * The envelope is intentionally a plain associative array, not a typed
     * VO: it is published to the SPA verbatim, serializes to JSON 1:1, and
     * matches the on-the-wire shape the ADR-010 process boundary will use in
     * part 2. Typing each slot would just be a second representation to
     * keep in sync.
     *
     * @return array{
     *     envelope_version: string,
     *     provider: string,
     *     type: string,
     *     type_version: string,
     *     custom_renderer: ?string,
     *     schema: array<string, mixed>,
     *     presentation: array<string, mixed>,
     *     capabilities: array<string, mixed>,
     * }
     */
    public function envelope(): array;
}
