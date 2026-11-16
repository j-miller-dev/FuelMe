import 'fake-indexeddb/auto'
import { PRESETS, saveSupp, listSupps, toggleTaken, logsOn, totals } from './supplements'
import { labStatus, addResult, listResults, latest } from './labs'
import { estimate, minutesFor } from './sun'

const ok = (n: string, c: boolean) => { console.log(`${c ? 'ok  ' : 'FAIL'} ${n}`); if (!c) process.exitCode = 1 }

// supplements
for (const p of PRESETS) await saveSupp(p)
const supps = await listSupps(); const d = supps.find(s => s.name === 'Vitamin D3')!, cr = supps.find(s => s.name.startsWith('Creatine'))!
ok('toggle takes then undoes', (await toggleTaken(d.id!, '2026-11-16')) === true && (await toggleTaken(d.id!, '2026-11-16')) === false && (await logsOn('2026-11-16')).length === 0)
await toggleTaken(d.id!, '2026-11-16'); await toggleTaken(cr.id!, '2026-11-16')
const t = totals(supps, await logsOn('2026-11-16'))
ok('vitamin D 1000 IU = 25 ug, creatine adds no micronutrient', t.d === 25 && Object.keys(t).length === 1)

// labs
ok('below lab low flagged', labStatus({ value: 40, refLow: 50, refHigh: 150 }) === 'low')
ok('in range ok, above high flagged', labStatus({ value: 80, refLow: 50, refHigh: 150 }) === 'ok' && labStatus({ value: 200, refLow: 50, refHigh: 150 }) === 'high')
ok('no range typed = unset, never guessed', labStatus({ value: 40 }) === 'unset')
await addResult({ date: '2026-08-01', marker: 'vitd', value: 38, refLow: 50 }); await addResult({ date: '2026-11-01', marker: 'vitd', value: 62, refLow: 50 })
ok('latest picks newest per marker', latest(await listResults()).get('vitd')!.value === 62)

// sun: reference rule from the MJA position statement (1/3 MED on 15% of body ~ 1000 IU, fair skin)
const uvi = 6, minutes = (SED => SED / (0.9 * uvi) * 60)(2 / 3)
const e = estimate(minutes, uvi, 0.15, 'fair')
ok('reference rule ~1000 IU (+-5%)', Math.abs(e.raw - 1000) < 50)
ok('range is ordered low<mid<high', e.low < e.mid && e.mid < e.high)
ok('low UV (<3) estimate is discounted vs the same dose at UV 3', estimate(40, 2.99, 0.25, 'fair').mid / estimate(40, 2.99, 0.25, 'fair').raw < estimate(40, 3, 0.25, 'fair').mid / estimate(40, 3, 0.25, 'fair').raw)
ok('zero UV gives nothing and infinite minutes', estimate(30, 0, 0.25, 'fair').high === 0 && minutesFor(1000, 0, 0.25, 'fair') === Infinity)
ok('darker skin yields less', estimate(20, 6, 0.25, 'dark').mid < estimate(20, 6, 0.25, 'fair').mid)
