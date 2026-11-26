import { Link } from '@inertiajs/react'
import programLogo from '@/assets/modes/ri-logo.webp'
import { phaseOf } from '@/lib/phaseArt'

/** The Rational Intensity title banner. Shown only when at least one of the folders is a phase, so it never labels some other program. */
export function ProgramBanner({ folders }: { folders: (string | null)[] }) {
  if (!folders.some((f) => phaseOf(f))) return null
  return <img src={programLogo} alt="Rational Intensity" className="mx-auto mt-3 block w-full max-w-xs" />
}

/** The coloured phase logo (Phase 1 Tension red, 2 Overload blue, 3 Heavy Duty yellow) for a folder. Nothing for folders that are not a phase. */
export function PhaseBanner({ folder }: { folder: string | null }) {
  const p = phaseOf(folder)
  if (!p) return null
  return <img src={p.logo} alt={`Phase ${p.n}: ${p.name}`} className="mt-1 h-14 w-auto" loading="lazy" />
}

interface F { key: string; title: string; routines: number }

/** Folder picker: the phase posters are the buttons (selected one ringed, others slightly dimmed); other folders stay as chips. */
export function FolderPicker({ folders, selected }: { folders: F[]; selected: string | null }) {
  const href = (k: string) => `/lifting/progression?folder=${encodeURIComponent(k)}`
  const phases = folders.filter((f) => phaseOf(f.title))
  const others = folders.filter((f) => !phaseOf(f.title))
  return (
    <div className="mt-3" role="group" aria-label="Folder">
      {phases.length ? (
        <div className="grid grid-cols-3 gap-2">
          {phases.map((f) => {
            const p = phaseOf(f.title)!
            const on = f.key === selected
            return (
              <Link key={f.key} href={href(f.key)} preserveScroll aria-current={on} className={`block overflow-hidden rounded-2xl bg-surface ${on ? 'ring-2 ring-accent' : 'opacity-70'}`}>
                <img src={p.art} alt="" className="aspect-[9/16] w-full object-cover" loading="lazy" />
                <span className="block px-2 py-1.5 text-center text-xs font-medium">P{p.n} {p.name}<span className="block text-[11px] font-normal text-muted">{f.routines} {f.routines === 1 ? 'routine' : 'routines'}</span></span>
              </Link>
            )
          })}
        </div>
      ) : null}
      {others.length ? (
        <div className="-mx-1 mt-2 flex gap-2 overflow-x-auto px-1 pb-1">
          {others.map((f) => (
            <Link key={f.key} href={href(f.key)} preserveScroll aria-current={f.key === selected} className={`flex min-h-11 shrink-0 items-center rounded-full px-4 text-sm ${f.key === selected ? 'bg-accent text-on-accent' : 'bg-raised'}`}>{f.title}</Link>
          ))}
        </div>
      ) : null}
    </div>
  )
}
