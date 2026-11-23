import { Head, Link, router, useForm } from '@inertiajs/react'
import AppLayout from '@/layouts/AppLayout'
import { Button } from '@/components/ui/button'

export default function Todoist({ connected }: { connected: boolean }) {
  const form = useForm({ token: '' })
  return (
    <AppLayout>
      <Head title="Todoist" />
      <header className="flex items-center justify-between"><h1 className="text-lg font-semibold">Todoist</h1><Link href="/shopping" className="min-h-11 px-2 text-sm text-accent underline leading-[2.75rem]">Shopping</Link></header>
      <p className="mt-1 text-sm text-muted">Send the items you still need on a shopping list to Todoist, under a project called &quot;FUEL shopping&quot;. FUEL only adds tasks. It never edits or deletes anything in Todoist, and an item is never added twice.</p>
      {connected ? (
        <section className="mt-4 rounded-2xl bg-surface p-4">
          <p className="text-sm">Todoist is connected. Open a shopping list and tap Send to Todoist.</p>
          <button type="button" className="mt-2 min-h-11 text-sm text-muted underline" onClick={() => { if (confirm('Disconnect Todoist? Tasks already sent stay in Todoist.')) router.delete('/todoist/connection', { preserveScroll: true }) }}>Disconnect</button>
        </section>
      ) : (
        <form className="mt-4 rounded-2xl bg-surface p-4" onSubmit={(e) => { e.preventDefault(); form.post('/todoist/connect', { preserveScroll: true, onSuccess: () => form.reset() }) }}>
          <h2 className="font-semibold">Connect Todoist</h2>
          <p className="mt-1 text-xs text-muted">In Todoist: Settings, then Integrations, then Developer, and copy your API token. It is stored encrypted on your server and never shown again.</p>
          <label className="sr-only" htmlFor="todoist-token">Todoist API token</label>
          <input id="todoist-token" type="password" autoComplete="off" value={form.data.token} onChange={(e) => form.setData('token', e.target.value)} className="mt-3 min-h-11 w-full rounded-xl bg-canvas/60 px-3 text-sm" placeholder="Paste your API token" />
          {form.errors.token ? <p role="alert" className="mt-2 text-sm text-fat">{form.errors.token}</p> : null}
          <Button type="submit" className="mt-3" disabled={form.processing || form.data.token.length < 20}>Connect</Button>
        </form>
      )}
    </AppLayout>
  )
}
