// MOCK foods for the prototype (rounded, illustrative values; the real app searches AFCD + Open Food Facts AU).
export type Food = { id: string; name: string; brand?: string; serving: string; grams: number; per100: { kcal: number; p: number; c: number; f: number }; fav?: boolean }
export type LogItem = { id: string; name: string; serving: string; kcal: number; p: number; c: number; f: number; slot: string; n: number }
export const SLOTS = ['Breakfast', 'Lunch', 'Dinner', 'Snack'] as const

export const foods: Food[] = [
  { id: 'wb', name: 'Weet-Bix', brand: 'Sanitarium', serving: '2 biscuits (30 g)', grams: 30, per100: { kcal: 355, p: 11.5, c: 67, f: 1.4 }, fav: true },
  { id: 'jy', name: 'Greek Style Natural Yoghurt', brand: 'Jalna', serving: '1 serve (200 g)', grams: 200, per100: { kcal: 98, p: 5.4, c: 4.6, f: 6.4 }, fav: true },
  { id: 'pt', name: 'Porterhouse Steak (raw)', brand: 'Aldi', serving: '1 steak (250 g)', grams: 250, per100: { kcal: 143, p: 21.5, c: 0, f: 6.2 }, fav: true },
  { id: 'rc', name: 'White rice, cooked', serving: '1 cup (158 g)', grams: 158, per100: { kcal: 130, p: 2.7, c: 28, f: 0.3 }, fav: true },
  { id: 'eg', name: 'Egg, whole', serving: '1 large (55 g)', grams: 55, per100: { kcal: 143, p: 12.6, c: 0.7, f: 9.5 }, fav: true },
  { id: 'ml', name: 'Full cream milk', serving: '1 cup (250 ml)', grams: 258, per100: { kcal: 66, p: 3.4, c: 4.8, f: 3.6 } },
  { id: 'bn', name: 'Banana', serving: '1 medium (118 g)', grams: 118, per100: { kcal: 89, p: 1.1, c: 22.8, f: 0.3 } },
  { id: 'ct', name: 'Chicken breast, raw', serving: '1 breast (200 g)', grams: 200, per100: { kcal: 120, p: 22.5, c: 0, f: 2.6 } },
  { id: 'ro', name: 'Rolled oats', serving: '1/2 cup (45 g)', grams: 45, per100: { kcal: 380, p: 13.5, c: 60, f: 7 } },
  { id: 'pb', name: 'Peanut butter', serving: '1 tbsp (20 g)', grams: 20, per100: { kcal: 600, p: 25, c: 20, f: 50 } },
  { id: 'cp', name: 'Capsicum, red', serving: '1 medium (150 g)', grams: 150, per100: { kcal: 31, p: 1, c: 6, f: 0.3 } },
  { id: 'bm', name: 'Beef mince, 5 star', serving: '100 g', grams: 100, per100: { kcal: 140, p: 21, c: 0, f: 6 } },
]
export const latestIds = ['wb', 'jy', 'ml', 'bn', 'eg', 'rc', 'ct']
export const perServe = (f: Food) => ({ kcal: Math.round(f.per100.kcal * f.grams / 100), p: Math.round(f.per100.p * f.grams / 100), c: Math.round(f.per100.c * f.grams / 100), f: Math.round(f.per100.f * f.grams / 100) })
/** Tiny client-side match with a few AU synonyms. The real search is the server's hybrid search (app/Domain/Search). */
const SYN: Record<string, string[]> = { capsicum: ['bell pepper'], mince: ['ground beef'], weetbix: ['weet-bix'], yoghurt: ['yogurt'] }
export const matchFoods = (q: string) => {
  const t = q.toLowerCase().trim(); if (!t) return []
  const terms = [t, ...Object.entries(SYN).flatMap(([k, v]) => t.includes(k) ? v.map(x => t.replace(k, x)) : [])]
  return foods.filter(f => terms.some(x => `${f.brand ?? ''} ${f.name}`.toLowerCase().includes(x)))
}
