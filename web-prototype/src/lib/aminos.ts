/**
 * Amino acids. Targets are mg per kg body weight per day for adults (FAO/WHO/UNU 2007 style requirement values).
 * src 'WHO' = value confirmed from a source this session (histidine 10, isoleucine 20, leucine 39 mg/kg).
 * src 'check' = value entered from general knowledge and still to be verified. Do not rely on these yet.
 * Tyrosine has NO requirement of its own: the body makes it from phenylalanine, so only intake is shown.
 * Intake below is SAMPLE data, not computed from logged foods. Real values need a food database with amino acid data
 * (coverage is patchy, especially for packaged foods).
 */
export type Amino = { key: string; name: string; mgPerKg?: number; src: 'WHO' | 'check'; sample: number; note?: string }
export const AMINOS: Amino[] = [
  { key: 'his', name: 'Histidine', mgPerKg: 10, src: 'WHO', sample: 2500 },
  { key: 'ile', name: 'Isoleucine', mgPerKg: 20, src: 'WHO', sample: 4200 },
  { key: 'leu', name: 'Leucine', mgPerKg: 39, src: 'WHO', sample: 7000 },
  { key: 'lys', name: 'Lysine', mgPerKg: 30, src: 'check', sample: 6800 },
  { key: 'saa', name: 'Methionine + cysteine', mgPerKg: 15, src: 'check', sample: 3300 },
  { key: 'aaa', name: 'Phenylalanine + tyrosine', mgPerKg: 25, src: 'check', sample: 7600 },
  { key: 'thr', name: 'Threonine', mgPerKg: 15, src: 'check', sample: 3700 },
  { key: 'trp', name: 'Tryptophan', mgPerKg: 4, src: 'check', sample: 1100, note: 'Your body uses tryptophan to make serotonin. Eating more protein does not simply raise brain tryptophan, because amino acids compete to cross into the brain.' },
  { key: 'val', name: 'Valine', mgPerKg: 26, src: 'check', sample: 4600 },
  { key: 'tyr', name: 'Tyrosine', src: 'check', sample: 3300, note: 'Your body uses tyrosine to make dopamine and noradrenaline. Reviews of healthy adults suggest tyrosine mainly helps thinking under stress or heavy mental load, with mixed results otherwise. Normal food intake is rarely the limit.' },
]
export const aminoTargetMg = (a: Amino, kg: number) => a.mgPerKg == null ? undefined : Math.round(a.mgPerKg * kg)
