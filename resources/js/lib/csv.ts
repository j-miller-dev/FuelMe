/**
 * A small, careful CSV reader for the history import. Handles quoted fields, commas and line breaks inside quotes, doubled quotes, a UTF-8 BOM,
 * and CRLF or LF. It does not guess what the columns MEAN: that is the person's choice on the import screen.
 */
export function parseCsv(text: string): string[][] {
  const t = text.replace(/^﻿/, '')
  const rows: string[][] = []
  let row: string[] = []
  let cell = ''
  let q = false
  for (let i = 0; i < t.length; i++) {
    const c = t[i]!
    if (q) {
      if (c === '"') { if (t[i + 1] === '"') { cell += '"'; i++ } else q = false } else cell += c
    } else if (c === '"' && cell === '') q = true
    else if (c === ',') { row.push(cell); cell = '' }
    else if (c === '\n' || c === '\r') {
      if (c === '\r' && t[i + 1] === '\n') i++
      row.push(cell); cell = ''
      if (row.some((x) => x.trim() !== '') || row.length > 1) rows.push(row)
      row = []
    } else cell += c
  }
  row.push(cell)
  if (row.some((x) => x.trim() !== '')) rows.push(row)
  return rows
}

export type DateOrder = 'iso' | 'dmy' | 'mdy'

/** What order the dates look like, when it can be told from the data; null when day and month are ambiguous (the person must choose). */
export function guessDateOrder(samples: string[]): DateOrder | null {
  const s = samples.map((x) => x.trim()).filter(Boolean)
  if (s.length && s.every((x) => /^\d{4}-\d{1,2}-\d{1,2}/.test(x))) return 'iso'
  const parts = s.map((x) => x.match(/^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})/)).filter((m): m is RegExpMatchArray => m !== null)
  if (!parts.length) return null
  if (parts.some((m) => Number(m[1]) > 12)) return 'dmy'
  if (parts.some((m) => Number(m[2]) > 12)) return 'mdy'
  return null
}

/** To YYYY-MM-DD, or null if it is not a real date. */
export function toIsoDate(raw: string, order: DateOrder): string | null {
  const v = raw.trim()
  let y: number, m: number, d: number
  if (order === 'iso') {
    const x = v.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/)
    if (!x) return null
    ;[y, m, d] = [Number(x[1]), Number(x[2]), Number(x[3])]
  } else {
    const x = v.match(/^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4}|\d{2})/)
    if (!x) return null
    const a = Number(x[1]), b = Number(x[2])
    ;[d, m] = order === 'dmy' ? [a, b] : [b, a]
    y = Number(x[3]) < 100 ? 2000 + Number(x[3]) : Number(x[3])
  }
  const dt = new Date(Date.UTC(y, m - 1, d))
  if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== m - 1 || dt.getUTCDate() !== d) return null
  return `${String(y).padStart(4, '0')}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`
}

/** "74,5" and "74.5 kg" are both 74.5; anything else is null (so a unit or a blank never becomes a made-up number). */
export function toNumber(raw: string): number | null {
  const t = raw.trim()
  if (/^\d{1,3}(,\d{3})+(\.\d+)?\s*[a-z]*$/i.test(t)) return null   // "2,850": a thousands separator or a decimal? Refuse rather than guess.
  const m = t.replace(',', '.').match(/^(-?\d+(?:\.\d+)?)\s*(?:kg|g|kcal|cal)?$/i)
  return m ? Number(m[1]) : null
}
