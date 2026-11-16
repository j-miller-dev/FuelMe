import 'fake-indexeddb/auto'
import { addPhoto, deletePhoto, listPhotos } from './photos'

const ok = (n: string, c: boolean) => { console.log(`${c ? 'ok  ' : 'FAIL'} ${n}`); if (!c) process.exitCode = 1 }
const b = (s: string) => new Blob([s], { type: 'image/jpeg' })
await addPhoto(b('a'), 'front', '2026-10-01')
await addPhoto(b('b'), 'front', '2026-11-01')
await addPhoto(b('c'), 'side', '2026-11-01')
ok('lists all, newest first', (await listPhotos())[0].date === '2026-11-01')
const fronts = await listPhotos('front')
ok('filters by pose', fronts.length === 2 && fronts.every(p => p.pose === 'front'))
ok('blob round-trips', fronts[0].blob.size === 1)
await deletePhoto(fronts[0].id!)
ok('delete removes one', (await listPhotos('front')).length === 1)
