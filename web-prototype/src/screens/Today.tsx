import { day } from '../mock'

function Ring({ value, max, calibrating }: { value: number; max: number; calibrating: boolean }) {
  const r = 84, c = 2 * Math.PI * r, pct = Math.min(value / max, 1)
  return (
    <svg viewBox="0 0 200 200" className="h-56 w-56" role="img" aria-label={`${max - value} calories remaining of ${max}`}>
      <circle cx="100" cy="100" r={r} fill="none" stroke="var(--color-raised)" strokeWidth="14" />
      <circle cx="100" cy="100" r={r} fill="none" stroke="var(--color-accent)" strokeWidth="14" strokeLinecap="round"
        strokeDasharray={calibrating ? '4 8' : c} strokeDashoffset={calibrating ? 0 : c * (1 - pct)} transform="rotate(-90 100 100)"
        style={{ transition: 'stroke-dashoffset 600ms cubic-bezier(.2,.8,.2,1)' }} />
      <text x="100" y="100" textAnchor="middle" className="fill-white" style={{ fontSize: 44, fontWeight: 700 }}>{max - value}</text>
      <text x="100" y="124" textAnchor="middle" fill="var(--color-muted)" style={{ fontSize: 13 }}>{calibrating ? 'kcal left (≈)' : 'kcal left'}</text>
    </svg>)
}
function Bar({ label, v, t, color }: { label: string; v: number; t: number; color: string }) {
  return (<div className="flex-1"><div className="flex justify-between text-sm"><span className="text-muted">{label}</span><span>{v}/{t}g</span></div>
    <div className="mt-1 h-2 overflow-hidden rounded-full bg-raised"><div className="h-full rounded-full" style={{ width: `${Math.min(v / t, 1) * 100}%`, background: color, transition: 'width 500ms' }} /></div></div>)
}
export default function Today({ calibrating = true }: { calibrating?: boolean }) {
  const { target: t, eaten: e, next } = day
  return (
    <div className="px-4 pb-28">
      <header className="flex items-center justify-between pt-6"><h1 className="text-xl font-semibold">Today</h1>
        <span className="rounded-full bg-raised px-3 py-1 text-sm text-accent">{day.type} · {day.session}</span></header>
      <section className="mt-4 flex justify-center"><Ring value={e.kcal} max={t.kcal} calibrating={calibrating} /></section>
      {calibrating && <div className="mt-1 rounded-2xl bg-surface p-3 text-sm"><span className="text-carbs">Calibrating · day 9 of ~21.</span> <span className="text-muted">Your target is a range (≈ ±350 kcal) until the estimate settles. Keep logging food and weighing in.</span></div>}
      <section className="mt-3 flex gap-3 rounded-2xl bg-surface p-4"><Bar label="Protein" v={e.p} t={t.p} color="var(--color-protein)" /><Bar label="Carbs" v={e.c} t={t.c} color="var(--color-carbs)" /><Bar label="Fat" v={e.f} t={t.f} color="var(--color-fat)" /></section>
      <section className="mt-3 rounded-2xl border border-accent/30 bg-surface p-4"><div className="text-sm text-accent">Next up · {next.label} · {next.when}</div>
        <div className="mt-1 text-lg font-medium">{next.carbs} g carbs + {next.protein} g protein</div><div className="text-sm text-muted">{next.note}</div></section>
      <section className="mt-3 space-y-2">{day.meals.map(m => (<div key={m.name} className="flex justify-between rounded-2xl bg-surface p-4"><div><div className="font-medium">{m.name}</div><div className="text-sm text-muted">{m.items}</div></div><div>{m.kcal}</div></div>))}</section>
    </div>)
}
