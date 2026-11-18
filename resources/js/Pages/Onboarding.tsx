import { Head, useForm } from '@inertiajs/react'
import { useState, type ReactNode } from 'react'
import { Button } from '@/components/ui/button'
import { Choice, Field } from '@/components/ui/field'
import AppLayout from '@/layouts/AppLayout'
import { cn } from '@/lib/utils'

type Phase = 'bulk' | 'maintain' | 'cut'
type Experience = 'novice' | 'intermediate' | 'advanced'

interface Defaults {
  sex: string
  dob: string
  height_cm: number
  weight_kg: number | null
  body_fat_pct: number | null
  job: string
  shift: string
  training_age: string
  lifting_style: string
  energy_unit: string
  phase: Phase
  rate_pct_per_week: number | null
  protein_g_per_kg: number | null
}

interface Props {
  editing: boolean
  defaults: Defaults | null
  rateDefaults: Record<Experience, Record<Phase, number>>
}

const JOBS = [['desk', 'Desk / seated'], ['mixed', 'Mixed'], ['on_feet', 'On my feet most of the shift'], ['heavy', 'Heavy manual']] as const
const SHIFTS = [['day', 'Days'], ['afternoon', 'Afternoons / evenings'], ['night', 'Nights'], ['varied', 'Varies']] as const
const YEARS = [['lt1', 'Under 1 year'], ['1to3', '1 to 3 years'], ['gt3', '3+ years']] as const
const STYLES = [['hit', 'Low volume, to failure (HIT)'], ['volume', 'Higher volume']] as const
const EXPERIENCE: Record<string, Experience> = { lt1: 'novice', '1to3': 'intermediate', gt3: 'advanced' }
const PHASES: ReadonlyArray<readonly [Phase, string]> = [['bulk', 'Lean bulk'], ['maintain', 'Maintain'], ['cut', 'Cut']]

// Which step shows each server-validated field, so an error sends you back to the right screen.
const STEP_OF: Record<string, number> = {
  sex: 0, dob: 0, height_cm: 1, weight_kg: 1, body_fat_pct: 1, job: 2, shift: 2, training_age: 2, lifting_style: 2, energy_unit: 2,
  phase: 3, rate_pct_per_week: 3, protein_g_per_kg: 3, acknowledged: 4,
}

const fmtRate = (r: number) => (r === 0 ? '0%/wk' : `${r > 0 ? '+' : '−'}${Math.abs(r).toFixed(2)}%/wk`)

export default function Onboarding({ editing, defaults, rateDefaults }: Props) {
  const [step, setStep] = useState(0)
  const form = useForm({
    sex: defaults?.sex ?? 'male',
    dob: defaults?.dob ?? '',
    height_cm: String(defaults?.height_cm ?? ''),
    weight_kg: String(defaults?.weight_kg ?? ''),
    body_fat_pct: defaults?.body_fat_pct != null ? String(defaults.body_fat_pct) : '',
    job: defaults?.job ?? 'on_feet',
    shift: defaults?.shift ?? 'afternoon',
    training_age: defaults?.training_age ?? '1to3',
    lifting_style: defaults?.lifting_style ?? 'hit',
    energy_unit: defaults?.energy_unit ?? 'kcal',
    phase: (defaults?.phase ?? 'bulk') as Phase,
    rate_pct_per_week: defaults?.rate_pct_per_week != null ? String(defaults.rate_pct_per_week) : '',
    protein_g_per_kg: defaults?.protein_g_per_kg != null ? String(defaults.protein_g_per_kg) : '',
    acknowledged: editing,
  })
  const d = form.data
  const err = form.errors as Record<string, string | undefined>
  const experience = EXPERIENCE[d.training_age] ?? 'intermediate'
  const blank = (v: string) => (v.trim() === '' ? null : v)

  const lastStep = 4
  const canContinue = [
    d.dob !== '',
    d.height_cm !== '' && d.weight_kg !== '',
    true,
    true,
    true,
  ][step] ?? true

  function submit() {
    form.transform((x) => ({ ...x, body_fat_pct: blank(x.body_fat_pct), rate_pct_per_week: blank(x.rate_pct_per_week), protein_g_per_kg: blank(x.protein_g_per_kg) }))
    const opts = {
      preserveScroll: true,
      onError: (errors: Record<string, string>) => {
        const first = Object.keys(errors).map((k) => STEP_OF[k]).filter((s): s is number => s !== undefined)
        if (first.length) setStep(Math.min(...first))
      },
    }
    if (editing) form.put('/profile', opts)
    else form.post('/onboarding', opts)
  }

  // Call sites pass correctly-typed literals; Inertia's mapped FormDataValues type can't express a generic key/value pair.
  const set = (k: keyof typeof d, v: string | boolean) => form.setData(k, v as never)
  const num = (k: 'height_cm' | 'weight_kg' | 'body_fat_pct' | 'rate_pct_per_week' | 'protein_g_per_kg') => (e: React.ChangeEvent<HTMLInputElement>) => set(k, e.target.value)

  const steps: ReactNode[] = [
    <section key="about" className="space-y-4">
      <h2 className="text-2xl font-semibold">About you</h2>
      <div className="flex gap-2" role="radiogroup" aria-label="Sex">
        {(['male', 'female'] as const).map((s) => (
          <button key={s} type="button" role="radio" aria-checked={d.sex === s} onClick={() => set('sex', s)}
            className={cn('min-h-12 flex-1 rounded-xl capitalize', d.sex === s ? 'bg-accent font-semibold text-on-accent' : 'bg-surface')}>{s}</button>
        ))}
      </div>
      <Field label="Date of birth" type="date" value={d.dob} onChange={(e) => set('dob', e.target.value)} error={err.dob ?? err.sex} hint="Used for your starting energy estimate. FUEL is for adults (18+)." />
    </section>,
    <section key="body" className="space-y-4">
      <h2 className="text-2xl font-semibold">Body</h2>
      <Field label="Height (cm)" type="number" inputMode="decimal" value={d.height_cm} onChange={num('height_cm')} error={err.height_cm} />
      <Field label="Weight (kg)" type="number" inputMode="decimal" step="0.1" value={d.weight_kg} onChange={num('weight_kg')} error={err.weight_kg} hint="Today's weight is your first weigh-in." />
      <Field label="Body fat % (optional)" type="number" inputMode="decimal" step="0.1" value={d.body_fat_pct} onChange={num('body_fat_pct')} error={err.body_fat_pct}
        hint="Sharpens protein and energy maths. Leave blank if unsure; FUEL will say when it is assuming." />
    </section>,
    <section key="work" className="space-y-4">
      <h2 className="text-2xl font-semibold">Work and training</h2>
      <div className="text-sm text-muted">Your job (matters a lot for daily energy use)</div>
      <Choice options={JOBS} value={d.job as (typeof JOBS)[number][0]} onChange={(v) => set('job', v)} />
      <div className="text-sm text-muted">Shift pattern</div>
      <Choice options={SHIFTS} value={d.shift as (typeof SHIFTS)[number][0]} onChange={(v) => set('shift', v)} />
      <div className="text-sm text-muted">Lifting experience</div>
      <Choice options={YEARS} value={d.training_age as (typeof YEARS)[number][0]} onChange={(v) => set('training_age', v)} />
      <div className="text-sm text-muted">Lifting style</div>
      <Choice options={STYLES} value={d.lifting_style as (typeof STYLES)[number][0]} onChange={(v) => set('lifting_style', v)} />
      <div className="text-sm text-muted">Show energy in</div>
      <Choice options={[['kcal', 'kcal'], ['kj', 'kJ']] as const} value={d.energy_unit as 'kcal' | 'kj'} onChange={(v) => set('energy_unit', v)} />
    </section>,
    <section key="goal" className="space-y-4">
      <h2 className="text-2xl font-semibold">Goal</h2>
      <Choice
        options={PHASES.map(([k, label]) => [k, label, fmtRate(rateDefaults[experience][k])] as const)}
        value={d.phase}
        onChange={(v) => set('phase', v)}
      />
      {err.phase ? <p role="alert" className="text-sm text-danger">{err.phase}</p> : null}
      <details className="rounded-xl bg-surface px-4 py-3" open={!!err.rate_pct_per_week || !!err.protein_g_per_kg}>
        <summary className="min-h-8 cursor-pointer text-sm text-muted">Adjust rate or protein (optional)</summary>
        <div className="mt-3 space-y-4">
          <Field label="Rate (% bodyweight per week)" type="number" inputMode="decimal" step="0.05" placeholder="default shown above" value={d.rate_pct_per_week} onChange={num('rate_pct_per_week')} error={err.rate_pct_per_week}
            hint="Negative = lose, positive = gain. Capped at safe rates for your experience." />
          <Field label="Protein (g per kg bodyweight)" type="number" inputMode="decimal" step="0.1" placeholder="automatic" value={d.protein_g_per_kg} onChange={num('protein_g_per_kg')} error={err.protein_g_per_kg}
            hint="Leave blank for the evidence-based default. 1.6 to 2.2 is typical." />
        </div>
      </details>
      <p className="text-sm text-muted">Your first weeks show a range, not a false-precise number, while FUEL calibrates your expenditure from your own data.</p>
    </section>,
    <section key="review" className="space-y-4">
      <h2 className="text-2xl font-semibold">{editing ? 'Save changes' : 'Before you start'}</h2>
      {editing ? (
        <p className="text-sm text-muted">Saving creates a new set of targets from these details. Your earlier targets are kept in your history.</p>
      ) : (
        <>
          <p className="text-sm text-muted">FUEL gives estimates to help you plan food and training. It is not medical advice and does not replace a doctor or dietitian, especially if you have a health condition or a history of disordered eating.</p>
          <label className="flex min-h-12 items-start gap-3 rounded-xl bg-surface p-4 text-sm">
            <input type="checkbox" checked={d.acknowledged} onChange={(e) => set('acknowledged', e.target.checked)} className="mt-0.5 size-5 shrink-0 accent-[var(--accent)]" />
            <span>I understand this is not medical advice.</span>
          </label>
          {err.acknowledged ? <p role="alert" className="text-sm text-danger">{err.acknowledged}</p> : null}
        </>
      )}
    </section>,
  ]

  const page = (
    <div className="flex min-h-[calc(100dvh-8rem)] flex-col">
      <Head title={editing ? 'Edit profile' : 'Welcome'} />
      <div className="mb-6 flex gap-1" aria-label={`Step ${step + 1} of ${steps.length}`}>
        {steps.map((_, i) => <span key={i} className={cn('h-1 flex-1 rounded-full', i <= step ? 'bg-accent' : 'bg-raised')} />)}
      </div>
      <div className="flex-1">{steps[step]}</div>
      <div className="mt-6 flex gap-2">
        {step > 0 ? <Button variant="secondary" onClick={() => setStep(step - 1)}>Back</Button> : null}
        {step < lastStep
          ? <Button className="flex-1" disabled={!canContinue} onClick={() => setStep(step + 1)}>Continue</Button>
          : <Button className="flex-1" disabled={form.processing || (!editing && !d.acknowledged)} onClick={submit}>{editing ? 'Save and recompute' : 'Start'}</Button>}
      </div>
    </div>
  )

  return editing ? <AppLayout>{page}</AppLayout> : <div className="mx-auto max-w-md px-4 py-8">{page}</div>
}
