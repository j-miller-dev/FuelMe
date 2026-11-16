import { useEffect, useRef, useState } from 'react'
import { POSES, addPhoto, deletePhoto, listPhotos, type Pose } from '../lib/photos'
import type { Photo } from '../lib/outbox'

function Img({ p }: { p: Photo }) {
  const [url, setUrl] = useState('')
  useEffect(() => { const u = URL.createObjectURL(p.blob); setUrl(u); return () => URL.revokeObjectURL(u) }, [p.blob])
  return <img src={url} alt={`${p.pose} ${p.date}`} className="aspect-[3/4] w-full rounded-xl object-cover" />
}

export default function Photos() {
  const [pose, setPose] = useState<Pose>('front')
  const [all, setAll] = useState<Photo[]>([])
  const [cmp, setCmp] = useState<number[]>([])
  const file = useRef<HTMLInputElement>(null)
  const refresh = () => void listPhotos().then(setAll)
  useEffect(refresh, [])
  const shown = all.filter(p => p.pose === pose)
  const pair = cmp.map(id => all.find(p => p.id === id)).filter(Boolean) as Photo[]
  const toggle = (id: number) => setCmp(c => c.includes(id) ? c.filter(x => x !== id) : [...c.slice(-1), id])
  return (
    <div className="space-y-3 pb-28">
      <p className="rounded-2xl bg-surface p-3 text-xs text-muted">Private. Photos are stored on this device only in the prototype. The real app keeps them on your own server, never a third party.</p>
      <div className="flex gap-2">{POSES.map(([v, l]) => <button key={v} onClick={() => setPose(v)} className={`min-h-11 flex-1 rounded-xl text-sm ${v === pose ? 'bg-accent font-semibold text-canvas' : 'bg-raised'}`}>{l}</button>)}</div>
      <input ref={file} type="file" accept="image/*" capture="environment" hidden onChange={async e => { const f = e.target.files?.[0]; if (f) { await addPhoto(f, pose); refresh() } e.target.value = '' }} />
      <button className="min-h-12 w-full rounded-2xl bg-accent font-semibold text-canvas" onClick={() => file.current?.click()}>Add {pose} photo</button>
      {pair.length === 2 && <section className="rounded-2xl bg-surface p-3"><div className="mb-2 text-sm font-medium">Compare</div>
        <div className="grid grid-cols-2 gap-2">{[...pair].sort((a, b) => a.date.localeCompare(b.date)).map(p => <div key={p.id}><Img p={p} /><div className="mt-1 text-center text-xs text-muted">{p.date}</div></div>)}</div></section>}
      {shown.length === 0 ? <p className="py-8 text-center text-sm text-muted">No {pose} photos yet.</p>
        : <><p className="text-xs text-muted">Tap two photos to compare.</p>
          <div className="grid grid-cols-3 gap-2">{shown.map(p => (
            <div key={p.id} className="relative">
              <button onClick={() => toggle(p.id!)} aria-pressed={cmp.includes(p.id!)} className={`block w-full rounded-xl ${cmp.includes(p.id!) ? 'ring-2 ring-accent' : ''}`}><Img p={p} /></button>
              <div className="mt-1 flex items-center justify-between text-[11px] text-muted"><span>{p.date}</span>
                <button className="min-h-8 px-1 text-fat" aria-label={`Delete ${p.pose} photo ${p.date}`} onClick={async () => { await deletePhoto(p.id!); setCmp(c => c.filter(x => x !== p.id)); refresh() }}>Delete</button></div>
            </div>))}</div></>}
    </div>)
}
