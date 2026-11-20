import { useEffect, useMemo, useRef, useState } from 'react'
import { api } from '@/lib/http'
import { SLOT_LABEL, hitName, macrosFor, unitOf, type FoodHit, type Slot } from '@/lib/food'
import type { NewLog } from '@/lib/useLogging'
import { Sheet } from './Sheet'

type Conf = 'green' | 'amber' | 'red'
interface Cand extends FoodHit { match: number; why: string[] }
interface ApiItem {
  id: string; phrase: string; confidence: Conf; notes: string[]; meal_slot: Slot | null; candidates: Cand[]
  parsed: { name_query: string; state: string | null }
  chosen: { food_id: number; grams: number; estimated: boolean; needs_amount: boolean } | null
}
interface Parsed { session_id: string; mode: 'ai' | 'basic'; note: string | null; items: ApiItem[]; unparsed: string[] }
interface Card extends ApiItem { food: Cand | null; grams: string; skipped: boolean; open: boolean; touched: boolean }

// The Web Speech API is not in the TypeScript DOM types.
interface Recognition { lang: string; interimResults: boolean; continuous: boolean; start(): void; stop(): void; onresult: ((e: { results: ArrayLike<ArrayLike<{ transcript: string }> & { isFinal: boolean }> }) => void) | null; onend: (() => void) | null; onerror: (() => void) | null }
const SpeechCtor = (): (new () => Recognition) | null => {
  const w = window as unknown as { SpeechRecognition?: new () => Recognition; webkitSpeechRecognition?: new () => Recognition }
  return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null
}

const DOT: Record<Conf, string> = { green: 'bg-accent', amber: 'bg-carbs', red: 'bg-danger' }
const DOT_LABEL: Record<Conf, string> = { green: 'Confident match', amber: 'Check this one', red: 'No good match' }

/**
 * Say or type a whole meal. The words are parsed into foods (AI when available, otherwise a basic rule parser), each is matched to the
 * food database, and you review the cards: red first, then amber, green collapsed. Numbers always come from the database row.
 */
export function CaptureTab({ slot, onAdd, onClose }: { slot: Slot; onAdd: (items: NewLog[]) => Promise<unknown>; onClose: () => void }) {
  const [text, setText] = useState('')
  const [res, setRes] = useState<Parsed | null>(null)
  const [cards, setCards] = useState<Card[]>([])
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const [swap, setSwap] = useState<string | null>(null)
  const [listening, setListening] = useState(false)
  const rec = useRef<Recognition | null>(null)
  const base = useRef('')
  const canSpeak = typeof window !== 'undefined' && SpeechCtor() !== null

  async function parse(t = text) {
    if (t.trim().length < 2) return
    setBusy(true); setErr(null)
    try {
      const r = await api<Parsed>('/capture/parse', { method: 'POST', body: { text: t, meal_slot: slot } })
      setRes(r)
      setCards(r.items.map((i) => {
        const food = i.chosen ? (i.candidates.find((c) => c.id === i.chosen!.food_id) ?? null) : null
        return { ...i, food, grams: String(i.chosen?.grams ?? ''), skipped: false, open: i.confidence !== 'green', touched: false }
      }))
    } catch (e) { setErr(e instanceof Error ? e.message : 'Could not read that. Try again.') } finally { setBusy(false) }
  }

  function listen(on: boolean) {
    if (!on) { rec.current?.stop(); return }
    const C = SpeechCtor()
    if (!C) return
    const r = new C()
    r.lang = 'en-AU'; r.interimResults = true; r.continuous = true
    base.current = text ? text.trimEnd() + ' ' : ''
    r.onresult = (e) => { let s = ''; for (let i = 0; i < e.results.length; i++) s += e.results[i]?.[0]?.transcript ?? ''; setText(base.current + s) }
    r.onend = () => { setListening(false); setText((t) => { if (t.trim().length >= 2) void parse(t); return t }) }
    r.onerror = () => setListening(false)
    rec.current = r; setListening(true); r.start()
  }
  useEffect(() => () => rec.current?.stop(), [])

  const patch = (id: string, p: Partial<Card>) => setCards((cs) => cs.map((c) => (c.id === id ? { ...c, ...p } : c)))
  const gramsOf = (c: Card) => Number(c.grams.replace(',', '.'))
  const live = cards.filter((c) => !c.skipped)
  const ready = live.length > 0 && live.every((c) => c.food && gramsOf(c) >= 0.1 && gramsOf(c) <= 5000)
  const sum = useMemo(() => live.reduce((a, c) => { const m = c.food ? macrosFor(c.food.per100, gramsOf(c) || 0) : { kcal: 0, protein: 0 }; return { kcal: a.kcal + m.kcal, protein: a.protein + m.protein } }, { kcal: 0, protein: 0 }), [cards]) // eslint-disable-line react-hooks/exhaustive-deps
  const swapCard = cards.find((c) => c.id === swap) ?? null

  async function logAll() {
    const items: NewLog[] = live.map((c) => ({ kind: 'food', food: c.food!, grams: gramsOf(c), slot: c.meal_slot ?? slot, serving_label: null }))
    const learn = live.map((c) => ({ phrase: c.parsed.name_query, food_id: c.food!.id, grams: gramsOf(c) }))
    void api('/capture/learn', { method: 'POST', body: { items: learn } }).catch(() => undefined)   // learning never blocks logging
    await onAdd(items)
    onClose()
  }

  if (!res) {
    return (
      <div>
        <label className="block text-xs text-muted">What did you have?
          <textarea value={text} onChange={(e) => setText(e.target.value)} rows={4} maxLength={600} autoFocus
            placeholder="Two scrambled eggs, two slices of toast with butter, a Jalna Greek yoghurt about 200 grams"
            className="mt-1 w-full resize-none rounded-xl bg-surface p-3 text-base text-ink outline-none focus:ring-2 focus:ring-accent" />
        </label>
        <p className="mt-1 text-xs text-muted">You can edit the text, or use your keyboard's microphone. Say the amounts: "250 grams", "2 slices", "half an avocado".</p>
        {err ? <p role="alert" className="mt-2 text-sm text-danger">{err}</p> : null}
        <div className="mt-3 flex gap-2">
          {canSpeak ? (
            <button onPointerDown={(e) => { e.preventDefault(); listen(true) }} onPointerUp={() => listen(false)} onPointerCancel={() => listen(false)} onContextMenu={(e) => e.preventDefault()}
              aria-pressed={listening} aria-label="Hold to talk" className={`min-h-12 select-none rounded-2xl px-4 font-medium ${listening ? 'bg-danger/30 text-danger' : 'bg-surface'}`}>{listening ? 'Listening…' : 'Hold to talk'}</button>
          ) : null}
          <button disabled={busy || text.trim().length < 2} onClick={() => void parse()} className="min-h-12 flex-1 rounded-2xl bg-accent font-semibold text-on-accent disabled:opacity-40">{busy ? 'Reading…' : 'Understand it'}</button>
        </div>
      </div>
    )
  }

  const order: Conf[] = ['red', 'amber', 'green']
  const sorted = [...cards].sort((a, b) => order.indexOf(a.confidence) - order.indexOf(b.confidence))
  return (
    <div>
      <div className="flex items-start gap-2">
        <div className="min-w-0 flex-1 rounded-xl bg-surface p-3 text-sm"><span className="text-muted">You said: </span>{text}</div>
        <button onClick={() => { setRes(null); setCards([]) }} className="min-h-11 shrink-0 rounded-xl bg-surface px-3 text-sm text-accent">Edit</button>
      </div>
      {res.mode === 'basic' ? <p className="mt-2 text-xs text-muted">Basic mode{res.note ? ` (${res.note})` : ''}: the words were split by simple rules, so check each card.</p> : null}
      {res.unparsed.length ? <p className="mt-2 text-xs text-carbs">Could not read: {res.unparsed.join('; ')}</p> : null}

      <ul className="mt-3 space-y-2">
        {sorted.map((c) => {
          const g = gramsOf(c)
          const m = c.food ? macrosFor(c.food.per100, g || 0) : null
          if (c.skipped) return <li key={c.id} className="flex min-h-12 items-center gap-2 rounded-2xl bg-surface/50 px-3 text-sm text-muted"><span className="flex-1 truncate line-through">{c.phrase}</span><button onClick={() => patch(c.id, { skipped: false })} className="min-h-11 text-accent">Undo</button></li>
          if (!c.open && c.food && m) {
            return (
              <li key={c.id}><button onClick={() => patch(c.id, { open: true })} className="flex min-h-12 w-full items-center gap-2 rounded-2xl bg-surface px-3 text-left text-sm" aria-label={`${hitName(c.food)}, ${DOT_LABEL[c.confidence]}. Tap to change`}>
                <span className={`size-2.5 shrink-0 rounded-full ${DOT[c.confidence]}`} aria-hidden /><span className="min-w-0 flex-1 truncate">{hitName(c.food)}</span><span className="tabular-nums text-muted">{Math.round(g)} {unitOf(c.food)} · {Math.round(m.kcal)} kcal</span>
              </button></li>
            )
          }
          return (
            <li key={c.id} className="rounded-2xl bg-surface p-3">
              <div className="flex items-start gap-2">
                <span className={`mt-1.5 size-2.5 shrink-0 rounded-full ${DOT[c.confidence]}`} role="img" aria-label={DOT_LABEL[c.confidence]} />
                <div className="min-w-0 flex-1">
                  <div className="truncate text-xs text-muted">“{c.phrase}”</div>
                  <div className="font-medium leading-tight">{c.food ? hitName(c.food) : 'Choose a food'}{c.food?.needs_check ? <span className="ml-1 rounded bg-carbs/20 px-1 text-[10px] text-carbs">check</span> : null}</div>
                  {c.food ? <div className="text-xs text-muted">{c.food.source === 'afcd' ? 'AFCD' : c.food.source === 'off' ? 'Packaged' : c.food.source === 'user' ? 'Yours' : c.food.source}{c.food.why?.length ? ` · ${c.food.why.join(', ')}` : ''}</div> : null}
                </div>
                <button onClick={() => patch(c.id, { skipped: true })} aria-label={`Skip ${c.phrase}`} className="size-11 shrink-0 text-muted">✕</button>
              </div>
              {c.notes.map((n) => <p key={n} className="mt-1 text-xs text-carbs">{n}</p>)}
              <div className="mt-2 flex items-center gap-2">
                <label className="flex items-center gap-1"><span className="sr-only">Amount</span>
                  <input inputMode="decimal" value={c.grams} onChange={(e) => patch(c.id, { grams: e.target.value.replace(',', '.'), touched: true })}
                    className="min-h-11 w-24 rounded-xl bg-raised px-2 text-right text-lg tabular-nums text-ink outline-none focus:ring-2 focus:ring-accent" />
                  <span className="text-sm text-muted">{c.food ? unitOf(c.food) : 'g'}</span></label>
                <span className="flex-1 text-right text-sm tabular-nums">{m ? `${Math.round(m.kcal)} kcal · ${Math.round(m.protein)}P ${Math.round(m.carbs)}C ${Math.round(m.fat)}F` : ''}</span>
                <button onClick={() => setSwap(c.id)} className="min-h-11 rounded-xl bg-raised px-3 text-sm text-accent">Swap</button>
              </div>
            </li>
          )
        })}
      </ul>

      <div className="sticky bottom-0 -mx-4 mt-4 bg-canvas px-4 pb-1 pt-2">
        <div className="mb-2 text-center text-xs text-muted tabular-nums">{live.length} item{live.length === 1 ? '' : 's'} · {Math.round(sum.kcal)} kcal · {Math.round(sum.protein)} g protein</div>
        <button disabled={!ready} onClick={() => void logAll()} className="min-h-12 w-full rounded-2xl bg-accent font-semibold text-on-accent disabled:opacity-40">Log all to {SLOT_LABEL[slot].toLowerCase()}</button>
        {!ready && live.length > 0 ? <p className="mt-1 text-center text-xs text-muted">Choose a food and an amount for every card, or skip it.</p> : null}
      </div>

      {swapCard ? <SwapSheet card={swapCard} onClose={() => setSwap(null)} onPick={(f) => { patch(swapCard.id, { food: f, open: true, touched: true, confidence: 'green', notes: [] }); setSwap(null) }} /> : null}
    </div>
  )
}

function SwapSheet({ card, onPick, onClose }: { card: Card; onPick: (f: Cand) => void; onClose: () => void }) {
  const [q, setQ] = useState(card.parsed.name_query)
  const [results, setResults] = useState<Cand[] | null>(null)
  const [state, setState] = useState<'any' | 'raw' | 'cooked'>('any')
  const seq = useRef(0)
  const alts = card.candidates.filter((c) => c.id !== card.food?.id)
  const isRaw = (n: string) => /\braw\b/i.test(n)
  const isCooked = (n: string) => /\b(cooked|grilled|roasted|fried|boiled|baked|steamed|stir-fried|scrambled|poached)\b/i.test(n)
  const shownAlts = alts.filter((c) => state === 'any' || (state === 'raw' ? isRaw(c.name) : isCooked(c.name))).slice(0, 6)

  useEffect(() => {
    const t = q.trim()
    if (t.length < 2 || t === card.parsed.name_query) { setResults(null); return }
    const mine = ++seq.current
    const ctl = new AbortController()
    const timer = setTimeout(() => {
      api<{ results: Cand[] }>(`/foods/search?q=${encodeURIComponent(t)}`, { signal: ctl.signal }).then((r) => { if (mine === seq.current) setResults(r.results.slice(0, 8)) }).catch(() => undefined)
    }, 120)
    return () => { clearTimeout(timer); ctl.abort() }
  }, [q, card.parsed.name_query])

  const row = (c: Cand) => (
    <li key={c.id}><button onClick={() => onPick(c)} className="flex min-h-14 w-full items-center gap-2 border-b border-raised/70 py-1 text-left">
      <span className="min-w-0 flex-1"><span className="block truncate text-sm font-medium">{hitName(c)}</span>
        <span className="block truncate text-xs text-muted tabular-nums">{Math.round(c.per100.kcal ?? 0)} kcal/100{unitOf(c)} · {Math.round(c.per100.protein ?? 0)}P{c.why?.length ? ` · ${c.why.join(', ')}` : ''}</span></span>
    </button></li>
  )

  return (
    <Sheet label={`Swap ${card.phrase}`} onClose={onClose} tall z={60}>
      <div className="flex items-center gap-2 px-4 pt-4"><h2 className="flex-1 truncate text-lg font-semibold">Swap “{card.phrase}”</h2><button onClick={onClose} aria-label="Close" className="size-11 rounded-full bg-surface">✕</button></div>
      <div className="min-h-0 flex-1 overflow-y-auto px-4 pb-6 pt-2">
        <div className="flex gap-2" role="group" aria-label="Raw or cooked">
          {(['any', 'raw', 'cooked'] as const).map((s) => (
            <button key={s} aria-pressed={state === s} onClick={() => setState(s)} className={`min-h-10 flex-1 rounded-full text-sm ${state === s ? 'bg-accent/20 text-accent ring-1 ring-accent' : 'bg-surface text-muted'}`}>{s === 'any' ? 'Any' : s === 'raw' ? 'Raw' : 'Cooked'}</button>
          ))}
        </div>
        <h3 className="mt-3 text-sm font-semibold text-muted">Best alternatives</h3>
        {shownAlts.length ? <ul>{shownAlts.map(row)}</ul> : <p className="mt-1 text-sm text-muted">Nothing else close{state !== 'any' ? ` that is ${state}` : ''}. Search below.</p>}
        <input aria-label="Search for another food" value={q} onChange={(e) => setQ(e.target.value)} autoComplete="off" className="mt-4 min-h-12 w-full rounded-xl bg-surface px-4 text-ink outline-none focus:ring-2 focus:ring-accent" />
        {results ? <ul className="mt-1">{results.length ? results.map(row) : <li className="p-3 text-sm text-muted">No match.</li>}</ul> : null}
      </div>
    </Sheet>
  )
}
