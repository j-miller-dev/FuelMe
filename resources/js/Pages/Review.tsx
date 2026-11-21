import { Head, Link, router, useForm } from '@inertiajs/react'
import AppLayout from '@/layouts/AppLayout'
import { Button } from '@/components/ui/button'
import { Field } from '@/components/ui/field'

interface Assessment { verdict: 'on_track' | 'needs_rate_change' | 'not_achievable_safely'; requiredPctPerWeek: number; capPctPerWeek: number; earliestDays: number | null; achievableKgByDate: number; lines: string[] }
interface Phase { until: string; target_kg: number }
interface EventData { name: string | null; event_date: string; target_kg: number; days: number; phases: Phase[]; assessment: Assessment | null; timeline: (Partial<Assessment> & Phase)[]; trend_kg: number; calibrating: boolean }
interface ReviewRow { week_start: string; note: string; generated_by: 'template' | 'model'; review: { week_end: string } }
interface Props { reviews: ReviewRow[]; event: EventData | null; today: string }

const VERDICT: Record<Assessment['verdict'], { label: string; cls: string }> = {
  on_track: { label: 'On track', cls: 'text-accent' },
  needs_rate_change: { label: 'Needs a change of pace', cls: 'text-carbs' },
  not_achievable_safely: { label: 'Not achievable safely by that date', cls: 'text-fat' },
}
const pretty = (ymd: string) => new Date(`${ymd}T12:00:00Z`).toLocaleDateString('en-AU', { day: 'numeric', month: 'short', timeZone: 'UTC' })

function EventCard({ event }: { event: EventData }) {
  const a = event.assessment
  return (
    <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Event">
      <div className="flex items-baseline justify-between">
        <h2 className="font-semibold">{event.name || 'Event'}: {event.target_kg} kg by {pretty(event.event_date)}</h2>
        <button type="button" className="min-h-11 px-2 text-sm text-accent underline" onClick={() => router.delete('/event', { preserveScroll: true })}>Remove</button>
      </div>
      {a ? (
        <>
          <p className={`mt-1 text-sm font-medium ${VERDICT[a.verdict].cls}`}>{VERDICT[a.verdict].label}</p>
          <ul className="mt-2 space-y-1 text-sm text-muted">{a.lines.map((l) => <li key={l}>{l}</li>)}</ul>
        </>
      ) : <p className="mt-2 text-sm text-muted">The event date has passed.</p>}
      {event.timeline.length ? (
        <ol className="mt-3 space-y-2 border-t border-raised pt-3 text-sm">
          {event.timeline.map((t, i) => (
            <li key={t.until}><span className="font-medium">Phase {i + 1}: {t.target_kg} kg by {pretty(t.until)}</span>{t.verdict ? <span className={`ml-2 ${VERDICT[t.verdict].cls}`}>{VERDICT[t.verdict].label}</span> : null}</li>
          ))}
        </ol>
      ) : null}
      {event.calibrating ? <p className="mt-2 text-xs text-muted">Still calibrating, so the pace check is a loose guide.</p> : null}
      <p className="mt-3 text-xs text-muted">Not medical advice. This app never plans rapid weigh-in cuts or dehydration. For a weight class, talk to a coach or doctor.</p>
    </section>
  )
}

function EventForm({ event }: { event: EventData | null }) {
  const form = useForm({ name: event?.name ?? '', event_date: event?.event_date ?? '', target_kg: event ? String(event.target_kg) : '', phases: (event?.phases ?? []).map((p) => ({ until: p.until, target_kg: String(p.target_kg) })) })
  const err = form.errors as Record<string, string | undefined>
  return (
    <form className="mt-3 rounded-2xl bg-surface p-4" onSubmit={(e) => { e.preventDefault(); form.put('/event', { preserveScroll: true }) }}>
      <h2 className="font-semibold">{event ? 'Change event' : 'Event mode'}</h2>
      <p className="mt-1 text-sm text-muted">A date and a weight. The app checks the pace against the safe weekly rate.</p>
      <div className="mt-3 space-y-3">
        <Field label="Name (optional)" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} error={err.name} />
        <Field label="Event date" type="date" value={form.data.event_date} onChange={(e) => form.setData('event_date', e.target.value)} error={err.event_date} />
        <Field label="Target weight (kg)" inputMode="decimal" value={form.data.target_kg} onChange={(e) => form.setData('target_kg', e.target.value)} error={err.target_kg} />
      </div>
      {form.data.phases.map((ph, i) => (
        <div key={i} className="mt-3 grid grid-cols-2 gap-2">
          <Field label={`Phase ${i + 1} ends`} type="date" value={ph.until} onChange={(e) => form.setData('phases', form.data.phases.map((x, j) => (j === i ? { ...x, until: e.target.value } : x)))} />
          <Field label="Weight then (kg)" inputMode="decimal" value={ph.target_kg} onChange={(e) => form.setData('phases', form.data.phases.map((x, j) => (j === i ? { ...x, target_kg: e.target.value } : x)))} />
        </div>
      ))}
      {form.data.phases.length < 3 ? <button type="button" className="mt-2 min-h-11 text-sm text-accent underline" onClick={() => form.setData('phases', [...form.data.phases, { until: '', target_kg: '' }])}>Add a bulk-then-cut phase</button> : null}
      <Button type="submit" className="mt-3 w-full" disabled={form.processing}>Save event</Button>
    </form>
  )
}

export default function Review({ reviews, event }: Props) {
  const latest = reviews[0]
  return (
    <AppLayout>
      <Head title="Review" />
      <header className="flex items-center justify-between">
        <h1 className="text-lg font-semibold">Weekly review</h1>
        <Link href="/progress" className="min-h-11 px-2 text-sm text-accent underline leading-[2.75rem]">Progress</Link>
      </header>

      {latest ? (
        <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Latest review">
          <div className="flex items-baseline justify-between text-xs text-muted"><span>{pretty(latest.week_start)} to {pretty(latest.review.week_end)}</span><span>{latest.generated_by === 'model' ? 'Reworded by AI, numbers checked' : 'Written from your numbers'}</span></div>
          <p className="mt-2 text-[0.95rem] leading-relaxed">{latest.note}</p>
        </section>
      ) : (
        <section className="mt-3 rounded-2xl bg-surface p-4 text-sm text-muted">No review yet. One is written each Sunday evening, or you can write this week's now.</section>
      )}
      <Button className="mt-3 w-full" variant="secondary" onClick={() => router.post('/review/generate', {}, { preserveScroll: true })}>{latest ? 'Rewrite this week\'s review' : 'Write this week\'s review'}</Button>

      {event ? <EventCard event={event} /> : null}
      <EventForm key={event?.event_date ?? 'new'} event={event} />

      {reviews.length > 1 ? (
        <section className="mt-4" aria-label="Earlier reviews">
          <h2 className="mb-2 text-sm font-semibold">Earlier</h2>
          <ul className="space-y-2">{reviews.slice(1).map((r) => <li key={r.week_start} className="rounded-2xl bg-surface p-3 text-sm"><div className="text-xs text-muted">{pretty(r.week_start)} to {pretty(r.review.week_end)}</div><p className="mt-1">{r.note}</p></li>)}</ul>
        </section>
      ) : null}
    </AppLayout>
  )
}
