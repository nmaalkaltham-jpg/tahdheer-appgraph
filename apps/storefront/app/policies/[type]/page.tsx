/**
 * Policy page — /policies/{refund|privacy|cookies|terms|license}
 *
 * Design: «Tahdheer - Content Pages» → the `isPolicy` branch: article column
 * (h1 · last-updated line · summary box · numbered sections) beside a sticky
 * policy-switcher rail, `minmax(0,1fr) 232px` on desktop and one column below.
 *
 * The design hides the rail on mobile (`tocDisplay: none`). Here it MOVES
 * below the article instead: it is this page's only in-page way to reach the
 * other four policies, and hiding navigation is not the same as reflowing it.
 *
 * HONESTY DOCTRINE: the legal text is the unapproved corpus draft. Every
 * `[[ ]]` owner decision is rendered as a visible unresolved marker and the
 * summary box states the page's status with a DERIVED count — the storefront
 * never invents a refund window, a response time or a download cap.
 */
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { qty, type PluralForms } from "@tahdheer/design-system/format";
import { routes } from "../../../lib/routes";
import {
  POLICIES,
  countPending,
  findPolicy,
  type Block,
  type PolicyDoc,
  type Rich,
} from "../content";
import styles from "./page.module.css";

interface PageProps {
  params: Promise<{ type: string }>;
}

/** Arabic agreement for «قرار» — the ONE plural source (design-system qty). */
const DECISION_FORMS: PluralForms = {
  zero: "لا قرارات معلّقة",
  one: "قرار واحد معلّق",
  two: "قراران معلّقان",
  few: "قرارات معلّقة",
  many: "قرارًا معلّقًا",
};

export function generateStaticParams() {
  return POLICIES.map((policy) => ({ type: policy.slug }));
}

export async function generateMetadata({
  params,
}: PageProps): Promise<Metadata> {
  const { type } = await params;
  const doc = findPolicy(type);
  if (!doc) return {};
  return {
    title: doc.title,
    description: `${doc.title} في متجر التحاضير الحديثة.`,
    alternates: { canonical: routes.policy(doc.slug) },
    // The drafts are unapproved: they must not be indexed as the store's
    // published legal position until the owner decisions are resolved.
    robots: { index: false, follow: true },
  };
}

/** An unresolved owner decision, carried verbatim from the draft's `[[ ]]`. */
function Pending({ text }: { text: string }) {
  return (
    <mark className={styles.pending}>
      <span className={styles.srOnly}>قرار مالك غير محسوم: </span>
      {`[[${text}]]`}
    </mark>
  );
}

/** Render a Rich value; a plain string is still scanned for `[[ ]]`. */
function RichText({ value }: { value: Rich }) {
  if (typeof value === "string") {
    const chunks = value.split(/\[\[([^\]]+)\]\]/);
    return (
      <>
        {chunks.map((chunk, i) =>
          i % 2 === 1 ? (
            <Pending key={i} text={chunk} />
          ) : (
            <span key={i}>{chunk}</span>
          ),
        )}
      </>
    );
  }
  return (
    <>
      {value.map((part, i) => {
        if (typeof part === "string") return <span key={i}>{part}</span>;
        if ("ph" in part) return <Pending key={i} text={part.ph} />;
        if ("b" in part) return <strong key={i}>{part.b}</strong>;
        return (
          <code key={i} className={styles.code}>
            <bdi dir="ltr">{part.code}</bdi>
          </code>
        );
      })}
    </>
  );
}

function BlockView({ block }: { block: Block }) {
  if (block.kind === "p") {
    return (
      <p className={styles.para}>
        <RichText value={block.text} />
      </p>
    );
  }

  if (block.kind === "note") {
    return (
      <p className={styles.ownerNote}>
        <RichText value={block.text} />
      </p>
    );
  }

  if (block.kind === "table") {
    return (
      <div
        className={styles.tableWrap}
        tabIndex={0}
        role="group"
        aria-label={block.caption}
      >
        <table className={styles.table}>
          <caption className={styles.srOnly}>{block.caption}</caption>
          <thead>
            <tr>
              {block.head.map((cell) => (
                <th key={cell} scope="col">
                  {cell}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {block.rows.map((row, r) => (
              <tr key={r}>
                {row.map((cell, c) => (
                  <td key={c}>
                    <RichText value={cell} />
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  }

  const List = block.kind === "ol" ? "ol" : "ul";
  return (
    <List className={styles.list}>
      {block.items.map((item, i) => (
        <li key={i}>
          <RichText value={item} />
        </li>
      ))}
    </List>
  );
}

function PolicyNav({ current }: { current: PolicyDoc }) {
  return (
    <aside className={styles.rail}>
      <h2 className={styles.railTitle}>السياسات</h2>
      <nav aria-label="السياسات">
        <ul className={styles.railList}>
          {POLICIES.map((policy) => {
            const active = policy.slug === current.slug;
            return (
              <li key={policy.slug}>
                <a
                  className={active ? styles.railLinkActive : styles.railLink}
                  href={routes.policy(policy.slug)}
                  aria-current={active ? "page" : undefined}
                >
                  {policy.navLabel}
                </a>
              </li>
            );
          })}
        </ul>
      </nav>
    </aside>
  );
}

export default async function PolicyPage({ params }: PageProps) {
  const { type } = await params;
  const doc = findPolicy(type);
  if (!doc) notFound();

  const pending = countPending(doc);

  return (
    <div className={styles.page}>
      <div className={styles.wrap}>
        <nav aria-label="مسار التنقّل">
          <ol className={styles.crumbs}>
            <li>
              <a href={routes.home}>الرئيسية</a>
            </li>
            <li aria-current="page">{doc.title}</li>
          </ol>
        </nav>
        <div className={styles.grid}>
          <article className={styles.article}>
            <p className={styles.eyebrow}>السياسات</p>
            <h1 className={styles.h1}>{doc.title}</h1>
            <p className={styles.meta}>
              آخر تحديث: <Pending text="التاريخ" /> · تسري على كل المنتجات
              الرقمية في المتجر
            </p>

            <div className={styles.summary} role="note">
              <b className={styles.summaryTitle}>حالة هذه الصفحة</b>
              <p className={styles.summaryBody}>
                هذه مسوّدة صياغة لم تُعتمد بعد. المواضع المظلَّلة أدناه —{" "}
                <b className={styles.summaryCount}>
                  {qty(pending, DECISION_FORMS)}
                </b>{" "}
                — لم يحسمها مالك المتجر، ولا يُعدّ أيٌّ منها التزامًا حتى
                يُستبدل بنصّ معتمد. لأي حالة عاجلة راسلنا عبر{" "}
                <a href={routes.contact}>صفحة التواصل</a>.
              </p>
            </div>

            {doc.sections.map((section) => (
              <section key={section.heading}>
                <h2 className={styles.h2}>{section.heading}</h2>
                {section.blocks.map((block, i) => (
                  <BlockView key={i} block={block} />
                ))}
              </section>
            ))}
          </article>

          <PolicyNav current={doc} />
        </div>
      </div>
    </div>
  );
}
