/** Mirrors App\Domain\Foods\Gtin::valid. A camera misread almost always fails the check digit, so we never look one up. */
export const cleanCode = (raw: string): string => raw.replace(/\D/g, '')

export function gtinValid(code: string): boolean {
  const n = code.length
  if (![8, 12, 13, 14].includes(n) || !/^\d+$/.test(code)) return false
  let sum = 0
  for (let i = 0; i < n - 1; i++) sum += Number(code[n - 2 - i]) * (i % 2 === 0 ? 3 : 1)
  return (10 - (sum % 10)) % 10 === Number(code[n - 1])
}
