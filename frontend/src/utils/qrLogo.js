import logoUrl from '../assets/logoQR.png'

/** Logo diameter as a share of the QR width. With its white ring it covers about 9% of the code, inside what level H repairs (30%). */
const LOGO_RATIO = 0.29
/** White ring around the logo, as a share of the QR width, so no module touches it. */
const RING_RATIO = 0.025

let logo = null
let logoPromise = null

/** Loads the centre logo once for every QR on the page. */
export const loadQrLogo = () => {
  logoPromise ||= new Promise((resolve, reject) => {
    const image = new Image()
    image.onload = () => { logo = image; resolve(image) }
    image.onerror = () => { logoPromise = null; reject(new Error('QR logo failed to load')) }
    image.src = logoUrl
  })
  return logoPromise
}

/**
 * Draws the logo on a clean white circle in the exact centre of a QR drawn at (x, y, size) on `canvas`.
 * Returns false (and draws nothing) when the logo has not loaded.
 */
export const drawQrLogo = (canvas, x = 0, y = 0, size = canvas.width) => {
  if (!logo) return false
  const ctx = canvas.getContext('2d')
  const cx = x + size / 2
  const cy = y + size / 2
  const diameter = size * LOGO_RATIO
  ctx.save()
  ctx.setTransform(1, 0, 0, 1, 0, 0)
  ctx.fillStyle = '#fff'
  ctx.beginPath()
  ctx.arc(cx, cy, diameter / 2 + size * RING_RATIO, 0, Math.PI * 2)
  ctx.fill()
  // The artwork is a round emblem on a square; clip it so its corners never cover modules.
  ctx.beginPath()
  ctx.arc(cx, cy, diameter / 2, 0, Math.PI * 2)
  ctx.clip()
  ctx.imageSmoothingEnabled = true
  ctx.imageSmoothingQuality = 'high'
  ctx.drawImage(logo, cx - diameter / 2, cy - diameter / 2, diameter, diameter)
  ctx.restore()
  return true
}

let readerPromise = null
// ZXing ships inside html5-qrcode (the check-in desk scanner); loading it on demand keeps it out of the main bundle.
const loadReader = () => {
  readerPromise ||= import('html5-qrcode/third_party/zxing-js.umd').then((module) => {
    const ZXing = module.QRCodeReader ? module : module.default
    const formats = [ZXing.DecodeHintType.POSSIBLE_FORMATS, [ZXing.BarcodeFormat.QR_CODE]]
    const scan = new Map([formats])
    const pure = new Map([formats, [ZXing.DecodeHintType.PURE_BARCODE, true]])
    return (canvas, { exact = false } = {}) => {
      const bitmap = new ZXing.BinaryBitmap(new ZXing.HybridBinarizer(new ZXing.HTMLCanvasElementLuminanceSource(canvas)))
      try { return new ZXing.QRCodeReader().decode(bitmap, exact ? pure : scan).getText() } catch { return null }
    }
  }).catch((error) => { readerPromise = null; throw error })
  return readerPromise
}

/**
 * Samples the QR the way a scanner does — once at the centre of every module, so the logo counts as
 * damaged modules — and redraws it on a whole-pixel grid with a quiet zone, so the result does not
 * depend on screen density.
 */
const sampleGrid = (image) => {
  const { width, data } = image
  const dark = (x, y) => {
    const i = (Math.floor(y) * width + Math.floor(x)) * 4
    return data[i] * 0.299 + data[i + 1] * 0.587 + data[i + 2] * 0.114 < 128
  }
  // The QR is drawn without a margin, so its first row starts with the 7-module-wide finder pattern.
  let finder = 0
  while (finder < width && dark(finder, 1)) finder++
  if (!finder) return null
  const modules = 17 + 4 * Math.max(1, Math.round((width / (finder / 7) - 17) / 4)) // sizes are 21, 25, 29…
  const step = width / modules
  const unit = 4
  const canvas = document.createElement('canvas')
  canvas.width = canvas.height = (modules + 8) * unit // 4-module quiet zone
  const ctx = canvas.getContext('2d', { willReadFrequently: true })
  ctx.fillStyle = '#fff'
  ctx.fillRect(0, 0, canvas.width, canvas.height)
  ctx.fillStyle = '#000'
  for (let row = 0; row < modules; row++) {
    for (let col = 0; col < modules; col++) {
      if (dark((col + 0.5) * step, (row + 0.5) * step)) ctx.fillRect((col + 4) * unit, (row + 4) * unit, unit, unit)
    }
  }
  return canvas
}

/**
 * Adds the centre logo to a rendered QR, then tests it before it is shown. The logo stays only if
 *  - every module still decodes back exactly to `expected` (error correction absorbs the logo), and
 *  - a camera-style scan reads it whenever it reads the plain code. (A few codes trip ZXing's finder
 *    detector even without a logo; the logo must never be what breaks a scan.)
 * Otherwise the plain QR is restored. Returns whether the logo was kept.
 */
export const addVerifiedLogo = async (qrCanvas, expected) => {
  const read = await loadReader()
  const ctx = qrCanvas.getContext('2d')
  const size = qrCanvas.width
  const plain = ctx.getImageData(0, 0, size, size)
  let ok = false
  try {
    if (!drawQrLogo(qrCanvas)) return false
    const withLogo = sampleGrid(ctx.getImageData(0, 0, size, size))
    const plainGrid = sampleGrid(plain)
    ok = Boolean(withLogo && plainGrid)
      && read(withLogo, { exact: true }) === expected
      && (read(withLogo) === expected || read(plainGrid) !== expected)
    return ok
  } finally {
    if (!ok) ctx.putImageData(plain, 0, 0)
  }
}
