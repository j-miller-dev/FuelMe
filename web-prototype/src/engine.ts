import data from './engine-data.json'

export type DayPlan = { class: string; kcal: number; protein: number; carbs: number; fat: number; carbGPerKg: number; netKcal: number }
export type Peri = {
  sessionNetKcal: number
  pre: { carbsG: number; proteinG: number; note: string }
  during: { carbsGPerHour: [number, number]; note: string }
  post: { proteinG: number; carbsG?: number; note: string }
}
export type Goal = 'bulk' | 'maintain' | 'cut'
export type CarbMode = 'flexible' | 'cap'
export type Settings = { goal: Goal; mode: CarbMode; protein: 1.8 | 2.2 | 2.6 }
export type Session = { type: string; intensity: string; minutes: number }

export const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const
export type Dow = typeof DAYS[number]
export const todayDow = (): Dow => DAYS[(new Date().getDay() + 6) % 7]
export const DAY_NAMES: Record<Dow, string> = { mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri', sat: 'Sat', sun: 'Sun' }
export const CLASS_LABEL: Record<string, string> = { rest: 'Rest day', light: 'Light day', moderate: 'Moderate day', high: 'High day', very_high: 'Very high day' }

export const catalogue = data.catalogue as Record<string, { label: string; intensities: Record<string, { met: number; glycolytic: boolean }> }>
export const weight = data.meta.weightKg
export const plannedSessions = (d: Dow): Session[] => ((data.meta.week as Record<string, Session[]>)[d] ?? [])
export const getPlan = (s: Settings) => data.plans[`${s.goal}|${s.mode}|${s.protein}` as keyof typeof data.plans] as unknown as
  { days: Record<Dow, DayPlan>; weekly: Record<string, number>; warnings: { level: string; code: string; msg: string }[] }
export const sessionInfo = (x: Session) => (data.sessions as unknown as Record<string, { net: number; low: number; high: number; peri: Peri }>)[`${x.type}|${x.intensity}|${x.minutes}`]
export const adjustInfo = (d: Dow, x: Session) => (data.adjust as Record<string, { before: DayPlan; after: DayPlan; othersKcalDelta: number }>)[`${d}|${x.type}|${x.intensity}|${x.minutes}`]
export const sessionLabel = (x: Session) => `${catalogue[x.type]?.label ?? x.type} · ${x.intensity} · ${x.minutes} min`
