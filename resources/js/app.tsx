import { createInertiaApp } from '@inertiajs/react'
import { createRoot } from 'react-dom/client'

createInertiaApp({
  title: (title) => (title ? `${title} · FUEL` : 'FUEL'),
  resolve: (name) => {
    const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true }) as Record<string, { default: unknown }>
    const page = pages[`./Pages/${name}.tsx`]
    if (!page) throw new Error(`Unknown Inertia page: ${name}`)
    return page as never
  },
  setup({ el, App, props }) {
    createRoot(el).render(<App {...props} />)
  },
  progress: { color: '#4DF0B0' },
})

// PWA: the service worker is served from "/" (see routes/web.php). Registration fails soft; the app works without it.
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js', { scope: '/' }).then((reg) => {
      // A new version was deployed: tell the app, which offers an Update button (the old version keeps working until you tap it).
      const announce = () => { if (reg.waiting && navigator.serviceWorker.controller) window.dispatchEvent(new CustomEvent('fuel-sw-update', { detail: reg })) }
      announce()
      reg.addEventListener('updatefound', () => reg.installing?.addEventListener('statechange', announce))
      document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') void reg.update().catch(() => undefined) })
    }).catch(() => undefined)
  })
}
