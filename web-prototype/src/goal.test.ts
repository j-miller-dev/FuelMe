// Parity test: the TS port must match the PHP GoalPlanner on the exported fixtures. Run: npx tsx src/goal.test.ts
import cases from './goal-cases.json'
import { assess, fromMuscle, type Experience } from './goal'
let bad = 0
const eq = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b)
cases.forEach((c, i) => {
  const [a, b, d, e, f] = c.in as [number, number, number, string, number]
  const got = c.kind === 'assess' ? assess(a, b, d, e as Experience, f) : fromMuscle(a, b, d, e as unknown as number, f as unknown as Experience)
  // muscle tuple order: [muscleKg, leanFraction, trendKg, days, experience]
  const g2 = c.kind === 'assess' ? got : fromMuscle(a, b, d, e as unknown as number, f as unknown as Experience)
  const ok = eq(g2, c.out) || JSON.stringify(g2) === JSON.stringify(c.out)
  if (!ok) { bad++; console.error('FAIL case', i, c.kind, '\n got', JSON.stringify(g2), '\n exp', JSON.stringify(c.out)) } else console.log('ok   case', i, c.kind)
})
process.exit(bad ? 1 : 0)
