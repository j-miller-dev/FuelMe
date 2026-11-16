import { createContext, useContext } from 'react'

export const KJ_PER_KCAL = 4.184
export const Units = createContext<{ kj: boolean; setKj: (v: boolean) => void }>({ kj: false, setKj: () => {} })
/** Display-only: all maths stays in kcal. */
export const useEnergy = () => { const { kj } = useContext(Units); return { kj, unit: kj ? 'kJ' : 'kcal', show: (kcal: number) => kj ? Math.round(kcal * KJ_PER_KCAL) : Math.round(kcal) } }
