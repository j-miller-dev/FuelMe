import { Head, Link } from '@inertiajs/react'
import { Minus, Pause, Play, Plus, Square, Vibrate, Volume2, VolumeX } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Button } from '@/components/ui/button'
import AppLayout from '@/layouts/AppLayout'
import { TIMER_BANNERS } from '@/lib/modeBanners'
import { fmtClock, LIMITS, PRESETS, sanitise, totalSeconds, type Kind, type Protocol } from '@/lib/intervalTimer'
import { DEFAULT_PREFS, type AudioPrefs } from '@/lib/timerAudio'
import { useIntervalTimer } from '@/lib/useIntervalTimer'
import { cn } from '@/lib/utils'

const KEY = 'fuel.timer.v1'

/** Light per-device preferences only (your tweaks to each protocol, sound options). Nothing here is training data. */
function load(): { protocols: Record<string, Protocol>; prefs: AudioPrefs } {
  try { const j = JSON.parse(localStorage.getItem(KEY) ?? '{}'); return { protocols: j.protocols ?? {}, prefs: { ...DEFAULT_PREFS, ...(j.prefs ?? {}) } } } catch { return { protocols: {}, prefs: DEFAULT_PREFS } }
}

const TONE: Record<Kind, { bg: string; text: string; word: string }> = {
  warmup: { bg: 'bg-carbs/15', text: 'text-carbs', word: 'Warm up' },
  work: { bg: 'bg-danger/20', text: 'text-danger', word: 'Go' },
  rest: { bg: 'bg-protein/15', text: 'text-protein', word: 'Rest' },
  cooldown: { bg: 'bg-accent/15', text: 'text-accent', word: 'Cool down' },
}

const mmss = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`

function Stepper({ label, value, step, min, max, fmt, onChange }: { label: string; value: number; step: number; min: number; max: number; fmt?: (n: number) => string; onChange: (n: number) => void }) {
  return (
    <div className="flex items-center justify-between py-1.5">
      <span className="text-sm">{label}</span>
      <span className="flex items-center gap-1">
        <button type="button" aria-label={`Less ${label}`} disabled={value <= min} onClick={() => onChange(Math.max(min, value - step))} className="grid size-11 place-items-center rounded-xl bg-raised disabled:opacity-30"><Minus aria-hidden size={18} /></button>
        <span className="w-16 text-center text-base font-semibold tabular-nums">{fmt ? fmt(value) : value}</span>
        <button type="button" aria-label={`More ${label}`} disabled={value >= max} onClick={() => onChange(Math.min(max, value + step))} className="grid size-11 place-items-center rounded-xl bg-raised disabled:opacity-30"><Plus aria-hidden size={18} /></button>
      </span>
    </div>
  )
}

export default function Timer() {
  const [init] = useState(load)
  const [prefs, setPrefs] = useState<AudioPrefs>(init.prefs)
  const [custom, setCustom] = useState<Record<string, Protocol>>(init.protocols)
  const [pickedId, setPickedId] = useState<string | null>(null)
  const base = PRESETS.find((p) => p.id === pickedId) ?? PRESETS[0]!
  const protocol = useMemo(() => sanitise(custom[base.id] ?? base), [custom, base])
  const timer = useIntervalTimer(protocol, prefs)

  useEffect(() => { try { localStorage.setItem(KEY, JSON.stringify({ protocols: custom, prefs })) } catch { /* private mode: fine */ } }, [custom, prefs])
  const edit = (patch: Partial<Protocol>) => setCustom((c) => ({ ...c, [base.id]: sanitise({ ...protocol, ...patch }) }))
  const reset = () => setCustom((c) => { const n = { ...c }; delete n[base.id]; return n })

  if (timer.status !== 'idle') return <RunView timer={timer} protocol={protocol} />

  const total = totalSeconds(timer.segments)
  return (
    <AppLayout>
      <Head title="Interval timer" />
      <h1 className="text-2xl font-bold tracking-tight">Interval timer</h1>
      <p className="mt-1 text-sm text-muted">Pick a session, tweak the times, press start. It talks you through each block and keeps the screen on.</p>

      <ul className="mt-4 space-y-2" aria-label="Sessions">
        {PRESETS.map((p) => (
          <li key={p.id}>
            <button type="button" onClick={() => setPickedId(p.id)} aria-pressed={p.id === base.id}
              className={cn('block w-full overflow-hidden rounded-2xl text-left ring-2 transition-all active:scale-[0.99]', p.id === base.id ? 'ring-accent' : 'ring-transparent')}>
              <img src={TIMER_BANNERS[p.id]} alt="" className="block h-auto w-full" loading="lazy" />
              <span className="sr-only">{p.name}. {p.blurb}</span>
            </button>
          </li>
        ))}
      </ul>

      <section className="mt-4 rounded-2xl bg-surface p-4" aria-label={`${base.name} settings`}>
        <div className="flex items-baseline justify-between"><h2 className="font-semibold">{base.name}</h2><span className="text-sm text-muted">{mmss(total)} total</span></div>
        <p className="text-xs text-muted">{base.blurb}</p>
        <div className="mt-2 divide-y divide-raised/70">
          <Stepper label="Warm-up" value={protocol.warmupSec} step={30} min={LIMITS.warmupSec[0]} max={LIMITS.warmupSec[1]} fmt={mmss} onChange={(n) => edit({ warmupSec: n })} />
          <Stepper label={`${protocol.workLabel} (work)`} value={protocol.workSec} step={protocol.workSec >= 120 ? 30 : 5} min={LIMITS.workSec[0]} max={LIMITS.workSec[1]} fmt={mmss} onChange={(n) => edit({ workSec: n })} />
          <Stepper label={protocol.restLabel} value={protocol.restSec} step={protocol.restSec >= 120 ? 30 : 5} min={LIMITS.restSec[0]} max={LIMITS.restSec[1]} fmt={mmss} onChange={(n) => edit({ restSec: n })} />
          <Stepper label="Rounds" value={protocol.rounds} step={1} min={LIMITS.rounds[0]} max={LIMITS.rounds[1]} onChange={(n) => edit({ rounds: n })} />
          <Stepper label="Cool-down" value={protocol.cooldownSec} step={30} min={LIMITS.cooldownSec[0]} max={LIMITS.cooldownSec[1]} fmt={mmss} onChange={(n) => edit({ cooldownSec: n })} />
        </div>
        {custom[base.id] ? <button type="button" onClick={reset} className="mt-1 min-h-11 text-sm text-accent underline">Back to the standard times</button> : null}
      </section>

      <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Sound">
        <h2 className="text-sm font-semibold">Cues</h2>
        <div className="mt-2 grid grid-cols-3 gap-2">
          {([['voice', 'Voice', Volume2], ['beeps', 'Beeps', VolumeX], ['vibrate', 'Buzz', Vibrate]] as const).map(([k, label, Icon]) => (
            <button key={k} type="button" aria-pressed={prefs[k]} onClick={() => setPrefs((p) => ({ ...p, [k]: !p[k] }))}
              className={cn('flex min-h-14 flex-col items-center justify-center gap-1 rounded-xl text-xs font-medium', prefs[k] ? 'bg-accent/15 text-accent' : 'bg-raised text-muted')}>
              <Icon aria-hidden size={18} />{label}{prefs[k] ? '' : ' off'}
            </button>
          ))}
        </div>
        <p className="mt-2 text-xs text-muted">Voice is your phone's own speech, so it works offline. Keep the screen on during a session: a browser cannot promise sound with the phone locked.</p>
      </section>

      <Button className="mt-4 w-full" onClick={timer.start}><Play aria-hidden size={20} />Start {base.name}</Button>
      <Link href="/training" className="mt-3 block text-center text-sm text-muted underline">Back to training</Link>
    </AppLayout>
  )
}

function RunView({ timer, protocol }: { timer: ReturnType<typeof useIntervalTimer>; protocol: Protocol }) {
  const { pos, status } = timer
  const seg = pos.segment
  const done = status === 'done'
  const tone = seg ? TONE[seg.kind] : TONE.cooldown
  const total = totalSeconds(timer.segments) * 1000
  const pct = total ? Math.min(100, (timer.elapsed / total) * 100) : 0
  const next = seg ? timer.segments[pos.index + 1] : null

  return (
    <div className={cn('fixed inset-0 z-40 mx-auto flex max-w-md flex-col bg-canvas px-5 pt-[max(1.5rem,env(safe-area-inset-top))]')} style={{ paddingBottom: 'max(1.5rem, env(safe-area-inset-bottom))' }}>
      <Head title={protocol.name} />
      <div className={cn('absolute inset-0 -z-10 transition-colors duration-300', done ? '' : tone.bg)} aria-hidden />
      <header className="flex items-center justify-between text-sm text-muted">
        <span className="font-medium text-ink">{protocol.name}</span>
        <span className="tabular-nums" aria-label="Time left in the session">{done ? 'Finished' : `${fmtClock(pos.totalRemainingMs)} left`}</span>
      </header>
      <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-raised" role="progressbar" aria-valuenow={Math.round(pct)} aria-valuemin={0} aria-valuemax={100} aria-label="Session progress"><div className="h-full rounded-full bg-accent" style={{ width: `${pct}%` }} /></div>

      <main className="flex flex-1 flex-col items-center justify-center text-center" aria-live="polite">
        {done ? (
          <>
            <p className="text-5xl font-bold text-accent">Done</p>
            <p className="mt-3 text-lg">{mmss(totalSeconds(timer.segments))} across {protocol.rounds} round{protocol.rounds === 1 ? '' : 's'}.</p>
            <p className="mt-2 max-w-xs text-sm text-muted">Saving this session to your training log is the next thing to build. For now, add it with “Say it” on Today.</p>
          </>
        ) : (
          <>
            <p className={cn('text-xl font-semibold uppercase tracking-widest', tone.text)}>{seg?.kind === 'work' ? seg.label : tone.word}</p>
            {seg && seg.round > 0 && seg.kind !== 'cooldown' ? <p className="mt-1 text-sm text-muted">Round {seg.round} of {seg.of}</p> : null}
            <p className="mt-2 font-mono text-[6.5rem] font-bold leading-none tabular-nums sm:text-[7.5rem]">{fmtClock(pos.remainingMs)}</p>
            <p className="mt-6 h-6 text-sm text-muted">{next ? `Next: ${next.kind === 'work' ? `${next.label} ${next.round}` : next.label} · ${mmss(next.seconds)}` : seg?.kind === 'cooldown' ? '' : 'Last block'}</p>
            {status === 'paused' ? <p className="mt-2 text-sm font-semibold text-carbs">Paused</p> : null}
          </>
        )}
      </main>

      <div className="grid grid-cols-2 gap-3">
        {done ? (
          <Button className="col-span-2" onClick={timer.stop}>Back to timers</Button>
        ) : (
          <>
            <Button variant="secondary" onClick={status === 'running' ? timer.pause : timer.resume}>{status === 'running' ? <><Pause aria-hidden size={20} />Pause</> : <><Play aria-hidden size={20} />Resume</>}</Button>
            <Button variant="danger" onClick={() => { if (window.confirm('Stop this session?')) timer.stop() }}><Square aria-hidden size={18} />Stop</Button>
          </>
        )}
      </div>
    </div>
  )
}
