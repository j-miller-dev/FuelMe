export const SLOTS = ['breakfast', 'lunch', 'dinner', 'snack'] as const
export type Slot = (typeof SLOTS)[number]
export const SLOT_LABEL: Record<Slot, string> = { breakfast: 'Breakfast', lunch: 'Lunch', dinner: 'Dinner', snack: 'Snacks' }

/** Mirrors App\Domain\Foods\Frequent::slotForHour. */
export function slotForHour(h: number): Slot {
  return h >= 4 && h < 10 ? 'breakfast' : h >= 10 && h < 15 ? 'lunch' : h >= 15 && h < 21 ? 'dinner' : 'snack'
}

export interface Per100 { kcal: number | null; protein: number | null; carbs: number | null; fat: number | null; fibre: number | null; sugar?: number | null; sat_fat?: number | null; sodium_mg?: number | null }
export interface FoodHit {
  id: number; source: string; name: string; brand: string | null; barcode: string | null; stores: string[]; category: string | null; state: string | null
  is_liquid: boolean; needs_check: boolean; per100: Per100
  servings: { id?: number | null; label: string; grams: number; own?: boolean }[]
  portion: { grams: number | null; label: string }
  last_grams: number | null; is_favourite: boolean
}
export interface LogRow {
  id: number; local_date: string; meal_slot: Slot; food_id: number | null; name: string; grams: number | null; serving_label: string | null
  kcal: number; protein: number; carbs: number; fat: number; fibre: number | null; source: string
  meal_id?: number | null; portions?: number | null; is_rough?: boolean
}
export interface Macros { kcal: number; protein: number; carbs: number; fat: number }

/** Open Food Facts often repeats the brand inside the product name ('Weet-Bix Bites' by 'Weet-Bix'); do not show it twice. */
export const hitName = (h: FoodHit) => (h.brand && !h.name.toLowerCase().includes(h.brand.toLowerCase()) ? `${h.brand} ${h.name}` : h.name)
export const unitOf = (h: FoodHit) => (h.is_liquid ? 'mL' : 'g')

/** Preview only: the server recomputes every number from the food row when it saves. */
export function macrosFor(p: Per100, grams: number): Macros {
  const f = grams / 100
  const r = (v: number | null) => Math.round((v ?? 0) * f * 100) / 100
  return { kcal: r(p.kcal), protein: r(p.protein), carbs: r(p.carbs), fat: r(p.fat) }
}

/** Default portion, in order: the amount typed in the search box ("150g chicken"), what you last logged, the first serving, 100. */
export function defaultGrams(h: FoodHit): number {
  return h.portion.grams ?? h.last_grams ?? h.servings[0]?.grams ?? 100
}

export function totals(logs: { kcal: number; protein: number; carbs: number; fat: number }[]): Macros {
  return logs.reduce((a, l) => ({ kcal: a.kcal + l.kcal, protein: a.protein + l.protein, carbs: a.carbs + l.carbs, fat: a.fat + l.fat }), { kcal: 0, protein: 0, carbs: 0, fat: 0 })
}

/** Stepper size grows with the amount, so 5 g steps for a spoonful and 25 g steps for a steak. */
export function stepFor(grams: number): number {
  return grams < 30 ? 1 : grams < 100 ? 5 : grams < 300 ? 10 : 25
}
