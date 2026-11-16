import { NUTRIENTS, SAMPLE } from './nutrients'
import { AMINOS, aminoTargetMg } from './aminos'

/** One card in the Today quick view. target undefined = intake only (no requirement exists). */
export type QItem = { id: string; name: string; unit: string; value: number; target?: number; limit?: boolean; tag: string; note?: string }

/** vitDIu = live sun estimate + logged supplements (IU). The 1,000 IU figure is a reference point, not a personal target. */
export function allItems(kg: number, vitDIu: number): QItem[] {
  const out: QItem[] = [{ id: 'd', name: 'Vitamin D', unit: 'IU', value: Math.round(vitDIu), target: 1000, tag: 'live · reference', note: 'Sun is an estimate. 1,000 IU is a common reference point, not a target set for you.' }]
  for (const n of NUTRIENTS) out.push({ id: n.key, name: n.name, unit: n.unit, value: SAMPLE[n.key] ?? 0, target: n.target, limit: n.limit, tag: n.src === 'NHMRC' ? 'sample · NHMRC target' : 'sample · US ref target' })
  for (const a of AMINOS) out.push({ id: a.key, name: a.name, unit: 'mg', value: a.sample, target: aminoTargetMg(a, kg), tag: a.src === 'WHO' ? 'sample · WHO target' : 'sample · target to verify', note: a.note })
  return out
}
export const DEFAULT_IDS = ['d', 'b6', 'tyr', 'leu', 'mg']
const KEY = 'fuel.quickview'
type Store = Pick<Storage, 'getItem' | 'setItem'>
/** Light per-viewer preference only; storage may be empty or throw, so fall back to defaults. */
export function loadIds(valid: string[], st?: Store): string[] {
  try { const raw = (st ?? localStorage).getItem(KEY); const ids = raw ? JSON.parse(raw) as string[] : null; const keep = ids?.filter(i => valid.includes(i)); if (keep && keep.length) return keep } catch { /* ignore */ }
  return DEFAULT_IDS.filter(i => valid.includes(i))
}
export const saveIds = (ids: string[], st?: Store) => { try { (st ?? localStorage).setItem(KEY, JSON.stringify(ids)) } catch { /* ignore */ } }
export const toggleId = (ids: string[], id: string) => ids.includes(id) ? (ids.length > 1 ? ids.filter(i => i !== id) : ids) : [...ids, id]
export const move = (ids: string[], id: string, dir: -1 | 1) => { const i = ids.indexOf(id), j = i + dir; if (i < 0 || j < 0 || j >= ids.length) return ids; const c = [...ids]; [c[i], c[j]] = [c[j], c[i]]; return c }
