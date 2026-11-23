import { Head, Link, router, useForm } from '@inertiajs/react'
import AppLayout from '@/layouts/AppLayout'
import { Button } from '@/components/ui/button'

interface Day { date: string; sleepMin: number | null; restingHr: number | null; hrv: number | null; steps: number | null }
interface Act { id: number; date: string; name: string; type: string | null; minutes: number | null; avgHr: number | null; maxHr: number | null; load: number | null; source: string | null; alsoRecordedBy?: string[] }
interface Props { connection: { last_synced_at: string | null; last_error: string | null } | null; days: Day[]; activities: Act[] }

const hm = (min: number | null) => (min === null ? '–' : `${Math.floor(min / 60)}h ${String(min % 60).padStart(2, '0')}`)
const dash = (n: number | null) => (n === null ? '–' : String(n))
const day = (ymd: string) => new Date(`${ymd}T12:00:00Z`).toLocaleDateString('en-AU', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' })

export default function Watch({ connection, days, activities }: Props) {
  const form = useForm({ api_key: '' })
  return (
    <AppLayout>
      <Head title="Watch data" />
      <header className="flex items-center justify-between">
        <h1 className="text-lg font-semibold">Watch data</h1>
        <Link href="/progress" className="min-h-11 px-2 text-sm text-accent underline leading-[2.75rem]">Progress</Link>
      </header>
      <p className="mt-1 text-sm text-muted">What your watch recorded, read through Intervals.icu (read-only). FUEL never changes anything there. These are the watch&apos;s own estimates. They are shown for interest and do not change your calorie target.</p>

      {!connection ? (
        <form className="mt-4 rounded-2xl bg-surface p-4" onSubmit={(e) => { e.preventDefault(); form.post('/watch/connect', { preserveScroll: true, onSuccess: () => form.reset() }) }}>
          <h2 className="font-semibold">Connect Intervals.icu</h2>
          <p className="mt-1 text-xs text-muted">Link your watch to Intervals.icu first (Settings, then Connections). Then in Intervals.icu: Settings, Developer Settings, create an API key. It is stored encrypted on your server and never shown again.</p>
          <label className="sr-only" htmlFor="icu-key">Intervals.icu API key</label>
          <input id="icu-key" type="password" autoComplete="off" value={form.data.api_key} onChange={(e) => form.setData('api_key', e.target.value)} className="mt-3 min-h-11 w-full rounded-xl bg-canvas/60 px-3 text-sm" placeholder="Paste your API key" />
          {form.errors.api_key ? <p role="alert" className="mt-2 text-sm text-fat">{form.errors.api_key}</p> : null}
          <Button type="submit" className="mt-3" disabled={form.processing || form.data.api_key.length < 10}>Connect</Button>
        </form>
      ) : (
        <section className="mt-4 rounded-2xl bg-surface p-4" aria-label="Sync">
          <p className="text-sm">{connection.last_synced_at ? `Last synced ${new Date(connection.last_synced_at).toLocaleString('en-AU', { dateStyle: 'medium', timeStyle: 'short' })}.` : 'First sync is running. Refresh in a minute.'} It updates by itself every hour.</p>
          {connection.last_error ? <p role="alert" className="mt-1 text-sm text-fat">{connection.last_error}</p> : null}
          <div className="mt-2 flex flex-wrap gap-2">
            <Button type="button" onClick={() => router.post('/watch/sync', {}, { preserveScroll: true })}>Sync now</Button>
            <button type="button" className="min-h-11 px-3 text-sm text-muted underline" onClick={() => { if (confirm('Disconnect Intervals.icu? Your synced history stays unless you delete it too.')) router.delete('/watch/connection', { preserveScroll: true }) }}>Disconnect</button>
            <button type="button" className="min-h-11 px-3 text-sm text-muted underline" onClick={() => { if (confirm('Disconnect and delete all synced watch data from FUEL?')) router.delete('/watch/connection?delete_data=1', { preserveScroll: true }) }}>Disconnect and delete data</button>
          </div>
        </section>
      )}

      {connection ? (
        <>
          <h2 className="mt-5 font-semibold">Last 14 days</h2>
          {days.length === 0 ? <p className="mt-1 text-sm text-muted">Nothing yet. Days appear once the first sync finishes. A dash means your watch did not report that value.</p> : (
            <table className="mt-2 w-full text-sm tabular-nums">
              <thead><tr className="text-left text-xs text-muted"><th className="py-1 font-normal">Day</th><th className="font-normal">Sleep</th><th className="font-normal">Rest HR</th><th className="font-normal">HRV</th><th className="font-normal">Steps</th></tr></thead>
              <tbody>{days.map((d) => <tr key={d.date} className="border-t border-raised"><td className="py-2">{day(d.date)}</td><td>{hm(d.sleepMin)}</td><td>{dash(d.restingHr)}</td><td>{dash(d.hrv)}</td><td>{d.steps === null ? '–' : d.steps.toLocaleString('en-AU')}</td></tr>)}</tbody>
            </table>
          )}

          <h2 className="mt-5 font-semibold">Recent activities</h2>
          {activities.length === 0 ? <p className="mt-1 text-sm text-muted">No activities yet.</p> : (
            <ul className="mt-2 space-y-2">{activities.map((a) => (
              <li key={a.id} className="rounded-2xl bg-surface p-3 text-sm">
                <div className="font-medium">{a.name}</div>
                <div className="text-xs text-muted tabular-nums">{day(a.date)}{a.minutes ? ` · ${a.minutes} min` : ''}{a.avgHr ? ` · avg HR ${a.avgHr}` : ''}{a.maxHr ? ` · max ${a.maxHr}` : ''}{a.load ? ` · load ${a.load}` : ''}{a.source ? ` · ${a.source}` : ''}{a.alsoRecordedBy?.length ? ` · also recorded by ${a.alsoRecordedBy.join(', ')} (Polar is used when both did)` : ''}</div>
              </li>))}</ul>
          )}
          <p className="mt-3 text-xs text-muted">When both watches recorded the same session, only one is shown and Polar is used. Sleep, HRV and resting HR are the watch&apos;s estimates, not medical measurements.</p>
        </>
      ) : null}
    </AppLayout>
  )
}
