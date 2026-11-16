import { useState } from 'react'

const goals = [['bulk', 'Lean bulk', '+0.25%/wk'], ['maintain', 'Maintain', '0%/wk'], ['cut', 'Cut', '−0.5%/wk']] as const
export default function Onboarding({ done }: { done: () => void }) {
  const [step, setStep] = useState(0)
  const [f, setF] = useState({ sex: 'male', dob: '', height: 180, weight: 74, bf: '', activity: 1.4, goal: 'bulk' })
  const set = (k: string, v: unknown) => setF(s => ({ ...s, [k]: v }))
  const steps = [
    <div key="0" className="space-y-3"><h2 className="text-2xl font-semibold">About you</h2>
      <div className="flex gap-2">{['male', 'female'].map(s => <button key={s} onClick={() => set('sex', s)} className={`min-h-12 flex-1 rounded-xl capitalize ${f.sex === s ? 'bg-accent text-canvas font-semibold' : 'bg-surface'}`}>{s}</button>)}</div>
      <label className="block text-sm text-muted">Date of birth<input type="date" value={f.dob} onChange={e => set('dob', e.target.value)} className="mt-1 min-h-12 w-full rounded-xl bg-surface px-3 text-white" /></label></div>,
    <div key="1" className="space-y-3"><h2 className="text-2xl font-semibold">Body</h2>
      <label className="block text-sm text-muted">Height (cm)<input type="number" value={f.height} onChange={e => set('height', +e.target.value)} className="mt-1 min-h-12 w-full rounded-xl bg-surface px-3 text-white" /></label>
      <label className="block text-sm text-muted">Weight (kg)<input type="number" value={f.weight} onChange={e => set('weight', +e.target.value)} className="mt-1 min-h-12 w-full rounded-xl bg-surface px-3 text-white" /></label>
      <label className="block text-sm text-muted">Body fat % (optional)<input type="number" value={f.bf} onChange={e => set('bf', e.target.value)} className="mt-1 min-h-12 w-full rounded-xl bg-surface px-3 text-white" /></label></div>,
    <div key="2" className="space-y-3"><h2 className="text-2xl font-semibold">Goal</h2>
      {goals.map(([k, n, r]) => <button key={k} onClick={() => set('goal', k)} className={`flex min-h-14 w-full items-center justify-between rounded-xl px-4 ${f.goal === k ? 'bg-accent/15 ring-1 ring-accent' : 'bg-surface'}`}><span>{n}</span><span className="text-sm text-muted">{r}</span></button>)}
      <p className="text-sm text-muted">Your first weeks show a range, not a false-precise number, while FUEL calibrates your expenditure from your own data.</p></div>,
  ]
  return (
    <div className="mx-auto flex min-h-dvh max-w-md flex-col px-4 py-8">
      <div className="mb-6 flex gap-1">{steps.map((_, i) => <span key={i} className={`h-1 flex-1 rounded-full ${i <= step ? 'bg-accent' : 'bg-raised'}`} />)}</div>
      <div className="flex-1">{steps[step]}</div>
      <button className="min-h-12 rounded-2xl bg-accent font-semibold text-canvas" onClick={() => step < steps.length - 1 ? setStep(step + 1) : done()}>{step < steps.length - 1 ? 'Continue' : 'Start'}</button>
    </div>)
}
