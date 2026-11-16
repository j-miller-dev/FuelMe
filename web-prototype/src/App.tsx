import { useState } from 'react'
import Today from './screens/Today'
import Capture from './screens/Capture'
import Progress from './screens/Progress'
import Shopping from './screens/Shopping'
import Targets from './screens/Targets'
import LogSession from './screens/LogSession'
import Onboarding, { type Profile } from './screens/Onboarding'
import { Units } from './lib/units'
import AddFood from './screens/AddFood'
import { todayTarget, type Session, type Settings } from './engine'
import { day } from './mock'
import type { LogItem } from './lib/foods'
import { initialMeasures, initialWeighIns, todayIso, type Measure, type WeighIn } from './lib/data'

type Tab = 'today' | 'log' | 'plan' | 'progress'
type AddTab = 'scan' | 'search' | 'ai' | 'quick' | 'library'

export default function App() {
  const [onboarded, setOnboarded] = useState(false)
  const [profile, setProfile] = useState<Profile | null>(null)
  const [kj, setKj] = useState(false)
  const [tab, setTab] = useState<Tab>('today')
  const [planView, setPlanView] = useState<'targets' | 'recipes'>('targets')
  const [adding, setAdding] = useState<AddTab | null>(null)
  const [logged, setLogged] = useState<LogItem[]>([])
  const [session, setSession] = useState(false)
  const [settings, setSettings] = useState<Settings>({ style: 'hit', goal: 'bulk', mode: 'flexible', protein: 2.2 })
  const [extra, setExtra] = useState<Session | null>(null)
  const [eatBack, setEatBack] = useState(false)
  const [weighIns, setWeighIns] = useState<WeighIn[]>(initialWeighIns)
  const [measures, setMeasures] = useState<Measure[]>(initialMeasures)
  // Prototype: new entries are held in memory. The real app queues them in the Dexie outbox (lib/outbox.ts) and the server recomputes the trend.
  const addWeight = (kg: number) => setWeighIns(w => { const t = todayIso(), keep = w.filter(x => x.date !== t); return [...keep, { date: t, kg, trend: keep[keep.length - 1].trend, pending: true }] })
  const addMeasure = (site: string, v: number) => setMeasures(m => m.map(x => x.site === site ? { ...x, points: [...x.points, { date: todayIso(), v }] } : x))
  const tgt = todayTarget(settings, extra, eatBack).t
  const eatenKcal = day.eaten.kcal + logged.reduce((a, i) => a + i.kcal * i.n, 0)
  if (!onboarded) return <Onboarding done={p => { setProfile(p); setKj(p.kj); setOnboarded(true) }} />
  const T = ({ id, label }: { id: Tab; label: string }) => (
    <button onClick={() => { setSession(false); setTab(id) }} className={`min-h-11 min-w-11 ${tab === id && !session ? 'text-accent' : ''}`}>{label}</button>)
  if (adding) return (
    <div className="mx-auto max-w-md">
      <AddFood initialTab={adding} eatenKcal={eatenKcal} targetKcal={tgt.kcal}
        onLog={items => { setLogged(l => [...l, ...items]); setAdding(null); setTab('today') }}
        onClose={() => setAdding(null)} onCapture={() => { setAdding(null); setTab('log') }} />
    </div>)
  return (
    <Units.Provider value={{ kj, setKj }}>
    <div className="mx-auto min-h-dvh max-w-md">
      {session ? <LogSession settings={settings} current={extra} eatBack={eatBack} onAdd={setExtra} onClear={() => { setExtra(null); setEatBack(false) }} onEatBack={setEatBack} close={() => setSession(false)} /> : <>
        {tab === 'today' && <Today settings={settings} extra={extra} eatBack={eatBack} logged={logged} openSession={() => setSession(true)} />}
        {tab === 'log' && <Capture onLog={items => { setLogged(l => [...l, ...items]); setTab('today') }} />}
        {tab === 'plan' && <>
          <div className="px-4 pt-6"><h1 className="text-xl font-semibold">Plan</h1>
            <div className="mt-3 mb-3 flex gap-2">{(['targets', 'recipes'] as const).map(v => (
              <button key={v} onClick={() => setPlanView(v)} className={`min-h-11 flex-1 rounded-xl text-sm capitalize ${planView === v ? 'bg-accent font-semibold text-canvas' : 'bg-surface'}`}>{v === 'targets' ? 'Daily targets' : 'Recipes & shopping'}</button>))}</div></div>
          {planView === 'targets' ? <Targets settings={settings} set={setSettings} profile={profile} /> : <Shopping />}</>}
        {tab === 'progress' && <Progress weighIns={weighIns} addWeight={addWeight} measures={measures} addMeasure={addMeasure} settings={settings} setSettings={setSettings} />}
      </>}
      {!session && tab === 'today' && <div className="fixed inset-x-0 bottom-[4.5rem] z-10 mx-auto flex max-w-md items-center gap-2 px-3">
        <button className="flex min-h-12 flex-1 items-center gap-2 rounded-full bg-raised px-4 text-left text-sm text-muted shadow-lg" onClick={() => setAdding('search')}>
          <span aria-hidden>⌕</span><span className="flex-1">Search for a food</span></button>
        <button aria-label="Scan a barcode" className="size-12 rounded-full bg-raised shadow-lg" onClick={() => setAdding('scan')}>▥</button>
        <button aria-label="Describe a meal with AI" className="size-12 rounded-full bg-raised text-accent shadow-lg" onClick={() => setAdding('ai')}>✦</button></div>}
      <nav className="fixed inset-x-0 bottom-0 z-10 border-t border-white/5 bg-surface/95 backdrop-blur">
        <div className="mx-auto flex h-16 max-w-md items-center justify-around text-sm text-muted">
          <T id="today" label="Today" /><T id="log" label="Log" />
          <button aria-label="Add" onClick={() => { navigator.vibrate?.(10); setAdding('search') }}
            className="-mt-8 size-14 rounded-full bg-accent text-3xl font-bold text-canvas shadow-lg shadow-accent/30 transition active:scale-95">+</button>
          <T id="plan" label="Plan" /><T id="progress" label="Progress" />
        </div></nav>
    </div>
    </Units.Provider>)
}
