#!/usr/bin/env python3
"""
خطّ إنتاج رسوم المتجر — من صحائف Freepik إلى ‎apps/storefront/public/art/**‎.

المصدر للقراءة فقط (لا يُكتب فيه شيء): مجلّد «صور للمتجر الالكتوني» على سطح المكتب،
أو ما يشير إليه ‎ART_SRC‎. ملفّات EPS/AI لا تُرسَّم هنا (لا Ghostscript)، فالمصدر النقطيّ
هو معاينات JPG عالية الدقّة (4500–6250px)، والـSVG يُرسَّم بـ‎rsvg-convert‎.

الخطوات لكلّ رسم: قصٌّ بصندوقٍ مسجَّل ← نزع الخلفيّة المسطّحة (غمرٌ من الحافّة، فالأبيض
الداخليّ المقصود يبقى) مع فكّ خلط حوافّ التنعيم ← تشذيب ← حشوٌ إلى نسبة المجموعة ←
تصغير بألفا مضروبة مسبقًا ← WebP بحجمين ‎1x‎ و‎@2x‎ (‎@2x‎ لا يتجاوز الدقّة الأصليّة أبدًا).

الحتميّة: لا عشوائيّة ولا طوابع زمنيّة؛ إعادة التشغيل تُنتج بايتات مطابقة
(‎--check‎ يبني في مجلّدٍ مؤقّت ويقارن).

    python3 scripts/art/build.py            # يبني ويكتب manifest.json
    python3 scripts/art/build.py --check    # يتحقّق أنّ المخرجات الملتزَمة مطابقة
    python3 scripts/art/build.py --debug /tmp/art-debug   # يُظهر الثقوب المغلقة بالأحمر
"""
import hashlib, json, os, re, shutil, subprocess, sys, tempfile
import xml.etree.ElementTree as ET
from collections import deque

import numpy as np
from PIL import Image, ImageDraw

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SRC = os.environ.get('ART_SRC', os.path.expanduser('~/Desktop/صور للمتجر الالكتوني'))
OUT = os.path.join(ROOT, 'apps/storefront/public/art')
MANIFEST = os.path.join(ROOT, 'scripts/art/manifest.json')

WHITE = (255, 255, 255)
BEIGE = (248, 243, 237)  # خلفيّة صحيفة الكتب (‎#f8f3ed‎ مقيسة؛ الموجز ذكر ‎#f7f2eb‎)

SHEETS = {
    'sci': ('science-research-icon-flat/4992.jpg', WHITE, 'macrovector'),
    'chem': ('chemistry-icon-set/7479.jpg', WHITE, 'katemangostar'),
    'elearn': ('e-learning-icon-flat/6705.jpg', WHITE, 'macrovector_official'),
    'lang': ('foreign-language-learning-program-element-collection/32838.jpg', WHITE, 'macrovector'),
    'books': ('collection-books/25422-NWUSCS.jpg', BEIGE, 'freepik'),
    'bench': ('illustration-chemistry-laboratory-instruments-set/60339.jpg', WHITE, 'rawpixel'),
}

# المجموعات: (عرض ‎1x‎، نسبة العرض/الارتفاع، الحشو النسبيّ حول الرسم)
GROUPS = {
    'subjects': (192, 1.0, 0.06),
    'stages': (192, 1.0, 0.06),
    'empty': (300, 1.0, 0.04),
    'people': (240, 0.75, 0.02),
    'hero': (600, None, 0.0),
}
SCALE_2X = 2

# ‎holes‎: ثقوب خلفيّةٍ مغلقة (لم يبلغها الغمر من الحافّة) تُزال أيضًا — تُختار بعد
# معاينة ‎--debug‎، فالأبيض الداخليّ المقصود (صفحات، ياقات، لمعات، زجاج) لا يُمسّ:
#   'all' كلّها · عددٌ صحيح = كلّ ثقبٍ مساحته ≥ هذا (اللمعات أصغر) ·
#   قائمة (fx, fy) = مراكز الثقوب نسبيّةً إلى الصندوق بعد الهامش (كما يطبعها ‎--debug‎).
ITEMS = [
    # ── المواد ──
    dict(group='subjects', name='math', sheet='sci', box=(3574, 1544, 4059, 2037), alt='آلة حاسبة'),
    dict(group='subjects', name='science', sheet='sci', box=(1528, 326, 2029, 985), alt='مجهر'),
    dict(group='subjects', name='chemistry', sheet='chem', box=(505, 3866, 968, 4676), alt='دورق كيمياء بفقاعات'),
    dict(group='subjects', name='physics', sheet='sci', box=(3491, 350, 4142, 1001), alt='ذرّة', holes=700, dark=True),
    dict(group='subjects', name='biology', sheet='sci', box=(602, 3507, 846, 4173), alt='شريط الحمض النوويّ', holes='all', dark=True),
    dict(group='subjects', name='arabic', sheet='books', box=(704, 1273, 965, 1410), alt='كتاب مفتوح'),
    dict(group='subjects', name='english', sheet='lang', box=(3515, 1395, 4385, 1923), alt='سمّاعة بحروف إنجليزيّة', holes=[(0.389, 0.696)]),
    dict(group='subjects', name='social-studies', sheet='sci', box=(1573, 2546, 1983, 3107), alt='مجسّم الكرة الأرضيّة', sphere=True),
    dict(group='subjects', name='computer', sheet='elearn', box=(2460, 1562, 3119, 1952), alt='حاسوب محمول'),
    dict(group='subjects', name='critical-thinking', sheet='sci', box=(2538, 358, 3095, 953), alt='دماغ'),
    # ── المراحل ──
    dict(group='stages', name='kindergarten', sheet='sci', box=(1558, 1423, 2019, 2158), alt='صاروخ'),
    dict(group='stages', name='primary', sheet='lang', box=(263, 98, 942, 1146), alt='طفلٌ يقرأ كتابًا'),
    dict(group='stages', name='middle', sheet='sci', box=(3643, 2507, 4070, 3146), alt='مقراب'),
    dict(group='stages', name='secondary', sheet='sci', box=(2496, 2628, 3237, 3084), alt='قبّعة التخرّج', dark=True),
    # ── الحالات الفارغة ──
    dict(group='empty', name='cart', sheet='sci', box=(1583, 3529, 1993, 4151), alt=''),
    dict(group='empty', name='library', sheet='elearn', box=(3590, 424, 4179, 890), alt=''),
    dict(group='empty', name='wishlist', sheet='sci', box=(362, 1486, 1086, 2035), alt=''),
    dict(group='empty', name='search', sheet='sci', box=(2497, 3612, 3057, 4088), alt=''),
    dict(group='empty', name='orders', sheet='elearn', box=(3520, 3807, 4179, 4197), alt=''),
    dict(group='empty', name='downloads', sheet='elearn', box=(321, 2563, 878, 3134), alt=''),
    dict(group='empty', name='generic', sheet='elearn', box=(2435, 399, 3144, 890), alt=''),
    # ── المعلّمون ──
    dict(group='people', name='teacher-man', sheet='lang', box=(1333, 3358, 2025, 4402), alt='معلّمٌ يكتب'),
    dict(group='people', name='teacher-woman', sheet='lang', box=(2554, 2216, 3161, 3160), alt='معلّمةٌ أمام حاسوبها'),
    # ── أجزاء البطل ──
    dict(group='hero', name='lab-bench', sheet='bench', box=(247, 1085, 4525, 3520), alt='', dark=True,
         holes=[(0.598, 0.347), (0.63, 0.376), (0.565, 0.383), (0.61, 0.374), (0.586, 0.377), (0.598, 0.409),
                (0.623, 0.407), (0.574, 0.412), (0.632, 0.435), (0.567, 0.442), (0.611, 0.442), (0.587, 0.445),
                (0.599, 0.472), (0.697, 0.876)]),
]
MARGIN = 24  # هامشٌ حول الصندوق المسجّل حتّى يتّصل الغمر بالخلفيّة من كلّ الجهات

T_BG = 26    # أقصى بُعد قناةٍ عن لون الخلفيّة ليُعدّ خلفيّةً خالصة (يمتصّ ضجيج JPEG)
BAND = 2     # عرض شريط التنعيم الذي يُفكّ خلطه (px في دقّة المصدر)


def src_path(rel):
    return os.path.join(SRC, rel)


def dist(rgb, bg):
    return np.abs(rgb.astype(np.int16) - np.array(bg, np.int16)).max(axis=2)


def flood(mask, seeds):
    """غمرٌ رباعيّ الاتّصال على قناعٍ منطقيّ من بذورٍ معطاة."""
    h, w = mask.shape
    out = np.zeros_like(mask)
    q = deque()
    for y, x in seeds:
        if 0 <= y < h and 0 <= x < w and mask[y, x] and not out[y, x]:
            out[y, x] = True
            q.append((y, x))
    while q:
        y, x = q.popleft()
        for ny, nx in ((y - 1, x), (y + 1, x), (y, x - 1), (y, x + 1)):
            if 0 <= ny < h and 0 <= nx < w and mask[ny, nx] and not out[ny, nx]:
                out[ny, nx] = True
                q.append((ny, nx))
    return out


def dilate(m, r):
    out = m.copy()
    for _ in range(r):
        p = np.pad(out, 1)
        out = p[1:-1, 1:-1] | p[:-2, 1:-1] | p[2:, 1:-1] | p[1:-1, :-2] | p[1:-1, 2:]
    return out


def enclosed(bgmask, region):
    """مكوّنات الخلفيّة التي لم يبلغها الغمر من الحافّة — للتشخيص."""
    rest = bgmask & ~region
    comps = []
    seen = np.zeros_like(rest)
    ys, xs = np.nonzero(rest)
    for y, x in zip(ys, xs):
        if seen[y, x]:
            continue
        c = flood(rest & ~seen, [(y, x)])
        seen |= c
        n = int(c.sum())
        if n >= 60:
            cy, cx = np.argwhere(c).mean(axis=0)
            comps.append((n, int(cy), int(cx), c))
    return comps


def sphere_cover(rgb):
    """تغطيةٌ منعَّمة لقرص الكرة الأرضيّة: قارّاتها بيضاء تبلغ حافّة القرص بلا خطّ،
    فلا يفصلها لونٌ عن الخلفيّة — القرص يُستنتج من امتداد المحيط الأزرق (أعلى/أسفل/يسار)."""
    r_, g_, b_ = (rgb[..., i].astype(np.int16) for i in range(3))
    blue = (b_ - r_ > 120) & (b_ > 200)
    ys, xs = np.nonzero(blue)
    top, bottom, left = ys.min(), ys.max() + 1, xs.min()
    rad = (bottom - top) / 2
    cy, cx = top + rad, left + rad
    yy, xx = np.mgrid[0:rgb.shape[0], 0:rgb.shape[1]]
    return np.clip(rad - np.hypot(yy + 0.5 - cy, xx + 0.5 - cx) + 0.5, 0, 1)


def key_out(rgb, bg, holes=(), debug=None, sphere=False):
    """يحوّل صورةً على خلفيّةٍ مسطّحة إلى RGBA بحوافّ مفكوكة الخلط."""
    h, w, _ = rgb.shape
    d = dist(rgb, bg)
    bgmask = d <= T_BG
    cover = sphere_cover(rgb) if sphere else None
    if cover is not None:
        bgmask &= cover < 1
    border = [(0, x) for x in range(w)] + [(h - 1, x) for x in range(w)] + \
             [(y, 0) for y in range(h)] + [(y, w - 1) for y in range(h)]
    region = flood(bgmask, border)
    comps = enclosed(bgmask, region) if (holes or debug is not None) else []
    for n, cy, cx, c in comps:
        fx, fy = cx / w, cy / h
        if holes == 'all' or (isinstance(holes, int) and n >= holes) or \
           (isinstance(holes, (list, tuple)) and any(abs(fx - hx) < 0.012 and abs(fy - hy) < 0.012 for hx, hy in holes)):
            region |= c
    if debug is not None:
        vis = rgb.copy()
        vis[region] = (200, 230, 255)
        for n, cy, cx, c in comps:
            vis[c] = (255, 0, 0)
        debug['vis'] = vis
        debug['comps'] = [(n, round(cx / w, 3), round(cy / h, 3)) for n, cy, cx, _ in comps]
    band = dilate(region, BAND) & ~region
    alpha = np.where(region, 0.0, 1.0)
    # لون المقدّمة لكلّ بكسلٍ في الشريط: أبعدُ جارٍ داخليٍّ عن الخلفيّة في نافذة 5×5
    inner = ~(region | band)
    dd = np.where(inner, d, -1).astype(np.int16)
    best = np.full((h, w), -1, np.int16)
    fg = rgb.astype(np.float32).copy()
    R = BAND
    pd = np.pad(dd, R, constant_values=-1)
    prgb = np.pad(rgb, ((R, R), (R, R), (0, 0)), mode='edge').astype(np.float32)
    for oy in range(-R, R + 1):
        for ox in range(-R, R + 1):
            cand = pd[R + oy:R + oy + h, R + ox:R + ox + w]
            better = band & (cand > best)
            best = np.where(better, cand, best)
            fg[better] = prgb[R + oy:R + oy + h, R + ox:R + ox + w][better]
    bgv = np.array(bg, np.float32)
    num = np.abs(rgb.astype(np.float32) - bgv).max(axis=2)
    den = np.maximum(np.abs(fg - bgv).max(axis=2), 1.0)
    a_band = np.clip(num / den, 0.0, 1.0)
    a_band = np.where(best < 0, np.clip(num / 255.0, 0, 1), a_band)
    alpha = np.where(band, a_band, alpha)
    if cover is not None:
        alpha = np.maximum(alpha, cover)
    # فكّ الخلط: C = (P − B(1−a)) / a
    a3 = alpha[..., None]
    col = np.where(a3 > 0.004, (rgb.astype(np.float32) - bgv * (1 - a3)) / np.maximum(a3, 0.004), 0)
    col = np.where(band[..., None], np.clip(col, 0, 255), rgb.astype(np.float32))
    out = np.dstack([col, alpha * 255.0]).round().clip(0, 255).astype(np.uint8)
    return Image.fromarray(out, 'RGBA')


def trim(im, thr=6):
    a = np.asarray(im.getchannel('A'))
    ys, xs = np.nonzero(a > thr)
    return im.crop((int(xs.min()), int(ys.min()), int(xs.max()) + 1, int(ys.max()) + 1))


def recolor_dark(im, maxv, color):
    """نسخةُ الخلفيّات الداكنة: الرماديّ/الأسود المحايد (خطوط الرسم) يُقلب فاتحًا، والألوان كما هي.
    الحوافّ مفكوكة الخلط سلفًا، فاللون يُستبدل ويبقى الألفا — لا هالة."""
    arr = np.asarray(im).astype(np.int16).copy()
    rgb = arr[..., :3]
    sat = rgb.max(axis=2) - rgb.min(axis=2)
    hit = (sat < 24) & (rgb.max(axis=2) < maxv)
    arr[..., :3][hit] = color
    return Image.fromarray(arr.astype(np.uint8), 'RGBA')


ON_DARK_ICON = ((112, (206, 215, 227)))   # ‎#cED7E3‎ تقريبًا — خطوط الأيقونات على ‎#0f1b2e‎
ON_DARK_LINE = ((90, (226, 232, 240)))    # ‎#e2e8f0‎ — خطوط الرسم الخطّيّ (المختبر)


def pad_to(im, ratio, pad):
    """حشوٌ شفّاف إلى نسبةٍ ثابتة (‎ratio = w/h‎) مع هامشٍ نسبيّ، والرسم في الوسط."""
    w, h = im.size
    if ratio is None:
        W, H = round(w * (1 + 2 * pad)), round(h * (1 + 2 * pad))
    else:
        inner_w, inner_h = w * (1 + 2 * pad), h * (1 + 2 * pad)
        if inner_w / inner_h > ratio:
            W, H = round(inner_w), round(inner_w / ratio)
        else:
            W, H = round(inner_h * ratio), round(inner_h)
    canvas = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    canvas.paste(im, ((W - w) // 2, (H - h) // 2))
    return canvas


def resize(im, w):
    h = round(im.height * w / im.width)
    if (w, h) == im.size:
        return im
    return im.convert('RGBa').resize((w, h), Image.LANCZOS).convert('RGBA')


def save_webp(im, path, quality):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    # ‎exact=False‎: لون البكسلات الشفّافة تمامًا يُصفَّر فيصغر الملفّ ولا يتغيّر المرئيّ.
    im.save(path, 'WEBP', quality=quality, method=6, alpha_quality=100, exact=False)


def emit(im, group, name, out_dir, quality=86, width1x=None):
    """يكتب ‎name.webp‎ (‎1x‎) و‎name@2x.webp‎ — ‎@2x‎ محدودٌ بالدقّة الأصليّة."""
    base_w = width1x or GROUPS[group][0]
    w2 = min(base_w * SCALE_2X, im.width)
    w1 = min(base_w, im.width)
    im2, im1 = resize(im, w2), resize(im, w1)
    p2 = os.path.join(out_dir, group, f'{name}@2x.webp')
    p1 = os.path.join(out_dir, group, f'{name}.webp')
    save_webp(im2, p2, quality)
    save_webp(im1, p1, quality)
    return {'1x': [p1, im1.size], '2x': [p2, im2.size], 'native': list(im.size)}


# ── الـSVG: مشهد المختبر ──
SVG_NS = 'http://www.w3.org/2000/svg'


def optimise_svg(text):
    """يحذف مستطيل الخلفيّة والتعليقات والمجموعات الفارغة، ويحوّل ‎style‎ إلى ‎fill‎، ويقرّب الأرقام."""
    ET.register_namespace('', SVG_NS)
    root = ET.fromstring(text)
    tag = lambda e: e.tag.split('}')[-1]

    def walk(parent):
        new = []
        for ch in list(parent):
            if tag(ch) == 'g' and ch.get('id') == 'BG':
                continue
            walk(ch)
            st = ch.attrib.pop('style', None)
            if st:
                for decl in st.split(';'):
                    if ':' in decl:
                        k, v = (s.strip() for s in decl.split(':', 1))
                        if k in ('fill', 'stroke', 'stroke-width', 'opacity', 'fill-rule', 'clip-rule', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit'):
                            ch.set(k, v)
            if 'd' in ch.attrib:
                ch.set('d', re.sub(r'-?\d*\.\d+', lambda m: f'{float(m.group()):.1f}'.rstrip('0').rstrip('.'), ch.get('d')))
            if tag(ch) == 'g' and not ch.attrib:
                new.extend(ch)  # فكّ المجموعة المجرّدة
            elif tag(ch) == 'g' and ch.get('id') and len(ch.attrib) == 1:
                new.extend(ch)
            else:
                new.append(ch)
        for ch in list(parent):
            parent.remove(ch)
        for ch in new:
            ch.tail = None
            ch.text = None if tag(ch) != 'text' else ch.text
            parent.append(ch)

    walk(root)
    for k in ('version', 'x', 'y', 'style', '{http://www.w3.org/XML/1998/namespace}space'):
        root.attrib.pop(k, None)
    return root


def build_svg(out_dir, debug):
    src = open(src_path('3428244_60341.svg'), encoding='utf-8').read()
    root = optimise_svg(src)
    tmp = tempfile.mkdtemp()
    raw = os.path.join(tmp, 'scene.svg')
    ET.ElementTree(root).write(raw, encoding='unicode' if False else 'utf-8', xml_declaration=False)
    # تشذيب ‎viewBox‎ على حدود المحتوى المقيسة من ترسيمٍ بدقّة الوحدة
    probe = os.path.join(tmp, 'probe.png')
    subprocess.run(['rsvg-convert', '-w', '1122', '-h', '1122', raw, '-o', probe], check=True)
    a = np.asarray(Image.open(probe).getchannel('A'))
    ys, xs = np.nonzero(a > 0)
    x0, y0, x1, y1 = int(xs.min()) - 4, int(ys.min()) - 4, int(xs.max()) + 5, int(ys.max()) + 5
    root.set('viewBox', f'{x0} {y0} {x1 - x0} {y1 - y0}')
    root.set('width', str(x1 - x0))
    root.set('height', str(y1 - y0))
    svg_out = os.path.join(out_dir, 'hero', 'lab-scene.svg')
    os.makedirs(os.path.dirname(svg_out), exist_ok=True)
    data = ET.tostring(root, encoding='unicode')
    data = re.sub(r'\s*\n\s*', ' ', data).replace('> <', '><')
    open(svg_out, 'w', encoding='utf-8').write(data)
    # نسخة الخلفيّات الداكنة: خطّ الرسم ‎#272525‎ يصير ‎#e2e8f0‎، والتعبئة البيضاء (زجاج، كرة،
    # إطار) تصير سطحًا داكنًا ‎#1a2942‎ حتّى تبقى الخطوط مقروءة؛ وكلّ لونٍ آخر كما هو.
    svg_dark = os.path.join(out_dir, 'hero', 'lab-scene-on-dark.svg')
    dark = re.sub('#272525', '#E2E8F0', re.sub('#FFFFFF', '#1A2942', data, flags=re.I))
    open(svg_dark, 'w', encoding='utf-8').write(dark)

    def raster(svg_path, stem):
        res = {}
        for label, w in (('1x', 600), ('2x', 1200)):
            p = os.path.join(tmp, f'{stem}-{label}.png')
            subprocess.run(['rsvg-convert', '-w', str(w), svg_path, '-o', p], check=True)
            im = Image.open(p).convert('RGBA')
            path = os.path.join(out_dir, 'hero', stem + ('@2x' if label == '2x' else '') + '.webp')
            save_webp(im, path, 88)
            res[label] = [path, im.size]
        res['svg'] = [svg_path, [x1 - x0, y1 - y0]]
        return res

    res = raster(svg_out, 'lab-scene')
    res['dark'] = raster(svg_dark, 'lab-scene-on-dark')
    res['native'] = ['vector']
    shutil.rmtree(tmp)
    return res


def build_globe(out_dir):
    im = Image.open(src_path('great-earth-globe-hand-drawn-style.png')).convert('RGBA')
    # الشعار «designed by freepik.com» تحت الكرة — يُقصّ؛ والإسناد في CREDITS.
    im = trim(im.crop((0, 0, im.width, 1800)), thr=8)
    return emit(pad_to(im, 1.0, 0.0), 'hero', 'globe', out_dir, quality=86, width1x=480)


def build_mada(out_dir):
    im = trim(Image.open(src_path('Mada_Logo.svg.webp')).convert('RGBA'), thr=4)
    res = {'light': emit(im, 'payment', 'mada', out_dir, quality=92, width1x=120)}
    # نسخة الخلفيّات الداكنة: الحروف الرماديّة الداكنة (‎#27282c‎) تُقلب بيضاء، والأزرق والأخضر كما هما.
    arr = np.asarray(im).astype(np.int16)
    rgb = arr[..., :3]
    sat = rgb.max(axis=2) - rgb.min(axis=2)
    dark = (sat < 24) & (rgb.max(axis=2) < 120)
    arr[..., :3][dark] = 255
    res['dark'] = emit(Image.fromarray(arr.astype(np.uint8), 'RGBA'), 'payment', 'mada-on-dark', out_dir, quality=92, width1x=120)
    return res


def build(out_dir, debug_dir=None):
    manifest = {}
    cache = {}
    for it in ITEMS:
        rel, bg, credit = SHEETS[it['sheet']]
        if rel not in cache:
            cache[rel] = np.asarray(Image.open(src_path(rel)).convert('RGB'))
        sheet = cache[rel]
        x0, y0, x1, y1 = it['box']
        H, W, _ = sheet.shape
        crop = sheet[max(0, y0 - MARGIN):min(H, y1 + MARGIN), max(0, x0 - MARGIN):min(W, x1 + MARGIN)]
        dbg = {} if debug_dir else None
        im = key_out(crop, bg, it.get('holes', ()), dbg, it.get('sphere', False))
        if debug_dir:
            os.makedirs(debug_dir, exist_ok=True)
            v = Image.fromarray(dbg['vis']); v.thumbnail((500, 500))
            v.save(os.path.join(debug_dir, f"{it['group']}-{it['name']}.png"))
            print(it['name'], dbg['comps'])
            continue
        im = trim(im)
        g = it['group']
        _, ratio, pad = GROUPS[g]
        im = pad_to(im, ratio, pad)
        res = emit(im, g, it['name'], out_dir, quality=86)
        if it.get('dark'):
            maxv, color = ON_DARK_LINE if g == 'hero' else ON_DARK_ICON
            res['dark'] = emit(recolor_dark(im, maxv, color), g, it['name'] + '-on-dark', out_dir, quality=86)
        res['credit'] = credit
        res['alt'] = it['alt']
        manifest[f"{g}/{it['name']}"] = res
    if debug_dir:
        return None
    manifest['hero/lab-scene'] = dict(build_svg(out_dir, debug_dir), credit='rawpixel', alt='')
    manifest['hero/globe'] = dict(build_globe(out_dir), credit='freepik', alt='')
    mada = build_mada(out_dir)
    manifest['payment/mada'] = dict(mada['light'], dark=mada['dark'], credit='mada', alt='مدى')
    return manifest


def relpaths(manifest, out_dir):
    def conv(res):
        r = {}
        for k, v in res.items():
            if isinstance(v, dict):
                r[k] = conv(v)
            elif isinstance(v, list) and v and isinstance(v[0], str) and v[0].startswith(out_dir):
                p = v[0]
                r[k] = {'src': '/art/' + os.path.relpath(p, out_dir), 'width': v[1][0], 'height': v[1][1],
                        'bytes': os.path.getsize(p), 'sha256': hashlib.sha256(open(p, 'rb').read()).hexdigest()[:16]}
            else:
                r[k] = v
        return r
    return {key: conv(res) for key, res in manifest.items()}


def total_bytes(node):
    if isinstance(node, dict):
        return (node['bytes'] if 'bytes' in node else 0) + sum(total_bytes(v) for v in node.values() if isinstance(v, dict))
    return 0


def main():
    args = sys.argv[1:]
    if '--debug' in args:
        build(None, args[args.index('--debug') + 1])
        return
    if '--check' in args:
        tmp = tempfile.mkdtemp()
        fresh = relpaths(build(tmp), tmp)
        committed = json.load(open(MANIFEST, encoding='utf-8'))
        shutil.rmtree(tmp)
        bad = [k for k in fresh if fresh[k] != committed.get(k)] + [k for k in committed if k not in fresh]
        if bad:
            print('FAIL: مخرجاتٌ غير مطابقة لإعادة البناء:', ', '.join(sorted(set(bad))))
            sys.exit(1)
        print(f'OK: {len(fresh)} رسمًا — إعادة البناء مطابقة بايتًا ببايت')
        return
    if os.path.isdir(OUT):
        shutil.rmtree(OUT)
    manifest = relpaths(build(OUT), OUT)
    with open(MANIFEST, 'w', encoding='utf-8') as f:
        json.dump(manifest, f, ensure_ascii=False, indent=2, sort_keys=True)
        f.write('\n')
    total = total_bytes(manifest)
    print(f'{len(manifest)} رسمًا · {total} بايت')


if __name__ == '__main__':
    main()
