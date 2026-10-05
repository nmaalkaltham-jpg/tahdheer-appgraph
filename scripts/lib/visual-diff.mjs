/**
 * مقارنةُ لقطتين بكسلًا بكسلًا — بلا pixelmatch ولا pngjs (غير مثبّتين)؛
 * `sharp` وحده يفكّ الترميز ويعيد الترميز.
 *
 * ─── عتبتان لا واحدة ───
 *
 * النسبةُ الكلّيّة وحدها تكذب: قسمٌ انزاح ‎40px‎ في صفحةٍ طولها ‎6000px‎ يغيّر
 * أقلّ من ‎0.5%‎ من البكسلات، فيمرّ. لذا تُقطَّع الصورة إلى أشرطةٍ أفقيّة ‎64px‎
 * ويُقاس كلّ شريطٍ وحده: أيُّ شريطٍ تجاوزت بكسلاتُه المختلفة ‎`bandPct`‎ من
 * مساحته يُسقط اللقطة ولو كانت النسبة الكلّيّة صفرًا تقريبًا.
 *
 * والصورتان غيرُ المتساويتين حجمًا تُقارَنان على المنطقة المشتركة، وما زاد
 * يُعدّ مختلفًا كلُّه (طولٌ اختلف = قسمٌ ظهر أو اختفى).
 */
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const sharp = require('sharp');

export const DEFAULTS = Object.freeze({
  /** الحدّ الأقصى للنسبة الكلّيّة (٪). */
  maxDiffPct: 0.5,
  /** الحدّ الأقصى لنسبة البكسلات المختلفة داخل أيّ شريط ‎64px‎ (٪). */
  bandPct: 1.0,
  bandHeight: 64,
  /** المسافة اللونيّة (٠..١ من أقصى مسافة RGB) التي يُعدّ البكسل بعدها مختلفًا. */
  colorThreshold: 0.1,
  /** العرض الأقصى للخطّ الأساس المخزون (يُصغَّر إليه قبل الحفظ). */
  maxWidth: 720,
});

/** PNG صغير: تصغيرٌ إلى ‎maxWidth‎ ثمّ لوحةُ ألوانٍ مكمَّمة. */
export async function shrinkPng(pngBuffer, maxWidth = DEFAULTS.maxWidth) {
  const meta = await sharp(pngBuffer).metadata();
  let img = sharp(pngBuffer);
  if (meta.width > maxWidth) img = img.resize({ width: maxWidth, kernel: 'lanczos3' });
  /* ‎dither: 0‎: الانتشار يضخّم فرقًا بقيمة ‎1‎ في المدخل إلى نقشٍ مختلف على
     مساحةٍ واسعة (قيس: ‎20%‎ من بكسلات لقطةٍ واحدة بين تشغيلين بفرقٍ ‎<4‎). */
  return img.png({ palette: true, colours: 128, dither: 0, compressionLevel: 9, effort: 10 }).toBuffer();
}

export async function decode(pngBuffer) {
  const { data, info } = await sharp(pngBuffer).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
  return { data, w: info.width, h: info.height };
}

/**
 * يقارن صورتين خامّتين ‎{data,w,h}‎ (RGBA). يعيد الأرقام وصورةَ الفرق (RGBA
 * خامّة) بحجم الاتّحاد: نسخةٌ باهتة من ‎A‎ والمختلفُ أحمر.
 */
export function diffRaw(A, B, opts = {}) {
  const o = { ...DEFAULTS, ...opts };
  const W = Math.max(A.w, B.w);
  const H = Math.max(A.h, B.h);
  const out = Buffer.alloc(W * H * 4);
  const maxDist = Math.sqrt(3 * 255 * 255);
  const bandCount = Math.ceil(H / o.bandHeight);
  const bandDiff = new Array(bandCount).fill(0);
  let diff = 0;

  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      const oi = (y * W + x) * 4;
      const inA = x < A.w && y < A.h;
      const inB = x < B.w && y < B.h;
      let differs = true;
      if (inA && inB) {
        const ia = (y * A.w + x) * 4;
        const ib = (y * B.w + x) * 4;
        const d = Math.hypot(A.data[ia] - B.data[ib], A.data[ia + 1] - B.data[ib + 1], A.data[ia + 2] - B.data[ib + 2]) / maxDist;
        differs = d > o.colorThreshold;
      }
      if (inA) {
        const ia = (y * A.w + x) * 4;
        out[oi] = 255 - ((255 - A.data[ia]) >> 2);
        out[oi + 1] = 255 - ((255 - A.data[ia + 1]) >> 2);
        out[oi + 2] = 255 - ((255 - A.data[ia + 2]) >> 2);
      } else {
        out[oi] = out[oi + 1] = out[oi + 2] = 235;
      }
      out[oi + 3] = 255;
      if (differs) {
        diff++;
        bandDiff[Math.floor(y / o.bandHeight)]++;
        out[oi] = 220; out[oi + 1] = 30; out[oi + 2] = 30;
      }
    }
  }

  const total = W * H;
  const bands = bandDiff.map((n, i) => {
    const rows = Math.min(o.bandHeight, H - i * o.bandHeight);
    return { band: i, y: i * o.bandHeight, pct: +((n / (rows * W)) * 100).toFixed(3) };
  });
  const worst = bands.reduce((m, b) => (b.pct > m.pct ? b : m), { band: -1, y: 0, pct: 0 });
  const diffPct = +((diff / total) * 100).toFixed(3);
  const sizeMismatch = A.w !== B.w || A.h !== B.h;
  return {
    diffPct,
    worstBand: worst,
    sizeMismatch,
    a: { w: A.w, h: A.h },
    b: { w: B.w, h: B.h },
    ok: diffPct <= o.maxDiffPct && worst.pct <= o.bandPct,
    image: { data: out, w: W, h: H },
  };
}

/** يقارن ملفّي PNG (مخازن) ويعيد النتيجة مع مخزن PNG لصورة الفرق. */
export async function diffPng(aPng, bPng, opts = {}) {
  const [A, B] = await Promise.all([decode(aPng), decode(bPng)]);
  const r = diffRaw(A, B, opts);
  const { image, ...rest } = r;
  const diffPngBuffer = await sharp(image.data, { raw: { width: image.w, height: image.h, channels: 4 } }).png().toBuffer();
  return { ...rest, diffPng: diffPngBuffer };
}
