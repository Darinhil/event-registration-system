const WIDTH = 720
const PADDING = 48
const QR_MAX = 520
const NAME_LINE = 46
const NAME_MAX_LINES = 3

// Khmer names may have no spaces, so an over-long word breaks between whole characters, never inside one.
const characters = (text) => [...new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(text)].map((part) => part.segment)

const wrapText = (ctx, text, maxWidth) => {
  const lines = []
  let line = ''
  const push = (piece, joiner) => {
    const next = line ? `${line}${joiner}${piece}` : piece
    if (ctx.measureText(next).width <= maxWidth) { line = next; return true }
    return false
  }
  for (const word of text.trim().split(/\s+/)) {
    if (push(word, ' ')) continue
    if (line) { lines.push(line); line = '' }
    if (push(word, '')) continue
    for (const character of characters(word)) {
      if (!push(character, '')) { lines.push(line); line = character }
    }
  }
  if (line) lines.push(line)
  if (lines.length <= NAME_MAX_LINES) return lines
  const kept = lines.slice(0, NAME_MAX_LINES)
  let last = characters(kept[NAME_MAX_LINES - 1])
  while (last.length && ctx.measureText(`${last.join('')}…`).width > maxWidth) last = last.slice(0, -1)
  kept[NAME_MAX_LINES - 1] = `${last.join('')}…`
  return kept
}

/** The downloadable pass: event name on top, QR code in the middle, registration ID below. */
export const buildPassImage = (qrCanvas, eventName, code) => {
  const pass = document.createElement('canvas')
  const ctx = pass.getContext('2d')
  const family = getComputedStyle(document.body).fontFamily || 'sans-serif'
  const nameFont = `700 34px ${family}`
  const codeFont = '700 34px ui-monospace, Consolas, monospace'

  ctx.font = nameFont
  const nameLines = eventName ? wrapText(ctx, String(eventName), WIDTH - PADDING * 2) : []
  // A whole-number scale keeps every QR module sharp, which is what scanners depend on.
  const scale = Math.max(Math.floor(QR_MAX / qrCanvas.width), 1)
  const qrSize = qrCanvas.width * scale
  const nameHeight = nameLines.length * NAME_LINE
  pass.width = Math.max(WIDTH, qrSize + PADDING * 2)
  pass.height = PADDING + nameHeight + (nameLines.length ? 28 : 0) + qrSize + 28 + 40 + PADDING

  ctx.fillStyle = '#fff'
  ctx.fillRect(0, 0, pass.width, pass.height)
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'

  ctx.font = nameFont
  ctx.fillStyle = '#0f1c2e'
  nameLines.forEach((line, index) => ctx.fillText(line, pass.width / 2, PADDING + NAME_LINE * index + NAME_LINE / 2))

  const qrTop = PADDING + nameHeight + (nameLines.length ? 28 : 0)
  ctx.imageSmoothingEnabled = false
  ctx.drawImage(qrCanvas, (pass.width - qrSize) / 2, qrTop, qrSize, qrSize)

  ctx.font = codeFont
  ctx.fillStyle = '#17365f'
  ctx.fillText(String(code), pass.width / 2, qrTop + qrSize + 28 + 20)
  return pass
}
