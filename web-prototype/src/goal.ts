// TypeScript port of app/Domain/Nutrition/GoalPlanner.php. Parity-tested against PHP output (goal.test.ts).
// In the real app this runs on the server; this copy exists only so the prototype can respond as you type.
export type Experience = 'novice' | 'intermediate' | 'advanced'
export const BULK_CAP: Record<Experience, number> = { novice: 0.5, intermediate: 0.35, advanced: 0.25 }
export const CUT_CAP = 1.0
export const MUSCLE_CEILING: Record<Experience, number> = { novice: 1.5, intermediate: 1.0, advanced: 0.5 }

export type Assessment = {
  direction: 'gain' | 'loss' | 'maintain'; deltaKg: number; ratePctPerWeek: number; capPctPerWeek: number
  status: 'on_track' | 'too_fast' | 'not_achievable'; planRatePctPerWeek: number; balanceKcalPerDay: number; kcalTarget: number
  surplusPctOfTdee: number; warnings: string[]; earliestDays: number | null; achievableKgByDate: number
}
const r = (x: number, d: number) => { const m = 10 ** d; return Math.sign(x) * Math.round(Math.abs(x) * m + 1e-9) / m } // PHP round(): half away from zero

export function assess(trendKg: number, targetKg: number, days: number, experience: Experience, tdee: number, kcalPerKg = 7700): Assessment {
  days = Math.max(days, 1)
  const delta = targetKg - trendKg
  const direction = delta > 0.05 ? 'gain' : delta < -0.05 ? 'loss' : 'maintain'
  const sign = direction === 'loss' ? -1 : 1, weeks = days / 7
  const rate = direction === 'maintain' ? 0 : delta / trendKg / weeks * 100
  const cap = direction === 'loss' ? CUT_CAP : BULK_CAP[experience], abs = Math.abs(rate)
  const status = direction === 'maintain' || abs <= cap + 1e-9 ? 'on_track' : abs <= 2 * cap ? 'too_fast' : 'not_achievable'
  const plan = sign * Math.min(abs, cap)
  const balance = plan / 100 * trendKg * kcalPerKg / 7, pct = balance / tdee * 100
  const warnings: string[] = []
  if (status !== 'on_track') warnings.push(status === 'too_fast'
    ? 'That date needs more than the safe weekly rate. Your plan uses the cap, so you will land short unless you move the date or the target.'
    : 'Not achievable safely by that date. Move the date out or lower the target.')
  if (direction === 'gain' && pct > 20) warnings.push('Surplus is over 20% of expenditure, which mostly adds fat.')
  return {
    direction, deltaKg: r(delta, 2), ratePctPerWeek: r(rate, 3), capPctPerWeek: cap, status, planRatePctPerWeek: r(plan, 3),
    balanceKcalPerDay: r(balance, 0), kcalTarget: r(tdee + balance, 0), surplusPctOfTdee: r(pct, 1), warnings,
    earliestDays: direction === 'maintain' ? null : Math.ceil(Math.abs(delta) / (cap / 100 * trendKg) * 7),
    achievableKgByDate: r(trendKg + sign * cap / 100 * trendKg * weeks, 2),
  }
}

export function fromMuscle(muscleKg: number, leanFraction: number, trendKg: number, days: number, experience: Experience) {
  leanFraction = Math.max(0.3, Math.min(0.8, leanFraction))
  const perMonth = muscleKg / (Math.max(days, 1) / 30.44), pct = perMonth / trendKg * 100, ceiling = MUSCLE_CEILING[experience]
  return { targetKg: r(trendKg + muscleKg / leanFraction, 2), muscleKgPerMonth: r(perMonth, 2), musclePctBwPerMonth: r(pct, 2),
    ceilingPctPerMonth: ceiling, muscleStatus: (pct <= ceiling ? 'within' : 'ambitious') as 'within' | 'ambitious' }
}
