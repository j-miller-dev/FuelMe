export const KJ_PER_KCAL = 4.184

export type EnergyUnit = 'kcal' | 'kj'

/** Display only: all engine maths stays in kcal. */
export function fmtEnergy(kcal: number, unit: EnergyUnit): string {
  return Math.round(unit === 'kj' ? kcal * KJ_PER_KCAL : kcal).toLocaleString('en-AU')
}

export const energyLabel = (unit: EnergyUnit) => (unit === 'kj' ? 'kJ' : 'kcal')
