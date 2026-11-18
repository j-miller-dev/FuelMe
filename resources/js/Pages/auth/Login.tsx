import { Head, Link, useForm } from '@inertiajs/react'
import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Field } from '@/components/ui/field'
import { Guest } from '@/layouts/AppLayout'
import { loginWithPasskey, passkeysSupported } from '@/lib/passkeys'

export default function Login({ canRegister }: { canRegister: boolean }) {
  const form = useForm({ email: '', password: '', remember: true })
  const [passkeyError, setPasskeyError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function passkey() {
    setPasskeyError(null)
    setBusy(true)
    try {
      await loginWithPasskey(form.data.remember)
    } catch (e) {
      // A cancelled prompt is not an error worth shouting about.
      const name = e instanceof Error ? e.name : ''
      setPasskeyError(name === 'NotAllowedError' ? 'Passkey sign-in was cancelled.' : e instanceof Error ? e.message : 'Passkey sign-in failed.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Guest title="Sign in">
      <Head title="Sign in" />
      {passkeysSupported() ? (
        <div className="mb-6">
          <Button className="w-full" onClick={passkey} disabled={busy}>{busy ? 'Waiting for passkey…' : 'Sign in with a passkey'}</Button>
          {passkeyError ? <p role="alert" className="mt-2 text-sm text-danger">{passkeyError}</p> : null}
          <div className="my-6 flex items-center gap-3 text-xs text-muted"><span className="h-px flex-1 bg-raised" />or use your password<span className="h-px flex-1 bg-raised" /></div>
        </div>
      ) : null}
      <form onSubmit={(e) => { e.preventDefault(); form.post('/login') }} className="space-y-4">
        <Field label="Email" type="email" autoComplete="username webauthn" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} error={form.errors.email} required />
        <Field label="Password" type="password" autoComplete="current-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} error={form.errors.password} required />
        <label className="flex min-h-12 items-center gap-3 text-sm text-muted">
          <input type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} className="size-5 accent-[var(--accent)]" />
          Keep me signed in on this device
        </label>
        <Button variant="secondary" className="w-full" disabled={form.processing}>Sign in</Button>
      </form>
      {canRegister ? <p className="mt-6 text-center text-sm text-muted">First time? <Link href="/register" className="text-accent underline">Create your account</Link></p> : null}
    </Guest>
  )
}
