/** Offline copies of your pages live on this device only. Signing out removes them. (Changes still waiting to sync stay, and go out when you sign back in.) */
export async function clearOfflineCaches(): Promise<void> {
  try {
    if ('caches' in window) await Promise.all(['pages', 'inertia', 'api'].map((k) => caches.delete(k)))
    navigator.serviceWorker?.controller?.postMessage({ type: 'CLEAR_USER_CACHES' })
  } catch { /* nothing to clear */ }
}
