import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { beep, buzz, keepAwake, letSleep, reacquire, speak, stopSpeaking, unlockAudio, type AudioPrefs } from '@/lib/timerAudio'
import { buildSegments, cuesBetween, position, type Protocol } from '@/lib/intervalTimer'

export type Status = 'idle' | 'running' | 'paused' | 'done'

/** Runs a protocol off real timestamps. Pausing stops the clock; a throttled tab catches up on the next tick instead of drifting. */
export function useIntervalTimer(protocol: Protocol, prefs: AudioPrefs) {
  const segments = useMemo(() => buildSegments(protocol), [protocol])
  const [status, setStatus] = useState<Status>('idle')
  const [elapsed, setElapsed] = useState(0)
  const acc = useRef(0)            // running time banked before the current run
  const startedAt = useRef(0)      // performance.now() when the current run began
  const last = useRef(-1)          // last elapsed handed to the cue engine
  const statusRef = useRef<Status>('idle')
  const prefsRef = useRef(prefs); prefsRef.current = prefs
  const segRef = useRef(segments); segRef.current = segments
  const set = (s: Status) => { statusRef.current = s; setStatus(s) }

  const tick = useCallback(() => {
    if (statusRef.current !== 'running') return
    const e = acc.current + (performance.now() - startedAt.current)
    const cues = cuesBetween(segRef.current, last.current, e)
    last.current = e
    const p = prefsRef.current
    // after a long sleep only the newest spoken call matters; old beeps are skipped
    const spoken = cues.filter((c) => c.kind === 'speak')
    if (p.voice && spoken.length) speak(spoken[spoken.length - 1]!.text ?? '')
    if (p.beeps) for (const c of cues) if ((c.kind === 'beep' || c.kind === 'long-beep') && e - c.atMs < 1500) beep(c.kind === 'long-beep')
    if (p.vibrate && cues.some((c) => c.kind === 'speak')) buzz(150)
    setElapsed(e)
    if (position(segRef.current, e).done) { set('done'); letSleep() }
  }, [])

  useEffect(() => {
    if (status !== 'running') return
    const id = window.setInterval(tick, 100)
    const vis = () => { reacquire(); tick() }
    document.addEventListener('visibilitychange', vis)
    return () => { window.clearInterval(id); document.removeEventListener('visibilitychange', vis) }
  }, [status, tick])

  useEffect(() => () => { letSleep(); stopSpeaking() }, [])

  const start = useCallback(() => {
    unlockAudio(); void keepAwake()
    acc.current = 0; last.current = -1; startedAt.current = performance.now()
    setElapsed(0); set('running'); tick()
  }, [tick])
  const pause = useCallback(() => { if (statusRef.current !== 'running') return; acc.current += performance.now() - startedAt.current; stopSpeaking(); set('paused') }, [])
  const resume = useCallback(() => { if (statusRef.current !== 'paused') return; unlockAudio(); void keepAwake(); startedAt.current = performance.now(); set('running') }, [])
  const stop = useCallback(() => { stopSpeaking(); letSleep(); acc.current = 0; last.current = -1; setElapsed(0); set('idle') }, [])

  return { segments, status, elapsed, pos: position(segments, elapsed), start, pause, resume, stop }
}
