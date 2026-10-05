'use client';
// UNIT: SEARCH-EXPLAIN-PANEL
// FILE: explain-panel.tsx
// STYLE: explain.module.css
// PARENT: PAGE-SEARCH-EXPLAIN
// USES: ADMIN-SESSION, LIB-ADMIN-API
// API: /admin/search/explain
// MAP: docs/PROJECT_MAP.md

/**
 * شرحُ ترتيبٍ وقع فعلًا — لا نموذجُ ترتيبٍ نتخيّله.
 *
 * كلّ رقمٍ في هذه الشاشة قياسٌ من الخادم: الدرجة تخرج من دالّة الترتيب
 * نفسها، وأثرُ كلّ حقلٍ وكلّ كلمةٍ يُقاس باستئصالها وإعادة التشغيل. فلا
 * تحسبُ الواجهة شيئًا ولا تُفسّر شيئًا — تعرض ما قيس.
 *
 * والأبعاد الغائبة معروضةٌ كغياب لا محذوفة: مصحّحٌ يُظهر ما يقيسه ويسكت عمّا
 * لا يقيسه يُعلّم المشغّل أنّ النظام يزن أشياء لا يزنها — وهو خطأٌ يقرّر على
 * أساسه لاحقًا.
 */

import { useCallback, useState, type FormEvent } from 'react';
import { api, firstError } from '../../../../lib/admin-api';
import styles from './explain.module.css';

interface Tier {
  name: string;
  value: number;
  label: string;
}

interface FieldEffect {
  unit: string;
  parent: string | null;
  label: string;
  columns: string[];
  text: string;
  normalized: string;
  scoreWithout: number;
  delta: number;
  decisive: boolean;
}

interface TokenEffect {
  token: string;
  source: 'query' | 'synonym';
  scoreWithout: number | null;
  delta: number | null;
  note?: string;
}

interface ExplainRow {
  rank: number;
  id: number;
  sku: string;
  title: string;
  publishedAt: string | null;
  score: number | null;
  tier: Tier | null;
  aboveTierFloor: number | null;
  decisiveUnit: string | null;
  unattributed: boolean;
  fieldEffects: FieldEffect[];
  tokenEffects: TokenEffect[];
  tiersNotReached: Tier[];
  reason?: string;
}

interface NotFactor {
  key: string;
  label: string;
  status: string;
  detail: string;
}

interface ExplainPayload {
  data: {
    query: {
      raw: string;
      normalized: string;
      lexical: string;
      tokens: string[];
      expandedTokens: string[];
      filtersExtracted: Record<string, string>;
      relaxed: string[];
    };
    provider: { configured: string; serving: string; explainable: boolean; reason: string | null };
    tiers: Tier[];
    sort: string;
    total: number;
    shown: number;
    results: ExplainRow[];
    notFactors: NotFactor[];
  };
}

const SORTS: { value: string; label: string }[] = [
  { value: 'relevance', label: 'الصلة' },
  { value: 'bestselling', label: 'الأكثر مبيعًا' },
  { value: 'newest', label: 'الأحدث' },
  { value: 'price_asc', label: 'السعر تصاعديًّا' },
  { value: 'price_desc', label: 'السعر تنازليًّا' },
];

/** أسماء أبعاد التصنيف كما يسمّيها المستخرِج. */
const FILTERS: Record<string, string> = {
  stage: 'المرحلة',
  grade: 'الصف',
  subject: 'المادة',
  semester: 'الفصل',
  kind: 'النوع',
  format: 'الصيغة',
  nature: 'الطبيعة',
  // التعافي يُصدر مفتاحين لا يسمّيان بُعدًا: تصحيحٌ إملائيّ، وكلمةٌ محذوفة.
  typo: 'الإملاء',
};

/** `token:<كلمة>` يُعرض بالكلمة نفسها؛ وما عداه يُسمّى من الخريطة. */
function relaxedLabel(key: string): string {
  return key.startsWith('token:') ? `كلمة «${key.slice('token:'.length)}»` : (FILTERS[key] ?? key);
}

/*
 * حالة البُعد الغائب ⇒ رمز لون دلاليّ.
 *
 * «مُعلَن بلا مُغذٍّ» تحذيرٌ لا خطر: الإعداد موجود والنتيجة صفر، وهو فخٌّ
 * يُخطئ فيه من يقرأ الإعداد ويظنّه عاملًا. أمّا «غائبٌ تمامًا» فمعلومة لا
 * عطل — ولذلك ‎info‎ لا ‎warning‎: تلوينُ الغياب تحذيرًا يدفع إلى «إصلاحه».
 */
const STATUS: Record<string, { label: string; tone: 'info' | 'warning' | 'success' }> = {
  absent: { label: 'غائب تمامًا', tone: 'info' },
  declared_unfed: { label: 'مُعلَن بلا مُغذٍّ', tone: 'warning' },
  recorded_unfed: { label: 'مُسجَّل ولا يُغذّي', tone: 'warning' },
  not_in_relevance: { label: 'خارج الصلة', tone: 'info' },
  active_as_sort: { label: 'فاعل — كفرزٍ لا كصلة', tone: 'success' },
};

export function SearchExplainPanel() {
  const [draft, setDraft] = useState('');
  const [sort, setSort] = useState('relevance');
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<{ message: string; code: number } | null>(null);
  const [payload, setPayload] = useState<ExplainPayload['data'] | null>(null);

  const submit = useCallback(
    async (event: FormEvent) => {
      event.preventDefault();
      const q = draft.trim();
      if (q === '') return;

      setBusy(true);
      setFailure(null);

      const result = await api.get<ExplainPayload>(
        `/admin/search/explain?q=${encodeURIComponent(q)}&sort=${encodeURIComponent(sort)}&limit=10`,
      );
      setBusy(false);

      if (!result.ok) {
        setFailure({ message: firstError(result), code: result.status });
        setPayload(null);

        return;
      }

      setPayload(result.data.data);
    },
    [draft, sort],
  );

  return (
    <section className={styles.root}>
      <p className="state-line">
        اكتب استعلامًا كما يكتبه الزائر. الشاشة تعرض الترتيب الحقيقيّ الذي يردّه البحث، ودرجةَ كلّ نتيجة
        كما تحسبها دالّة الترتيب نفسها، وأثرَ كلّ حقلٍ وكلّ كلمةٍ مقيسًا بإزالتها وإعادة الحساب.
      </p>

      <form className={styles.form} onSubmit={(event) => void submit(event)}>
        <label className="field field-wide">
          <span>الاستعلام</span>
          <input
            type="search"
            name="q"
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            placeholder="مثال: أوراق عمل الرياضيات"
            autoComplete="off"
          />
        </label>

        <label className="field">
          <span>الفرز</span>
          <select value={sort} onChange={(event) => setSort(event.target.value)}>
            {SORTS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </label>

        <button type="submit" className="btn btn-primary" disabled={busy || draft.trim() === ''}>
          {busy ? 'يفحص…' : 'افحص الترتيب'}
        </button>
      </form>

      {/*
        الحرمان يُقرأ برمزه: ‎٤٠٣‎ ليس عطلًا يُعاد عليه، والصلاحية تُسمّى كما
        يسمّيها الخادم ليطلبها الدور من مالك اللوحة.
      */}
      {failure ? (
        <div className="state-error" role="alert">
          {failure.code === 403 ? (
            <p>
              لا تملك صلاحية <bdi dir="ltr">analytics.read</bdi> لقراءة مصحّح الصلة.
            </p>
          ) : (
            <p>{failure.message}</p>
          )}
        </div>
      ) : null}

      {payload ? <Report payload={payload} /> : null}
    </section>
  );
}

function Report({ payload }: { payload: ExplainPayload['data'] }) {
  const { query, provider, tiers, results, notFactors } = payload;
  const filters = Object.entries(query.filtersExtracted);

  return (
    <>
      <section className={styles.block} aria-labelledby="explain-query">
        <h2 id="explain-query" className={styles.heading}>
          ما وصل إلى المطابقة
        </h2>

        <dl className={styles.facts}>
          <div>
            <dt>كما كُتب</dt>
            <dd>
              <bdi>{query.raw}</bdi>
            </dd>
          </div>
          <div>
            <dt>بعد التطبيع</dt>
            <dd>
              <bdi>{query.normalized}</bdi>
            </dd>
          </div>
          <div>
            <dt>الباقي المعجميّ</dt>
            <dd>
              {query.lexical === '' ? (
                <span className={styles.none}>فارغ — استُهلكت كلّ الكلمات في مرشِّحات</span>
              ) : (
                <bdi>{query.lexical}</bdi>
              )}
            </dd>
          </div>
          <div>
            <dt>الكلمات المقارَنة</dt>
            <dd>
              {query.tokens.length === 0 ? (
                <span className={styles.none}>لا كلمة</span>
              ) : (
                <ul className={styles.chips}>
                  {query.tokens.map((token) => (
                    <li key={token} className={styles.chip}>
                      <bdi>{token}</bdi>
                      {query.expandedTokens.includes(token) ? <span className={styles.chipTag}>مرادف</span> : null}
                    </li>
                  ))}
                </ul>
              )}
            </dd>
          </div>
          <div>
            <dt>مرشِّحات استُخرجت من النصّ</dt>
            <dd>
              {filters.length === 0 ? (
                <span className={styles.none}>لا مرشِّح</span>
              ) : (
                <ul className={styles.chips}>
                  {filters.map(([key, value]) => (
                    <li key={key} className={styles.chip}>
                      {FILTERS[key] ?? key}: <bdi>{value}</bdi>
                    </li>
                  ))}
                </ul>
              )}
            </dd>
          </div>
          <div>
            <dt>المسار الخادم</dt>
            <dd>
              <bdi dir="ltr">{provider.configured}</bdi>
              {provider.explainable ? null : <span className={styles.warn}> — {provider.reason}</span>}
            </dd>
          </div>
        </dl>

        {query.relaxed.length > 0 ? (
          <p className={styles.warn} role="note">
            خُفِّف الطلب بإسقاط: {query.relaxed.map(relaxedLabel).join('، ')} — فالنتائج أوسع من
            الاستعلام الحرفيّ.
          </p>
        ) : null}

        <p className="state-line">
          {payload.total} نتيجة إجمالًا، معروضٌ منها {payload.shown} بالترتيب الحقيقيّ.
        </p>
      </section>

      <details className={styles.block}>
        <summary className={styles.summary}>الطبقات وأوزانها — مقروءةً من دالّة الترتيب وقت الطلب</summary>
        <div className="table-scroll" tabIndex={0} role="region" aria-label="أوزان الطبقات">
          <table className="data-table">
            <caption className="sr-only">أوزان طبقات الترتيب</caption>
            <thead>
              <tr>
                <th scope="col">الوزن</th>
                <th scope="col">الثابت</th>
                <th scope="col">ما يعنيه</th>
              </tr>
            </thead>
            <tbody>
              {tiers.map((tier) => (
                <tr key={tier.name}>
                  <td>{tier.value}</td>
                  <td>
                    <bdi dir="ltr">{tier.name}</bdi>
                  </td>
                  <td>{tier.label}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </details>

      <section aria-labelledby="explain-results">
        <h2 id="explain-results" className={styles.heading}>
          النتائج بترتيبها
        </h2>

        {results.length === 0 ? (
          <p className="state-line">لا نتيجة لهذا الاستعلام — فلا ترتيب يُشرَح.</p>
        ) : (
          <ol className={styles.results}>
            {results.map((row) => (
              <li key={row.id}>
                <ResultCard row={row} />
              </li>
            ))}
          </ol>
        )}
      </section>

      <section className={styles.block} aria-labelledby="explain-absent">
        <h2 id="explain-absent" className={styles.heading}>
          ما لا يدخل هذا الترتيب
        </h2>
        <p className="state-line">
          أبعادٌ يظنّها كثيرون عاملةً وهي ليست كذلك. تُذكر هنا صراحةً لأنّ مصحّحًا يُخفي بُعدًا غائبًا يُعلّم
          نموذجًا خاطئًا عن المحرّك.
        </p>

        <ul className={styles.absent}>
          {notFactors.map((factor) => {
            const status = STATUS[factor.status] ?? { label: factor.status, tone: 'info' as const };

            return (
              <li key={factor.key} className={styles[`tone-${status.tone}`]}>
                <p className={styles.absentHead}>
                  <strong>{factor.label}</strong>
                  <span className={styles.badge}>{status.label}</span>
                </p>
                <p className={styles.absentBody}>{factor.detail}</p>
              </li>
            );
          })}
        </ul>
      </section>
    </>
  );
}

function ResultCard({ row }: { row: ExplainRow }) {
  /*
   * «الكلمات التي رفعت الدرجة» = ما هبطت الدرجة بإزالته. وليست «ما ورد في
   * العنوان»: كلمةٌ ترد في العنوان وترد معها أخرى تكفي وحدها أثرُها صفر،
   * وعدّها رافعةً يجعل المشغّل يحسّن كلمةً لا تفعل شيئًا.
   */
  const lifting = row.tokenEffects.filter((effect) => (effect.delta ?? 0) > 0);
  const units = row.fieldEffects.filter((effect) => effect.parent === null);
  const nested = row.fieldEffects.filter((effect) => effect.parent !== null);

  return (
    <article className={styles.card}>
      <header className={styles.cardHead}>
        <p className={styles.rank} aria-label={`الترتيب ${row.rank}`}>
          #{row.rank}
        </p>
        <div className={styles.cardTitle}>
          <h3>
            <bdi>{row.title}</bdi>
          </h3>
          <p className={styles.sku}>
            <bdi dir="ltr">{row.sku}</bdi>
          </p>
        </div>
        <p className={styles.score}>
          {row.score === null ? (
            <span className={styles.none}>بلا درجة</span>
          ) : (
            <>
              <strong>{row.score}</strong>
              <span>درجة</span>
            </>
          )}
        </p>
      </header>

      {row.score === null ? (
        <p className={styles.warn} role="note">
          {row.reason}
        </p>
      ) : (
        <>
          <p className={styles.tier}>
            الطبقة: <strong>{row.tier ? row.tier.label : 'غير معروفة'}</strong>
            {row.tier ? (
              <>
                {' '}
                (<bdi dir="ltr">{row.tier.name}</bdi> = {row.tier.value}
                {row.aboveTierFloor ? <> + {row.aboveTierFloor} مكافأة تغطية</> : null})
              </>
            ) : null}
          </p>

          {row.unattributed ? (
            <p className={styles.danger} role="alert">
              لم يُعثر على حقلٍ تُسقط إزالتُه الدرجة تحت أرضيّة طبقتها — وحدات القياس لم تعد تصف ما تقرؤه دالّة
              الترتيب. لا تُبنَ قراءةٌ على هذا الصفّ حتّى يُراجَع.
            </p>
          ) : null}

          <p className={styles.tokens}>
            الكلمات التي رفعت الدرجة:{' '}
            {lifting.length === 0 ? (
              <span className={styles.none}>لا كلمة بمفردها — انظر جدول الحقول</span>
            ) : (
              lifting.map((effect) => (
                <span key={effect.token} className={styles.chip}>
                  <bdi>{effect.token}</bdi>
                  <span className={styles.chipTag}>−{effect.delta}</span>
                </span>
              ))
            )}
          </p>

          <div className="table-scroll" tabIndex={0} role="region" aria-label={`أثر الحقول في ${row.sku}`}>
            <table className="data-table">
              <caption className="sr-only">أثر كلّ حقل، مقيسًا بإزالته</caption>
              <thead>
                <tr>
                  <th scope="col">الحقل</th>
                  <th scope="col">نصّه بعد التطبيع</th>
                  <th scope="col">الدرجة بدونه</th>
                  <th scope="col">الأثر</th>
                </tr>
              </thead>
              <tbody>
                {units.map((effect) => (
                  <tr key={effect.unit} className={effect.decisive ? styles.decisiveRow : undefined}>
                    <th scope="row">
                      {effect.label}
                      {effect.decisive ? <span className={styles.badgeOk}>حاسم</span> : null}
                    </th>
                    <td className={styles.norm}>
                      {effect.normalized === '' ? <span className={styles.none}>فارغ</span> : <bdi>{effect.normalized}</bdi>}
                    </td>
                    <td>{effect.scoreWithout}</td>
                    <td>
                      {effect.delta === 0 ? (
                        <span className={styles.none}>لا أثر</span>
                      ) : (
                        <strong>−{effect.delta}</strong>
                      )}
                    </td>
                  </tr>
                ))}
                {nested.map((effect) => (
                  <tr key={effect.unit} className={styles.nested}>
                    <th scope="row">↳ {effect.label}</th>
                    <td className={styles.norm}>
                      <bdi>{effect.normalized}</bdi>
                    </td>
                    <td>{effect.scoreWithout}</td>
                    <td>
                      {effect.delta === 0 ? (
                        <span className={styles.none}>لا أثر</span>
                      ) : (
                        <strong>−{effect.delta}</strong>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <details>
            <summary className={styles.summary}>أثر كلّ كلمة على حدة</summary>
            <div className="table-scroll" tabIndex={0} role="region" aria-label={`أثر الكلمات في ${row.sku}`}>
              <table className="data-table">
                <caption className="sr-only">أثر كلّ كلمة، مقيسًا بإزالتها</caption>
                <thead>
                  <tr>
                    <th scope="col">الكلمة</th>
                    <th scope="col">مصدرها</th>
                    <th scope="col">الدرجة بدونها</th>
                    <th scope="col">الأثر</th>
                  </tr>
                </thead>
                <tbody>
                  {row.tokenEffects.map((effect) => (
                    <tr key={effect.token}>
                      <th scope="row">
                        <bdi>{effect.token}</bdi>
                      </th>
                      <td>{effect.source === 'synonym' ? 'مرادف مُضاف' : 'من الاستعلام'}</td>
                      <td>{effect.scoreWithout === null ? <span className={styles.none}>لا يُقاس</span> : effect.scoreWithout}</td>
                      <td>
                        {effect.note ? (
                          <span className={styles.none}>{effect.note}</span>
                        ) : effect.delta === 0 ? (
                          <span className={styles.none}>لا أثر</span>
                        ) : (
                          <strong>−{effect.delta}</strong>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </details>

          {row.tiersNotReached.length > 0 ? (
            <p className={styles.notReached}>
              طبقاتٌ أعلى لم تُبلَغ:{' '}
              {row.tiersNotReached.map((tier) => (
                <span key={tier.name} className={styles.chip}>
                  {tier.label} ({tier.value})
                </span>
              ))}
            </p>
          ) : null}
        </>
      )}
    </article>
  );
}
