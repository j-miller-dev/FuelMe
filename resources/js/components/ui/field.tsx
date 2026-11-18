import * as React from 'react'
import { cn } from '@/lib/utils'

export const inputClass =
  'mt-1 min-h-12 w-full rounded-xl bg-surface px-3 text-ink placeholder:text-muted/70 ring-1 ring-transparent focus:ring-accent'

type FieldProps = React.InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string; hint?: string }

export function Field({ label, error, hint, className, id, ...props }: FieldProps) {
  const autoId = React.useId()
  const fieldId = id ?? autoId
  return (
    <div className="block">
      <label htmlFor={fieldId} className="block text-sm text-muted">{label}</label>
      <input id={fieldId} aria-invalid={!!error} aria-describedby={error ? `${fieldId}-err` : undefined} className={cn(inputClass, className)} {...props} />
      {hint && !error ? <p className="mt-1 text-xs text-muted">{hint}</p> : null}
      {error ? <p id={`${fieldId}-err`} role="alert" className="mt-1 text-sm text-danger">{error}</p> : null}
    </div>
  )
}

/** Single-choice list of large tap targets (44px+). */
export function Choice<T extends string>({ options, value, onChange }: {
  options: ReadonlyArray<readonly [T, string, string?]>
  value: T
  onChange: (v: T) => void
}) {
  return (
    <div role="radiogroup" className="space-y-2">
      {options.map(([k, label, note]) => (
        <button
          key={k}
          type="button"
          role="radio"
          aria-checked={value === k}
          onClick={() => onChange(k)}
          className={cn('flex min-h-12 w-full items-center justify-between rounded-xl px-4 text-left', value === k ? 'bg-accent/15 ring-1 ring-accent' : 'bg-surface')}
        >
          <span>{label}</span>
          {note ? <span className="text-sm text-muted">{note}</span> : null}
        </button>
      ))}
    </div>
  )
}
