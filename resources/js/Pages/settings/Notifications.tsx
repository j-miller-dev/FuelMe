import { useEffect, useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppLayout from '@/layouts/AppLayout'
import { Button } from '@/components/ui/button'
import { api } from '@/lib/http'
import { currentSubscription, disablePush, enablePush, pushSupported } from '@/lib/push'

interface Props { configured: boolean; publicKey: string | null; devices: number; prefs: { session_fuel: boolean; checkin: boolean } }

export default function Notifications({ configured, publicKey, devices, prefs }: Props) {
  const [here, setHere] = useState<boolean | null>(null)   // is THIS device subscribed
  const [msg, setMsg] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const supported = pushSupported()
  useEffect(() => { void currentSubscription().then((s) => setHere(!!s)).catch(() => setHere(false)) }, [])

  const run = async (fn: () => Promise<string | null | void>) => { setBusy(true); setMsg(null); try { const m = await fn(); if (m) setMsg(m) } catch (e) { setMsg(e instanceof Error ? e.message : 'Something went wrong.') } finally { setBusy(false) } }
  const toggle = (k: 'session_fuel' | 'checkin') => router.put('/push/prefs', { ...prefs, [k]: !prefs[k] }, { preserveScroll: true })

  return (
    <AppLayout>
      <Head title="Notifications" />
      <header className="flex items-center justify-between"><h1 className="text-lg font-semibold">Notifications</h1><Link href="/settings/security" className="min-h-11 px-2 text-sm text-accent underline leading-[2.75rem]">Security</Link></header>
      <p className="mt-1 text-sm text-muted">Optional reminders on this phone. They stay generic (no numbers) because they show on your lock screen, and you can turn them off any time.</p>

      {!configured ? (
        <p className="mt-4 rounded-2xl bg-surface p-4 text-sm">Notifications are not set up on the server yet. Run <code className="rounded bg-raised px-1">php artisan push:keys</code> on the server, set <code className="rounded bg-raised px-1">VAPID_PUBLIC_KEY</code> and <code className="rounded bg-raised px-1">VAPID_PRIVATE_KEY</code>, and redeploy.</p>
      ) : !supported ? (
        <p className="mt-4 rounded-2xl bg-surface p-4 text-sm">This browser cannot do notifications. On an iPhone, open the Share menu, choose Add to Home Screen, then open FUEL from there.</p>
      ) : (
        <section className="mt-4 rounded-2xl bg-surface p-4" aria-label="This device">
          <p className="text-sm">{here ? 'Notifications are on for this device.' : 'Notifications are off for this device.'} <span className="text-muted">{devices} device{devices === 1 ? '' : 's'} in total.</span></p>
          <div className="mt-3 flex flex-wrap gap-2">
            {here ? (
              <>
                <Button type="button" disabled={busy} onClick={() => run(async () => { const r = await api<{ sent: number }>('/push/test', { method: 'POST' }); return r.sent ? 'Sent. It should arrive in a few seconds.' : 'Nothing was delivered. Try turning notifications off and on again.' })}>Send a test</Button>
                <button type="button" disabled={busy} className="min-h-11 px-3 text-sm text-muted underline" onClick={() => run(async () => { await disablePush(); setHere(false); router.reload({ only: ['devices'] }) })}>Turn off on this device</button>
              </>
            ) : <Button type="button" disabled={busy || !publicKey} onClick={() => run(async () => { const m = await enablePush(publicKey as string); if (!m) { setHere(true); router.reload({ only: ['devices'] }) } return m })}>Turn on notifications</Button>}
          </div>
          {msg ? <p role="status" className="mt-2 text-sm text-muted">{msg}</p> : null}
        </section>
      )}

      {configured ? (
        <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Which reminders">
          <h2 className="font-semibold">Which reminders</h2>
          {([['session_fuel', 'Pre-session meal', 'About 3 hours before a session that has a start time, between 6:00 and 21:30.'], ['checkin', 'Weekly check-in ready', 'Sunday evening, only when the check-in has a proposal to look at.']] as const).map(([k, title, text]) => (
            <label key={k} className="mt-3 flex min-h-11 items-start gap-3 text-sm">
              <input type="checkbox" checked={prefs[k]} onChange={() => toggle(k)} className="mt-1 h-5 w-5" />
              <span><span className="font-medium">{title}</span><span className="block text-xs text-muted">{text}</span></span>
            </label>
          ))}
        </section>
      ) : null}
    </AppLayout>
  )
}
