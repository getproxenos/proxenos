# Connector Primitive — Design Notes

> **Status: decided enough for v0 implementation.** This note locks the connector slice of
> ADR-010: how out-of-process transport connectors enter the host, what they declare, and
> where identity, delivery, persistence, and model execution boundaries sit.

**Anchored by:** ADR-010 (host-mediated extension surface), ADR-001/004 (server-authoritative,
event-log streaming), ADR-003/013 (typed provider primitives).

---

## 1. Frame

A connector is a transport adapter: web chat, CLI, Slack, Telegram, an inbound MCP-facing
surface, or a scheduler-triggered proactive path. It is not the agent runtime, not the
conversation store, and not the identity authority. Connectors run out-of-process and talk to
the host over ADR-010's JSON-RPC-shaped extension protocol.

The host owns the gateway. A gateway turn is:

1. accept a normalized inbound message from a connector;
2. resolve the actor and authorization context;
3. create or locate durable user/assistant message records;
4. build and run the response pipeline;
5. append durable stream events;
6. ask the connector to deliver those events in the transport's native way.

This keeps the extension boundary around transport-specific ingress/egress while preserving
server-authoritative state and replay.

## 2. Boundary Decisions

### Identity resolution lives in the host

Connectors may report transport identity claims, but they do not resolve those claims into
host users. The normalized inbound message carries an `actor_claim`:

```json
{
  "connector_id": "slack",
  "actor_claim": {
    "scheme": "slack_user",
    "subject": "U12345",
    "workspace": "T67890",
    "display": "Beau"
  }
}
```

The host maps `(connector_id, scheme, subject, workspace?)` to a user, tenant, permissions, and
conversation visibility. First-party web remains a special case only in claim shape: the HTTP
session or token has already authenticated the user, so the claim is `host_user` and the host
still validates it.

An MCP-style server that claims to "be a transport" is therefore just a connector with MCP-shaped
ingress. It may authenticate the remote MCP client to itself, but the host only trusts a declared
claim after applying host-side mapping and grants. No extension can mint a host user by asserting
one.

### Gateway responsibility

The host gateway owns:

- identity resolution, tenant scoping, authorization, and connector grants;
- inbound normalization validation;
- thread/message creation, retry semantics, branching, cancellation state, and event-log writes;
- response-pipeline construction, model/tool selection, prompt/context assembly, and token accounting;
- stream replay, durable completion/failure marking, and client-visible event sequencing.

The gateway should be trigger-agnostic. HTTP requests, queue jobs, scheduler ticks, and connector
webhooks all enter the same turn API once they have produced a normalized inbound message.

### Connector responsibility

A connector owns:

- transport-specific ingress parsing and signature/auth verification for its own transport;
- mapping raw transport payloads into the host's normalized inbound message;
- declaring delivery capabilities and limits;
- delivering host stream events to the transport, either live or aggregated;
- transport-specific formatting constraints, rate limits, chunking, and acknowledgements.

Connectors do not build prompts, call models, persist conversation content, decide host identity,
or mutate host state except through declared host primitives.

## 3. Capability Declaration

At extension handshake, a connector declares one or more `connector.transport` capabilities:

```json
{
  "name": "connector.transport",
  "version": "0.1.0",
  "connector_id": "slack",
  "display_name": "Slack",
  "ingress": {
    "modes": ["webhook"],
    "actor_claim_schemes": ["slack_user"],
    "thread_claims": true,
    "attachments": ["image", "file"]
  },
  "delivery": {
    "mode": "aggregate",
    "events": ["text_delta", "tool_call_summary", "citation", "final", "error"],
    "max_message_chars": 40000,
    "supports_update": true,
    "supports_cancel_notice": true
  },
  "requires": {
    "host_primitives": ["conversation.turn.submit", "conversation.stream.subscribe"]
  }
}
```

Minimal v0 fields:

- `connector_id`: stable identifier used in identity-link records and event metadata.
- `version`: connector capability schema version, separate from the extension protocol version.
- `ingress.modes`: how messages arrive (`webhook`, `poll`, `host_callback`, `stdio`, `http`).
- `ingress.actor_claim_schemes`: claim schemes the connector can emit.
- `ingress.attachments`: accepted inbound attachment classes; empty if text-only.
- `delivery.mode`: `stream`, `aggregate`, or `none`.
- `delivery.events`: stream event kinds the connector can faithfully deliver.
- `delivery` limits: message size, update/edit support, cancellation notice support.

Tool use is not a connector capability by itself. Tools belong to the response pipeline and MCP
tool primitive. A connector only declares whether it can render tool-related stream events
faithfully, summarize them, or hide them.

## 4. Wire Shape

The host exposes a connector-facing primitive set over JSON-RPC:

- `conversation.turn.submit`: connector sends a normalized inbound message and receives a
  host `turn_id`, `thread_id`, and delivery subscription handle.
- `conversation.stream.subscribe`: connector receives ordered host stream events for a turn.
- `conversation.turn.cancel`: connector reports a user cancel request; host records cancellation
  intent and the pipeline cooperatively stops.
- `connector.delivery.ack`: connector reports final delivery status, transport message ids, and
  retryable/non-retryable delivery failures.

The normalized inbound message is host-shaped:

```json
{
  "connector_id": "slack",
  "actor_claim": { "scheme": "slack_user", "subject": "U12345", "workspace": "T67890" },
  "thread_claim": { "scheme": "slack_channel_thread", "subject": "C111:1720000000.000100" },
  "content": [{ "kind": "text", "text": "Summarize the attached decision." }],
  "attachments": [],
  "idempotency_key": "slack:T67890:C111:1720000000.000100:U12345:1720000100.000200",
  "metadata": { "raw_event_id": "EvABC" }
}
```

Host stream events are the same durable events used by clients under ADR-004. Connectors may
down-convert them for a transport, but the canonical event log stays host-owned.

## 5. Operational Notes

- Discovery can stay outside this note: a manifest or Compose label tells the host how to start
  or reach an extension; handshake tells the host what connector capabilities that extension
  actually offers.
- Grants are host-side. For v0, local deployments may allow all configured connectors, but the
  data model should still record which connector ids and actor claim schemes are trusted.
- Delivery failures do not roll back the assistant message. They mark delivery status separately
  from generation status so a failed Slack post can be retried without re-running the model.
- Aggregating connectors still subscribe to the event stream. They buffer and send a final
  transport message, while the host persists every event for replay and audit.
