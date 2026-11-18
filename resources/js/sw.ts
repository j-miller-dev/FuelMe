/// <reference lib="webworker" />
import { cleanupOutdatedCaches, matchPrecache, precacheAndRoute } from 'workbox-precaching'
import { NavigationRoute, registerRoute, setCatchHandler } from 'workbox-routing'
import { NetworkOnly } from 'workbox-strategies'

declare const self: ServiceWorkerGlobalScope & { __WB_MANIFEST: Array<{ url: string; revision: string | null }> }

// App shell: hashed JS/CSS/fonts are precached, so cold start is fast and works with a flaky connection.
precacheAndRoute(self.__WB_MANIFEST)
cleanupOutdatedCaches()

// Pages are server-rendered per user (Inertia), so navigations always go to the network and are never served stale.
// When the network is unreachable the visitor sees the precached offline page instead of a browser error.
registerRoute(new NavigationRoute(new NetworkOnly()))
setCatchHandler(async ({ request }) => {
  if (request.destination === 'document') {
    return (await matchPrecache('/offline.html')) ?? Response.error()
  }
  return Response.error()
})

self.addEventListener('message', (e) => {
  if (e.data?.type === 'SKIP_WAITING') void self.skipWaiting()
})
self.addEventListener('activate', () => void self.clients.claim())
