'use client';

// UNIT: PAGE-ANALYTICS-OS-ACTIONS
// FILE: page.tsx
// ROUTE: /analytics/os/actions
// STYLE: os.module.css
// PARENT: none
// API-VIA: ADMIN-SESSION, FEEDS, LIB-ACTIONS

import Link from 'next/link';
import {
  useMemo,
  useState,
} from 'react';

import {
  firstError,
  type ApiResult,
} from '../../../../../lib/admin-api';

import {
  count,
  dateTime,
} from '../../../../../lib/format';

import {
  useSession,
} from '../../../../../components/admin-session';

import {
  DEMAND_STATUS_LABELS,
} from '../../../demand/_vocabulary';

import styles from '../os.module.css';

import {
  OsIcon,
} from '../_components/os-kit';

import {
  LEVEL_LOCK_REASON,
  levelAllowsExecute,
  useOsLevel,
} from '../_lib/level';

import {
  OS_AUDIT_ACTIONS,
  ready,
  useActionFeeds,
} from '../_lib/feeds';

import {
  createSynonym,
  deriveOsActions,
  reindexSearch,
  rollbackSearchIndex,
  runCrawl,
  setDemandStatus,
  stopSynonym,
  type BlockedCard,
  type OsActionCard,
} from '../_lib/actions';


/* -------------------------------------------------------------------------- */
/* Configuration                                                              */
/* -------------------------------------------------------------------------- */

const MIN_CRAWL_PAGES = 1;

const MAX_CRAWL_PAGES = 2_000;

const DEFAULT_CRAWL_PAGES = 400;


/**
 * review هنا مستوى مخاطرة فقط، وليس طلب مراجعة يدوية.
 */
const RISK_LABELS = {
  safe: 'منخفض',
  review: 'متوسط',
  high: 'مرتفع',
} as const;


const RISK_CHIP = {
  safe: styles.chipSafe,
  review: styles.chipReview,
  high: styles.chipHigh,
} as const;


const RISK_EDGE = {
  safe: styles.edgeSafe,
  review: styles.edgeReview,
  high: styles.edgeHigh,
} as const;


const PERMISSION_LABELS: Record<string, string> = {
  'search.synonyms.manage':
    'إدارة مرادفات البحث',

  'search.index.manage':
    'إدارة فهرس البحث',

  'products.create':
    'إنشاء المنتجات',
};


/* -------------------------------------------------------------------------- */
/* Types                                                                      */
/* -------------------------------------------------------------------------- */

type DemandChoice =
  | 'planned'
  | 'declined';


type OperationKind =
  | 'execute'
  | 'rollback';


interface PendingOperation {
  cardId: string;
  kind: OperationKind;
}


interface CardRun {
  ok: boolean;

  message: string;

  createdSynonymId?: number;

  rolledBack?: boolean;

  executedAt?: number;
}


/* -------------------------------------------------------------------------- */
/* Helpers                                                                    */
/* -------------------------------------------------------------------------- */

function clampInteger(
  value: number,
  minimum: number,
  maximum: number,
): number {
  if (!Number.isFinite(value)) {
    return minimum;
  }

  return Math.min(
    maximum,
    Math.max(
      minimum,
      Math.round(value),
    ),
  );
}


function apiMessage(
  result: ApiResult<unknown>,
): string {
  if (!result.ok) {
    return firstError(result);
  }

  const data =
    result.data as {
      message?: unknown;
    } | null;

  if (
    typeof data?.message === 'string'
    && data.message.trim() !== ''
  ) {
    return data.message.trim();
  }

  return 'تم التنفيذ بنجاح.';
}


function unexpectedMessage(): string {
  return 'تعذر إكمال العملية بسبب خطأ غير متوقع. لم تُعتبر العملية ناجحة.';
}


/**
 * لا توجد مراجعة/موافقة يدوية.
 *
 * true هنا تعني أن بوابة approval الخاصة بالواجهة
 * محسومة تلقائيًا.
 *
 * ما يزال levelAllowsExecute يقرر هل مستوى التشغيل
 * نفسه يسمح بالكتابة.
 */
function levelAllowsDirectExecution(
  level: Parameters<
    typeof levelAllowsExecute
  >[0],

  risk: Parameters<
    typeof levelAllowsExecute
  >[1],
): boolean {
  return levelAllowsExecute(
    level,
    risk,
    true,
  );
}


/* -------------------------------------------------------------------------- */
/* Page                                                                       */
/* -------------------------------------------------------------------------- */

export default function OsActionsPage() {
  const feeds =
    useActionFeeds();

  const {
    level,
  } = useOsLevel();

  const {
    can,
  } = useSession();


  const [
    dryOpen,
    setDryOpen,
  ] = useState<
    ReadonlySet<string>
  >(
    new Set(),
  );


  const [
    synonymInput,
    setSynonymInput,
  ] = useState<
    Record<string, string>
  >({});


  const [
    demandChoice,
    setDemandChoice,
  ] = useState<
    Record<string, DemandChoice>
  >({});


  const [
    crawlPages,
    setCrawlPages,
  ] = useState(
    DEFAULT_CRAWL_PAGES,
  );


  /**
   * عملية كتابة واحدة في اللحظة نفسها.
   *
   * يمنع:
   * - double click
   * - تنفيذ وتراجع متداخلين
   * - تنفيذ بطاقتين إداريتين بالتوازي من الصفحة نفسها
   */
  const [
    pending,
    setPending,
  ] = useState<
    PendingOperation | null
  >(null);


  const [
    runs,
    setRuns,
  ] = useState<
    Record<string, CardRun>
  >({});


  const derived = useMemo(
    () =>
      deriveOsActions({
        queries:
          ready(
            feeds.queries.state,
          ),

        synonyms:
          ready(
            feeds.synonyms.state,
          )?.data ?? null,

        crawl:
          ready(
            feeds.crawl.state,
          ),

        index:
          ready(
            feeds.index.state,
          ),

        demand:
          ready(
            feeds.demand.state,
          )?.data ?? null,
      }),

    [
      feeds.queries.state,
      feeds.synonyms.state,
      feeds.crawl.state,
      feeds.index.state,
      feeds.demand.state,
    ],
  );


  const sourceStates = [
    feeds.queries,
    feeds.synonyms,
    feeds.crawl,
    feeds.index,
    feeds.demand,
  ];


  const loading =
    sourceStates.some(
      (feed) =>
        feed.state.status
        === 'loading',
    );


  const problem =
    sourceStates.find(
      (feed) =>
        feed.state.status
        === 'error',
    );


  const globallyBusy =
    pending !== null;


  /* ------------------------------------------------------------------------ */
  /* Payload                                                                   */
  /* ------------------------------------------------------------------------ */

  const payloadFor = (
    card: OsActionCard,
  ): Record<string, unknown> => {
    switch (card.kind) {
      case 'synonym':
        return {
          term:
            card.term ?? '',

          synonym:
            synonymInput[
              card.id
            ]?.trim() ?? '',

          direction:
            'one_way',
        };


      case 'crawl':
        return {
          pages:
            clampInteger(
              crawlPages,
              MIN_CRAWL_PAGES,
              MAX_CRAWL_PAGES,
            ),
        };


      case 'reindex':
        return {};


      case 'demand':
        return {
          status:
            demandChoice[
            card.id
            ] ?? 'planned',
        };
    }
  };


  const hasMissingInput = (
    card: OsActionCard,
  ): boolean => {
    if (
      card.needsInput
      === 'synonym'
    ) {
      return (
        synonymInput[
          card.id
        ]?.trim() ?? ''
      ) === '';
    }

    return false;
  };


  /* ------------------------------------------------------------------------ */
  /* State helpers                                                             */
  /* ------------------------------------------------------------------------ */

  const record = (
    id: string,
    run: CardRun,
  ): void => {
    setRuns(
      (current) => ({
        ...current,
        [id]: run,
      }),
    );
  };


  const toggleDryRun = (
    id: string,
  ): void => {
    setDryOpen(
      (current) => {
        const next =
          new Set(current);

        if (next.has(id)) {
          next.delete(id);
        } else {
          next.add(id);
        }

        return next;
      },
    );
  };


  /* ------------------------------------------------------------------------ */
  /* Execution                                                                 */
  /* ------------------------------------------------------------------------ */

  const execute = async (
    card: OsActionCard,
  ): Promise<void> => {
    /*
     * Guard مزدوج:
     *
     * disabled في JSX للواجهة.
     * وهذه الشروط داخل handler ضد stale event / fast interaction.
     */
    if (pending !== null) {
      return;
    }


    if (!can(card.permission)) {
      record(
        card.id,
        {
          ok: false,

          message:
            `لا تملك صلاحية «${PERMISSION_LABELS[
            card.permission
            ]
            ?? card.permission
            }».`,
        },
      );

      return;
    }


    if (
      !levelAllowsDirectExecution(
        level,
        card.risk,
      )
    ) {
      record(
        card.id,
        {
          ok: false,
          message:
            LEVEL_LOCK_REASON,
        },
      );

      return;
    }


    if (
      hasMissingInput(card)
    ) {
      record(
        card.id,
        {
          ok: false,

          message:
            'أكمل المدخل المطلوب قبل التنفيذ.',
        },
      );

      return;
    }


    setPending({
      cardId:
        card.id,

      kind:
        'execute',
    });


    try {
      let result:
        ApiResult<unknown>;

      let createdSynonymId:
        number | undefined;


      switch (card.kind) {
        case 'synonym': {
          const target =
            synonymInput[
              card.id
            ]?.trim() ?? '';

          const created =
            await createSynonym(
              card.term ?? '',
              target,
            );

          result =
            created;

          if (created.ok) {
            createdSynonymId =
              created.data.data.id;
          }

          break;
        }


        case 'crawl':
          result =
            await runCrawl(
              clampInteger(
                crawlPages,
                MIN_CRAWL_PAGES,
                MAX_CRAWL_PAGES,
              ),
            );

          break;


        case 'reindex':
          result =
            await reindexSearch();

          break;


        case 'demand':
          result =
            await setDemandStatus(
              card.demandId ?? 0,

              demandChoice[
              card.id
              ] ?? 'planned',
            );

          break;
      }


      if (!result.ok) {
        record(
          card.id,
          {
            ok: false,

            message:
              firstError(
                result,
              ),
          },
        );

        return;
      }


      record(
        card.id,
        {
          ok: true,

          message:
            apiMessage(
              result,
            ),

          createdSynonymId,

          executedAt:
            Date.now(),
        },
      );


      /*
       * نحدث audit فقط.
       *
       * لا نعيد تحميل derived feeds فورًا حتى لا تختفي
       * البطاقة قبل أن يستطيع المستخدم تنفيذ rollback.
       */
      feeds.audit.reload();
    } catch (error) {
      console.error(
        'analytics.os.execute_failed',
        {
          cardId:
            card.id,

          kind:
            card.kind,

          error,
        },
      );


      record(
        card.id,
        {
          ok: false,

          message:
            unexpectedMessage(),
        },
      );
    } finally {
      setPending(null);
    }
  };


  /* ------------------------------------------------------------------------ */
  /* Rollback                                                                  */
  /* ------------------------------------------------------------------------ */

  const rollback = async (
    card: OsActionCard,
  ): Promise<void> => {
    if (pending !== null) {
      return;
    }


    const previous =
      runs[card.id];


    if (
      previous?.ok !== true
      || previous.rolledBack === true
    ) {
      return;
    }


    if (!can(card.permission)) {
      record(
        card.id,
        {
          ...previous,

          message:
            `تعذر التراجع: لا تملك صلاحية «${PERMISSION_LABELS[
            card.permission
            ]
            ?? card.permission
            }».`,
        },
      );

      return;
    }


    if (
      !levelAllowsDirectExecution(
        level,
        card.risk,
      )
    ) {
      record(
        card.id,
        {
          ...previous,

          message:
            `تعذر التراجع: ${LEVEL_LOCK_REASON}`,
        },
      );

      return;
    }


    setPending({
      cardId:
        card.id,

      kind:
        'rollback',
    });


    try {
      let result:
        ApiResult<unknown>
        | null = null;


      switch (card.kind) {
        case 'synonym':
          result =
            previous.createdSynonymId
              === undefined

              ? null

              : await stopSynonym(
                previous.createdSynonymId,
              );

          break;


        case 'reindex':
          result =
            await rollbackSearchIndex();

          break;


        case 'demand':
          result =
            await setDemandStatus(
              card.demandId ?? 0,
              'new',
            );

          break;


        case 'crawl':
          result = null;

          break;
      }


      if (result === null) {
        record(
          card.id,
          {
            ...previous,

            message:
              'لا توجد عملية تراجع آلية لهذا الإجراء.',
          },
        );

        return;
      }


      if (!result.ok) {
        record(
          card.id,
          {
            ...previous,

            message:
              `فشل التراجع: ${firstError(result)
              }`,
          },
        );

        return;
      }


      record(
        card.id,
        {
          ...previous,

          ok: true,

          rolledBack: true,

          message:
            'تم التراجع بنجاح واستُعيدت الحالة السابقة.',
        },
      );


      feeds.audit.reload();
    } catch (error) {
      console.error(
        'analytics.os.rollback_failed',
        {
          cardId:
            card.id,

          kind:
            card.kind,

          error,
        },
      );


      record(
        card.id,
        {
          ...previous,

          message:
            `فشل التراجع: ${unexpectedMessage()
            }`,
        },
      );
    } finally {
      setPending(null);
    }
  };


  /* ------------------------------------------------------------------------ */
  /* UI                                                                        */
  /* ------------------------------------------------------------------------ */

  return (
    <div
      className={
        `${styles.screen} ${styles.fade}`
      }
      aria-busy={
        globallyBusy
      }
    >
      <div className={styles.headRow}>
        <div>
          <h1 className={styles.pageH1}>
            مركز الإجراءات
          </h1>

          <span className={styles.subNote}>
            إجراءات تشغيلية مباشرة مبنية على بيانات فعلية — معاينة اختيارية، تنفيذ، تحقق وتراجع.
          </span>
        </div>

        <WeekChip
          feeds={feeds}
        />
      </div>


      {problem?.state.status === 'error' ? (
        <div
          className="state-error"
          role="alert"
        >
          <p>
            {problem.state.message}
          </p>

          <button
            type="button"
            className="btn btn-ghost"
            disabled={
              globallyBusy
            }
            onClick={() => {
              for (
                const feed
                of sourceStates
              ) {
                feed.reload();
              }
            }}
          >
            إعادة المحاولة
          </button>
        </div>
      ) : null}


      {loading ? (
        <p
          className="state-line"
          role="status"
          aria-live="polite"
        >
          جارٍ تحديث مركز الإجراءات…
        </p>
      ) : null}


      <div className={styles.list}>
        {!loading
          && derived.real.length === 0 ? (
          <div className={styles.absentCard}>
            <b className={styles.absentTitle}>
              لا توجد إجراءات مطلوبة الآن
            </b>

            <p className={styles.absentWhy}>
              جميع المصادر المقروءة حاليًا مستقرة ولم تنتج إجراءً قابلًا للتنفيذ.
            </p>
          </div>
        ) : null}


        {derived.real.map(
          (card) => {
            const run =
              runs[card.id];


            const done =
              run?.ok === true
              && run.rolledBack !== true;


            const allowedByRole =
              can(
                card.permission,
              );


            /*
             * لا موافقة يدوية.
             *
             * approval=true بصورة تلقائية،
             * بينما قواعد level نفسها تبقى محترمة.
             */
            const allowedByLevel =
              levelAllowsDirectExecution(
                level,
                card.risk,
              );


            const missingInput =
              hasMissingInput(
                card,
              );


            const isOpen =
              dryOpen.has(
                card.id,
              );


            const payload =
              payloadFor(
                card,
              );


            const isExecuting =
              pending?.cardId
              === card.id
              && pending.kind
              === 'execute';


            const isRollingBack =
              pending?.cardId
              === card.id
              && pending.kind
              === 'rollback';


            const lockWhy =
              !allowedByRole

                ? `لا صلاحية «${PERMISSION_LABELS[
                card.permission
                ]
                ?? card.permission
                }» في دورك`

                : !allowedByLevel

                  ? LEVEL_LOCK_REASON

                  : missingInput

                    ? 'أكمل المدخل المطلوب'

                    : globallyBusy

                      ? 'توجد عملية أخرى قيد التنفيذ'

                      : null;


            const dryId =
              `dry-${card.id}`;


            return (
              <section
                key={card.id}

                className={
                  `${styles.action} ${RISK_EDGE[
                  card.risk
                  ]
                  } ${styles.fade}`
                }

                aria-busy={
                  isExecuting
                  || isRollingBack
                }
              >
                {/* ---------------------------------------------------------- */}
                {/* Header                                                     */}
                {/* ---------------------------------------------------------- */}

                <div className={styles.headRow}>
                  <b className={styles.actionTitle}>
                    {card.title}
                  </b>


                  <span
                    className={
                      `${styles.chip} ${RISK_CHIP[
                      card.risk
                      ]
                      }`
                    }
                  >
                    مخاطرة{' '}
                    {
                      RISK_LABELS[
                      card.risk
                      ]
                    }
                  </span>


                  <span
                    className={
                      `${styles.chip} ${styles.chipMuted}`
                    }
                  >
                    تنفيذ مباشر
                  </span>


                  <span className={styles.impact}>
                    {card.impact}
                  </span>
                </div>


                {/* ---------------------------------------------------------- */}
                {/* Evidence                                                   */}
                {/* ---------------------------------------------------------- */}

                <p className={styles.evidence}>
                  <b>
                    الدليل:
                  </b>{' '}

                  {card.evidence}
                </p>


                {/* ---------------------------------------------------------- */}
                {/* Synonym input                                              */}
                {/* ---------------------------------------------------------- */}

                {card.needsInput === 'synonym'
                  && !done ? (
                  <div className={styles.inputRow}>
                    <label
                      className={styles.inputLabel}
                      htmlFor={
                        `syn-${card.id}`
                      }
                    >
                      مصطلح الكتالوج المستهدف
                    </label>


                    <input
                      id={
                        `syn-${card.id}`
                      }

                      className={styles.input}

                      type="text"

                      autoComplete="off"

                      spellCheck={false}

                      disabled={
                        globallyBusy
                      }

                      value={
                        synonymInput[
                        card.id
                        ] ?? ''
                      }

                      onChange={
                        (event) => {
                          const value =
                            event.target.value;

                          setSynonymInput(
                            (current) => ({
                              ...current,

                              [card.id]:
                                value,
                            }),
                          );
                        }
                      }

                      placeholder="مثال: ملزمة"
                    />
                  </div>
                ) : null}


                {/* ---------------------------------------------------------- */}
                {/* Demand choice                                              */}
                {/* ---------------------------------------------------------- */}

                {card.needsInput
                  === 'demand-status'
                  && !done ? (
                  <div
                    className={styles.inputRow}

                    role="radiogroup"

                    aria-label="قرار الطلب الفائت"
                  >
                    {(
                      [
                        'planned',
                        'declined',
                      ] as const
                    ).map(
                      (choice) => (
                        <label
                          key={choice}

                          className={
                            styles.radio
                          }
                        >
                          <input
                            type="radio"

                            name={
                              `demand-${card.id}`
                            }

                            disabled={
                              globallyBusy
                            }

                            checked={
                              (
                                demandChoice[
                                card.id
                                ]
                                ?? 'planned'
                              ) === choice
                            }

                            onChange={() => {
                              setDemandChoice(
                                (current) => ({
                                  ...current,

                                  [card.id]:
                                    choice,
                                }),
                              );
                            }}
                          />

                          {
                            DEMAND_STATUS_LABELS[
                            choice
                            ]
                            ?? choice
                          }
                        </label>
                      ),
                    )}
                  </div>
                ) : null}


                {/* ---------------------------------------------------------- */}
                {/* Crawl pages                                                */}
                {/* ---------------------------------------------------------- */}

                {card.kind === 'crawl'
                  && !done ? (
                  <div className={styles.inputRow}>
                    <label
                      className={styles.inputLabel}

                      htmlFor={
                        `pages-${card.id}`
                      }
                    >
                      سقف الصفحات
                    </label>


                    <input
                      id={
                        `pages-${card.id}`
                      }

                      className={
                        styles.input
                      }

                      type="number"

                      min={
                        MIN_CRAWL_PAGES
                      }

                      max={
                        MAX_CRAWL_PAGES
                      }

                      step={1}

                      disabled={
                        globallyBusy
                      }

                      value={
                        crawlPages
                      }

                      onChange={
                        (event) => {
                          setCrawlPages(
                            clampInteger(
                              Number(
                                event.target.value,
                              ),

                              MIN_CRAWL_PAGES,

                              MAX_CRAWL_PAGES,
                            ),
                          );
                        }
                      }

                      style={{
                        inlineSize: 100,
                      }}
                    />
                  </div>
                ) : null}


                {/* ---------------------------------------------------------- */}
                {/* Controls                                                   */}
                {/* ---------------------------------------------------------- */}

                <div className={styles.controls}>
                  <code
                    dir="ltr"
                    className={styles.urls}
                  >
                    {card.urls}
                  </code>


                  <span
                    className={styles.controlsSpacer}
                  />


                  <button
                    type="button"

                    className={
                      styles.btn
                    }

                    aria-expanded={
                      isOpen
                    }

                    aria-controls={
                      dryId
                    }

                    disabled={
                      globallyBusy
                    }

                    onClick={() => {
                      toggleDryRun(
                        card.id,
                      );
                    }}
                  >
                    {isOpen
                      ? 'إخفاء التفاصيل'
                      : 'معاينة الطلب'}
                  </button>


                  {!done ? (
                    <button
                      type="button"

                      className={
                        `${styles.btn} ${styles.btnExec}`
                      }

                      disabled={
                        lockWhy !== null
                      }

                      onClick={() => {
                        void execute(
                          card,
                        );
                      }}
                    >
                      {isExecuting
                        ? 'جارٍ التنفيذ…'
                        : 'تنفيذ الآن'}
                    </button>
                  ) : null}


                  {lockWhy !== null
                    && !done ? (
                    <span
                      className={
                        styles.lockWhy
                      }
                    >
                      {lockWhy}
                    </span>
                  ) : null}


                  {done ? (
                    <>
                      <span
                        className={
                          styles.doneChip
                        }
                      >
                        <OsIcon name="check" />

                        تم التنفيذ
                      </span>


                      {card.rollback.kind
                        === 'real' ? (
                        <button
                          type="button"

                          className={
                            `${styles.btn} ${styles.btnRollback}`
                          }

                          disabled={
                            globallyBusy
                            || !allowedByRole
                            || !allowedByLevel
                          }

                          onClick={() => {
                            void rollback(
                              card,
                            );
                          }}
                        >
                          {isRollingBack
                            ? 'جارٍ التراجع…'
                            : 'تراجع'}
                        </button>
                      ) : (
                        <span
                          className={
                            styles.lockWhy
                          }
                        >
                          {
                            card.rollback.reason
                          }
                        </span>
                      )}
                    </>
                  ) : null}
                </div>


                {/* ---------------------------------------------------------- */}
                {/* Optional preview                                           */}
                {/* ---------------------------------------------------------- */}

                {isOpen ? (
                  <div
                    id={dryId}

                    className={
                      styles.dryPanel
                    }
                  >
                    <span
                      className={
                        styles.dryTitle
                      }
                    >
                      الطلب الذي سيُرسل

                      {' — '}

                      {card.method}

                      {' '}

                      <bdi dir="ltr">
                        /api/v1{card.endpoint}
                      </bdi>
                    </span>


                    <code
                      dir="ltr"

                      className={
                        styles.payload
                      }
                    >
                      {
                        Object.keys(
                          payload,
                        ).length === 0

                          ? '{} — بلا جسم'

                          : JSON.stringify(
                            payload,
                            null,
                            2,
                          )
                      }
                    </code>


                    {card.kind === 'synonym' ? (
                      <span
                        className={
                          styles.subNote
                        }
                      >
                        الخادم يفرض source=&quot;manual&quot; تلقائيًا.
                      </span>
                    ) : null}


                    <span
                      className={
                        styles.dryTitle
                      }
                    >
                      الأثر المتوقع من البيانات المحمّلة
                    </span>


                    {card.affected.map(
                      (row) => (
                        <div
                          className={
                            styles.dryRow
                          }

                          key={
                            row.label
                          }
                        >
                          <span>
                            {row.label}
                          </span>

                          <b>
                            {row.value}
                          </b>
                        </div>
                      ),
                    )}


                    <div
                      className={
                        styles.dryRow
                      }
                    >
                      <span>
                        التراجع
                      </span>

                      <b>
                        {
                          card.rollback.kind
                            === 'real'

                            ? card.rollback.note

                            : card.rollback.reason
                        }
                      </b>
                    </div>


                    <span
                      className={
                        styles.subNote
                      }
                    >
                      المعاينة اختيارية ولا تمنع التنفيذ. صلاحية العملية تُحسم من الجلسة والخادم.
                    </span>
                  </div>
                ) : null}


                {/* ---------------------------------------------------------- */}
                {/* Result                                                     */}
                {/* ---------------------------------------------------------- */}

                {run ? (
                  <p
                    className={
                      `${styles.statusLine} ${run.ok
                        ? ''
                        : styles.statusErr
                      }`
                    }

                    role={
                      run.ok
                        ? 'status'
                        : 'alert'
                    }

                    aria-live="polite"
                  >
                    {run.message}
                  </p>
                ) : null}
              </section>
            );
          },
        )}


        {derived.blocked.map(
          (card) => (
            <BlockedRow
              key={
                card.id
              }

              card={
                card
              }
            />
          ),
        )}
      </div>
    </div>
  );
}


/* -------------------------------------------------------------------------- */
/* Week audit                                                                 */
/* -------------------------------------------------------------------------- */

function WeekChip({
  feeds,
}: {
  feeds:
  ReturnType<
    typeof useActionFeeds
  >;
}) {
  const state =
    feeds.audit.state;


  if (
    state.status
    === 'loading'
  ) {
    return (
      <span
        className={
          `${styles.headChip} ${styles.headChipAbsent}`
        }

        role="status"
      >
        سجل الأسبوع: جارٍ التحديث…
      </span>
    );
  }


  if (
    state.status
    === 'error'
  ) {
    return (
      <span
        className={
          `${styles.headChip} ${styles.headChipAbsent}`
        }
      >
        {
          state.code === 403

            ? 'سجل الأسبوع غير متاح لهذا الدور'

            : `تعذر قراءة سجل الأسبوع: ${state.message}`
        }
      </span>
    );
  }


  const actions =
    new Set<string>(
      OS_AUDIT_ACTIONS,
    );


  const executed =
    state.data.data.filter(
      (entry) =>
        actions.has(
          entry.action,
        ),
    ).length;


  const sampled =
    state.data.meta.lastPage > 1;


  return (
    <span
      className={
        styles.headChip
      }
    >
      <bdi dir="ltr">
        {count(executed)}
      </bdi>{' '}

      عملية منذ{' '}

      {dateTime(
        `${feeds.auditFrom}T00:00:00Z`,
      )}

      {sampled
        ? ' — عينة أول 100 سجل'
        : ''}
    </span>
  );
}


/* -------------------------------------------------------------------------- */
/* Blocked                                                                    */
/* -------------------------------------------------------------------------- */

function BlockedRow({
  card,
}: {
  card: BlockedCard;
}) {
  return (
    <section
      className={
        `${styles.action} ${styles.fade}`
      }
    >
      <div
        className={
          styles.headRow
        }
      >
        <b
          className={
            styles.actionTitle
          }
        >
          {card.title}
        </b>


        <span
          className={
            `${styles.chip} ${styles.chipMuted}`
          }
        >
          غير متاح
        </span>
      </div>


      <p
        className={
          styles.evidence
        }
      >
        <b>
          السبب:
        </b>{' '}

        {card.reason}


        {card.evidence ? (
          <>
            {' '}

            <b>
              الدليل:
            </b>{' '}

            {card.evidence}
          </>
        ) : null}
      </p>


      {card.linkOut ? (
        <div
          className={
            styles.controls
          }
        >
          <span
            className={
              styles.controlsSpacer
            }
          />

          <Link
            href={
              card.linkOut.href
            }

            className={
              styles.btn
            }
          >
            {card.linkOut.label} ←
          </Link>
        </div>
      ) : null}
    </section>
  );
}