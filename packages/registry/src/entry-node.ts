// UNIT: ENTRY-NODE
// FILE: entry-node.ts
// STYLE: none
// PARENT: REGISTRY-INDEX
// USES: DOCUMENT, PROPS, REGISTRY-REGISTRY
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * مدخل سجلّ ⇦ عقدة استوديو حقيقية — المصدر الواحد لكل ما يبني عقدة من مدخل
 * (عقد المرحلة ٣٫٥، قرار C46).
 *
 * **أربعة** مستهلكين يبنون العقدة نفسها، وهم في **تطبيقين**: سطح المعاينة
 * الساكن في المتجر، ومعاينة المتصفّح ومعاينة المحرّر في اللوحة، و«أضف إلى
 * صفحة»/«حفظ كقالب». ولهذا يعيش الباني في الحزمة لا في أحد التطبيقين: نسخةٌ
 * في كلٍّ منهما تعني أنّ ما يراه المشرف في اللوحة وما يصيّره المتجر يفترقان
 * عند أوّل تعديل — وهو بعينه ما جاء هذا الدور ليمنعه. (اكتُشف تنفيذًا: أوّل
 * نسخة من السطح الساكن بنت عقدتها بنفسها فأسقطت الاستعلام، فظهرت كل مجموعة
 * فارغةً بصدق تامّ وسببٍ خاطئ.)
 *
 * ─── الاشتقاق آليّ لا مكتوب ───
 *
 * `props` = **تقاطع** مفاتيح الضوابط مع `PROP_SCHEMAS[component]`، لا خريطة
 * مكتوبة باليد. والسبب أنّ الخريطة المكتوبة تنحرف بصمت: ضابط يُضاف في
 * `controls.ts` أو مفتاح يُضاف في `props.ts` فتبقى الخريطة على حالها ولا شيء
 * يشتكي. أمّا التقاطع فيتبعهما معًا بلا صيانة، و`unreflectedKeys` تُعلن
 * الفرق **محسوبًا** فلا يبقى ضابطٌ يبدو مؤثِّرًا وهو ليس كذلك.
 */
import { PROP_SCHEMAS, defaultPropsFor, type SchemaComponentId } from './props.ts';
import type { StudioDevice, StudioElementNode, StudioQuery } from './document.ts';
import type { RegistryEntry } from './registry.ts';

/** أجهزة الإخفاء — مفاتيح `hide` في الضوابط هي `StudioDevice` نفسها. */
const DEVICES: readonly StudioDevice[] = ['desk', 'tab', 'mob'];

/** مصادر الاستعلام التي يفهمها المتجر فعلًا (StudioQuery). */
const QUERY_SOURCES: ReadonlySet<string> = new Set<StudioQuery['source']>([
  'new',
  'manual',
  'best',
  'featured',
]);

/** مفاتيح الضوابط التي تصل العقدة خارج `props` — تُستثنى من سطر «لا أثر». */
const NON_PROP_KEYS: ReadonlySet<string> = new Set(['source', 'limit', 'manualIds', 'hide']);

/** قيم ضوابط المحرّر كما هي — مفاتيحها تُقاطَع مع المخطّط لا تُفترض. */
export type EntrySettings = Record<string, unknown>;

/** معرّف المخطّط للمدخل، أو null إن لم يكن قسمًا ذا خصائص. */
export function schemaIdFor(entry: RegistryEntry): SchemaComponentId | null {
  return entry.component in PROP_SCHEMAS ? (entry.component as SchemaComponentId) : null;
}

/** هل يُصيَّر هذا المدخل حقيقةً اليوم؟ (نفس بوّابة `renderNode` حرفيًّا). */
export function isRenderable(entry: RegistryEntry): boolean {
  return entry.status === 'exists' && schemaIdFor(entry) !== null;
}

const isRecord = (v: unknown): v is Record<string, unknown> =>
  typeof v === 'object' && v !== null && !Array.isArray(v);

/**
 * عقدة من المدخل، بخصائص السجل الافتراضية معدَّلةً بما يقابلها في الضوابط.
 * يعيد null لمدخل لا يُصيَّر — الغياب أصدق من عقدة تُرسم فراغًا.
 */
export function nodeForEntry(
  entry: RegistryEntry,
  settings: EntrySettings = {},
  nodeId = 'preview',
): StudioElementNode | null {
  const schemaId = schemaIdFor(entry);
  if (schemaId === null || entry.status !== 'exists') return null;

  const props = defaultPropsFor(schemaId);
  for (const control of PROP_SCHEMAS[schemaId]) {
    if (control.key in settings) props[control.key] = settings[control.key];
  }

  const node: StudioElementNode = { id: nodeId, registryId: entry.id, props };

  if (schemaId === 'collection') {
    /*
     * مجموعةٌ بلا استعلام تُصيَّر فارغة — نفس افتراض المحرّر. والمصدر يُؤخذ
     * من الضوابط فقط حين يكون مصدرًا يفهمه المتجر: مفردة `design_components`
     * تحمل `rel` ولا مقابل له في `StudioQuery`، وتمريره كان سيُسقطه الخادم
     * صامتًا فتُعرض مجموعةٌ بمصدرٍ غير الذي يظنّه المشرف.
     */
    const source = settings.source;
    const limit = settings.limit;
    const query: StudioQuery = {
      source:
        typeof source === 'string' && QUERY_SOURCES.has(source)
          ? (source as StudioQuery['source'])
          : 'new',
      limit: typeof limit === 'number' && Number.isInteger(limit) && limit > 0 ? limit : 8,
    };
    const ids = settings.manualIds;
    if (query.source === 'manual' && Array.isArray(ids)) {
      query.ids = ids.filter((id): id is number => Number.isInteger(id) && (id as number) > 0);
    }
    node.query = query;
  }

  const hide = settings.hide;
  if (isRecord(hide)) {
    const hidden: Partial<Record<StudioDevice, boolean>> = {};
    for (const device of DEVICES) if (hide[device] === true) hidden[device] = true;
    if (Object.keys(hidden).length > 0) node.hidden = hidden;
  }

  return node;
}

/**
 * مفاتيح الضوابط التي **لا** تصل العقدة — محسوبة لا مكتوبة. تُعرض للمشرف
 * حرفيًّا: «هذه تُحفظ ولا يظهر أثرها في المعاينة». وضابطٌ جديد بلا مقابل
 * ينضمّ إلى السطر تلقائيًّا يوم يُضاف، لا يوم يتذكّره أحد.
 */
export function unreflectedKeys(entry: RegistryEntry, settings: EntrySettings): string[] {
  const schemaId = schemaIdFor(entry);
  if (schemaId === null) return Object.keys(settings);
  const known = new Set<string>(PROP_SCHEMAS[schemaId].map((c) => c.key));
  return Object.keys(settings).filter((key) => !known.has(key) && !NON_PROP_KEYS.has(key)).sort();
}
