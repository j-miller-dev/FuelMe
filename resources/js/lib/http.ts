/** Same-origin JSON requests with Laravel's XSRF cookie. Throws an Error carrying the server's first validation message. */
export function xsrf(): string {
  const raw = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length)
  return raw ? decodeURIComponent(raw) : ''
}

export async function api<T>(url: string, init: { method?: string; body?: unknown; signal?: AbortSignal } = {}): Promise<T> {
  const res = await fetch(url, {
    method: init.method ?? 'GET',
    credentials: 'same-origin',
    signal: init.signal,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
    body: init.body === undefined ? undefined : JSON.stringify(init.body),
  })
  if (!res.ok) {
    let msg = res.status === 419 ? 'Your session expired. Reload the page.' : `Request failed (${res.status})`
    try {
      const b = (await res.json()) as { message?: string; errors?: Record<string, string[]> }
      msg = (b.errors ? Object.values(b.errors)[0]?.[0] : undefined) ?? b.message ?? msg
    } catch { /* keep the generic message */ }
    throw new Error(msg)
  }
  return (await res.json()) as T
}

export const uuid = (): string =>
  typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = (Math.random() * 16) | 0; return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16) })
