import { useState } from 'react'
import Supplements from './Supplements'
import BloodTests from './BloodTests'
import Sun from './Sun'

const V = [['supps', 'Supplements'], ['blood', 'Blood tests'], ['sun', 'Sun & D']] as const
export default function Health() {
  const [v, setV] = useState<typeof V[number][0]>('supps')
  return (<div>
    <div className="mb-3 flex gap-2">{V.map(([k, l]) => <button key={k} onClick={() => setV(k)} className={`min-h-11 flex-1 rounded-xl text-sm ${v === k ? 'bg-accent font-semibold text-canvas' : 'bg-surface'}`}>{l}</button>)}</div>
    {v === 'supps' && <Supplements />}{v === 'blood' && <BloodTests />}{v === 'sun' && <Sun />}</div>)
}
