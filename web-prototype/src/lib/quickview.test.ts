import { allItems, loadIds, saveIds, toggleId, move, DEFAULT_IDS } from './quickview'
const ok = (n: string, c: boolean) => { console.log(`${c ? 'ok  ' : 'FAIL'} ${n}`); if (!c) process.exitCode = 1 }
const items = allItems(74, 1250), by = (id: string) => items.find(i => i.id === id)!
ok('tyrosine is intake-only (no target)', by('tyr').target === undefined)
ok('leucine target = 39 mg/kg x 74 kg = 2886', by('leu').target === 2886)
ok('vitamin D is live from the figure passed in', by('d').value === 1250 && by('d').target === 1000)
ok('every default id exists', DEFAULT_IDS.every(i => items.some(x => x.id === i)))
ok('ids are unique', new Set(items.map(i => i.id)).size === items.length)
const mem = new Map<string, string>(); const st = { getItem: (k: string) => mem.get(k) ?? null, setItem: (k: string, v: string) => void mem.set(k, v) }
ok('empty storage falls back to defaults', loadIds(items.map(i => i.id), st).join() === DEFAULT_IDS.join())
saveIds(['tyr', 'd'], st); ok('saved selection round-trips', loadIds(items.map(i => i.id), st).join() === 'tyr,d')
saveIds(['gone', 'd'], st); ok('unknown ids are dropped', loadIds(items.map(i => i.id), st).join() === 'd')
ok('cannot remove the last card', toggleId(['d'], 'd').join() === 'd')
ok('move swaps neighbours, edges are no-ops', move(['a', 'b', 'c'], 'b', -1).join() === 'b,a,c' && move(['a', 'b'], 'a', -1).join() === 'a,b')
ok('throwing storage does not crash', loadIds(['d'], { getItem: () => { throw new Error('x') }, setItem: () => {} }).join() === 'd')

import { SAMPLE_MEALS, mealsHitting, bcaaMg, eaaMg, perMealProteinG } from './muscle'
ok('2 of 3 sample meals reach 2.5 g leucine', mealsHitting(SAMPLE_MEALS) === 2)
ok('BCAA total = leu+ile+val', bcaaMg() === 7000 + 4200 + 4600)
ok('EAA sum excludes standalone tyrosine', eaaMg() === 2500 + 4200 + 7000 + 6800 + 3300 + 7600 + 3700 + 1100 + 4600)
ok('per-meal protein 0.4 g/kg at 74 kg = 30 g', perMealProteinG(74) === 30)
