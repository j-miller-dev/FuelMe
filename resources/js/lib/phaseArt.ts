import tension from '@/assets/modes/phase-tension.webp'
import overload from '@/assets/modes/phase-overload.webp'
import heavyduty from '@/assets/modes/phase-heavyduty.webp'
import logo1 from '@/assets/modes/ri-phase-1.webp'
import logo2 from '@/assets/modes/ri-phase-2.webp'
import logo3 from '@/assets/modes/ri-phase-3.webp'
import { phaseNumber } from '@/lib/phaseNumber'

export interface Phase { n: 1 | 2 | 3; name: string; line: string; art: string; logo: string }

const PHASES: Record<1 | 2 | 3, Phase> = {
  1: { n: 1, name: 'Tension', line: 'The mind-muscle connection', art: tension, logo: logo1 },
  2: { n: 2, name: 'Overload', line: 'More stress, more growth', art: overload, logo: logo2 },
  3: { n: 3, name: 'Heavy Duty', line: 'The final stimulus', art: heavyduty, logo: logo3 },
}

/** The phase poster for a Hevy folder, or null when the folder is not one of the three phases. */
export function phaseOf(title: string | null | undefined): Phase | null {
  const n = phaseNumber(title)
  return n ? PHASES[n] : null
}
