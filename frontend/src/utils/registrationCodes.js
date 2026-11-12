const GENDER_CODES = new Set(['M', 'F', 'N', 'P'])
const AGE_CODES = new Set(['K', 'Y', 'A', 'E'])

const codeFromChoice = (value, allowed) => {
  const text = String(value ?? '').trim()
  if (!text) return ''

  const prefixed = text.match(/^([a-z])\s*:/i)?.[1]?.toUpperCase()
  if (prefixed && allowed.has(prefixed)) return prefixed

  const direct = text.toUpperCase()
  return allowed.has(direct) ? direct : ''
}

export const genderCode = (value) => {
  for (const item of Array.isArray(value) ? value : [value]) {
    const text = String(item ?? '').trim()
    const code = codeFromChoice(text, GENDER_CODES)
    if (code) return code

    const normalized = text.toLowerCase().replace(/[^a-z]/g, '')
    if (normalized.includes('prefernottosay')) return 'P'
    if (normalized.includes('nonbinary')) return 'N'
    if (normalized.includes('female')) return 'F'
    if (normalized.includes('male')) return 'M'
  }
  return ''
}

export const ageCode = (value) => {
  const text = String(value ?? '').trim()
  if (!text) return ''

  const code = codeFromChoice(text, AGE_CODES)
  if (code) return code

  if (/^\d+(?:\.\d+)?$/.test(text)) {
    const age = Number(text)
    if (Number.isFinite(age)) return age < 18 ? 'K' : age < 30 ? 'Y' : age <= 60 ? 'A' : 'E'
  }

  const normalized = text.toLowerCase().replace(/[^a-z0-9]/g, '')
  if (/^60\s*\+$/.test(text)) return 'E'
  if (normalized.includes('under18') || normalized.includes('below18')) return 'K'
  if (normalized.includes('1829')) return 'Y'
  if (normalized.includes('3060')) return 'A'
  if (normalized.includes('above60') || normalized.includes('over60') || normalized.includes('60plus')) return 'E'
  return ''
}
