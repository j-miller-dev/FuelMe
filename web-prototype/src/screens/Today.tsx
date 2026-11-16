import { day } from '../mock'
import MacroRings from '../lib/MacroRings'
import { CLASS_LABEL, adjustInfo, applySession, getPlan, plannedSessions, sessionInfo, sessionLabel, todayDow, type Session, type Settings } from '../engine'

function Ring({ value, max, calibrating }: { value: number; max: number; calibrating: boolean }) {
  const r = 84, c = 2 * Math.PI * r, pct = Math.min(value / max, 1)
  return (
    <svg viewBox="0 0 200 200" className="h-56 w-56" role="img" aria-label={`${max - value} calories remaining of ${max}`}>
      <circle cx="100" cy="100" r={r} fill="none" stroke="var(--color-raised)" strokeWidth="14" />
      <circle cx="100" cy="100" r={r} fill="none" stroke="var(--color-accent)" strokeWidth="14" strokeLinecap="round"
        strokeDasharray={c} strokeDashoffset={c * (1 - pct)} transform="rotate(-90 100 100)" style={{ transition: 'stroke-dashoffset 600ms cubic-bezier(.2,.8,.2,1)' }} />
      <text x="100" y="100" textAnchor="middle" className="fill-white" style={{ fontSize: 44, fontWeight: 700 }}>{max - value}</text>
      <text x="100" y="124" textAnchor="middle" fill="var(--color-muted)" style={{ fontSize: 13 }}>{calibrating ? 'kcal left (≈)' : 'kcal left'}</text>
    </svg>)
}

export default function Today({ settings, extra, eatBack, openSession, calibrating = true }:
  { settings: Settings; extra: Session | null; eatBack: boolean; openSession: () => void; calibrating?: boolean }) {
  const dow = todayDow(), plan = getPlan(settings).days[dow]
  const adj = extra ? adjustInfo(dow, extra) : null
  const base = adj ? applySession(plan, adj) : plan
  const eatBackKcal = extra && eatBack ? Math.round(sessionInfo(extra).net / 10) * 10 : 0
  const t = { kcal: base.kcal + eatBackKcal, p: base.protein, c: base.carbs + Math.round(eatBackKcal / 4), f: base.fat }
  const e = day.eaten
  const sessions = [...plannedSessions(dow), ...(extra ? [extra] : [])]
  const main = sessions.length ? sessions.reduce((a, b) => (sessionInfo(b)?.net ?? 0) > (sessionInfo(a)?.net ?? 0) ? b : a) : null
  const peri = main ? sessionInfo(main)?.peri : null
  const delta = t.kcal - plan.kcal
  return (
    <div className="px-4 pb-28">
      <header className="flex items-center justify-between pt-6"><h1 className="text-xl font-semibold">Today</h1>
        <span className="rounded-full bg-raised px-3 py-1 text-sm text-accent">{CLASS_LABEL[base.class]}</span></header>
      {sessions.length > 0 && <p className="mt-1 text-xs text-muted">{sessions.map(sessionLabel).join('  +  ')}</p>}
      <section className="mt-3 flex justify-center"><Ring value={e.kcal} max={t.kcal} calibrating={calibrating} /></section>
      {delta !== 0 && <p className="text-center text-sm text-accent">{delta > 0 ? '+' : ''}{delta} kcal vs your planned {CLASS_LABEL[plan.class].toLowerCase()}</p>}
      {calibrating && <div className="mt-2 rounded-2xl bg-surface p-3 text-sm"><span className="text-carbs">Calibrating · day 9 of ~21.</span> <span className="text-muted">Targets are a starting estimate until your expenditure settles.</span></div>}
      <MacroRings eaten={{ p: e.p, c: e.c, f: e.f }} target={{ p: t.p, c: t.c, f: t.f }} />
      <button onClick={openSession} className="mt-3 flex min-h-12 w-full items-center justify-between rounded-2xl bg-surface px-4 text-left">
        <span><span className="font-medium">Log a session</span><span className="block text-xs text-muted">BJJ, lifting, HIIT: see what it changes today</span></span><span className="text-2xl text-accent">+</span></button>
      <section className="mt-3 rounded-2xl border border-accent/30 bg-surface p-4">
        {peri && main ? <>
          <div className="text-sm text-accent">Fuel for {sessionLabel(main)}</div>
          <div className="mt-1 text-lg font-medium">Before: {peri.pre.carbsG} g carbs + {peri.pre.proteinG} g protein</div>
          <div className="text-sm text-muted">{peri.pre.note}</div>
          <div className="mt-2 text-sm">After: {peri.post.proteinG} g protein{peri.post.carbsG ? ` + ${peri.post.carbsG} g carbs` : ''}</div>
        </> : <><div className="text-sm text-accent">Rest day</div><div className="mt-1 text-sm text-muted">Hit protein, keep carbs lower. No special fuelling needed.</div></>}
      </section>
      <section className="mt-3 space-y-2">{day.meals.map(m => (<div key={m.name} className="flex justify-between rounded-2xl bg-surface p-4"><div><div className="font-medium">{m.name}</div><div className="text-sm text-muted">{m.items}</div></div><div>{m.kcal}</div></div>))}</section>
    </div>)
}
