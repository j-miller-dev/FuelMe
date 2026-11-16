import { db, type SunLog } from './outbox'

/**
 * ROUGH estimate of vitamin D made by sun. Built from published rules of thumb, not a measurement:
 *  - UV index 1 = 0.025 W/m2 erythemal, so 1 UVI for 1 h = 90 J/m2 = 0.9 SED.
 *  - 1 MED is about 2 SED (fair skin). Whole body at 1 MED is about 20,000 IU (MJA position statement).
 *  - Check: 1/3 MED on 15% of the body gives about 1,000 IU (same source).
 * Skin tone, posture, shade, clothing gaps, season and low sun angle all move the real number, so a range is shown.
 */
export const SED_PER_UVI_HOUR = 0.9
export const IU_WHOLE_BODY_PER_MED = 20000
export const SED_PER_MED = 2
export const SKIN: Record<SunLog['skin'], { label: string; factor: number }> = {
  fair: { label: 'Fair', factor: 1 }, medium: { label: 'Olive / tan', factor: 0.6 }, dark: { label: 'Dark', factor: 0.3 } } // factors are assumptions
export const AREAS: [number, string][] = [[0.08, 'Face + hands'], [0.15, 'Face, hands, forearms'], [0.25, 'T-shirt + shorts'], [0.35, 'Shirtless + shorts']] // approximate body fractions
export const REAL_WORLD_MID = 0.7 // assumption: posture, shade and clothing gaps
export const REAL_WORLD_LOW = 0.35
/** Assumption: below UV index 3 the sun is low or the season is winter, where vitamin D yield per UV unit is weaker than the index implies. */
export const LOW_UV_DISCOUNT = 0.5
export const LOW_UV_BELOW = 3

export function estimate(minutes: number, uvi: number, area: number, skin: SunLog['skin']) {
  const sed = SED_PER_UVI_HOUR * uvi * minutes / 60
  const raw = IU_WHOLE_BODY_PER_MED * (sed / SED_PER_MED) * area * SKIN[skin].factor
  const d = uvi < LOW_UV_BELOW ? LOW_UV_DISCOUNT : 1
  return { sed, raw, low: Math.round(raw * REAL_WORLD_LOW * d), mid: Math.round(raw * REAL_WORLD_MID * d), high: Math.round(raw) }
}
/** Minutes of sun to reach a mid-estimate target (fair-skin-style rule). Infinity if UV index is 0. */
export const minutesFor = (targetIu: number, uvi: number, area: number, skin: SunLog['skin']) => {
  const perMin = estimate(1, uvi, area, skin).mid
  return perMin > 0 ? targetIu / perMin : Infinity
}
export const logSun = (s: Omit<SunLog, 'id'>) => db.sun.add(s)
export const sunOn = (date: string) => db.sun.where('date').equals(date).toArray()
export const deleteSun = (id: number) => db.sun.delete(id)
