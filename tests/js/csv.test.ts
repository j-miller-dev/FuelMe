import assert from 'node:assert/strict'
import { test } from 'node:test'
import { guessDateOrder, parseCsv, toIsoDate, toNumber } from '../../resources/js/lib/csv.ts'

test('reads quoted fields, embedded commas, line breaks in quotes, doubled quotes, BOM and CRLF', () => {
  const rows = parseCsv('﻿Date,Note,Kg\r\n2026-10-01,"Felt good, ate well",74.2\r\n2026-10-02,"He said ""fine""\nok",74.0\r\n')
  assert.deepEqual(rows, [['Date', 'Note', 'Kg'], ['2026-10-01', 'Felt good, ate well', '74.2'], ['2026-10-02', 'He said "fine"\nok', '74.0']])
})

test('skips blank lines but keeps rows with empty cells', () => {
  assert.deepEqual(parseCsv('a,b\n\n1,\n,\n\n'), [['a', 'b'], ['1', ''], ['', '']])
})

test('dates: ISO, day-first and month-first, and refuses impossible dates', () => {
  assert.equal(toIsoDate('2026-10-01', 'iso'), '2026-10-01')
  assert.equal(toIsoDate('2026-10-01 07:30:00', 'iso'), '2026-10-01')
  assert.equal(toIsoDate('01/10/2026', 'dmy'), '2026-10-01')
  assert.equal(toIsoDate('01/10/2026', 'mdy'), '2026-01-10')
  assert.equal(toIsoDate('3.4.26', 'dmy'), '2026-04-03')
  assert.equal(toIsoDate('31/02/2026', 'dmy'), null)
  assert.equal(toIsoDate('13/13/2026', 'mdy'), null)
  assert.equal(toIsoDate('hello', 'iso'), null)
})

test('guesses the date order only when the data decides it', () => {
  assert.equal(guessDateOrder(['2026-10-01', '2026-10-02']), 'iso')
  assert.equal(guessDateOrder(['13/10/2026', '01/10/2026']), 'dmy')
  assert.equal(guessDateOrder(['10/13/2026', '10/01/2026']), 'mdy')
  assert.equal(guessDateOrder(['01/02/2026', '03/04/2026']), null)   // ambiguous: the person chooses
  assert.equal(guessDateOrder([]), null)
})

test('numbers: comma decimals and a trailing unit are fine, junk is not', () => {
  assert.equal(toNumber('74,5'), 74.5)
  assert.equal(toNumber(' 74.5 kg '), 74.5)
  assert.equal(toNumber('2,850 kcal'), null)   // a thousands separator is ambiguous, so it is refused rather than guessed
  assert.equal(toNumber('2850 kcal'), 2850)
  assert.equal(toNumber(''), null)
  assert.equal(toNumber('abc'), null)
})
