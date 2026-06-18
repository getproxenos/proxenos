import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'

import { App } from './App'

const rootElement = document.getElementById('root')
if (!rootElement) {
  throw new Error('Root element #root not found in index.html.')
}

// basename="/app" matches Vite's `base: '/app/'` and the SpaController
// catch-all — client-side routes are written basename-relative (see
// shell/routes.ts).
createRoot(rootElement).render(
  <StrictMode>
    <BrowserRouter basename="/app">
      <App />
    </BrowserRouter>
  </StrictMode>,
)
