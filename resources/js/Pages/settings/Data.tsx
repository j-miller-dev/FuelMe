import { Head, Link } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import AppLayout from '@/layouts/AppLayout'
import { api } from '@/lib/http'
import { guessDateOrder, parseCsv, toIsoDate, toNumber, type DateOrder } from '@/lib/csv'

interface Result { added: number; skipped: number; invalid: { row: number; reason: string }[]; first_date: string | null; last_date: string | null }
type Row = Record<string, unknown>
const sum = (a: Result, b: Result): Result => ({ added: a.added + b.added, skipped: a.skipped + b.skipped, invalid: [...a.invalid, ...b.invalid].slice(0, 25), first_date: [a.first_date, b.first_date].filter(Boolean).sort()[0] ?? null, last_date: [a.last_date, b.last_date].filter(Boolean).sort().at(-1) ?? null })
const EMPTY: Result = { added: 0, skipped: 0, invalid: [], first_date: null, last_date: null }

/** Sends rows in requests of 1000 (the server's limit), adding up the answers. */
async function sendRows(kind: string, rows: Row[], max: number, progress: (done: number) => void): Promise<Result> {
  let total = EMPTY
  for (let i = 0; i < rows.length; i += max) {
    const r = await api<Result>('/import/rows', { method: 'POST', body: { kind, rows: rows.slice(i, i + max) } })
    // Row numbers in a chunk start at 1; make them file-wide.
    total = sum(total, { ...r, invalid: r.invalid.map((x) => ({ ...x, row: x.row + i })) })
    progress(Math.min(rows.length, i + max))
  }
  return total
}

export default function Data({ counts, max }: { counts: { foodLogs: number; weights: number; workouts: number }; max: number }) {
  return (
    <AppLayout>
      <Head title="Your data" />
      <h1 className="text-2xl font-bold">Your data</h1>
      <p className="mt-1 text-sm text-muted">It is yours. Download all of it, restore it, or bring history in from a spreadsheet. You have {counts.foodLogs.toLocaleString()} food entries, {counts.weights.toLocaleString()} weigh-ins and {counts.workouts.toLocaleString()} sessions.</p>
      <Download />
      <Restore max={max} />
      <SpreadsheetImport max={max} />
      <Link href="/settings/security" className="mt-6 block text-center text-sm text-muted underline">Back to Security</Link>
    </AppLayout>
  )
}

function Download() {
  const link = 'flex min-h-12 items-center justify-between rounded-xl bg-raised px-4 text-sm'
  return (
    <section className="mt-5 rounded-2xl bg-surface p-4" aria-label="Download">
      <h2 className="font-semibold">Download</h2>
      <p className="mt-1 text-xs text-muted">The backup has everything (profile, targets, logs, meals, your foods, training, lists) and none of your passwords or passkeys. The spreadsheets open in Excel, Numbers or Sheets.</p>
      <div className="mt-3 grid gap-2">
        <a className={link} href="/settings/data/backup.json" download><span>Full backup</span><span className="text-muted">.json</span></a>
        <a className={link} href="/settings/data/food-logs.csv" download><span>Food log</span><span className="text-muted">.csv</span></a>
        <a className={link} href="/settings/data/weights.csv" download><span>Weigh-ins</span><span className="text-muted">.csv</span></a>
        <a className={link} href="/settings/data/workouts.csv" download><span>Training sessions</span><span className="text-muted">.csv</span></a>
      </div>
    </section>
  )
}

function Restore({ max }: { max: number }) {
  const [backup, setBackup] = useState<Record<string, Row[]> | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [res, setRes] = useState<Record<string, Result> | null>(null)

  async function pick(f: File | undefined) {
    setErr(null); setRes(null); setBackup(null)
    if (!f) return
    try {
      const j = JSON.parse(await f.text()) as { app?: string; version?: number } & Record<string, Row[]>
      if (j.app !== 'FUEL' || typeof j.version !== 'number') throw new Error('That is not a FUEL backup.')
      if (j.version > 1) throw new Error('That backup is from a newer version of FUEL. Update the app first.')
      setBackup(j)
    } catch (e) { setErr(e instanceof Error && e.message.startsWith('That') ? e.message : 'Could not read that file. Is it a FUEL backup (.json)?') }
  }
  async function go() {
    if (!backup) return
    setErr(null); const out: Record<string, Result> = {}
    try {
      for (const [section, kind] of [['weights', 'weights'], ['food_logs', 'food_logs'], ['workouts', 'workouts']] as const) {
        const rows = backup[section] ?? []
        if (!rows.length) continue
        setBusy(`${section.replace('_', ' ')}…`)
        out[section] = await sendRows(kind, rows, max, () => undefined)
      }
      setRes(out)
    } catch (e) { setErr(e instanceof Error ? e.message : 'Restore stopped. Nothing is lost: run it again and it carries on.') } finally { setBusy(null) }
  }
  const n = (k: string) => backup?.[k]?.length ?? 0
  return (
    <section className="mt-4 rounded-2xl bg-surface p-4" aria-label="Restore a backup">
      <h2 className="font-semibold">Restore a backup</h2>
      <p className="mt-1 text-xs text-muted">Adds weigh-ins, food entries and sessions from a FUEL backup. Anything you already have is left alone, so running it twice does nothing the second time.</p>
      <input type="file" accept=".json,application/json" aria-label="Choose a backup file" onChange={(e) => void pick(e.target.files?.[0])} className="mt-3 block w-full text-sm" />
      {backup ? (
        <div className="mt-3">
          <p className="text-sm">{n('weights').toLocaleString()} weigh-ins · {n('food_logs').toLocaleString()} food entries · {n('workouts').toLocaleString()} sessions</p>
          <button disabled={!!busy} onClick={() => void go()} className="mt-2 min-h-12 w-full rounded-2xl bg-accent font-semibold text-on-accent disabled:opacity-40">{busy ? `Restoring ${busy}` : 'Restore'}</button>
        </div>
      ) : null}
      {err ? <p role="alert" className="mt-2 text-sm text-danger">{err}</p> : null}
      {res ? <ul className="mt-3 space-y-1 text-sm" role="status">{Object.entries(res).map(([k, r]) => <li key={k}>{k.replace('_', ' ')}: {r.added} added, {r.skipped} already there{r.invalid.length ? `, ${r.invalid.length} skipped as invalid` : ''}</li>)}</ul> : null}
    </section>
  )
}

function SpreadsheetImport({ max }: { max: number }) {
  const [table, setTable] = useState<string[][] | null>(null)
  const [headers, setHeaders] = useState(true)
  const [kind, setKind] = useState<'weights' | 'daily_totals'>('weights')
  const [map, setMap] = useState<Record<string, number>>({})
  const [order, setOrder] = useState<DateOrder>('iso')
  const [err, setErr] = useState<string | null>(null)
  const [busy, setBusy] = useState<number | null>(null)
  const [res, setRes] = useState<Result | null>(null)

  async function pick(f: File | undefined) {
    setErr(null); setRes(null); setTable(null); setMap({})
    if (!f) return
    if (f.size > 25_000_000) { setErr('That file is over 25 MB. Export a shorter date range.'); return }
    const t = parseCsv(await f.text())
    if (t.length < 2) { setErr('That file has no data rows.'); return }
    setTable(t)
    const body = t.slice(1)
    const names = t[0]!.map((h) => h.toLowerCase())
    const find = (...w: string[]) => Math.max(-1, names.findIndex((n) => w.some((x) => n.includes(x))))
    const dateCol = find('date', 'day')
    setMap({ date: dateCol, kg: find('weight', 'kg'), kcal: find('calor', 'energy', 'kcal'), protein: find('protein'), carbs: find('carb'), fat: find('fat') })
    setOrder(guessDateOrder(body.map((r) => r[dateCol] ?? '')) ?? 'dmy')
  }

  const body = useMemo(() => (table ? (headers ? table.slice(1) : table) : []), [table, headers])
  const fields = kind === 'weights' ? [['date', 'Date'], ['kg', 'Weight (kg)']] as const : [['date', 'Date'], ['kcal', 'Calories'], ['protein', 'Protein (g), optional'], ['carbs', 'Carbs (g), optional'], ['fat', 'Fat (g), optional']] as const
  const required = kind === 'weights' ? ['date', 'kg'] : ['date', 'kcal']
  const ready = table && required.every((k) => (map[k] ?? -1) >= 0)
  const auto = useMemo(() => (table ? guessDateOrder(body.map((r) => r[map.date ?? -1] ?? '')) : null), [table, body, map.date])

  const mapped = useMemo<Row[]>(() => {
    if (!ready) return []
    const cell = (r: string[], k: string) => (map[k] !== undefined && map[k]! >= 0 ? (r[map[k]!] ?? '') : '')
    return body.map((r) => {
      const row: Row = { date: toIsoDate(cell(r, 'date'), order) ?? (cell(r, 'date') || 'blank') }
      for (const [k] of fields) if (k !== 'date') { const v = toNumber(cell(r, k)); row[k] = v ?? (cell(r, k) === '' ? null : cell(r, k)) }
      return row
    })
  }, [ready, body, map, order]) // eslint-disable-line react-hooks/exhaustive-deps

  async function go() {
    setBusy(0); setErr(null); setRes(null)
    try { setRes(await sendRows(kind, mapped, max, setBusy)) } catch (e) { setErr(e instanceof Error ? e.message : 'Import stopped. Run it again: what already went in is skipped.') } finally { setBusy(null) }
  }

  const sel = 'mt-1 min-h-11 w-full rounded-xl bg-raised px-3 text-ink'
  return (
    <section className="mt-4 rounded-2xl bg-surface p-4" aria-label="Import history from a spreadsheet">
      <h2 className="font-semibold">Import history from a spreadsheet</h2>
      <p className="mt-1 text-xs text-muted">Bring weigh-ins or daily calorie totals from another app (export a CSV from it) so your trend and expenditure start from real history. FUEL does not recognise another app's file layout automatically: you tell it which column is which, and see the result before anything is saved.</p>
      <input type="file" accept=".csv,text/csv" aria-label="Choose a CSV file" onChange={(e) => void pick(e.target.files?.[0])} className="mt-3 block w-full text-sm" />
      {err ? <p role="alert" className="mt-2 text-sm text-danger">{err}</p> : null}

      {table ? (
        <div className="mt-3 space-y-3">
          <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={headers} onChange={(e) => setHeaders(e.target.checked)} className="size-5" />The first row is column names</label>
          <div className="flex gap-2" role="group" aria-label="What is in this file">
            {([['weights', 'Weigh-ins'], ['daily_totals', 'Daily calories']] as const).map(([k, l]) => <button key={k} aria-pressed={kind === k} onClick={() => setKind(k)} className={`min-h-11 flex-1 rounded-full text-sm ${kind === k ? 'bg-accent/20 text-accent ring-1 ring-accent' : 'bg-raised text-muted'}`}>{l}</button>)}
          </div>
          {fields.map(([k, label]) => (
            <label key={k} className="block text-xs text-muted">{label}
              <select value={map[k] ?? -1} onChange={(e) => setMap((m) => ({ ...m, [k]: Number(e.target.value) }))} className={sel}>
                <option value={-1}>{required.includes(k) ? 'Choose a column' : 'Not in the file'}</option>
                {table[0]!.map((h, i) => <option key={i} value={i}>{headers ? h || `Column ${i + 1}` : `Column ${i + 1} (e.g. ${table[0]![i]})`}</option>)}
              </select>
            </label>
          ))}
          <label className="block text-xs text-muted">How are the dates written?
            <select value={order} onChange={(e) => setOrder(e.target.value as DateOrder)} className={sel}>
              <option value="iso">2026-10-31 (year first)</option><option value="dmy">31/10/2026 (day first, Australian)</option><option value="mdy">10/31/2026 (month first, US)</option>
            </select>
          </label>
          {auto === null && order !== 'iso' ? <p className="text-xs text-carbs">Check this one: dates like 03/04/2026 could be 3 April or 4 March, and only you know which.</p> : null}

          {ready ? (
            <div aria-live="polite">
              <p className="text-sm font-medium">Preview ({mapped.length.toLocaleString()} rows)</p>
              <ul className="mt-1 space-y-1 text-xs tabular-nums text-muted">{mapped.slice(0, 5).map((r, i) => <li key={i}>{JSON.stringify(r).replace(/[{}"]/g, '').replace(/,/g, ' · ')}</li>)}</ul>
              <button disabled={busy !== null} onClick={() => void go()} className="mt-3 min-h-12 w-full rounded-2xl bg-accent font-semibold text-on-accent disabled:opacity-40">{busy !== null ? `Importing ${busy.toLocaleString()} of ${mapped.length.toLocaleString()}…` : `Import ${mapped.length.toLocaleString()} rows`}</button>
            </div>
          ) : <p className="text-xs text-muted">Choose the {required.length === 2 ? 'date and value' : 'date and calories'} columns to see a preview.</p>}
        </div>
      ) : null}

      {res ? (
        <div role="status" className="mt-3 rounded-xl bg-raised p-3 text-sm">
          <p><span className="font-semibold">{res.added.toLocaleString()} added</span>, {res.skipped.toLocaleString()} already there{res.invalid.length ? `, ${res.invalid.length} rows skipped as invalid` : ''}.</p>
          {res.first_date ? <p className="text-muted">Covering {res.first_date} to {res.last_date}. Your trend now starts from the earliest weigh-in.</p> : null}
          {res.invalid.length ? <ul className="mt-1 space-y-0.5 text-xs text-muted">{res.invalid.slice(0, 8).map((x) => <li key={x.row}>Row {x.row + (headers ? 1 : 0)}: {x.reason}</li>)}</ul> : null}
          <Link href="/progress" className="mt-2 block text-accent underline">See your progress</Link>
        </div>
      ) : null}
    </section>
  )
}
