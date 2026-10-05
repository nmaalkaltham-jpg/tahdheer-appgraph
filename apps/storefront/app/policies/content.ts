/**
 * The five policy documents of «التحاضير الحديثة».
 *
 * PROVENANCE — every sentence below is the verbatim Arabic draft from the
 * design corpus: «تصميم المتجر المستقل /theme-patch/content/pages-ar.html».
 * Nothing here is authored by the storefront.
 *
 * HONESTY DOCTRINE (binding for this file):
 *  · Every `[[ … ]]` marker in the drafts is PRESERVED and rendered visibly as
 *    an unresolved owner decision. Replacing one with a plausible value —
 *    a refund window, a response time, a download cap — would publish a legal
 *    claim the store has never made. The drafts say so themselves.
 *  · The ONLY editorial act is SPLITTING the drafts' combined
 *    «الشروط والترخيص» page across the two routes the route map declares
 *    (/policies/terms · /policies/license). Both keep «ما تحصل عليه» because
 *    each page must stand alone; every sentence is still the draft's own.
 *  · Draft PAGE 5 («التسليم والتنزيل والدعم») is the /how-it-works page, not a
 *    policy, and is deliberately absent here.
 *  · Arabic-Indic numerals inside the drafts (٩٠ يومًا · ٤٨ ساعة) are kept as
 *    written: this is narrative legal prose, not a storefront statistic.
 *  · ONE factual correction to the draft: the privacy «حقوقك» sentence named
 *    WordPress export/erase tooling. This store is Next.js + Laravel, so the
 *    platform name was dropped («أدوات رسمية») rather than published as a
 *    false technical claim. No obligation was added or removed.
 */

/** The five real policy types — mirrors `routes.policy()`. */
type PolicyType = 'refund' | 'privacy' | 'cookies' | 'terms' | 'license';

/** An unresolved owner decision, carried through from the draft's `[[ ]]`. */
interface PlaceholderPart {
  readonly ph: string;
}

/** `<strong>` run inside a sentence. */
interface BoldPart {
  readonly b: string;
}

/** `<code>` run — a Latin technical identifier; rendered inside <bdi dir="ltr">. */
interface CodePart {
  readonly code: string;
}

type Part = string | PlaceholderPart | BoldPart | CodePart;

/** A plain string (scanned for `[[ ]]`) or an explicit run of parts. */
export type Rich = string | readonly Part[];

interface ParagraphBlock {
  readonly kind: 'p';
  readonly text: Rich;
}

interface ListBlock {
  readonly kind: 'ul' | 'ol';
  readonly items: readonly Rich[];
}

interface TableBlock {
  readonly kind: 'table';
  /** Accessible <caption> — the table is scrollable on narrow screens. */
  readonly caption: string;
  readonly head: readonly string[];
  readonly rows: readonly (readonly Rich[])[];
}

/** The draft's `<em>` aside addressed to the store owner. */
interface NoteBlock {
  readonly kind: 'note';
  readonly text: Rich;
}

export type Block = ParagraphBlock | ListBlock | TableBlock | NoteBlock;

interface PolicySection {
  readonly heading: string;
  readonly blocks: readonly Block[];
}

export interface PolicyDoc {
  readonly slug: PolicyType;
  /** Page <h1> and <title>. */
  readonly title: string;
  /** Short label for the policy switcher rail. */
  readonly navLabel: string;
  readonly sections: readonly PolicySection[];
}

const REFUND: PolicyDoc = {
  slug: 'refund',
  title: 'سياسة الاسترجاع',
  navLabel: 'الاسترجاع',
  sections: [
    {
      heading: 'طبيعة المنتجات',
      blocks: [
        {
          kind: 'p',
          text: 'جميع المنتجات في «التحاضير الحديثة» ملفات رقمية تُسلَّم فورًا بعد إتمام الدفع، عبر رابط تنزيل يظهر في صفحة تأكيد الطلب ويبقى متاحًا في حساب العميل.',
        },
      ],
    },
    {
      heading: 'حالات الاسترجاع',
      blocks: [
        { kind: 'p', text: 'لأن الملف يُسلَّم كاملًا ومباشرة، تُطبَّق الحالات التالية:' },
        {
          kind: 'ul',
          items: [
            [
              { b: 'قبل التنزيل:' },
              ' ',
              { ph: 'قرار المالك: هل يُسترجَع المبلغ كاملًا إذا لم يُنزَّل الملف؟ وخلال كم يومًا؟' },
            ],
            [
              { b: 'بعد التنزيل:' },
              ' ',
              { ph: 'قرار المالك: لا استرجاع؟ أم استرجاع في حالات محدّدة فقط؟' },
            ],
            [
              { b: 'خطأ تقني من جانبنا:' },
              ' إذا كان الملف تالفًا أو ناقصًا أو لا يطابق وصفه، يُصلَح الملف أو يُسترجَع المبلغ كاملًا. هذه الحالة لا تخضع لأي قيد زمني قبل إثبات المشكلة.',
            ],
            [{ b: 'شراء مكرّر بالخطأ:' }, ' يُسترجَع مبلغ النسخة المكرّرة كاملًا.'],
          ],
        },
      ],
    },
    {
      heading: 'ما لا يُعدّ سببًا للاسترجاع',
      blocks: [
        {
          kind: 'ul',
          items: [
            'عدم قراءة وصف الملف أو مواصفاته المعلنة في صفحة المنتج قبل الشراء.',
            'شراء ملف لصف أو مادة أو فصل غير المطلوب، مع وضوح ذلك في صفحة المنتج.',
            'الرغبة في تعديل يتجاوز ما يسمح به الترخيص المعلن.',
          ],
        },
      ],
    },
    {
      heading: 'كيف تطلب استرجاعًا',
      blocks: [
        {
          kind: 'p',
          text: [
            'راسلنا عبر ',
            { ph: 'قرار المالك: قناة التواصل — واتساب / بريد / نموذج' },
            ' مرفقًا رقم الطلب ووصفًا للمشكلة. نردّ خلال ',
            { ph: 'قرار المالك: مدّة الردّ' },
            '.',
          ],
        },
      ],
    },
    {
      heading: 'مدّة تنفيذ الاسترجاع',
      blocks: [
        {
          kind: 'p',
          text: 'عند قبول الطلب، يُعاد المبلغ بنفس وسيلة الدفع. مدّة وصوله تتبع سياسة بنك العميل أو بوابة الدفع، وهي خارج سيطرتنا.',
        },
      ],
    },
  ],
};

const PRIVACY: PolicyDoc = {
  slug: 'privacy',
  title: 'سياسة الخصوصية',
  navLabel: 'الخصوصية',
  sections: [
    {
      heading: 'المبدأ الحاكم',
      blocks: [
        {
          kind: 'p',
          text: [
            'لا نجمع عنك أكثر مما يحتاجه تشغيل المتجر. والقاعدة التقنية المطبَّقة في موقعنا: ',
            { b: 'لا يُنشأ معرّف زائر ولا تُكتب أي كوكي قياس قبل موافقتك الصريحة' },
            '.',
          ],
        },
      ],
    },
    {
      heading: 'ما نجمعه ولماذا',
      blocks: [
        {
          kind: 'table',
          caption: 'البيانات التي نجمعها وأغراضها وما يحتاج موافقتك منها',
          head: ['البيانات', 'الغرض', 'تحتاج موافقتك'],
          rows: [
            [
              'الاسم والبريد ووسيلة التواصل',
              'إتمام الطلب وإرسال روابط التنزيل والدعم',
              'لا — ضرورية لتنفيذ الطلب',
            ],
            ['سجلّ طلباتك وتنزيلاتك', 'إعادة التنزيل من حسابك، والدعم، والفاتورة', 'لا — ضرورية'],
            [
              'عنوان الصفحة ونوع الحدث ونوع الجهاز',
              'معرفة أي أقسام المتجر تُستخدم',
              'لا — مجموعة بلا معرّف يربطها بك',
            ],
            ['معرّف زائر وجلسة', 'عدّ الزوّار الفريدين', [{ b: 'نعم' }]],
          ],
        },
      ],
    },
    {
      heading: 'ما لا نفعله',
      blocks: [
        {
          kind: 'ul',
          items: [
            'لا نستخدم أي أداة تتبّع من طرف ثالث في هذا الموقع: لا Google Analytics، ولا Google Tag Manager، ولا بكسل إعلاني من أي منصّة. كل القياس محلّي داخل قاعدة بيانات موقعنا.',
            'لا نبيع بياناتك ولا نشاركها لأغراض تسويقية.',
            'لا نستخدم بصمة الجهاز ولا أي معرّف دائم مخفيّ.',
            'لا نخزّن عنوان IP الخام ولا معرّف المتصفّح الخام في أي حال.',
            'لا نخزّن بيانات بطاقتك — الدفع يتمّ لدى بوابة الدفع مباشرة ولا تمرّ بياناتها بنا.',
          ],
        },
      ],
    },
    {
      heading: 'إشارات المتصفح',
      blocks: [
        {
          kind: 'p',
          text: [
            'نحترم إشارتَي ',
            { code: 'Do Not Track' },
            ' و',
            { code: 'Global Privacy Control' },
            '. عند وصول أيّهما، يتوقّف القياس كليًا، ولا يوجد إعداد في الموقع يستطيع تجاوز ذلك.',
          ],
        },
      ],
    },
    {
      heading: 'مدّة الاحتفاظ',
      blocks: [
        {
          kind: 'p',
          text: 'تفاصيل أحداث القياس تُحذف بعد ‎٩٠ يومًا. الملخّصات المجمَّعة — بلا أي معرّف — تُحفظ مدّة أطول لأغراض المقارنة السنوية. بيانات الطلبات تُحفظ بحسب المدّة النظامية للفواتير.',
        },
      ],
    },
    {
      heading: 'حقوقك',
      blocks: [
        {
          kind: 'p',
          text: [
            'يمكنك طلب نسخة من بياناتك أو حذفها عبر ',
            { ph: 'قرار المالك: قناة التواصل' },
            '. وحساباتنا مبنيّة على أدوات رسمية لتصدير البيانات الشخصية ومسحها، فالطلب يُنفَّذ تقنيًا لا يدويًا.',
          ],
        },
      ],
    },
    {
      heading: 'تغييرات هذه السياسة',
      blocks: [
        {
          kind: 'p',
          text: [
            'عند أي تغيير جوهري في ما نجمعه أو في أغراضه، نُحدّث هذه الصفحة ونطلب موافقتك من جديد. آخر تحديث: ',
            { ph: 'التاريخ' },
            '.',
          ],
        },
      ],
    },
  ],
};

const COOKIES: PolicyDoc = {
  slug: 'cookies',
  title: 'سياسة ملفات تعريف الارتباط',
  navLabel: 'ملفات تعريف الارتباط',
  sections: [
    {
      heading: 'ما نستخدمه فعلًا',
      blocks: [
        {
          kind: 'table',
          caption: 'ملفات تعريف الارتباط المستخدمة وأغراضها ومدّتها وفئتها',
          head: ['الاسم', 'الغرض', 'المدّة', 'الفئة'],
          rows: [
            ['كوكيز تسجيل الدخول', 'إبقاء حسابك مفتوحًا بأمان', 'الجلسة أو حتى الخروج', 'ضرورية'],
            ['كوكي جلسة السلة', 'الاحتفاظ بسلّتك حتى إتمام الشراء', 'حتى ‎٤٨ ساعة', 'ضرورية'],
            ['كوكي اللغة', 'تذكّر لغة الواجهة التي اخترتها', 'سنة', 'ضرورية وظيفيًا'],
            [
              'كوكي الموافقة',
              'تذكّر قرارك في هذه الصفحة حتى لا نسألك كل زيارة',
              'سنة',
              'ضرورية',
            ],
            [
              'كوكي القياس (زائر / جلسة)',
              'عدّ الزوّار الفريدين والجلسات',
              'سنة / ‎٣٠ دقيقة',
              [{ b: 'اختيارية — لا تُكتب قبل موافقتك' }],
            ],
          ],
        },
      ],
    },
    {
      heading: 'الفئات',
      blocks: [
        {
          kind: 'ul',
          items: [
            [
              { b: 'ضرورية:' },
              ' بلا هذه لا يعمل الدخول ولا السلة ولا الدفع ولا التنزيل. رفضها يعني تعطيل الخدمة التي طلبتها، لذلك لا تُطلب موافقة عليها.',
            ],
            [
              { b: 'اختيارية:' },
              ' القياس. رفضها لا يمنعك من إتمام أي عملية شراء، ولا يخفي عنك أي محتوى.',
            ],
          ],
        },
      ],
    },
    {
      heading: 'كيف تغيّر قرارك',
      blocks: [
        {
          kind: 'p',
          text: 'من رابط «إعدادات الخصوصية» أسفل كل صفحة، في أي وقت، بلا حاجة إلى حذف كوكيز المتصفح يدويًا. سحب الموافقة يحذف كوكيز القياس فورًا.',
        },
        {
          kind: 'note',
          text: [
            {
              ph: 'ملاحظة تنفيذية للمالك: هذا الرابط ومركز التفضيلات يجب أن يوجدا فعلًا قبل نشر هذه الصفحة. الوعد بلا مسار أسوأ من عدم الوعد.',
            },
          ],
        },
      ],
    },
  ],
};

/** Draft PAGE 4, part two — the terms of sale, account and liability. */
const TERMS: PolicyDoc = {
  slug: 'terms',
  title: 'الشروط والأحكام',
  navLabel: 'الشروط والأحكام',
  sections: [
    {
      heading: 'ما تحصل عليه',
      blocks: [
        {
          kind: 'p',
          text: [
            'عند شراء أي ملف من «التحاضير الحديثة»، تحصل على ',
            { b: 'ترخيص استخدام' },
            ' لا على ملكية المحتوى. حقوق الملكية الفكرية للملفات تبقى لنا.',
          ],
        },
      ],
    },
    {
      heading: 'الحساب والطلب',
      blocks: [
        {
          kind: 'ul',
          items: [
            'أنت مسؤول عن صحّة بيانات التواصل التي تُدخلها — رابط التنزيل والدعم يعتمدان عليها.',
            'أنت مسؤول عن حماية بيانات دخول حسابك.',
            'لا يُعدّ أي طلب مؤكَّدًا قبل تأكيد الدفع من بوابة الدفع. أي رسالة نجاح في المتصفّح وحدها لا تكفي.',
          ],
        },
      ],
    },
    {
      heading: 'التنزيل',
      blocks: [
        {
          kind: 'p',
          text: [
            'رابط التنزيل مرتبط بحسابك وطلبك. ',
            {
              ph: 'قرار المالك: هل هناك حدّ لعدد مرّات التنزيل أو مدّة صلاحية الرابط؟ اذكر الرقم أو انصّ على أنه غير محدود.',
            },
          ],
        },
      ],
    },
    {
      heading: 'حدود المسؤولية',
      blocks: [
        {
          kind: 'p',
          text: 'الملفات مواد مساندة لعمل المعلّم، ولا تُغني عن المرجع الرسمي للمنهج. نبذل جهدًا في مطابقتها لآخر توزيع معلن، ونصحّح أي خطأ يُبلَّغ عنه.',
        },
      ],
    },
    {
      heading: 'القانون المطبَّق',
      blocks: [
        { kind: 'p', text: 'تخضع هذه الشروط لأنظمة المملكة العربية السعودية.' },
      ],
    },
  ],
};

/** Draft PAGE 4, part one — the licence grant itself. */
const LICENSE: PolicyDoc = {
  slug: 'license',
  title: 'اتفاقية الترخيص',
  navLabel: 'الترخيص',
  sections: [
    {
      heading: 'ما تحصل عليه',
      blocks: [
        {
          kind: 'p',
          text: [
            'عند شراء أي ملف من «التحاضير الحديثة»، تحصل على ',
            { b: 'ترخيص استخدام' },
            ' لا على ملكية المحتوى. حقوق الملكية الفكرية للملفات تبقى لنا.',
          ],
        },
      ],
    },
    {
      heading: 'الترخيص الفردي — الافتراضي في كل ملف',
      blocks: [
        { kind: 'p', text: [{ b: 'يسمح لك بـ:' }] },
        {
          kind: 'ul',
          items: [
            'استخدام الملف في عملك التعليمي الخاص.',
            'تعديل الملف: إضافة اسمك ومدرستك وفصلك، وتعديل ما يناسب طلابك.',
            'طبع الملف بعدد النسخ التي يحتاجها عملك مع فصولك.',
            'رفع الملف على منصّة مدرستك في نطاق فصولك أنت.',
          ],
        },
        { kind: 'p', text: [{ b: 'لا يسمح لك بـ:' }] },
        {
          kind: 'ul',
          items: [
            'مشاركة الملف أو رابط تنزيله مع معلّم آخر أو في مجموعة عامّة.',
            'إعادة بيعه أو توزيعه بأي مقابل.',
            'نشره على أي موقع أو قناة عامّة.',
            'إزالة ما يدلّ على مصدره من الملف.',
          ],
        },
        {
          kind: 'p',
          text: [
            'للاستخدام على مستوى مدرسة أو إدارة تعليمية، يوجد ترخيص أوسع: ',
            { ph: 'قرار المالك: رابط صفحة ترخيص المدرسة وسعره' },
            '.',
          ],
        },
      ],
    },
  ],
};

/** Nav order = the order the switcher rail renders. */
export const POLICIES: readonly PolicyDoc[] = [REFUND, PRIVACY, COOKIES, TERMS, LICENSE];


export function findPolicy(type: string): PolicyDoc | undefined {
  return POLICIES.find((p) => p.slug === type);
}

/** Count the unresolved `[[ ]]` owner decisions in a document — derived, never written. */
export function countPending(doc: PolicyDoc): number {
  const inRich = (rich: Rich): number => {
    if (typeof rich === 'string') return (rich.match(/\[\[/g) ?? []).length;
    return rich.filter((part) => typeof part === 'object' && 'ph' in part).length;
  };
  let total = 0;
  for (const section of doc.sections) {
    for (const block of section.blocks) {
      if (block.kind === 'p' || block.kind === 'note') total += inRich(block.text);
      else if (block.kind === 'table') {
        for (const row of block.rows) for (const cell of row) total += inRich(cell);
      } else {
        for (const item of block.items) total += inRich(item);
      }
    }
  }
  return total;
}
