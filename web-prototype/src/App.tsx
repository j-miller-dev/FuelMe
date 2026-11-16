import { useState } from 'react'
import { day } from './mock'

function Ring({ value, max }: { value: number; max: number }) {
  const r = 84, c = 2 * Math.PI * r, pct = Math.min(value / max, 1)
  return (
    <svg viewBox="0 0 200 200" className="w-56 h-56" role="img" aria-label={`${max - value} calories remaining of ${max}`}>
      <circle cx="100" cy="100" r={r} fill="none" stroke="var(--color-raised)" strokeWidth="14" />
      <circle cx="100" cy="100" r={r} fill="none" stroke="var(--color-accent)" strokeWidth="14" strokeLinecap="round"
        strokeDasharray={c} strokeDashoffset={c * (1 - pct)} transform="rotate(-90 100 100)"
        style={{ transition: 'stroke-dashoffset 600ms cubic-bezier(.2,.8,.2,1)' }} />
      <text x="100" y="100" textAnchor="middle" className="fill-white" style={{ fontSize: 44, fontWeight: 700 }}>{max - value}</text>
      <text x="100" y="124" textAnchor="middle" fill="var(--color-muted)" style={{ fontSize: 13 }}>kcal left</text>
    </svg>
  )
}

function Bar({ label, v, t, color }: { label: string; v: number; t: number; color: string }) {
  return (
    <div className="flex-1">
      <div className="flex justify-between text-sm"><span className="text-muted">{label}</span><span>{v}/{t}g</span></div>
      <div className="h-2 mt-1 rounded-full bg-raised overflow-hidden">
        <div className="h-full rounded-full" style={{ width: `${Math.min(v / t, 1) * 100}%`, background: color, transition: 'width 500ms' }} />
      </div>
    </div>
  )
}

const actions = ['Search', 'Speak', 'Scan', 'Quick-add', 'Meals']

export default function App() {
  const [sheet, setSheet] = useState(false)
  const { target: t, eaten: e, next } = day
  return (
    <div className="mx-auto max-w-md min-h-dvh pb-28 px-4">
      <header className="pt-6 flex items-center justify-between">
        <h1 className="text-xl font-semibold">Today</h1>
        <span className="rounded-full bg-raised px-3 py-1 text-sm text-accent">{day.type} · {day.session}</span>
      </header>
      <section className="flex justify-center mt-4"><Ring value={e.kcal} max={t.kcal} /></section>
      <section className="flex gap-3 mt-2 rounded-2xl bg-surface p-4">
        <Bar label="Protein" v={e.p} t={t.p} color="var(--color-protein)" />
        <Bar label="Carbs" v={e.c} t={t.c} color="var(--color-carbs)" />
        <Bar label="Fat" v={e.f} t={t.f} color="var(--color-fat)" />
      </section>
      <section className="mt-3 rounded-2xl bg-surface p-4 border border-accent/30">
        <div className="text-sm text-accent">Next up · {next.label} · {next.when}</div>
        <div className="mt-1 text-lg font-medium">{next.carbs} g carbs + {next.protein} g protein</div>
        <div className="text-sm text-muted">{next.note}</div>
      </section>
      <section className="mt-3 space-y-2">
        {day.meals.map(m => (
          <div key={m.name} className="rounded-2xl bg-surface p-4 flex justify-between">
            <div><div className="font-medium">{m.name}</div><div className="text-sm text-muted">{m.items}</div></div>
            <div className="tabular-nums">{m.kcal}</div>
          </div>
        ))}
      </section>

      {sheet && (
        <div className="fixed inset-0 bg-black/60" onClick={() => setSheet(false)}>
          <div className="absolute bottom-0 inset-x-0 mx-auto max-w-md rounded-t-3xl bg-raised p-5 grid grid-cols-5 gap-2" onClick={ev => ev.stopPropagation()}>
            {actions.map(a => <button key={a} className="min-h-14 rounded-xl bg-surface text-xs active:scale-95 transition">{a}</button>)}
          </div>
        </div>
      )}
      <nav className="fixed bottom-0 inset-x-0 bg-surface/95 backdrop-blur border-t border-white/5">
        <div className="mx-auto max-w-md flex items-center justify-around h-16 text-sm text-muted">
          <span className="text-accent">Today</span><span>Log</span>
          <button aria-label="Add" onClick={() => { navigator.vibrate?.(10); setSheet(true) }}
            className="-mt-8 size-14 rounded-full bg-accent text-base text-3xl font-bold shadow-lg shadow-accent/30 active:scale-95 transition">+</button>
          <span>Plan</span><span>Progress</span>
        </div>
      </nav>
    </div>
  )
}
