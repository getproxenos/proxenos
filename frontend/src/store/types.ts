/**
 * Wire envelope used by both the cursor-replay endpoint and the Mercure
 * push transport. Live + replay are byte-identical for the same
 * (thread_id, sequence) — the reducer can fold them through one union
 * and dedupe on `id` / `sequence` (handoff §1/§2, ADR-026).
 *
 * Source of truth: `App\Conversation\ConversationEventEnvelope`. Keep
 * field names exact; mismatch would diverge live and replay shapes.
 */
export type ConversationEventType =
  | 'user_message_submitted'
  | 'assistant_turn_created'
  | 'assistant_content_delta'
  | 'assistant_turn_completed'
  | 'assistant_turn_failed'

export type ActorType = 'user' | 'assistant' | 'connector' | 'system' | 'extension'

export interface ConversationEventEnvelope {
  id: string
  sequence: number
  thread_id: string
  turn_id: string | null
  type: ConversationEventType
  version: number
  actor_type: ActorType
  actor_id: string | null
  occurred_at: string
  payload: Record<string, unknown>
}

/** Run lifecycle (`design-notes/streaming-runtime-notes.md` §4). */
export type RunStatus = 'idle' | 'streaming' | 'cancelling' | 'completed' | 'failed'

/**
 * Host-projected message — the SPA's reducer reconstructs this from the
 * event log so live + replay land in the same shape. Folds idempotently
 * on (id, sequence) so duplicate live+replay deliveries converge.
 */
export interface HostMessage {
  id: string
  role: 'user' | 'assistant'
  text: string
  status: 'complete' | 'streaming' | 'failed'
  turnId: string | null
  position: number
}

/**
 * Per-thread reducer state. Keyed by `threadId` so thread-switch
 * mid-stream is safe — the prior thread's state keeps folding any
 * in-flight Mercure deliveries while the UI renders a different thread
 * (handoff §4 reconciliation case 3).
 */
export interface ThreadState {
  threadId: string
  lastSeenSequence: number
  seenEventIds: Set<string>
  messages: HostMessage[]
  runStatus: RunStatus
  activeTurnId: string | null
  /** Last error_summary when runStatus === 'failed'. */
  errorSummary: string | null
}

/**
 * Conversation store — sums per-thread reducer state plus the focused
 * thread for the UI. The branchRepositoryByThreadId and
 * sidePayloadCache slots from `streaming-runtime-notes.md` §4 are
 * reserved for step-5+ (handoff "Hard exclusions") and intentionally
 * absent here so the v0 store stays thin.
 */
export interface ConversationStoreState {
  currentThreadId: string | null
  threadsById: Record<string, ThreadState>
}
