import { useEffect, useRef, useState } from 'react'
import { createScanner, type Scanner } from '@/lib/scanner'

/**
 * Live camera view that reports each valid barcode once (re-reports the same code only after a few seconds).
 * Needs HTTPS (or localhost) for the camera. Torch where the device offers it; haptic tap on a read.
 */
export function BarcodeScanner({ onCode, paused }: { onCode: (code: string) => void; paused: boolean }) {
  const video = useRef<HTMLVideoElement>(null)
  const track = useRef<MediaStreamTrack | null>(null)
  const pausedRef = useRef(paused)
  const onCodeRef = useRef(onCode)
  const [engine, setEngine] = useState<Scanner['engine'] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [torch, setTorch] = useState<{ available: boolean; on: boolean }>({ available: false, on: false })
  useEffect(() => { pausedRef.current = paused }, [paused])
  useEffect(() => { onCodeRef.current = onCode }, [onCode])

  useEffect(() => {
    let stopped = false
    let stream: MediaStream | null = null
    let timer: ReturnType<typeof setTimeout> | undefined
    const seen = new Map<string, number>()

    async function start() {
      if (!navigator.mediaDevices?.getUserMedia) { setError(window.isSecureContext ? 'This browser cannot use the camera.' : 'The camera needs a secure (HTTPS) connection.'); return }
      try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false })
      } catch (e) {
        const name = e instanceof Error ? e.name : ''
        setError(name === 'NotAllowedError' ? 'Camera permission was denied. Allow it in your browser settings, or type the barcode below.'
          : name === 'NotFoundError' ? 'No camera found. Type the barcode below.' : 'Could not start the camera. Type the barcode below.')
        return
      }
      if (stopped) { stream.getTracks().forEach((t) => t.stop()); return }
      const t = stream.getVideoTracks()[0] ?? null
      track.current = t
      const caps = (t?.getCapabilities?.() ?? {}) as { torch?: boolean }
      setTorch({ available: Boolean(caps.torch), on: false })
      if (video.current) { video.current.srcObject = stream; await video.current.play().catch(() => undefined) }

      let scanner: Scanner
      try { scanner = await createScanner() } catch { setError('The barcode reader failed to load. Type the barcode below.'); return }
      if (stopped) return
      setEngine(scanner.engine)

      const tick = async () => {
        if (stopped) return
        const v = video.current
        if (v && v.readyState >= 2 && !pausedRef.current) {
          try {
            const code = await scanner.detect(v)
            const now = Date.now()
            if (code && now - (seen.get(code) ?? 0) > 3500) {
              seen.set(code, now)
              navigator.vibrate?.(30)
              onCodeRef.current(code)
            }
          } catch { /* a failed frame is fine; try the next */ }
        }
        timer = setTimeout(tick, scanner.engine === 'wasm' ? 180 : 100)
      }
      void tick()
    }
    void start()
    return () => { stopped = true; clearTimeout(timer); stream?.getTracks().forEach((t) => t.stop()); track.current = null }
  }, [])

  async function toggleTorch() {
    const next = !torch.on
    try { await track.current?.applyConstraints({ advanced: [{ torch: next } as MediaTrackConstraintSet] }); setTorch({ available: true, on: next }) } catch { /* torch not honoured */ }
  }

  if (error) return <div role="alert" className="rounded-2xl bg-surface p-4 text-sm text-muted">{error}</div>
  return (
    <div className="relative overflow-hidden rounded-2xl bg-black" style={{ aspectRatio: '4 / 3' }}>
      <video ref={video} playsInline muted autoPlay className="size-full object-cover" aria-label="Camera view for scanning a barcode" />
      <div className="pointer-events-none absolute inset-x-8 top-1/2 h-24 -translate-y-1/2 rounded-xl border-2 border-accent/80" aria-hidden />
      <div className="absolute inset-x-0 bottom-2 text-center text-xs text-white/80">{engine ? 'Point at a barcode' : 'Starting camera…'}</div>
      {torch.available ? <button onClick={() => void toggleTorch()} aria-pressed={torch.on} aria-label="Torch" className={`absolute right-2 top-2 size-11 rounded-full ${torch.on ? 'bg-accent text-on-accent' : 'bg-black/60 text-white'}`}>⚡</button> : null}
    </div>
  )
}
