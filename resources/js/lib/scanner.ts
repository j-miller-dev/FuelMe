import { gtinValid } from '@/lib/gtin'

/** One barcode reader, two engines: the browser's native BarcodeDetector where it exists, the zxing WASM build everywhere else (iOS Safari). */
export interface Scanner {
  engine: 'native' | 'wasm'
  /** Returns a valid GTIN seen in the current video frame, or null. */
  detect: (video: HTMLVideoElement) => Promise<string | null>
}

interface NativeDetector { detect: (src: CanvasImageSource) => Promise<{ rawValue: string }[]> }
interface NativeCtor { new (o: { formats: string[] }): NativeDetector; getSupportedFormats?: () => Promise<string[]> }

const NATIVE_FORMATS = ['ean_13', 'ean_8', 'upc_a']
const WASM_FORMATS = ['EAN-13', 'EAN-8', 'UPC-A'] as const

async function nativeScanner(): Promise<Scanner | null> {
  const Ctor = (globalThis as unknown as { BarcodeDetector?: NativeCtor }).BarcodeDetector
  if (!Ctor) return null
  try {
    const supported = (await Ctor.getSupportedFormats?.()) ?? NATIVE_FORMATS
    const formats = NATIVE_FORMATS.filter((f) => supported.includes(f))
    if (!formats.includes('ean_13')) return null   // some desktop builds expose the class but cannot read retail codes
    const det = new Ctor({ formats })
    return {
      engine: 'native',
      detect: async (video) => (await det.detect(video)).map((r) => r.rawValue).find(gtinValid) ?? null,
    }
  } catch { return null }
}

async function wasmScanner(): Promise<Scanner> {
  // Loaded on demand so the ~1 MB decoder is only downloaded by browsers that need it, and served from this origin (no CDN).
  const [{ readBarcodes, prepareZXingModule }, wasm] = await Promise.all([import('zxing-wasm/reader'), import('zxing-wasm/reader/zxing_reader.wasm?url')])
  const url = wasm.default
  await prepareZXingModule({ overrides: { locateFile: (path: string, prefix: string) => (path.endsWith('.wasm') ? url : prefix + path) }, fireImmediately: true })
  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d', { willReadFrequently: true })
  return {
    engine: 'wasm',
    detect: async (video) => {
      if (!ctx || !video.videoWidth) return null
      const w = Math.min(video.videoWidth, 960)
      canvas.width = w
      canvas.height = Math.round((video.videoHeight / video.videoWidth) * w)
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height)
      const res = await readBarcodes(ctx.getImageData(0, 0, canvas.width, canvas.height), { formats: [...WASM_FORMATS], tryHarder: true, maxNumberOfSymbols: 2 })
      return res.map((r) => r.text).find(gtinValid) ?? null
    },
  }
}

export async function createScanner(): Promise<Scanner> {
  return (await nativeScanner()) ?? (await wasmScanner())
}
