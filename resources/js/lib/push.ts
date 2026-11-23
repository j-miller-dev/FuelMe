import { api } from '@/lib/http'

/** Notifications need a service worker and the Push API. On iPhone that means the app is added to the Home Screen first (iOS 16.4+). */
export const pushSupported = () => typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

const b64ToBytes = (b64: string) => {
  const pad = '='.repeat((4 - (b64.length % 4)) % 4)
  const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from(raw, (c) => c.charCodeAt(0))
}

export async function currentSubscription(): Promise<PushSubscription | null> {
  if (!pushSupported()) return null
  const reg = await navigator.serviceWorker.ready
  return reg.pushManager.getSubscription()
}

/** Ask permission, subscribe this device, and tell the server. Returns an error message, or null on success. */
export async function enablePush(publicKey: string): Promise<string | null> {
  if (!pushSupported()) return 'This browser cannot do notifications. On an iPhone, add FUEL to the Home Screen first.'
  const perm = await Notification.requestPermission()
  if (perm !== 'granted') return 'Notifications are blocked for this site. Allow them in your browser or phone settings, then try again.'
  const reg = await navigator.serviceWorker.ready
  const sub = (await reg.pushManager.getSubscription()) ?? (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(publicKey) }))
  await api('/push/subscribe', { method: 'POST', body: sub.toJSON() })
  return null
}

export async function disablePush(): Promise<void> {
  const sub = await currentSubscription()
  if (!sub) return
  await api('/push/unsubscribe', { method: 'POST', body: { endpoint: sub.endpoint } })
  await sub.unsubscribe()
}
