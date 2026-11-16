import { AMINOS } from './aminos'

/**
 * Muscle-focused amino acid tracking.
 * RULE OF THUMB (still debated, not a measurement): about 2.5 g leucine in a meal is often cited as enough to switch on muscle protein
 * synthesis, and roughly 0.3-0.4 g/kg protein per meal is a common practical target. Daily totals matter more than any one meal.
 * Per-meal amounts below are SAMPLE data for the prototype.
 */
export const LEUCINE_MEAL_MG = 2500
export type MealAmino = { meal: string; leuMg: number; proteinG: number }
export const SAMPLE_MEALS: MealAmino[] = [
  { meal: 'Breakfast', leuMg: 2600, proteinG: 38 }, { meal: 'Snack', leuMg: 1200, proteinG: 14 }, { meal: 'Lunch', leuMg: 3200, proteinG: 40 }]
export const mealsHitting = (m: MealAmino[], thr = LEUCINE_MEAL_MG) => m.filter(x => x.leuMg >= thr).length
/** Branched-chain total (leucine + isoleucine + valine) in mg from the sample day. */
export const bcaaMg = () => ['leu', 'ile', 'val'].reduce((a, k) => a + (AMINOS.find(x => x.key === k)?.sample ?? 0), 0)
/** Sum of the indispensable amino acids available in the sample (aaa counted once; tyrosine alone excluded to avoid double counting). */
export const eaaMg = () => AMINOS.filter(a => a.key !== 'tyr').reduce((s, a) => s + a.sample, 0)
/** Per-meal protein target from body weight (g/kg per meal). */
export const perMealProteinG = (kg: number, gPerKg = 0.4) => Math.round(kg * gPerKg)
