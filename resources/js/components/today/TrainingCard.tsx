import { Link, router } from '@inertiajs/react'
import { useState } from 'react'
import { api, uuid } from '@/lib/http'
import { slotForHour } from '@/lib/food'
import { INTENSITY_LABEL, inMinutes, type Composer, type ComposerOption, type Session, type TrainingProps } from '@/lib/training'
import { WorkoutSay } from '@/components/training/WorkoutSay'
import { SessionSheet } from '@/components/training/SessionSheet'
import { energyLabel, fmtEnergy, type EnergyUnit } from '@/lib/units'

const RPE_WORDS: Record<number, string> = { 1: 'very easy', 3: 'easy', 5: 'moderate', 7: 'hard', 9: 'very hard', 10: 'all out' }

/** "How hard was it?" 1-10, asked after a session is done. Optional; a rating makes your weekly load more accurate. */
function EffortRow({ s, onSet, disabled }: { s: Session; onSet: (rpe: number | null) => void; disabled: boolean }) {
  if (s.id === null || s.status !== 'done') return null
  if (s.rpe != null) {
    return <div className="flex items-center justify-between pb-2 text-xs text-muted"><span>Effort {s.rpe} of 10 · load {Math.round(s.rpe * s.minutes)}</span><button type="button" disabled={disabled} className="min-h-11 px-2 underline" onClick={() => onSet(null)}>Change</button></div>
  }
  return (
    <div className="pb-2" role="group" aria-label={`How hard was ${s.label}?`}>
      <p className="text-xs text-muted">How hard was it? 1 easy, 10 all out.</p>
      <div className="mt-1 flex flex-wrap gap-1">
        {[1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map((n) => <button key={n} type="button" disabled={disabled} title={RPE_WORDS[n]} aria-label={`${n}${RPE_WORDS[n] ? `, ${RPE_WORDS[n]}` : ''}`} className="size-11 rounded-xl bg-raised text-sm tabular-nums disabled:opacity-40" onClick={() => onSet(n)}>{n}</button>)}
      </div>
    </div>
  )
}

const STATUS: Record<string, string> = { virtual: 'Planned', planned: 'Planned', done: 'Done', skipped: 'Skipped' }

/** Today's training and the "next up" fuelling card. Sessions are estimates; nothing here changes your food target except what the day is. */
export function TrainingCard({ training, date, isToday, unit, hour }: { training: TrainingProps; date: string; isToday: boolean; unit: EnergyUnit; hour: number }) {
  const [sheet, setSheet] = useState<{ row?: Session } | null>(null)
  const [say, setSay] = useState(false)
  const [lead, setLead] = useState<'1' | '2' | '3'>('2')
  const [msg, setMsg] = useState<string | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const reload = () => router.reload({ only: ['training', 'target', 'dayClass', 'logs'] })
  const run = async (id: string, fn: () => Promise<unknown>) => { setBusy(id); setMsg(null); try { await fn(); reload() } catch (e) { setMsg(e instanceof Error ? e.message : 'Something went wrong.') } finally { setBusy(null) } }
  // A session copied from your usual week is confirmed/skipped through its template slot (which also makes the day's rows real);
  // one you added yourself is just a row with a status.
  const mark = (s: Session, action: 'done' | 'skip' | 'reset') => run(s.key + action, () => s.template_id !== null
    ? api('/workouts/template-session', { method: 'POST', body: { template_id: s.template_id, action, local_date: date } })
    : api(`/workouts/${s.id}`, { method: 'PATCH', body: { status: action === 'skip' ? 'skipped' : action === 'done' ? 'done' : 'planned' } }))
  const logOption = (o: ComposerOption, id: string) => run(id, () => api('/logs', { method: 'POST', body: { local_date: date, items: o.items.map((i) => (i.kind === 'meal'
    ? { idempotency_key: uuid(), meal_slot: slotForHour(hour), source: 'meal', meal_id: i.ref, portions: i.amount }
    : { idempotency_key: uuid(), meal_slot: slotForHour(hour), source: 'search', food_id: i.ref, grams: i.amount })) } }))

  const f = training.fuel
  const sessions = training.sessions.filter((s) => s.status !== 'skipped' || isToday)
  if (!sessions.length && !isToday) return null
  const kc = (n: number) => `${fmtEnergy(n, unit)} ${energyLabel(unit)}`

  const composer = (c: Composer | null, id: string) => c ? (
    <div className="mt-2">
      {c.shift ? <p className="mb-1 text-xs text-carbs">{c.shift.note}</p> : null}
      {c.options.length ? (
        <ul className="space-y-2">{c.options.map((o, i) => (
          <li key={i} className="rounded-xl bg-raised p-3 text-sm">
            <div className="font-medium">{o.items.map((x) => x.label).join(' + ')}</div>
            <div className="mt-0.5 text-xs text-muted tabular-nums">{o.carbs} g carbs · {o.protein} g protein · {o.fat} g fat · {kc(o.kcal)}</div>
            <div className="text-xs text-muted tabular-nums">Leaves {Math.max(0, o.after.carbs)} g carbs, {Math.max(0, o.after.protein)} g protein for the rest of the day{o.after.carbs < 0 ? ' (over on carbs)' : ''}.</div>
            <button disabled={busy === `${id}${i}`} onClick={() => void logOption(o, `${id}${i}`)} className="mt-2 min-h-11 rounded-xl bg-accent px-4 text-sm font-semibold text-on-accent disabled:opacity-40">Log this</button>
          </li>))}</ul>
      ) : c.shortfall ? <p className="text-xs text-muted">{c.shortfall}</p> : null}
    </div>
  ) : null

  const lp = f?.leads[lead]
  return (
    <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Training">
      <div className="flex items-center gap-2">
        <h2 className="flex-1 font-semibold">{isToday ? 'Training today' : 'Training'}</h2>
        {isToday ? <button onClick={() => setSay(true)} className="min-h-11 rounded-full bg-raised px-3 text-sm text-accent">Say it</button> : null}
        <button onClick={() => setSheet({})} className="min-h-11 rounded-full bg-raised px-3 text-sm text-accent">Log a session</button>
      </div>
      {training.explain ? <p className="mt-1 text-xs text-accent">{training.explain}</p> : null}
      {training.effort_plan && sessions.some((x) => x.type === 'lifting') ? <p className="mt-1 text-xs text-muted">Lifting effort this week (week {training.effort_plan.week} of {training.effort_plan.of}): {training.effort_plan.label}. Your plan.</p> : null}
      {sessions.length === 0 ? <p className="mt-1 text-sm text-muted">Rest day. Add a session if you train.</p> : (
        <ul className="mt-1">{sessions.map((s) => (
          <li key={s.key} className="border-t border-raised/70 text-sm"><div className="flex min-h-14 items-center gap-2">
            <button onClick={() => s.id !== null ? setSheet({ row: s }) : undefined} className="min-w-0 flex-1 py-2 text-left" disabled={s.id === null} aria-label={`${s.label}, ${STATUS[s.status]}`}>
              <span className={`block truncate font-medium ${s.status === 'skipped' ? 'text-muted line-through' : ''}`}>{s.start ? `${s.start} ` : ''}{s.label} <span className="font-normal text-muted">· {INTENSITY_LABEL[s.intensity] ?? s.intensity}</span></span>
              <span className="block text-xs text-muted tabular-nums">{s.minutes} min · ≈ {kc(s.netKcal)}{s.override ? ' (watch)' : ` (${fmtEnergy(s.low, unit)}–${fmtEnergy(s.high, unit)})`}</span>
            </button>
            {s.status === 'done' ? <span className="rounded-full bg-accent/20 px-2 py-0.5 text-xs text-accent">Done</span> : null}
            {(s.status === 'virtual' || s.status === 'planned') && isToday ? (
              <>
                <button disabled={!!busy} onClick={() => void mark(s, 'done')} className="min-h-11 rounded-xl bg-raised px-3 text-sm text-accent disabled:opacity-40">Done</button>
                <button disabled={!!busy} onClick={() => void mark(s, 'skip')} className="min-h-11 px-2 text-sm text-muted disabled:opacity-40">Skip</button>
              </>
            ) : null}
            {s.status === 'skipped' ? <button disabled={!!busy} onClick={() => void mark(s, 'reset')} className="min-h-11 px-2 text-sm text-accent">Undo</button> : null}
          </div>
          <EffortRow s={s} disabled={!!busy} onSet={(rpe) => void run('rpe' + s.key, () => api(`/workouts/${s.id}`, { method: 'PATCH', body: { rpe } }))} />
          </li>))}</ul>
      )}
      {msg ? <p role="alert" className="mt-2 text-sm text-danger">{msg}</p> : null}

      {f && lp ? (
        <div className="mt-3 rounded-2xl bg-raised p-3" aria-label="Next up">
          <div className="flex items-center justify-between"><h3 className="font-semibold">{f.when === 'tomorrow' ? 'Tomorrow' : 'Next up'}: {f.next.label}</h3><span className="text-sm text-muted tabular-nums">{f.next.start ?? 'time not set'}{f.next.minutesUntil !== null ? ` · in ${inMinutes(f.next.minutesUntil)}` : ''}</span></div>
          {f.later > 0 ? <p className="text-xs text-muted">{f.later} more session{f.later === 1 ? '' : 's'} after this one today.</p> : null}
          {f.next.sharedMealNote ? <p className="mt-1 text-xs text-carbs">{f.next.sharedMealNote}</p> : null}

          {f.timeline ? (
            <>
              <h4 className="mt-3 text-sm font-semibold">Your plan for {f.next.start}</h4>
              <ol className="mt-1" aria-label="Timeline">
                {f.timeline.map((t) => (
                  <li key={t.atMin + t.kind} className={`flex gap-3 border-t border-surface py-2 first:border-0 ${t.inMin < 0 ? 'opacity-50' : ''}`}>
                    <span className="w-16 shrink-0 text-sm font-semibold tabular-nums">{t.at}</span>
                    <span className="min-w-0 text-sm"><span className="font-medium">{t.title}</span>{t.inMin < 0 ? <span className="text-xs text-muted"> · passed</span> : t.inMin <= 90 ? <span className="text-xs text-accent"> · soon</span> : null}<span className="block text-xs text-muted">{t.text}</span></span>
                  </li>
                ))}
              </ol>
              <p className="mt-1 text-xs text-muted">Fluid amounts are guidelines (ACSM), not prescriptions. Not medical advice.</p>
            </>
          ) : null}

          <h4 className="mt-3 text-sm font-semibold">{f.timeline ? 'Running late? Pick how long before you eat' : 'Before'}</h4>
          <div className="mt-1 flex gap-2" role="group" aria-label="How long before the session you eat">
            {(['1', '2', '3'] as const).map((h) => <button key={h} aria-pressed={lead === h} onClick={() => setLead(h)} className={`min-h-10 flex-1 rounded-full text-xs ${lead === h ? 'bg-accent/20 text-accent ring-1 ring-accent' : 'bg-surface text-muted'}`}>{h} h before</button>)}
          </div>
          {lp.pre.skip ? <p className="mt-1 text-sm text-muted">{lp.pre.note}</p> : (
            <>
              <p className="mt-1 text-sm tabular-nums"><span className="font-semibold">{lp.pre.carbsG} g carbs</span> · {lp.pre.proteinG} g protein · fat under {lp.pre.fatMaxG} g · fibre under {lp.pre.fibreMaxG} g</p>
              <p className="text-xs text-muted">{lp.pre.note}</p>
              {composer(lp.composer, 'pre' + lead)}
            </>
          )}

          <h4 className="mt-3 text-sm font-semibold">During</h4>
          <p className="text-sm text-muted">{f.during.carbsGPerHour[1] > 0 ? `${f.during.carbsGPerHour[0]}–${f.during.carbsGPerHour[1]} g carbs an hour. ` : ''}{f.during.note}</p>

          <h4 className="mt-3 text-sm font-semibold">After</h4>
          <p className="text-sm tabular-nums"><span className="font-semibold">{f.post.proteinG} g protein</span>{f.post.carbsG ? ` · ${f.post.carbsG} g carbs` : f.post.carbsGPerHour ? ` · ${f.post.carbsGPerHour[0]}–${f.post.carbsGPerHour[1]} g carbs an hour for ${f.post.forHours} h` : ''} within {f.post.withinHours} h</p>
          <p className="text-xs text-muted">{f.post.note}</p>
          {composer(f.postMeal, 'post')}
          {!f.hasTags ? <p className="mt-2 text-xs text-muted">Tip: tag a meal <span className="text-ink">pre</span> or <span className="text-ink">post</span> on the <Link href="/meals" className="underline">Meals</Link> page and it is suggested first.</p> : null}
          <p className="mt-2 text-xs text-muted">These amounts come out of your day's targets, they are not extra.</p>
        </div>
      ) : null}

      <Link href="/training" className="mt-3 block text-center text-sm text-muted underline">Edit your training week</Link>
      {say ? <WorkoutSay catalogue={training.catalogue} kg={training.kg} onClose={() => setSay(false)} onSaved={() => { setSay(false); reload() }} /> : null}
      {sheet ? <SessionSheet mode={{ kind: 'log', date, row: sheet.row }} catalogue={training.catalogue} kg={training.kg} onClose={() => setSheet(null)} onSaved={() => { setSheet(null); reload() }} /> : null}
    </section>
  )
}
