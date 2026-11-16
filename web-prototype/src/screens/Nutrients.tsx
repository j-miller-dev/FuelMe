import { AMINOS, aminoTargetMg } from '../lib/aminos'
import MuscleAminos from './MuscleAminos'
import { weight } from '../engine'
import { NUTRIENTS, SAMPLE, lowestFirst, pct, status, type Nutrient } from '../lib/nutrients'

const col = { met: 'bg-protein', near: 'bg-carbs', low: 'bg-fat', over: 'bg-fat', ok: 'bg-protein' } as const
const fmt = (n: number) => n >= 100 ? Math.round(n) : Math.round(n * 10) / 10

function Row({ n }: { n: Nutrient }) {
  const v = SAMPLE[n.key] ?? 0, s = status(n, v), w = Math.min(100, pct(n, v) * 100)
  return (
    <div className="py-2">
      <div className="flex items-baseline justify-between text-sm"><span>{n.name}{n.src === 'US' && <span className="ml-1 text-[10px] text-muted">US ref</span>}</span>
        <span className="text-xs text-muted">{fmt(v)} / {n.limit ? 'max ' : ''}{fmt(n.target)} {n.unit}</span></div>
      <div className="relative mt-1 h-2 rounded-full bg-raised" role="progressbar" aria-label={n.name} aria-valuenow={Math.round(w)} aria-valuemin={0} aria-valuemax={100}>
        <div className={`h-2 rounded-full ${col[s]}`} style={{ width: `${w}%` }} />
        {!n.limit && s === 'met' && <span className="absolute -top-1 right-0 text-[10px] text-protein" aria-hidden>✓</span>}</div>
    </div>)
}

export default function Nutrients() {
  const gaps = lowestFirst(SAMPLE).slice(0, 3)
  return (
    <div className="space-y-3 pb-28">
      <p className="rounded-2xl border border-carbs/40 bg-surface p-3 text-xs text-carbs">Sample data. Intake below is illustrative, not computed from your log. Real values come from the food database; packaged foods often have no micronutrient data, so totals will under-count.</p>
      <section className="rounded-2xl bg-surface p-4">
        <div className="text-sm font-medium">Biggest gaps today</div>
        <p className="mt-1 text-xs text-muted">{gaps.map(g => `${g.name} ${Math.round(pct(g, SAMPLE[g.key] ?? 0) * 100)}%`).join(' · ')}</p></section>
      {(['Minerals', 'Vitamins'] as const).map(g => (
        <section key={g} className="rounded-2xl bg-surface p-4"><div className="text-sm font-medium">{g}</div>
          <div className="divide-y divide-raised">{NUTRIENTS.filter(n => n.group === g).map(n => <Row key={n.key} n={n} />)}</div></section>))}
      <MuscleAminos />
      <section className="rounded-2xl bg-surface p-4"><div className="text-sm font-medium">All amino acids</div>
        <div className="divide-y divide-raised">{AMINOS.map(a => { const t = aminoTargetMg(a, weight); const w = t ? Math.min(100, a.sample / t * 100) : null; return (
          <div key={a.key} className="py-2"><div className="flex items-baseline justify-between text-sm"><span>{a.name}{a.src === 'check' && <span className="ml-1 text-[10px] text-muted">verify target</span>}</span>
            <span className="text-xs text-muted">{a.sample.toLocaleString()}{t ? ` / ${t.toLocaleString()}` : ''} mg</span></div>
            {w != null ? <div className="mt-1 h-2 rounded-full bg-raised"><div className="h-2 rounded-full bg-protein" style={{ width: `${w}%` }} /></div> : <div className="mt-1 text-xs text-muted">Intake only. No requirement set.</div>}
            {a.note && <p className="mt-1 text-xs text-muted">{a.note}</p>}</div>) })}</div>
        <p className="mt-2 text-xs text-muted">Targets are minimum requirements for adults scaled to {weight} kg. Histidine, isoleucine and leucine are confirmed; the rest are marked to verify. Amino acid data is patchy for packaged foods, so real totals can under-count.</p></section>
      <p className="text-xs text-muted">Targets are for an adult male 19-50. Iron, zinc and magnesium are Australian NHMRC values (magnesium 420 mg is the 31+ figure; 400 mg if you are under 31). Rows tagged "US ref" use US reference values until the NHMRC figure is checked. Sodium is a limit, not a goal. These are population reference values, not a personal prescription.</p>
    </div>)
}
