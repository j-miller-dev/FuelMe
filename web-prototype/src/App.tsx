import { useState } from 'react'
import Today from './screens/Today'
import Capture from './screens/Capture'
import Progress from './screens/Progress'
import Shopping from './screens/Shopping'
import Onboarding from './screens/Onboarding'

type Tab = 'today' | 'log' | 'plan' | 'progress'
const actions: [string, Tab | null][] = [['Search', null], ['Speak', 'log'], ['Scan', null], ['Quick-add', null], ['Meals', 'plan']]

export default function App() {
  const [onboarded, setOnboarded] = useState(false)
  const [tab, setTab] = useState<Tab>('today')
  const [sheet, setSheet] = useState(false)
  if (!onboarded) return <Onboarding done={() => setOnboarded(true)} />
  const T = ({ id, label }: { id: Tab; label: string }) => (
    <button onClick={() => setTab(id)} className={`min-h-11 min-w-11 ${tab === id ? 'text-accent' : ''}`}>{label}</button>)
  return (
    <div className="mx-auto min-h-dvh max-w-md">
      {tab === 'today' && <Today />}{tab === 'log' && <Capture />}{tab === 'plan' && <Shopping />}{tab === 'progress' && <Progress />}
      {sheet && <div className="fixed inset-0 z-20 bg-black/60" onClick={() => setSheet(false)}>
        <div className="absolute inset-x-0 bottom-0 mx-auto grid max-w-md grid-cols-5 gap-2 rounded-t-3xl bg-raised p-5" onClick={e => e.stopPropagation()}>
          {actions.map(([a, to]) => <button key={a} className="min-h-14 rounded-xl bg-surface text-xs transition active:scale-95" onClick={() => { if (to) setTab(to); setSheet(false) }}>{a}</button>)}
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
