import { Head, Link, router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Field } from '@/components/ui/field'
import AppLayout from '@/layouts/AppLayout'
import { deletePasskey, passkeysSupported, registerPasskey } from '@/lib/passkeys'

interface Passkey { id: number; name: string; createdAt: string | null; lastUsedAt: string | null }

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('en-AU', { day: 'numeric', month: 'short', year: 'numeric' }) : 'never')

export default function Security({ passkeys }: { passkeys: Passkey[] }) {
  const [name, setName] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const pw = useForm({ current_password: '', password: '', password_confirmation: '' })

  async function add() {
    setError(null)
    setBusy(true)
    try {
      await registerPasskey(name.trim() || 'My phone')
      setName('')
      router.reload({ only: ['passkeys'] })
    } catch (e) {
      setError(e instanceof Error && e.name === 'NotAllowedError' ? 'Passkey setup was cancelled.' : e instanceof Error ? e.message : 'Passkey setup failed.')
    } finally {
      setBusy(false)
    }
  }

  async function remove(p: Passkey) {
    setError(null)
    try {
      await deletePasskey(p.id)
      router.reload({ only: ['passkeys'] })
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Could not remove the passkey.')
    }
  }

  return (
    <AppLayout>
      <Head title="Security" />
      <h1 className="mb-6 text-2xl font-semibold">Security</h1>

      <section className="mb-8" aria-labelledby="pk">
        <h2 id="pk" className="mb-1 font-semibold">Passkeys</h2>
        <p className="mb-3 text-sm text-muted">Sign in with your fingerprint, face or screen lock instead of a password. Add one on each device you use.</p>
        <ul className="mb-4 space-y-2">
          {passkeys.length === 0 ? <li className="rounded-xl bg-surface px-4 py-3 text-sm text-muted">No passkeys yet.</li> : null}
          {passkeys.map((p) => (
            <li key={p.id} className="flex items-center justify-between rounded-xl bg-surface px-4 py-3">
              <div>
                <div className="font-medium">{p.name}</div>
                <div className="text-xs text-muted">Added {when(p.createdAt)} · last used {when(p.lastUsedAt)}</div>
              </div>
              <Button variant="danger" className="min-h-11 px-3 text-sm" onClick={() => remove(p)} aria-label={`Remove passkey ${p.name}`}>Remove</Button>
            </li>
          ))}
        </ul>
        {passkeysSupported() ? (
          <div className="space-y-3">
            <Field label="Name this device" placeholder="e.g. Pixel 9" value={name} onChange={(e) => setName(e.target.value)} />
            <Button className="w-full" onClick={add} disabled={busy}>{busy ? 'Waiting for your device…' : 'Add a passkey'}</Button>
          </div>
        ) : <p className="text-sm text-muted">This browser does not support passkeys.</p>}
        {error ? <p role="alert" className="mt-2 text-sm text-danger">{error}</p> : null}
      </section>

      <section className="mb-8" aria-labelledby="pw">
        <h2 id="pw" className="mb-3 font-semibold">Password</h2>
        <form onSubmit={(e) => { e.preventDefault(); pw.put('/user/password', { preserveScroll: true, onSuccess: () => pw.reset() }) }} className="space-y-4">
          <Field label="Current password" type="password" autoComplete="current-password" value={pw.data.current_password} onChange={(e) => pw.setData('current_password', e.target.value)} error={pw.errors.current_password} />
          <Field label="New password" type="password" autoComplete="new-password" value={pw.data.password} onChange={(e) => pw.setData('password', e.target.value)} error={pw.errors.password} />
          <Field label="Confirm new password" type="password" autoComplete="new-password" value={pw.data.password_confirmation} onChange={(e) => pw.setData('password_confirmation', e.target.value)} />
          <Button variant="secondary" className="w-full" disabled={pw.processing}>{pw.recentlySuccessful ? 'Password updated' : 'Change password'}</Button>
        </form>
      </section>

      <Link href="/shopping" className="mb-2 block text-center text-sm text-muted underline">Shopping lists</Link>
      <Link href="/meals" className="mb-2 block text-center text-sm text-muted underline">Meals</Link>
      <Link href="/foods" className="mb-2 block text-center text-sm text-muted underline">Your foods</Link>
      <Link href="/settings/about" className="mb-4 block text-center text-sm text-muted underline">About &amp; data sources</Link>
      <Button variant="ghost" className="w-full" onClick={() => router.post('/logout')}>Sign out</Button>
    </AppLayout>
  )
}
