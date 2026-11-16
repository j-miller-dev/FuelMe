import { useState } from 'react'
import Today from './screens/Today'
import Capture from './screens/Capture'
import Progress from './screens/Progress'
import Shopping from './screens/Shopping'
import Targets from './screens/Targets'
import LogSession from './screens/LogSession'
import Onboarding from './screens/Onboarding'
import type { Session, Settings } from './engine'
import { initialMeasures, initialWeighIns, todayIso, type Measure, type WeighIn } from './lib/data'

type Tab = 'today' | 'log' | 'plan' | 'progress'
const actions: [string, string][] = [['Search', ''], ['Speak', 'log'], ['Scan', ''], ['Session', 'session'], ['Quick-add', ''], ['Meals', 'recipes']]

export default function App() {
  const [onboarded, setOnboarded] = useState(false)
  const [tab, setTab] = useState<Tab>('today')
  const [planView, setPlanView] = useState<'targets' | 'recipes'>('targets')
  const [sheet, setSheet] = useState(false)
  const [session, setSession] = useState(false)
  const [settings, setSettings] = useState<Settings>({ goal: 'bulk', mode: 'flexible', protein: 2.2 })
  const [extra, setExtra] = useState<Session | null>(null)
  const [eatBack, setEatBack] = useState(false)
  const [weighIns, setWeighIns] = useState<WeighIn[]>(initialWeighIns)
  const [measures, setMeasures] = useState<Measure[]>(initialMeasures)
  // Prototype: new entries are held in memory. The real app queues them in the Dexie outbox (lib/outbox.ts) and the server recomputes the trend.
  const addWeight = (kg: number) => setWeighIns(w => { const t = todayIso(), keep = w.filter(x => x.date !== t); return [...keep, { date: t, kg, trend: keep[keep.length - 1].trend, pending: true }] })
  const addMeasure = (site: string, v: number) => setMeasures(m => m.map(x => x.site === site ? { ...x, points: [...x.points, { date: todayIso(), v }] } : x))
  if (!onboarded) return <Onboarding done={() => setOnboarded(true)} />
  const T = ({ id, label }: { id: Tab; label: string }) => (
    <button onClick={() => { setSession(false); setTab(id) }} className={`min-h-11 min-w-11 ${tab === id && !session ? 'text-accent' : ''}`}>{label}</button>)
  const go = (to: string) => { setSheet(false); if (to === 'session') setSession(true); else if (to === 'recipes') { setSession(false); setTab('plan'); setPlanView('recipes') } else if (to) { setSession(false); setTab(to as Tab) } }
  return (
    <div className="mx-auto min-h-dvh max-w-md">
      {session ? <LogSession settings={settings} current={extra} eatBack={eatBack} onAdd={setExtra} onClear={() => { setExtra(null); setEatBack(false) }} onEatBack={setEatBack} close={() => setSession(false)} /> : <>
        {tab === 'today' && <Today settings={settings} extra={extra} eatBack={eatBack} openSession={() => setSession(true)} />}
        {tab === 'log' && <Capture />}
        {tab === 'plan' && <>
          <div className="px-4 pt-6"><h1 className="text-xl font-semibold">Plan</h1>
            <div className="mt-3 mb-3 flex gap-2">{(['targets', 'recipes'] as const).map(v => (
              <button key={v} onClick={() => setPlanView(v)} className={`min-h-11 flex-1 rounded-xl text-sm capitalize ${planView === v ? 'bg-accent font-semibold text-canvas' : 'bg-surface'}`}>{v === 'targets' ? 'Daily targets' : 'Recipes & shopping'}</button>))}</div></div>
          {planView === 'targets' ? <Targets settings={settings} set={setSettings} /> : <Shopping />}</>}
        {tab === 'progress' && <Progress weighIns={weighIns} addWeight={addWeight} measures={measures} addMeasure={addMeasure} settings={settings} setSettings={setSettings} />}
      </>}
      {sheet && <div className="fixed inset-0 z-20 bg-black/60" onClick={() => setSheet(false)}>
        <div className="absolute inset-x-0 bottom-0 mx-auto grid max-w-md grid-cols-3 gap-2 rounded-t-3xl bg-raised p-5" onClick={e => e.stopPropagation()}>
          {actions.map(([a, to]) => <button key={a} className="min-h-14 rounded-xl bg-surface text-sm transition active:scale-95" onClick={() => go(to)}>{a}</button>)}
        </div></div>}
      <nav className="fixed inset-x-0 bottom-0 z-10 border-t border-white/5 bg-surface/95 backdrop-blur">
        <div className="mx-auto flex h-16 max-w-md items-center justify-around text-sm text-muted">
          <T id="today" label="Today" /><T id="log" label="Log" />
          <button aria-label="Add" onClick={() => { navigator.vibrate?.(10); setSheet(true) }}
            className="-mt-8 size-14 rounded-full bg-accent text-3xl font-bold text-canvas shadow-lg shadow-accent/30 transition active:scale-95">+</button>
          <T id="plan" label="Plan" /><T id="progress" label="Progress" />
        </div></nav>
    </div>)
}
