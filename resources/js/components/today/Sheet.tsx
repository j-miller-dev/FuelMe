import { useEffect, useRef, type ReactNode } from 'react'

/** Bottom sheet: dimmed backdrop, Escape and backdrop-tap close, focus moves in. No dependency. */
export function Sheet({ label, onClose, children, tall, z = 40 }: { label: string; onClose: () => void; children: ReactNode; tall?: boolean; z?: number }) {
  const ref = useRef<HTMLDivElement>(null)
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    document.addEventListener('keydown', onKey)
    // Move focus into the dialog, but never steal it from an input that already autofocused.
    if (ref.current && !ref.current.contains(document.activeElement)) ref.current.focus()
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])
  return (
    <div className="fixed inset-0" style={{ zIndex: z }}>
      <div className="absolute inset-0 bg-black/60" onClick={onClose} aria-hidden />
      <div ref={ref} tabIndex={-1} role="dialog" aria-modal="true" aria-label={label}
        className={`absolute inset-x-0 bottom-0 mx-auto flex max-w-md flex-col rounded-t-3xl bg-canvas outline-none focus-visible:outline-none ${tall ? 'h-[92dvh]' : 'max-h-[88dvh]'}`}
        style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}>
        {children}
      </div>
    </div>
  )
}
