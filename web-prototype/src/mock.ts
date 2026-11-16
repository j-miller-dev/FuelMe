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
