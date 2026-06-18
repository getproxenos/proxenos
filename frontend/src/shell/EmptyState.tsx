/**
 * Empty-state landing for `/app` with no thread selected (decision 8). New
 * thread creation and the populated sidebar arrive in D3; for D1 this is the
 * honest "nothing selected yet" surface.
 */
export function EmptyState() {
  return (
    <div className="app-empty">
      <h2>No thread selected</h2>
      <p>Pick a thread from the sidebar to get started.</p>
    </div>
  )
}
