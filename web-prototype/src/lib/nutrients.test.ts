import { NUTRIENTS, lowestFirst, status, SAMPLE } from './nutrients'
const ok = (n: string, c: boolean) => { console.log(`${c ? 'ok  ' : 'FAIL'} ${n}`); if (!c) process.exitCode = 1 }
const na = NUTRIENTS.find(n => n.key === 'na')!, fe = NUTRIENTS.find(n => n.key === 'iron')!
ok('sodium over limit flagged', status(na, 2650) === 'over')
ok('sodium excluded from gap ranking', !lowestFirst(SAMPLE).some(n => n.key === 'na'))
ok('lowest-first is ascending', (() => { const r = lowestFirst(SAMPLE).map(n => (SAMPLE[n.key] ?? 0) / n.target); return r.every((v, i) => i === 0 || v >= r[i - 1]) })())
ok('iron 9.5 vs 8 met', status(fe, 9.5) === 'met')
ok('only verified rows tagged NHMRC', NUTRIENTS.filter(n => n.src === 'NHMRC').map(n => n.key).sort().join() === 'iron,mg,zinc')
