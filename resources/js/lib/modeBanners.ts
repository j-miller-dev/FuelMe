import bjj from '@/assets/modes/bjj.webp'
import hiit from '@/assets/modes/hiit.webp'
import jumprope from '@/assets/modes/jumprope.webp'
import norwegian from '@/assets/modes/norwegian.webp'
import resistance from '@/assets/modes/resistance.webp'
import trainingDay from '@/assets/modes/training-day.webp'

/** Header for the Training card on Today when the day has sessions. */
export const TRAINING_DAY_BANNER = trainingDay

/** Banner image per timer preset id. */
export const TIMER_BANNERS: Record<string, string> = { hiit, norwegian, jumprope, bjj }

/** Banner image per training session type (the catalogue keys). Types with no banner (custom, others) simply show none. */
export const SESSION_BANNERS: Record<string, string> = { bjj, hiit, norwegian_4x4: norwegian, lifting: resistance }
