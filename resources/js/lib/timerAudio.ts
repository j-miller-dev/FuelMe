/**
 * Browser side of the timer: spoken calls, beeps, vibration and keeping the screen on. All of it is best effort and fails soft.
 * Voice is the phone's own text-to-speech (free, works offline), not a cloud AI voice. Must be started from a tap (browsers refuse sound before one).
 */
export interface AudioPrefs { voice: boolean; beeps: boolean; vibrate: boolean }
export const DEFAULT_PREFS: AudioPrefs = { voice: true, beeps: true, vibrate: true }

let ctx: AudioContext | null = null
let wake: WakeLockSentinel | null = null

export function unlockAudio(): void {
  try {
    const AC = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
    if (!AC) return
    ctx ??= new AC()
    void ctx.resume()
    // some browsers also need speech "primed" inside the tap
    if ('speechSynthesis' in window) window.speechSynthesis.getVoices()
  } catch { /* no audio: the screen still works */ }
}

export function beep(long = false): void {
  try {
    if (!ctx) return
    const o = ctx.createOscillator(), g = ctx.createGain()
    o.type = 'sine'; o.frequency.value = long ? 1320 : 880
    const t = ctx.currentTime, dur = long ? 0.6 : 0.12
    g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.35, t + 0.01); g.gain.exponentialRampToValueAtTime(0.0001, t + dur)
    o.connect(g).connect(ctx.destination); o.start(t); o.stop(t + dur + 0.02)
  } catch { /* ignore */ }
}

export function speak(text: string): void {
  try {
    if (!('speechSynthesis' in window)) return
    const synth = window.speechSynthesis
    synth.cancel()   // a new call replaces an old one that is still talking
    const u = new SpeechSynthesisUtterance(text)
    const voices = synth.getVoices()
    const v = voices.find((x) => x.lang === 'en-AU') ?? voices.find((x) => x.lang.startsWith('en'))
    if (v) { u.voice = v; u.lang = v.lang } else u.lang = 'en-AU'
    u.rate = 1.05
    synth.speak(u)
  } catch { /* ignore */ }
}

export function stopSpeaking(): void { try { window.speechSynthesis?.cancel() } catch { /* ignore */ } }

export function buzz(pattern: number | number[]): void { try { navigator.vibrate?.(pattern) } catch { /* ignore */ } }

export async function keepAwake(): Promise<void> {
  try { if ('wakeLock' in navigator && !wake) wake = await navigator.wakeLock.request('screen') } catch { wake = null }
}
export function letSleep(): void { try { void wake?.release() } catch { /* ignore */ } wake = null }
/** The browser drops the lock when the tab is hidden; ask again when it is back. */
export function reacquire(): void { if (document.visibilityState === 'visible') { wake = null; void keepAwake() } }
