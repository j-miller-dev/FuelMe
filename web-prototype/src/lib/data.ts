import { series } from '../mock'

export type WeighIn = { date: string; kg: number; trend: number; pending?: boolean }   // date = YYYY-MM-DD (local)
export type Measure = { site: string; unit: string; points: { date: string; v: number }[] }

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const daysAgo = (n: number) => { const d = new Date(); d.setDate(d.getDate() - n); return iso(d) }
export const todayIso = () => iso(new Date())
export const addDays = (isoDate: string, n: number) => { const d = new Date(isoDate + 'T00:00:00'); d.setDate(d.getDate() + n); return iso(d) }
export const daysBetween = (a: string, b: string) => Math.round((new Date(b + 'T00:00:00').getTime() - new Date(a + 'T00:00:00').getTime()) / 86400000)
export const fmtDate = (isoDate: string) => new Date(isoDate + 'T00:00:00').toLocaleDateString('en-AU', { weekday: 'short', day: 'numeric', month: 'short' })

/** Mock history from the prototype series. The real trend comes from the Kalman filter on the server. */
export const initialWeighIns = (): WeighIn[] =>
  series.flatMap(s => s.scale === null ? [] : [{ date: daysAgo(55 - s.i), kg: s.scale, trend: s.trend }])

export const initialMeasures = (): Measure[] => [
  { site: 'Waist', unit: 'cm', points: [{ date: daysAgo(56), v: 82.5 }, { date: daysAgo(28), v: 82.8 }, { date: daysAgo(0), v: 83.4 }] },
  { site: 'Chest', unit: 'cm', points: [{ date: daysAgo(56), v: 100.5 }, { date: daysAgo(28), v: 101.0 }, { date: daysAgo(0), v: 101.8 }] },
  { site: 'Upper arm', unit: 'cm', points: [{ date: daysAgo(56), v: 35.0 }, { date: daysAgo(28), v: 35.3 }, { date: daysAgo(0), v: 35.8 }] },
  { site: 'Thigh', unit: 'cm', points: [{ date: daysAgo(56), v: 58.0 }, { date: daysAgo(28), v: 58.4 }, { date: daysAgo(0), v: 58.9 }] },
  { site: 'Neck', unit: 'cm', points: [{ date: daysAgo(56), v: 38.0 }, { date: daysAgo(0), v: 38.4 }] },
]

export const sd = (xs: number[]) => { const m = xs.reduce((a, b) => a + b, 0) / xs.length; return Math.sqrt(xs.reduce((a, b) => a + (b - m) ** 2, 0) / Math.max(xs.length - 1, 1)) }
export const mean = (xs: number[]) => xs.reduce((a, b) => a + b, 0) / xs.length
/** ISO-ish week start (Monday) for grouping weigh-ins. */
export const weekStart = (isoDate: string) => { const d = new Date(isoDate + 'T00:00:00'); d.setDate(d.getDate() - ((d.getDay() + 6) % 7)); return iso(d) }
