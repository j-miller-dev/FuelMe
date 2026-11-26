/** Which Rational Intensity phase (1-3) a Hevy folder name is, e.g. "Rational Intensity P2: Overload". Anything else = null. */
export function phaseNumber(title: string | null | undefined): 1 | 2 | 3 | null {
  const t = title ?? ''
  const m = /\bP([123])\b/i.exec(t)
  if (m) return Number(m[1]) as 1 | 2 | 3
  if (/tension/i.test(t)) return 1
  if (/overload/i.test(t)) return 2
  if (/heavy\s*duty/i.test(t)) return 3
  return null
}
