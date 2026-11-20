import { Head, Link, useForm, usePage } from '@inertiajs/react'
import AppLayout from '@/layouts/AppLayout'
import { Button } from '@/components/ui/button'
import { Field } from '@/components/ui/field'
import hitBanner from '@/assets/hit-banner.webp'
import { energyLabel, fmtEnergy, KJ_PER_KCAL, type EnergyUnit } from '@/lib/units'
import { cn } from '@/lib/utils'

interface Totals { kcal: number; protein: number; fat: number; carbs: number; proteinGPerKg: number }
interface Rules { kcal_pct: number; protein_min_pct: number; carbs_pct: number; fat_pct: number }
interface Props {
  recommended: Totals
  yours: Totals
  warnings: { level: string; code: string; msg: string }[]
  overrides: { protein_g_override: number | null; fat_min_g_per_kg: number | null; kcal_offset: number; protein_g_per_kg_profile: number | null }
  hitRules: Rules
  hitDefaults: Rules
  style: 'hit' | 'volume'
  weightKg: number
  saved: boolean
}

const blank = (v: string) => (v.trim() === '' ? null : v)

function Row({ label, rec, mine, unit, color }: { label: string; rec: number; mine: number; unit: string; color?: string }) {
  const changed = rec !== mine
  return (
    <div className="flex items-baseline justify-between border-t border-raised py-2 first:border-0">
      <span className="text-sm" style={color ? { color } : undefined}>{label}</span>
      <span className="text-right">
        <span className={cn('font-semibold', changed && 'text-accent')}>{mine.toLocaleString('en-AU')}</span>
        <span className="text-xs text-muted"> {unit}</span>
        {changed ? <span className="ml-2 text-xs text-muted">app suggests {rec.toLocaleString('en-AU')}</span> : null}
      </span>
    </div>
  )
}

export default function Targets(p: Props) {
  const unit = (usePage().props as { energyUnit?: EnergyUnit }).energyUnit ?? 'kcal'
  const u = energyLabel(unit)
  const e = (kcal: number) => Number(fmtEnergy(kcal, unit).replace(/,/g, ''))
  const kjMode = unit === 'kj'

  const form = useForm({
    protein_g_override: p.overrides.protein_g_override != null ? String(p.overrides.protein_g_override) : '',
    fat_min_g_per_kg: p.overrides.fat_min_g_per_kg != null ? String(p.overrides.fat_min_g_per_kg) : '',
    // The offset is stored in kcal; the box is in the unit you chose for the app.
    kcal_offset: p.overrides.kcal_offset ? String(Math.round(kjMode ? p.overrides.kcal_offset * KJ_PER_KCAL : p.overrides.kcal_offset)) : '',
    hit_rules: Object.fromEntries(Object.entries(p.hitRules).map(([k, v]) => [k, String(v)])) as Record<keyof Rules, string>,
  })
  const d = form.data
  const gPerKg = d.protein_g_override ? Number(d.protein_g_override) / p.weightKg : null
  const above = d.protein_g_override && Number(d.protein_g_override) > p.recommended.protein
  const dirty = form.isDirty

  const submit = (ev: React.FormEvent) => {
    ev.preventDefault()
    form.transform((x) => ({
      protein_g_override: blank(x.protein_g_override),
      fat_min_g_per_kg: blank(x.fat_min_g_per_kg),
      kcal_offset: x.kcal_offset.trim() === '' ? 0 : Math.round(kjMode ? Number(x.kcal_offset) / KJ_PER_KCAL : Number(x.kcal_offset)),
      hit_rules: Object.fromEntries(Object.entries(x.hit_rules).map(([k, v]) => [k, blank(v)])),
    }))
    form.put('/targets', { preserveScroll: true })
  }
  const err = form.errors as Record<string, string | undefined>
  const rule = (k: keyof Rules, label: string, hint: string) => (
    <Field label={label} type="number" inputMode="decimal" step="1" value={d.hit_rules[k]} hint={hint}
      onChange={(ev) => form.setData('hit_rules', { ...d.hit_rules, [k]: ev.target.value })} error={err[`hit_rules.${k}`]} />
  )

  return (
    <AppLayout>
      <Head title="Targets" />
      <header className="mb-5">
        <h1 className="text-2xl font-bold">Targets</h1>
        <p className="mt-1 text-sm text-muted">The app recommends. You decide. Anything you set here sits on top of the recommendation, and clearing a box goes back to it.</p>
      </header>

      <section className="mb-6 rounded-2xl bg-surface p-4" aria-label="Your targets">
        <h2 className="mb-1 font-semibold">Your daily average</h2>
        <Row label="Energy" rec={e(p.recommended.kcal)} mine={e(p.yours.kcal)} unit={u} />
        <Row label="Protein" rec={p.recommended.protein} mine={p.yours.protein} unit="g" color="var(--protein)" />
        <Row label="Carbs" rec={p.recommended.carbs} mine={p.yours.carbs} unit="g" color="var(--carbs)" />
        <Row label="Fat" rec={p.recommended.fat} mine={p.yours.fat} unit="g" color="var(--fat)" />
        <p className="mt-2 text-xs text-muted">Carbs take whatever energy is left after protein and fat, and still move with your training days. Weekly averages; see <Link href="/plan" className="text-accent underline">Plan</Link> for each day.</p>
      </section>

      {p.warnings.filter((w) => w.code === 'protein_capped' || w.level === 'critical').map((w) => (
        <p key={w.code} className={cn('mb-3 rounded-xl px-4 py-3 text-sm', w.level === 'critical' ? 'bg-danger/15 text-danger' : 'bg-surface text-muted')}>{w.msg}</p>
      ))}

      <form onSubmit={submit} className="space-y-4" noValidate>
        <section className="space-y-4 rounded-2xl bg-surface p-4">
          <h2 className="font-semibold">Adjust</h2>
          <Field label="Protein (g per day)" type="number" inputMode="numeric" placeholder={`automatic (${p.recommended.protein})`} value={d.protein_g_override}
            onChange={(ev) => form.setData('protein_g_override', ev.target.value)} error={err.protein_g_override}
            hint={gPerKg ? `${gPerKg.toFixed(2)} g per kg at ${p.weightKg} kg. The planner still caps protein at 35% of calories.` : 'Leave blank to use the recommendation.'} />
          {above ? <p className="-mt-2 text-xs text-muted">That is above the recommendation. Fine if you want it; more protein is not automatically more muscle, and the extra calories come out of carbs and fat.</p> : null}
          {p.overrides.protein_g_per_kg_profile && !d.protein_g_override ? <p className="-mt-2 text-xs text-muted">Your profile asks for {p.overrides.protein_g_per_kg_profile} g/kg. Grams here win over it.</p> : null}

          <Field label={`Energy offset (${u} per day, + or −)`} type="number" inputMode="numeric" placeholder="0" value={d.kcal_offset}
            onChange={(ev) => form.setData('kcal_offset', ev.target.value)} error={err.kcal_offset}
            hint="Added to the recommended target every day. The weekly check-in keeps working from its own number and your offset rides on top." />

          <Field label="Minimum fat (g per kg)" type="number" inputMode="decimal" step="0.1" placeholder="automatic" value={d.fat_min_g_per_kg}
            onChange={(ev) => form.setData('fat_min_g_per_kg', ev.target.value)} error={err.fat_min_g_per_kg}
            hint="Raises the fat floor if you want more fat; it can never go below the app's own floor." />
        </section>

        <details className="rounded-2xl bg-surface p-4" open={Object.keys(err).some((k) => k.startsWith('hit_rules'))}>
          <summary className="min-h-8 cursor-pointer font-semibold">What counts as on target</summary>
          <p className="mt-2 text-xs text-muted">Used for the last-5-days strip on Today. These are everyday tolerances, not scientific thresholds.</p>
          <div className="mt-3 space-y-3">
            {rule('kcal_pct', 'Energy within ± %', `Default ${p.hitDefaults.kcal_pct}`)}
            {rule('protein_min_pct', 'Protein at least % of target', `Default ${p.hitDefaults.protein_min_pct}. Going over is fine.`)}
            {rule('carbs_pct', 'Carbs within ± %', `Default ${p.hitDefaults.carbs_pct}`)}
            {rule('fat_pct', 'Fat within ± %', `Default ${p.hitDefaults.fat_pct}`)}
          </div>
        </details>

        <Button type="submit" className="w-full" disabled={form.processing || !dirty}>{form.processing ? 'Saving…' : 'Save'}</Button>
        {p.saved && !dirty ? <p role="status" className="text-center text-sm text-accent">Saved. Today and Plan now use your numbers.</p> : null}
      </form>

      <section className="mt-8" aria-label="Lifting style">
        <h2 className="mb-2 font-semibold">Lifting style</h2>
        {p.style === 'hit' ? <img src={hitBanner} alt="HIT training mode" width={900} height={337} className="mb-3 w-full rounded-2xl" /> : null}
        <p className="text-sm text-muted">
          {p.style === 'hit'
            ? 'High-intensity, low-volume lifting. Energy use for it is an estimate (about 3.5 MET), protein stays the same every day, and no carb-loading is suggested for lifting-only days.'
            : 'Higher-volume lifting. Lifting days get more carbohydrate than a rest day.'}
        </p>
        <p className="mt-2 text-sm text-muted">Change the style in <Link href="/profile" className="text-accent underline">Profile</Link>, or edit individual sessions in <Link href="/training" className="text-accent underline">your training week</Link>.</p>
      </section>

      <p className="mt-6 text-xs text-muted">Estimates for planning, not medical advice. Check big changes to your diet with your GP or an accredited dietitian.</p>
    </AppLayout>
  )
}
