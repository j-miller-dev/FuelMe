import { startAuthentication, startRegistration } from '@simplewebauthn/browser'

function xsrfToken(): string {
  const raw = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length)
  return raw ? decodeURIComponent(raw) : ''
}

async function api(url: string, init: RequestInit = {}): Promise<Response> {
  return fetch(url, {
    credentials: 'same-origin',
    ...init,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-XSRF-TOKEN': xsrfToken(),
      ...init.headers,
    },
  })
}

async function failure(res: Response, fallback: string): Promise<Error> {
  try {
    const body = (await res.json()) as { message?: string; errors?: Record<string, string[]> }
    const first = body.errors ? Object.values(body.errors)[0]?.[0] : undefined
    return new Error(first ?? body.message ?? fallback)
  } catch {
    return new Error(fallback)
  }
}

export const passkeysSupported = () => typeof window !== 'undefined' && 'PublicKeyCredential' in window

/** Sign in with a discoverable passkey (no username needed). Throws a user-readable Error on failure/cancel. */
export async function loginWithPasskey(remember: boolean): Promise<void> {
  const optionsRes = await api('/passkeys/login/options')
  if (!optionsRes.ok) throw await failure(optionsRes, 'Could not start passkey sign-in.')
  const { options } = (await optionsRes.json()) as { options: Parameters<typeof startAuthentication>[0]['optionsJSON'] }

  const credential = await startAuthentication({ optionsJSON: options })

  const res = await api('/passkeys/login', { method: 'POST', body: JSON.stringify({ credential, remember }) })
  if (!res.ok) throw await failure(res, 'That passkey was not accepted.')
  // Full navigation so the new session's CSRF token and Inertia version are picked up.
  window.location.assign('/')
}

/** Register a passkey for the signed-in user. Requires a recent password confirmation (enforced server-side). */
export async function registerPasskey(name: string): Promise<void> {
  const optionsRes = await api('/user/passkeys/options')
  if (!optionsRes.ok) throw await failure(optionsRes, 'Could not start passkey setup.')
  const { options } = (await optionsRes.json()) as { options: Parameters<typeof startRegistration>[0]['optionsJSON'] }

  const credential = await startRegistration({ optionsJSON: options })

  const res = await api('/user/passkeys', { method: 'POST', body: JSON.stringify({ name, credential }) })
  if (!res.ok) throw await failure(res, 'Could not save the passkey.')
}

export async function deletePasskey(id: number): Promise<void> {
  const res = await api(`/user/passkeys/${id}`, { method: 'DELETE' })
  if (!res.ok) throw await failure(res, 'Could not remove the passkey.')
}
