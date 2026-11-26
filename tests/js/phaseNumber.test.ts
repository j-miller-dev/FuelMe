import assert from 'node:assert/strict'
import { test } from 'node:test'
import { phaseNumber } from '../../resources/js/lib/phaseNumber.ts'

test('phase folders are recognised', () => {
  assert.equal(phaseNumber('Rational Intensity P1: Optimized (Tension)'), 1)
  assert.equal(phaseNumber('Rational Intensity P2: Overload'), 2)
  assert.equal(phaseNumber('Rational Intensity P3: Heavy Duty'), 3)
  assert.equal(phaseNumber('Heavy Duty'), 3)
})
test('other folders have no phase', () => {
  for (const t of ['PHASE 1', 'HIT: HOME EDITION', '3 Day Full Body Split', 'Rational Intensity', '', null]) assert.equal(phaseNumber(t), null, String(t))
})
