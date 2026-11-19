import type { Per100 } from '@/lib/food'

export const MEAL_TAGS = ['breakfast', 'pre', 'post', 'prep', 'snack'] as const
export type MealTag = (typeof MEAL_TAGS)[number]
export const TAG_LABEL: Record<MealTag, string> = { breakfast: 'Breakfast', pre: 'Pre-workout', post: 'Post-workout', prep: 'Meal prep', snack: 'Snack' }

export interface MealItem { food_id: number | null; name: string; grams: number; unit: 'g' | 'mL'; per100: Per100 | null }
export interface MealCalc {
  raw_grams: number; cooked_grams: number; incomplete: number
  total: { kcal: number; protein: number; carbs: number; fat: number; fibre: number }
  portion: { grams: number; kcal: number; protein: number; carbs: number; fat: number; fibre: number }
  per100: { kcal: number; protein: number; carbs: number; fat: number }
}
export interface Batch { id: number; portions: number; made_on: string; remaining: number }
export interface Meal { id: number; name: string; yield_portions: number; cook_loss: number; tags: MealTag[]; items: MealItem[]; computed: MealCalc; batches: Batch[] }

/**
 * Live preview while editing (the server recomputes on save). Same rule as MealMath: macros are the sum of the raw ingredients,
 * cooking only removes weight, a portion is 1/yield of both.
 */
export function previewMeal(items: { per100: Per100 | null; grams: number }[], yieldPortions: number, cookLoss: number): MealCalc | null {
  if (!(yieldPortions > 0) || cookLoss < 0 || cookLoss >= 1) return null
  const t = { kcal: 0, protein: 0, carbs: 0, fat: 0, fibre: 0 }
  let raw = 0, incomplete = 0
  for (const it of items) {
    raw += it.grams
    if (!it.per100 || it.per100.kcal === null) { incomplete++; continue }
    const f = it.grams / 100
    t.kcal += it.per100.kcal * f; t.protein += (it.per100.protein ?? 0) * f; t.carbs += (it.per100.carbs ?? 0) * f; t.fat += (it.per100.fat ?? 0) * f; t.fibre += (it.per100.fibre ?? 0) * f
  }
  const cooked = raw * (1 - cookLoss)
  const d = (v: number) => v / yieldPortions
  return {
    raw_grams: raw, cooked_grams: cooked, incomplete, total: t,
    portion: { grams: d(cooked), kcal: d(t.kcal), protein: d(t.protein), carbs: d(t.carbs), fat: d(t.fat), fibre: d(t.fibre) },
    per100: cooked > 0 ? { kcal: t.kcal / cooked * 100, protein: t.protein / cooked * 100, carbs: t.carbs / cooked * 100, fat: t.fat / cooked * 100 } : { kcal: 0, protein: 0, carbs: 0, fat: 0 },
  }
}

export const fmtPortions = (n: number) => (Math.round(n * 100) / 100).toString()
