import { useState } from 'react'

const goals = [['bulk', 'Lean bulk', '+0.25%/wk'], ['maintain', 'Maintain', '0%/wk'], ['cut', 'Cut', '−0.5%/wk']] as const
export type Profile = { sex: string; dob: string; height: number; weight: number; bf: string; job: string; shift: string; trainYears: string; kj: boolean }
const JOBS = [['desk', 'Desk / seated'], ['mixed', 'Mixed'], ['on_feet', 'On my feet most of the shift'], ['heavy', 'Heavy manual']] as const
const SHIFTS = [['day', 'Days'], ['afternoon', 'Afternoons / evenings'], ['night', 'Nights'], ['varied', 'Varies']] as const
const YEARS = [['lt1', 'Under 1 year'], ['1to3', '1 to 3 years'], ['gt3', '3+ years']] as const
const Pick = ({ opts, v, on }: { opts: readonly (readonly [string, string])[]; v: string; on: (k: string) => void }) => (
  <div className="space-y-2">{opts.map(([k, l]) => <button key={k} onClick={() => on(k)} className={`flex min-h-12 w-full items-center rounded-xl px-4 text-left ${v === k ? 'bg-accent/15 ring-1 ring-accent' : 'bg-surface'}`}>{l}</button>)}</div>)

export default function Onboarding({ done }: { done: (p: Profile) => void }) {
  const [step, setStep] = useState(0)
  const [f, setF] = useState({ sex: 'male', dob: '', height: 180, weight: 74, bf: '', job: 'on_feet', shift: 'afternoon', trainYears: '1to3', kj: false, goal: 'bulk' })
  const set = (k: string, v: unknown) => setF(s => ({ ...s, [k]: v }))
  const steps = [
    <div key="0" className="space-y-3"><h2 className="text-2xl font-semibold">About you</h2>
      <div className="flex gap-2">{['male', 'female'].map(s => <button key={s} onClick={() => set('sex', s)} className={`min-h-12 flex-1 rounded-xl capitalize ${f.sex === s ? 'bg-accent text-canvas font-semibold' : 'bg-surface'}`}>{s}</button>)}</div>
      <label className="block text-sm text-muted">Date of birth<input type="date" value={f.dob} onChange={e => set('dob', e.target.value)} className="mt-1 min-h-12 w-full rounded-xl bg-surface px-3 text-white" /></label></div>,
    <div key="1" className="space-y-3"><h2 className="text-2xl font-semibold">Body</h2>
      <label className="block text-sm text-muted">Height (cm)<input type="number" value={f.height} onChange={e => set('height', +e.target.value)} className="mt-1 min-h-12 w-full rounded-xl bg-surface px-3 text-white" /></label>
      <label className="block text-sm text-muted">Weight (kg)<input type="number" value={f.weight} onChange={e => set('weight', +e.target.value)} className="mt-1 min-h-12 w-full rounded-xl bg-surface px-3 text-white" /></label>
      <label className="block text-sm text-muted">Body fat % (optional)<input type="number" value={f.bf} onChange={e => set('bf', e.target.value)} className="mt-1 min-h-12 w-full rounded-xl bg-surface px-3 text-white" /></label></div>,
    <div key="w" className="space-y-3"><h2 className="text-2xl font-semibold">Work and training</h2>
      <div className="text-sm text-muted">Your job (matters a lot for daily energy use)</div><Pick opts={JOBS} v={f.job} on={k => set('job', k)} />
      <div className="text-sm text-muted">Shift pattern</div><Pick opts={SHIFTS} v={f.shift} on={k => set('shift', k)} />
      <div className="text-sm text-muted">Lifting experience</div><Pick opts={YEARS} v={f.trainYears} on={k => set('trainYears', k)} />
      <div className="text-sm text-muted">Show energy in</div>
      <div className="flex gap-2">{[[false, 'kcal'], [true, 'kJ']].map(([v, l]) => <button key={String(l)} onClick={() => set('kj', v)} className={`min-h-12 flex-1 rounded-xl ${f.kj === v ? 'bg-accent font-semibold text-canvas' : 'bg-surface'}`}>{String(l)}</button>)}</div></div>,
    <div key="2" className="space-y-3"><h2 className="text-2xl font-semibold">Goal</h2>
      {goals.map(([k, n, r]) => <button key={k} onClick={() => set('goal', k)} className={`flex min-h-14 w-full items-center justify-between rounded-xl px-4 ${f.goal === k ? 'bg-accent/15 ring-1 ring-accent' : 'bg-surface'}`}><span>{n}</span><span className="text-sm text-muted">{r}</span></button>)}
      <p className="text-sm text-muted">Your first weeks show a range, not a false-precise number, while FUEL calibrates your expenditure from your own data.</p></div>,
  ]
  return (
    <div className="mx-auto flex min-h-dvh max-w-md flex-col px-4 py-8">
      <div className="mb-6 flex gap-1">{steps.map((_, i) => <span key={i} className={`h-1 flex-1 rounded-full ${i <= step ? 'bg-accent' : 'bg-raised'}`} />)}</div>
      <div className="flex-1">{steps[step]}</div>
      <button className="min-h-12 rounded-2xl bg-accent font-semibold text-canvas" onClick={() => step < steps.length - 1 ? setStep(step + 1) : done({ sex: f.sex, dob: f.dob, height: f.height, weight: f.weight, bf: f.bf, job: f.job, shift: f.shift, trainYears: f.trainYears, kj: f.kj })}>{step < steps.length - 1 ? 'Continue' : 'Start'}</button>
    </div>)
}
