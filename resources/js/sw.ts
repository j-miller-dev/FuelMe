/// <reference lib="webworker" />
import { cleanupOutdatedCaches, matchPrecache, precacheAndRoute } from 'workbox-precaching'
import { registerRoute, setCatchHandler } from 'workbox-routing'
import { NetworkFirst } from 'workbox-strategies'

declare const self: ServiceWorkerGlobalScope & { __WB_MANIFEST: Array<{ url: string; revision: string | null }> }

// App shell: hashed JS/CSS/fonts are precached, so cold start is fast and works with a flaky connection.
precacheAndRoute(self.__WB_MANIFEST)
cleanupOutdatedCaches()

// OFFLINE READING. Pages are per-user, so the network always wins when it answers; the last good copy is only the fallback when it does not
// (airplane mode, a tunnel, a gym basement). Cached copies stay on this device and are cleared when you sign out.
const NEVER_CACHE = ['/login', '/logout', '/register', '/forgot-password', '/two-factor-challenge', '/user/confirm-password', '/up']
const keep = {
  // Only a straight 200 for an app page: never a redirect (that would store the login page under the wrong URL), never an error.
  cacheWillUpdate: async ({ response }: { response: Response }) => {
    if (response.status !== 200 || response.redirected) return null
    const path = new URL(response.url).pathname
    return NEVER_CACHE.some((p) => path === p || path.startsWith(p + '/')) ? null : response
  },
}

// Full page loads (opening the app from the home screen).
registerRoute(({ request }) => request.mode === 'navigate', new NetworkFirst({ cacheName: 'pages', networkTimeoutSeconds: 4, plugins: [keep] }))

// Inertia page-to-page visits are fetches with an X-Inertia header; cache them the same way so tapping between tabs works offline too.
registerRoute(({ request, url }) => request.method === 'GET' && request.headers.has('X-Inertia') && url.origin === self.location.origin, new NetworkFirst({ cacheName: 'inertia', networkTimeoutSeconds: 4, plugins: [keep] }))

// The lists the Add sheet needs to log without search: frequent foods, recents, favourites, your meals.
registerRoute(({ request, url }) => request.method === 'GET' && ['/foods/quick', '/meals/list'].includes(url.pathname) && url.origin === self.location.origin, new NetworkFirst({ cacheName: 'api', networkTimeoutSeconds: 3, plugins: [keep] }))

self.addEventListener('message', (e) => {
  if (e.data?.type === 'SKIP_WAITING') void self.skipWaiting()
  if (e.data?.type === 'CLEAR_USER_CACHES') e.waitUntil(Promise.all(['pages', 'inertia', 'api'].map((k) => caches.delete(k))))
})
self.addEventListener('activate', () => void self.clients.claim())

// A first-ever offline visit (nothing cached yet) gets the plain offline page rather than a browser error.
setCatchHandler(async ({ request }) => (request.destination === 'document' ? ((await matchPrecache('/offline.html')) ?? Response.error()) : Response.error()))
