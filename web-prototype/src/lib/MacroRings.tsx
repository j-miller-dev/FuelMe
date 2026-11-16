type M = { label: string; eaten: number; target: number; color: string }
function Ring({ m }: { m: M }) {
  const r = 34, c = 2 * Math.PI * r, pct = Math.min(m.eaten / m.target, 1), left = Math.round(m.target - m.eaten), over = left < 0
  return (
    <div className="flex flex-1 flex-col items-center" role="img" aria-label={`${m.label}: ${m.eaten} of ${m.target} grams, ${Math.abs(left)} ${over ? 'over' : 'left'}`}>
      <svg viewBox="0 0 84 84" className="h-24 w-24">
        <circle cx="42" cy="42" r={r} fill="none" stroke="var(--color-raised)" strokeWidth="8" />
        <circle cx="42" cy="42" r={r} fill="none" stroke={over ? 'var(--color-fat)' : m.color} strokeWidth="8" strokeLinecap="round"
          strokeDasharray={c} strokeDashoffset={c * (1 - pct)} transform="rotate(-90 42 42)" style={{ transition: 'stroke-dashoffset 600ms cubic-bezier(.2,.8,.2,1)' }} />
        <text x="42" y="43" textAnchor="middle" className="fill-white" style={{ fontSize: 19, fontWeight: 700 }}>{Math.abs(left)}</text>
        <text x="42" y="56" textAnchor="middle" fill="var(--color-muted)" style={{ fontSize: 9 }}>{over ? 'g over' : 'g left'}</text>
      </svg>
      <div className="text-sm font-medium" style={{ color: m.color }}>{m.label}</div>
      <div className="text-xs text-muted tabular-nums">{Math.round(m.eaten)} / {m.target} g</div>
    </div>)
}

/** Three macro rings + a stacked "where your calories came from" bar. */
export default function MacroRings({ eaten, target }: { eaten: { p: number; c: number; f: number }; target: { p: number; c: number; f: number } }) {
  const kP = eaten.p * 4, kC = eaten.c * 4, kF = eaten.f * 9, tot = Math.max(kP + kC + kF, 1)
  return (
    <section className="mt-3 rounded-2xl bg-surface p-4">
      <div className="flex gap-1">
        <Ring m={{ label: 'Protein', eaten: eaten.p, target: target.p, color: 'var(--color-protein)' }} />
        <Ring m={{ label: 'Carbs', eaten: eaten.c, target: target.c, color: 'var(--color-carbs)' }} />
        <Ring m={{ label: 'Fat', eaten: eaten.f, target: target.f, color: 'var(--color-fat)' }} />
      </div>
      <div className="mt-4 text-xs text-muted">Calories eaten so far, by macro</div>
      <div className="mt-1 flex h-3 overflow-hidden rounded-full bg-raised" aria-hidden>
        <div style={{ width: `${kP / tot * 100}%`, background: 'var(--color-protein)' }} />
        <div style={{ width: `${kC / tot * 100}%`, background: 'var(--color-carbs)' }} />
        <div style={{ width: `${kF / tot * 100}%`, background: 'var(--color-fat)' }} />
      </div>
      <div className="mt-1 flex justify-between text-xs tabular-nums text-muted">
        <span>P {Math.round(kP / tot * 100)}%</span><span>C {Math.round(kC / tot * 100)}%</span><span>F {Math.round(kF / tot * 100)}%</span>
      </div>
    </section>)
}
