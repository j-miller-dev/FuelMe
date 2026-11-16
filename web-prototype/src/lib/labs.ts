import { db, type LabResult } from './outbox'

/** Units are the usual Australian lab units. Reference ranges come from YOUR report; the app only compares against what you type. */
export type Marker = { key: string; name: string; unit: string; foods?: string; note?: string }
export const MARKERS: Marker[] = [
  { key: 'vitd', name: 'Vitamin D (25-OH)', unit: 'nmol/L', foods: 'Oily fish, eggs, fortified milk. Sun and supplements matter more than food.', note: 'Healthy Bones Australia suggests at least 50 nmol/L at the end of winter. Check your own lab range too.' },
  { key: 'ferritin', name: 'Ferritin', unit: 'µg/L', foods: 'Red meat, liver, legumes with a vitamin C food.', note: 'Iron can be harmful in excess. Do not self-dose; ask your GP.' },
  { key: 'hb', name: 'Haemoglobin', unit: 'g/L' },
  { key: 'b12', name: 'Vitamin B12', unit: 'pmol/L', foods: 'Meat, fish, eggs, dairy.' },
  { key: 'folate', name: 'Folate', unit: 'nmol/L', foods: 'Leafy greens, legumes, citrus, fortified bread.' },
  { key: 'zinc', name: 'Zinc', unit: 'µmol/L', foods: 'Oysters, red meat, pumpkin seeds.' },
  { key: 'mg', name: 'Magnesium', unit: 'mmol/L', foods: 'Nuts, seeds, wholegrains, legumes.' },
  { key: 'tsh', name: 'TSH', unit: 'mIU/L' },
  { key: 'glucose', name: 'Fasting glucose', unit: 'mmol/L' },
  { key: 'hba1c', name: 'HbA1c', unit: '%' },
  { key: 'ldl', name: 'LDL cholesterol', unit: 'mmol/L' },
  { key: 'hdl', name: 'HDL cholesterol', unit: 'mmol/L' },
  { key: 'trig', name: 'Triglycerides', unit: 'mmol/L' },
  { key: 'crp', name: 'CRP', unit: 'mg/L' },
  { key: 'creat', name: 'Creatinine', unit: 'µmol/L', note: 'Creatine supplements can raise blood creatinine without any kidney change. Tell your GP you take it.' },
  { key: 'egfr', name: 'eGFR', unit: 'mL/min/1.73m²' },
  { key: 'testo', name: 'Total testosterone', unit: 'nmol/L' },
]
export type LabStatus = 'low' | 'ok' | 'high' | 'unset'
export const labStatus = (r: Pick<LabResult, 'value' | 'refLow' | 'refHigh'>): LabStatus =>
  r.refLow == null && r.refHigh == null ? 'unset' : r.refLow != null && r.value < r.refLow ? 'low' : r.refHigh != null && r.value > r.refHigh ? 'high' : 'ok'

export const addResult = (r: Omit<LabResult, 'id'>) => db.labs.add(r)
export const deleteResult = (id: number) => db.labs.delete(id)
export const listResults = () => db.labs.orderBy('date').reverse().toArray()
/** Newest result per marker. */
export const latest = (all: LabResult[]) => { const m = new Map<string, LabResult>(); for (const r of all) if (!m.has(r.marker)) m.set(r.marker, r); return m }
