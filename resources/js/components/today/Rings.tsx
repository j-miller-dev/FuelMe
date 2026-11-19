import { useState } from 'react'
import { fmtEnergy, energyLabel, type EnergyUnit } from '@/lib/units'

export function CalorieRing({ eaten, target, unit, calibrating }: { eaten: number; target: number; unit: EnergyUnit; calibrating: boolean }) {
  const [mode, setMode] = useState<'left' | 'eaten'>('left')
  const r = 84
  const c = 2 * Math.PI * r
  const pct = target > 0 ? Math.min(eaten / target, 1) : 0
  const left = target - eaten
  const over = left < 0
  const u = energyLabel(unit)
  const big = mode === 'left' ? fmtEnergy(Math.abs(left), unit) : fmtEnergy(eaten, unit)
  const small = mode === 'left' ? (over ? `${u} over` : calibrating ? `${u} left (≈)` : `${u} left`) : `${u} eaten`
  return (
    <section className="flex flex-col items-center" aria-label="Calories">
      <div className="mb-2 flex gap-1 rounded-full bg-surface p-1" role="group" aria-label="Ring shows">
        {([['left', 'Remaining'], ['eaten', 'Consumed']] as const).map(([v, l]) => (
          <button key={v} aria-pressed={mode === v} onClick={() => setMode(v)}
            className={`min-h-9 rounded-full px-4 text-sm ${mode === v ? 'bg-raised font-semibold text-accent' : 'text-muted'}`}>{l}</button>
        ))}
      </div>
      <svg viewBox="0 0 200 200" className="h-56 w-56" role="img"
        aria-label={`${fmtEnergy(eaten, unit)} of ${fmtEnergy(target, unit)} ${u} eaten, ${fmtEnergy(Math.abs(left), unit)} ${over ? 'over' : 'left'}`}>
        <circle cx="100" cy="100" r={r} fill="none" stroke="var(--raised)" strokeWidth="14" />
        <circle cx="100" cy="100" r={r} fill="none" stroke={over ? 'var(--fat)' : 'var(--accent)'} strokeWidth="14" strokeLinecap="round"
          strokeDasharray={c} strokeDashoffset={c * (1 - pct)} transform="rotate(-90 100 100)"
          style={{ transition: 'stroke-dashoffset 600ms cubic-bezier(.2,.8,.2,1)' }} />
        <text x="100" y="104" textAnchor="middle" fill="var(--ink)" style={{ fontSize: 44, fontWeight: 700 }}>{big}</text>
        <text x="100" y="128" textAnchor="middle" fill="var(--muted)" style={{ fontSize: 13 }}>{small}</text>
      </svg>
    </section>
  )
}

interface M { label: string; eaten: number; target: number; color: string }

function MacroRing({ m }: { m: M }) {
  const r = 34
  const c = 2 * Math.PI * r
  const pct = m.target > 0 ? Math.min(m.eaten / m.target, 1) : 0
  const left = Math.round(m.target - m.eaten)
  const over = left < 0
  return (
    <div className="flex flex-1 flex-col items-center" role="img" aria-label={`${m.label}: ${Math.round(m.eaten)} of ${m.target} grams, ${Math.abs(left)} ${over ? 'over' : 'left'}`}>
      <svg viewBox="0 0 84 84" className="h-24 w-24">
        <circle cx="42" cy="42" r={r} fill="none" stroke="var(--raised)" strokeWidth="8" />
        <circle cx="42" cy="42" r={r} fill="none" stroke={over ? 'var(--danger)' : m.color} strokeWidth="8" strokeLinecap="round"
          strokeDasharray={c} strokeDashoffset={c * (1 - pct)} transform="rotate(-90 42 42)" style={{ transition: 'stroke-dashoffset 600ms cubic-bezier(.2,.8,.2,1)' }} />
        <text x="42" y="44" textAnchor="middle" fill="var(--ink)" style={{ fontSize: 19, fontWeight: 700 }}>{Math.abs(left)}</text>
        <text x="42" y="57" textAnchor="middle" fill="var(--muted)" style={{ fontSize: 9 }}>{over ? 'g over' : 'g left'}</text>
      </svg>
      <div className="text-sm font-medium" style={{ color: m.color }}>{m.label}</div>
      <div className="text-xs text-muted tabular-nums">{Math.round(m.eaten)} / {m.target} g</div>
    </div>
  )
}

export function MacroRings({ eaten, target }: { eaten: { protein: number; carbs: number; fat: number }; target: { protein: number; carbs: number; fat: number } }) {
  return (
    <section className="mt-3 flex gap-1 rounded-2xl bg-surface p-4" aria-label="Macros">
      <MacroRing m={{ label: 'Protein', eaten: eaten.protein, target: target.protein, color: 'var(--protein)' }} />
      <MacroRing m={{ label: 'Carbs', eaten: eaten.carbs, target: target.carbs, color: 'var(--carbs)' }} />
      <MacroRing m={{ label: 'Fat', eaten: eaten.fat, target: target.fat, color: 'var(--fat)' }} />
    </section>
  )
}
