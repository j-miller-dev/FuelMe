// Mock day. Real numbers will come from the Laravel API (engine in app/Domain/Nutrition).
export const day = {
  type: 'Hard day', session: 'BJJ 11:30 · 60 min',
  target: { kcal: 3050, p: 165, c: 440, f: 85 },
  eaten: { kcal: 1480, p: 92, c: 190, f: 38 },
  next: { label: 'Pre-session', when: '10:00', carbs: 74, protein: 30, note: '1 g/kg carbs, 90 min before' },
  meals: [
    { name: 'Breakfast', items: 'Weet-Bix ×3, Jalna Greek yoghurt 200 g, banana', kcal: 640 },
    { name: 'Snack', items: 'Up&Go, rice cakes ×3', kcal: 380 },
    { name: 'Lunch', items: 'Chicken breast 200 g, rice 1.5 cup', kcal: 460 },
  ],
}

// ---- Capture review (voice/text -> parsed items with confidence + swap candidates)
export type Candidate = { name: string; brand?: string; kcal: number; p: number; c: number; f: number }
export type CaptureItem = { id: string; said: string; grams: number; conf: number; picked: number; candidates: Candidate[] }
export const capture: { transcript: string; items: CaptureItem[] } = {
  transcript: 'Two weetbix with milk, a Jalna greek yoghurt, and a 300g aldi porterhouse',
  items: [
    { id: 'a', said: '2 weetbix', grams: 30, conf: 0.96, picked: 0, candidates: [
      { name: 'Weet-Bix', brand: 'Sanitarium', kcal: 355, p: 11.5, c: 67, f: 1.4 },
      { name: 'Weet-Bix Protein', brand: 'Sanitarium', kcal: 360, p: 22, c: 54, f: 2.5 }] },
    { id: 'b', said: 'milk', grams: 200, conf: 0.58, picked: 0, candidates: [
      { name: 'Full cream milk', kcal: 66, p: 3.4, c: 4.8, f: 3.6 },
      { name: 'Lite white milk', kcal: 46, p: 3.5, c: 5, f: 1.2 },
      { name: 'Oat milk', brand: 'Oatly', kcal: 48, p: 1, c: 6.7, f: 1.5 }] },
    { id: 'c', said: 'Jalna greek yoghurt', grams: 200, conf: 0.91, picked: 0, candidates: [
      { name: 'Greek Style Natural Yoghurt', brand: 'Jalna', kcal: 98, p: 5.4, c: 4.6, f: 6.4 },
      { name: 'Greek Style Natural Yoghurt', brand: 'Chobani', kcal: 61, p: 9, c: 3.8, f: 0.9 }] },
    { id: 'd', said: '300g aldi porterhouse', grams: 300, conf: 0.74, picked: 0, candidates: [
      { name: 'Porterhouse Steak (raw)', brand: 'Aldi', kcal: 143, p: 21.5, c: 0, f: 6.2 },
      { name: 'Beef sirloin, raw, trimmed', kcal: 124, p: 22, c: 0, f: 3.9 }] },
  ],
}

// ---- Progress: 56 days of scale (noisy), trend, tdee with uncertainty
function lcg(seed: number) { let s = seed; return () => (s = (s * 1664525 + 1013904223) % 4294967296) / 4294967296 }
const rnd = lcg(7)
export const series = Array.from({ length: 56 }, (_, i) => {
  const trend = 73.4 + i * 0.035
  const scale = trend + (rnd() - 0.5) * 1.2
  const sd = Math.max(60, 350 * Math.exp(-i / 12))
  return { i, scale: i % 9 === 4 ? null : +scale.toFixed(1), trend: +trend.toFixed(2), tdee: 2820 + i * 3 + Math.sin(i / 5) * 25, sd }
})

// ---- Recipes -> shopping list
export type Ing = { name: string; qty: number | null; unit: 'g' | 'ml' | 'count'; aisle: string }
export type Recipe = { id: string; name: string; yield: number; kcal: number; p: number; ings: Ing[] }
export const recipes: Recipe[] = [
  { id: 'r1', name: 'Chicken & rice bowls', yield: 4, kcal: 640, p: 52, ings: [
    { name: 'Chicken breast', qty: 800, unit: 'g', aisle: 'Meat & seafood' }, { name: 'Basmati rice', qty: 300, unit: 'g', aisle: 'Pantry' },
    { name: 'Broccoli', qty: 400, unit: 'g', aisle: 'Produce' }, { name: 'Soy sauce', qty: null, unit: 'ml', aisle: 'Pantry' }] },
  { id: 'r2', name: 'Beef mince bolognese', yield: 6, kcal: 710, p: 48, ings: [
    { name: 'Beef mince', qty: 900, unit: 'g', aisle: 'Meat & seafood' }, { name: 'Pasta', qty: 600, unit: 'g', aisle: 'Pantry' },
    { name: 'Onion', qty: 2, unit: 'count', aisle: 'Produce' }, { name: 'Passata', qty: 700, unit: 'ml', aisle: 'Pantry' }] },
  { id: 'r3', name: 'Overnight oats', yield: 2, kcal: 520, p: 34, ings: [
    { name: 'Rolled oats', qty: 120, unit: 'g', aisle: 'Pantry' }, { name: 'Greek yoghurt', qty: 300, unit: 'g', aisle: 'Dairy & eggs' },
    { name: 'Milk', qty: 300, unit: 'ml', aisle: 'Dairy & eggs' }, { name: 'Banana', qty: 2, unit: 'count', aisle: 'Produce' }] },
  { id: 'r4', name: 'Egg fried rice', yield: 4, kcal: 590, p: 28, ings: [
    { name: 'Eggs', qty: 8, unit: 'count', aisle: 'Dairy & eggs' }, { name: 'Basmati rice', qty: 400, unit: 'g', aisle: 'Pantry' },
    { name: 'Onion', qty: 1, unit: 'count', aisle: 'Produce' }, { name: 'Frozen peas', qty: 200, unit: 'g', aisle: 'Frozen' }] },
]
export const aisleOrder = ['Produce', 'Meat & seafood', 'Dairy & eggs', 'Bakery', 'Pantry', 'Frozen', 'Drinks', 'Other']
