/**
 * Micronutrient targets for an adult male 19-50.
 * src 'NHMRC' = confirmed against Australian NRV (RDI). src 'US' = US RDA/AI used as a stand-in until the NHMRC value is verified.
 * Do NOT treat 'US' rows as Australian. limit = keep UNDER (not a goal to reach).
 */
export type Nutrient = { key: string; name: string; unit: string; target: number; src: 'NHMRC' | 'US'; limit?: boolean; group: 'Vitamins' | 'Minerals' }
// Magnesium RDI is 400 mg for men 19-30 and 420 mg for 31+ (NHMRC); 420 is used here.
export const NUTRIENTS: Nutrient[] = [
  { key: 'iron', name: 'Iron', unit: 'mg', target: 8, src: 'NHMRC', group: 'Minerals' },
  { key: 'zinc', name: 'Zinc', unit: 'mg', target: 14, src: 'NHMRC', group: 'Minerals' },
  { key: 'mg', name: 'Magnesium', unit: 'mg', target: 420, src: 'NHMRC', group: 'Minerals' },
  { key: 'k', name: 'Potassium', unit: 'mg', target: 3400, src: 'US', group: 'Minerals' },
  { key: 'ca', name: 'Calcium', unit: 'mg', target: 1000, src: 'US', group: 'Minerals' },
  { key: 'se', name: 'Selenium', unit: 'µg', target: 55, src: 'US', group: 'Minerals' },
  { key: 'na', name: 'Sodium', unit: 'mg', target: 2300, src: 'US', limit: true, group: 'Minerals' },
  { key: 'b12', name: 'Vitamin B12', unit: 'µg', target: 2.4, src: 'US', group: 'Vitamins' },
  { key: 'folate', name: 'Folate', unit: 'µg', target: 400, src: 'US', group: 'Vitamins' },
  { key: 'c', name: 'Vitamin C', unit: 'mg', target: 90, src: 'US', group: 'Vitamins' },
  { key: 'e', name: 'Vitamin E', unit: 'mg', target: 15, src: 'US', group: 'Vitamins' },
  { key: 'b6', name: 'Vitamin B6', unit: 'mg', target: 1.3, src: 'US', group: 'Vitamins' },
  { key: 'k1', name: 'Vitamin K', unit: 'µg', target: 120, src: 'US', group: 'Vitamins' },
  { key: 'a', name: 'Vitamin A', unit: 'µg', target: 900, src: 'US', group: 'Vitamins' },
]
/** Fraction of target met (0..1+). For limits, the same ratio; the UI colours it differently. */
export const pct = (n: Nutrient, intake: number) => intake / n.target
/** Lowest-coverage first; limits sort after goals so an over-limit sodium doesn't hide a real gap. */
export const lowestFirst = (intake: Record<string, number>) =>
  NUTRIENTS.filter(n => !n.limit).sort((a, b) => pct(a, intake[a.key] ?? 0) - pct(b, intake[b.key] ?? 0))
export const status = (n: Nutrient, intake: number) => n.limit ? (intake > n.target ? 'over' : 'ok') : intake >= n.target ? 'met' : intake >= n.target * 0.67 ? 'near' : 'low'
/** SAMPLE intake for the prototype only. Not computed from foods. */
export const SAMPLE: Record<string, number> = { iron: 9.5, zinc: 13, mg: 330, k: 2900, ca: 780, se: 70, na: 2650, b12: 4.1, folate: 310, c: 62, e: 9, b6: 2.0, k1: 85, a: 640 }
