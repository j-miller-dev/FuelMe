export interface Catalogue { [type: string]: { label: string; minutes: number; intensities: Record<string, { met: number | null; src: string }> } }
export interface TemplateRow { id: number; day: string; start: string | null; type: string; intensity: string; minutes: number; customMet: number | null; name: string | null }
export interface Session {
  key: string; id: number | null; template_id: number | null; status: 'virtual' | 'planned' | 'done' | 'skipped'; type: string; intensity: string; minutes: number; start: string | null
  label: string; netKcal: number; low: number; high: number; met: number; source: string; name: string | null; override: number | null; date?: string
}
export interface ComposerItem { kind: 'meal' | 'food'; ref: number; name: string; label: string; amount: number; unit: string }
export interface ComposerOption { items: ComposerItem[]; carbs: number; protein: number; fat: number; fibre: number; kcal: number; after: { kcal: number; protein: number; carbs: number; fat: number } }
export interface Composer { options: ComposerOption[]; shortfall: string | null; shift: { carbsG: number; note: string } | null }
export interface Pre { skip?: boolean; carbsG?: number; proteinG?: number; fatMaxG?: number; fibreMaxG?: number; note: string }
export interface Fuel {
  next: { key: string; label: string; type: string; intensity: string; minutes: number; start: string | null; netKcal: number; minutesUntil: number | null; sharedMealNote: string | null; gapToNextHours: number | null }
  during: { carbsGPerHour: [number, number]; note: string }
  post: { proteinG: number; withinHours: number; carbsG?: number; carbsGPerHour?: [number, number]; forHours?: number; note: string }
  postMeal: Composer | null; leads: Record<string, { pre: Pre; composer: Composer | null }>; hasTags: boolean; later: number
}
export interface TrainingProps { sessions: Session[]; explain: string | null; fuel: Fuel | null; catalogue: Catalogue; kg: number }

export const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const
export const DAY_LABEL: Record<string, string> = { mon: 'Monday', tue: 'Tuesday', wed: 'Wednesday', thu: 'Thursday', fri: 'Friday', sat: 'Saturday', sun: 'Sunday' }
export const CLASS_LABEL: Record<string, string> = { rest: 'Rest', light: 'Light', moderate: 'Moderate', high: 'High', very_high: 'Very high' }
export const INTENSITY_LABEL: Record<string, string> = { light: 'Light', standard: 'Standard', hard: 'Hard', hit: 'HIT (few hard sets)', heavy: 'Heavy', moderate: 'Moderate', vigorous: 'Vigorous', custom: 'Custom' }

export const inMinutes = (m: number | null): string => {
  if (m === null) return ''
  if (m <= 0) return 'now'
  return m >= 60 ? `${Math.floor(m / 60)} h ${m % 60 ? `${m % 60} min` : ''}`.trim() : `${m} min`
}
