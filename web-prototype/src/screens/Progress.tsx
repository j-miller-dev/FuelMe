import { useState } from 'react'
import Overview from './Overview'
import Weight from './Weight'
import Body from './Body'
import GoalScreen from './Goal'
import type { Measure, WeighIn } from '../lib/data'
import type { Settings } from '../engine'
import { series } from '../mock'

type View = 'overview' | 'weight' | 'body' | 'goal'
const VIEWS: [View, string][] = [['overview', 'Overview'], ['weight', 'Weight'], ['body', 'Body'], ['goal', 'Goal']]

export default function Progress(p: { weighIns: WeighIn[]; addWeight: (kg: number) => void; measures: Measure[]; addMeasure: (s: string, v: number) => void; settings: Settings; setSettings: (s: Settings) => void }) {
  const [view, setView] = useState<View>('overview')
  const last = series[series.length - 1], first = p.weighIns[0], cur = p.weighIns[p.weighIns.length - 1]
  return (
    <div className="px-4 pt-6">
      <h1 className="text-xl font-semibold">Progress</h1>
      <div className="mb-3 mt-3 flex gap-1 rounded-2xl bg-surface p-1" role="tablist">{VIEWS.map(([v, l]) => (
        <button key={v} role="tab" aria-selected={view === v} onClick={() => setView(v)} className={`min-h-11 flex-1 rounded-xl text-sm ${view === v ? 'bg-raised font-semibold text-accent' : 'text-muted'}`}>{l}</button>))}</div>
      {view === 'overview' && <Overview />}
      {view === 'weight' && <Weight weighIns={p.weighIns} add={p.addWeight} />}
      {view === 'body' && <Body measures={p.measures} add={p.addMeasure} weightChangeKg={cur.trend - first.trend} />}
      {view === 'goal' && <GoalScreen trendKg={cur.trend} tdee={Math.round(last.tdee)} settings={p.settings} set={p.setSettings} />}
    </div>)
}
