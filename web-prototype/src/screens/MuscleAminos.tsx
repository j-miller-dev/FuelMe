import { AMINOS, aminoTargetMg } from '../lib/aminos'
import { LEUCINE_MEAL_MG, SAMPLE_MEALS, bcaaMg, eaaMg, mealsHitting, perMealProteinG } from '../lib/muscle'
import { weight } from '../engine'

const g = (mg: number) => (mg / 1000).toFixed(1)
export default function MuscleAminos() {
  const leu = AMINOS.find(a => a.key === 'leu')!, tgt = aminoTargetMg(leu, weight)!, hit = mealsHitting(SAMPLE_MEALS)
  return (
    <section className="rounded-2xl bg-surface p-4">
      <div className="text-sm font-medium">Muscle amino acids</div>
      <p className="mt-1 text-xs text-carbs">Sample data. Rules of thumb, not measurements.</p>
      <div className="mt-3 flex justify-between text-sm"><span>Leucine today</span><span className="tabular-nums">{g(leu.sample)} g <span className="text-xs text-muted">(minimum requirement {g(tgt)} g)</span></span></div>
      <div className="flex justify-between text-sm"><span>BCAA (leu + ile + val)</span><span className="tabular-nums">{g(bcaaMg())} g</span></div>
      <div className="flex justify-between text-sm"><span>Essential amino acids total</span><span className="tabular-nums">{g(eaaMg())} g</span></div>
      <div className="mt-4 text-sm">Leucine per meal <span className="text-xs text-muted">· {hit} of {SAMPLE_MEALS.length} reach {g(LEUCINE_MEAL_MG)} g</span></div>
      <div className="mt-2 space-y-2">{SAMPLE_MEALS.map(m => { const ok = m.leuMg >= LEUCINE_MEAL_MG; return (
        <div key={m.meal}><div className="flex justify-between text-xs"><span>{m.meal} · {m.proteinG} g protein</span><span className="tabular-nums">{g(m.leuMg)} g</span></div>
          <div className="relative mt-1 h-2 rounded-full bg-raised"><div className={`h-2 rounded-full ${ok ? 'bg-protein' : 'bg-carbs'}`} style={{ width: `${Math.min(100, m.leuMg / (LEUCINE_MEAL_MG * 1.4) * 100)}%` }} />
            <span className="absolute -top-0.5 h-3 w-0.5 bg-white/70" style={{ left: `${100 / 1.4}%` }} aria-hidden /></div></div>) })}</div>
      <p className="mt-3 text-xs text-muted">The white mark is about {g(LEUCINE_MEAL_MG)} g leucine, a figure often cited as enough to trigger muscle protein synthesis. This is still debated. A common practical aim is about {perMealProteinG(weight)} g protein per meal (0.4 g/kg), spread over several meals. Your daily protein total matters more than any one meal. The "minimum requirement" is what keeps you healthy, not an amount shown to be best for gaining muscle.</p>
    </section>)
}
