export interface Point { date: string; trend: number; tdee: number; tdeeSd: number; scale: number | null }
export interface CheckIn {
  status: 'hold' | 'wait' | 'ready'; holdReasons: string[]; proposedKcal: number; change: number; idealKcal: number; guard: string | null; calibrating: boolean; lines: string[]
}
export interface Nudge { flag: boolean; lowDays: number; loggedDays: number; avgPct: number; gapKcal: number; gapProtein: number; adds: { name: string; grams: number; unit: string; kcal: number; protein: number }[] }
export interface ProgressData {
  series: Point[]; today: string
  summary: { trendKg: number; tdee: number; tdeeSd: number; calibrating: boolean; ratePctPerWeek: number | null; rateKgPerWeek: number | null; goalPctPerWeek: number; phase: string; target: number }
  adherence: { loggedDays7: number; weighIns14: number }
  insights: { days: number; protein: { avg: number; target: number; pct: number } | null; slots: Record<string, number>; perMealG: number; fibre: { avg: number; guide: number; coverage: number } | null; notes: string[] }
  checkin: CheckIn | null; undereating: Nudge; weights: { id: number; date: string; kg: number }[]
}

export const RANGES = [{ k: 28, label: '4 weeks' }, { k: 84, label: '12 weeks' }, { k: 0, label: 'All' }] as const

export const shortDate = (ymd: string) => new Date(`${ymd}T12:00:00Z`).toLocaleDateString('en-AU', { day: 'numeric', month: 'short', timeZone: 'UTC' })
export const longDate = (ymd: string) => new Date(`${ymd}T12:00:00Z`).toLocaleDateString('en-AU', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' })

/** Round to a tidy axis range: min/max of the data with a little padding, never a zero-height axis. */
export function extent(values: number[], pad = 0.4): [number, number] {
  const lo = Math.min(...values), hi = Math.max(...values)
  const span = Math.max(hi - lo, 1)
  return [lo - span * pad / 2, hi + span * pad / 2]
}
