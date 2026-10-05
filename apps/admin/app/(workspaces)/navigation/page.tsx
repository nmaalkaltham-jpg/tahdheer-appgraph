'use client';
// UNIT: PAGE-NAVIGATION
// FILE: page.tsx
// ROUTE: /navigation
// STYLE: page.module.css
// PARENT: none
// USES: DIALOGS, INSPECTOR, LIB-ADMIN-API, LIB-BROWSER-STORE, MODEL, NAVIGATION-ICONS +4
// API: POST /admin/navigation, POST /admin/navigation/link-check
// MAP: docs/PROJECT_MAP.md

/**
 * استوديو القوائم — /navigation.
 *
 * الحقيقة أولًا (MIP §1.3): الخادم موجود ويُستعمل. المسوّدة تُقرأ وتُحفظ لكل
 * موقع من مواقع القالب الثلاثة، والنشر ذرّي على الخادم (قفل · فحص مسبق ·
 * لقطة · التزام · إبطال · تحقّق) مع مفتاح تكرار وتراجع تعويضي، وفاحص الوجهات
 * محميّ من SSRF. ما لا وجود له — مكتبة قوائم متعددة، أرشيف، سجل تدقيق
 * مقروء، قراءة المراجعات — يُعلَن غيابه بالاسم بدل أن يُحاكى.
 *
 * التخزين المحلّي استرجاعُ عطلٍ فقط، ومفهرس بالموقع: لا يُنشر ولا يُعتبر مصدرًا.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useHydrated } from '../../../lib/browser-store';
import { api, firstError } from '../../../lib/admin-api';
import { PageHeader } from '../../../components/resource';
import {
  BranchDeleteDialog,
  ExportDialog,
  ImportDialog,
  PublishContractDialog,
  ShortcutsDialog,
} from './dialogs';
import { Icon } from './icons';
import { HealthRail, Inspector, type InsertPosition, LibraryRail, type RailTab } from './inspector';
import {
  adoptStableIds,
  analyze,
  byId,
  childrenOf,
  deleteWithPromotion,
  descendantIds,
  duplicateBranch,
  fromServerShape,
  health,
  insertAfter,
  type Issue,
  LINK_CHECK_BATCH,
  makeNode,
  MAX_DEPTH,
  type MenuNode,
  type PublishContract,
  mergeServerDraft,
  moveWithinSiblings,
  nestUnderPrevious,
  patchNode,
  pathOf,
  type ProbeResult,
  reparent,
  rows,
  sameAsServer,
  type ServerNode,
  setDeleted,
  STOREFRONT_REACH,
  toServerShape,
  typeMeta,
  unnest,
} from './model';
import { buildExport, clearDraft, type DraftEnvelope, readDraft, writeDraft } from './_model-transfer';
import {
  failureText,
  type HistoryEntry,
  type MenuPayload,
  MISSING_SURFACES,
  OPERATION_COMPLETED,
  OPERATION_ROLLED_BACK,
  type PublishOperation,
  SEARCH_ROWS_SHOWN,
  type SearchRow,
  SECTION_TABS,
  type SectionKey,
  TIME_FORMAT,
  VIEW_TABS,
  type ViewKey,
} from './_page-model';
import styles from './page.module.css';
import { type DropPosition, LiveView, MapView, type StudioCommands, TableView, TreeView } from './views';

const GROUP_CLASS = {
  error: styles.groupError,
  warn: styles.groupWarn,
  info: styles.groupInfo,
} as const;

export default function NavigationStudioPage() {
  const [nodes, setNodes] = useState<MenuNode[]>([]);
  const [past, setPast] = useState<HistoryEntry[]>([]);
  const [future, setFuture] = useState<HistoryEntry[]>([]);
  const [selection, setSelection] = useState<string[]>([]);
  const [collapsed, setCollapsed] = useState<Set<string>>(() => new Set());
  const [editingId, setEditingId] = useState<string | null>(null);
  const [view, setView] = useState<ViewKey>('map');
  const [section, setSection] = useState<SectionKey>('studio');
  const [railTab, setRailTab] = useState<RailTab>('inspector');
  const [query, setQuery] = useState('');
  /** لوح «نتائج عبر كل القوائم» — يُفتح بالكتابة ويُغلق بزرّه دون مسح البحث. */
  const [searchOpen, setSearchOpen] = useState(false);
  const [expert, setExpert] = useState(false);
  const [dialog, setDialog] = useState<'publish' | 'shortcuts' | 'export' | 'import' | null>(null);
  /** العنصر المطلوب حذفه وله أبناء — ينتظر قرار المشغّل بين الفرع والترقية. */
  const [branchDelete, setBranchDelete] = useState<string | null>(null);
  const [recovered, setRecovered] = useState<DraftEnvelope | null>(null);
  const [edits, setEdits] = useState(0);
  const [savedAt, setSavedAt] = useState<number | null>(null);
  const [announcement, setAnnouncement] = useState('');

  /* ── الحالة على الخادم: هي المصدر، والتخزين المحلّي استرجاعُ عطلٍ فقط ── */
  const [location, setLocation] = useState('header');
  const [locations, setLocations] = useState<Record<string, string> | null>(null);
  /** عقد النشر كما يعرّفه الخادم؛ null قبل وصول الردّ. */
  const [publishContract, setPublishContract] = useState<PublishContract | null>(null);
  const [menus, setMenus] = useState<MenuPayload[]>([]);
  const [serverVersion, setServerVersion] = useState<number | null>(null);
  const [serverDraft, setServerDraft] = useState<ServerNode[] | null>(null);
  const [publishedVersion, setPublishedVersion] = useState<number | null>(null);
  const [publishedAt, setPublishedAt] = useState<string | null>(null);
  const [lastOperation, setLastOperation] = useState<PublishOperation | null>(null);
  const [busy, setBusy] = useState(false);
  const [serverNote, setServerNote] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  /*
   * فشل قراءة الخادم يُفصَل عن `serverNote`: ملاحظة الحفظ والنشر تُستبدل بأوّل
   * نداء تالٍ، أمّا تعذّر القراءة فحالةٌ تبقى حتّى تنجح إعادة المحاولة — ولا
   * إعادة محاولة بلا زرّ يُظهرها (معيار `ResourceView`).
   */
  const [loadError, setLoadError] = useState<string | null>(null);
  const [reloadTick, setReloadTick] = useState(0);
  /*
   * ٤٠٣: كلّ مسارات `‎/admin/navigation*‎` خلف `appearance.manage`
   * (`routes/api-admin.php:433-447`). الردّ يصل رسالةً عامّة، والمشغّل الذي لا
   * يملك الصلاحية يحتاج اسمها لا «تعذّر التحميل» ولا زرّ إعادة محاولة لن ينجح.
   */
  const [denied, setDenied] = useState(false);

  /* فحص الوجهات: نتيجة قياس فعلي، لا جدول ثابت. */
  const [probes, setProbes] = useState<Map<string, ProbeResult> | null>(null);
  const [probeAt, setProbeAt] = useState<number | null>(null);
  const [probing, setProbing] = useState(false);
  const [probeError, setProbeError] = useState<string | null>(null);

  /*
   * مفتاح التكرار: يُسكّ لمحاولة النشر ويبقى ما دامت المحاولة نفسها معلّقة.
   * انقطاعُ الشبكة بعد أن يلتزم الخادم يجعل إعادة الضغط بالمفتاح ذاته قراءةً
   * للعملية القائمة لا نشرًا ثانيًا. أمّا فشلٌ رواه الخادم فيبطل المفتاح:
   * المحاولة التالية عملية جديدة، وإلا لأعاد الخادم الفشل نفسه إلى الأبد.
   */
  const publishKeyRef = useRef<string | null>(null);

  /** قفزة بحث عابرة للمواقع: يُحدَّد العنصر بعد أن يقرأ الموقع الهدف من الخادم. */
  const pendingJumpRef = useRef<string | null>(null);

  /* مرايا للحالة: الأوامر تقرأ آخر قيمة دون أن تُعاد بناؤها مع كل رسم،
     وكل أثر جانبي (كتابة المسودة) يبقى خارج دوال التحديث. */
  const nodesRef = useRef(nodes);
  const pastRef = useRef(past);
  const futureRef = useRef(future);
  const editsRef = useRef(edits);
  const locationRef = useRef(location);
  // تُحدَّث بعد كل التزام (لا أثناء الرسم)، وقبل أي معالج حدث لاحق.
  useEffect(() => {
    nodesRef.current = nodes;
    pastRef.current = past;
    futureRef.current = future;
    editsRef.current = edits;
    locationRef.current = location;
  });

  const issues = useMemo(() => analyze(nodes), [nodes]);
  const summary = useMemo(() => health(issues), [issues]);
  const issuesByNode = useMemo(() => {
    const map = new Map<string, Issue[]>();
    for (const issue of issues) {
      if (issue.nodeId === null) continue;
      map.set(issue.nodeId, [...(map.get(issue.nodeId) ?? []), issue]);
    }
    return map;
  }, [issues]);

  /* ─── الالتزام: كل تغيير يدخل التاريخ ويُكتب في مسودة المتصفح ─── */
  /**
   * آخر التزامٍ قابلٍ للدمج ووقته.
   *
   * كلّ ضغطة مفتاح في المفتّش كانت مدخلَ تاريخٍ مستقلًّا: كتابة «الرئيسة»
   * تبتلع سبعةً من الخمسين مدخلًا المتاحة، ثمّ يضغط المشغّل «تراجع» فيمحو
   * حرفًا واحدًا لا التعديل. الكتابة المتّصلة على الحقل نفسه تُدمج.
   */
  const coalesceRef = useRef<{ key: string; at: number } | null>(null);

  const commit = useCallback(
    (label: string, next: MenuNode[] | null, coalesceKey?: string) => {
      if (next === null) {
        setAnnouncement(`تعذّر: ${label}`);
        return;
      }
      const previous = nodesRef.current;
      const count = editsRef.current + 1;

      const now = Date.now();
      const last = coalesceRef.current;
      const top = pastRef.current[pastRef.current.length - 1];
      // الدمج يُبقي لقطة ما قبل بدء الكتابة: التراجع يعود إلى النصّ السابق كاملًا.
      const merge =
        coalesceKey !== undefined && last !== null && last.key === coalesceKey && now - last.at < 1500 && top !== undefined;
      coalesceRef.current = coalesceKey === undefined ? null : { key: coalesceKey, at: now };

      setPast(
        merge
          ? [...pastRef.current.slice(0, -1), { label, nodes: top!.nodes }]
          : [...pastRef.current.slice(-49), { label, nodes: previous }],
      );
      setFuture([]);
      setNodes(next);
      setEdits(count);
      writeDraft(locationRef.current, next, count);
      setSavedAt(Date.now());
      setAnnouncement(label);
    },
    [],
  );

  /* ───── الجسر إلى الخادم ───── */

  const adopt = useCallback((payload: MenuPayload) => {
    setServerVersion(payload.version);
    setServerDraft(payload.draft);
    setPublishedVersion(payload.publishedVersion);
    setPublishedAt(payload.publishedAt);
  }, []);

  /** سجل المواقع: ما يعرف المتجر كيف يعرض قائمةً فيه، وحالة كلٍّ منها. */
  // سلسلة وعود لا `async`: كل تحديث حالة يقع في `then` فلا يُعدّ متزامنًا داخل الأثر.
  const refreshLocations = useCallback(
    () =>
      api
        .get<{
          data: MenuPayload[];
          meta: { locations: Record<string, string>; publishContract: PublishContract };
        }>('/admin/navigation')
        .then((result) => {
          if (!result.ok) {
            if (result.status === 403) setDenied(true);
            else setServerNote({ tone: 'error', text: firstError(result) });
            return;
          }
          setLocations(result.data.meta.locations);
          // عقد النشر من الخادم — لا قائمة خطوات مكتوبة في الواجهة.
          setPublishContract(result.data.meta.publishContract ?? null);
          setMenus(result.data.data);
        }),
    [],
  );

  useEffect(() => {
    void refreshLocations();
  }, [refreshLocations]);

  /*
   * المسوّدة المحفوظة على الخادم هي نقطة البداية لكل موقع. المسوّدة المحلّية
   * تبقى لاسترجاع عمل ضاع بإغلاق مفاجئ، ولا تُعتبر مصدرًا.
   */
  // «تعديل الحالة أثناء الرسم»: تغيّر الموقع يصفّر ما يخصّ الموقع السابق قبل قراءة الجديد.
  const [loadedLocation, setLoadedLocation] = useState(location);
  if (loadedLocation !== location) {
    setLoadedLocation(location);
    setServerVersion(null);
    setServerDraft(null);
    setProbes(null);
    setProbeAt(null);
    setLastOperation(null);
  }
  useEffect(() => {
    let cancelled = false;
    publishKeyRef.current = null;

    void api.get<{ data: MenuPayload }>(`/admin/navigation/${location}`).then((result) => {
      if (cancelled) return;
      if (!result.ok) {
        /*
         * كان الفشل هنا يُكتب ملاحظةً ويسكت: لا زرّ يُعيد المحاولة، فانقطاعُ
         * شبكةٍ لحظيّ يترك المحرّر فارغًا بلا سبيل إلى استئنافه إلّا بإعادة
         * تحميل الصفحة. الخطأ صار حالةً لها زرّها، والمنع صار فرعه.
         */
        if (result.status === 403) setDenied(true);
        else setLoadError(firstError(result));
        return;
      }
      setLoadError(null);
      adopt(result.data.data);

      /*
       * المعرّفات المؤقّتة تُستبدل عند القراءة: الفحص المسبق على الخادم يرفض
       * نشر أي عقدة معرّفها يبدأ بـ tmp_، فمسوّدة محفوظة بها غير قابلة للنشر
       * إلى الأبد. الاستبدال هنا يجعل الحالة «غير محفوظة» فيحفظ المشغّل مرّة
       * ويصير النشر ممكنًا.
       */
      const adopted = adoptStableIds(fromServerShape(result.data.data.draft));
      setNodes(adopted.nodes);
      setPast([]);
      setFuture([]);
      // قفزة بحث معلّقة تُطبَّق إن بقي المعرّف موجودًا بعد القراءة، وإلا صفر تحديد.
      const jump = pendingJumpRef.current;
      pendingJumpRef.current = null;
      setSelection(jump !== null && adopted.nodes.some((node) => node.id === jump) ? [jump] : []);
      setServerNote(
        adopted.remapped === 0
          ? null
          : {
              tone: 'error',
              text: `استُبدل ${adopted.remapped} معرّفًا مؤقّتًا بمعرّفات ثابتة. احفظ على الخادم قبل النشر: الفحص المسبق يرفض المعرّفات المؤقّتة.`,
            },
      );
    });

    return () => {
      cancelled = true;
    };
  }, [adopt, location, reloadTick]);

  const saveToServer = useCallback(async () => {
    if (serverVersion === null) return;
    setBusy(true);
    setServerNote(null);

    /*
     * المشطوب لا يُرسَل. `MenuTree::normalize` لا يقرأ `deleted` إطلاقًا، فكان
     * العنصر المحذوف مؤجَّلًا يُحفظ في المسوّدة ثم يُنشر عنصرًا حيًّا في ترويسة
     * المتجر — أي أن «الحذف المؤجل» كان ينشر ما حُذف. يبقى محلّيًا للتراجع.
     */
    const result = await api.patch<{ data: MenuPayload; dropped: number }>(
      `/admin/navigation/${location}`,
      { nodes: toServerShape(nodesRef.current), version: serverVersion },
    );
    setBusy(false);

    if (!result.ok) {
      if (result.code === 'VERSION_CONFLICT') {
        const body = result.body as { data?: MenuPayload } | null;
        if (body?.data) adopt(body.data);
      }
      setServerNote({ tone: 'error', text: firstError(result) });
      return;
    }

    adopt(result.data.data);
    // ما طبّعه الخادم يعود إلى المحرّر: بدونه تختلف النسختان بعد كل حفظ ناجح.
    const merged = mergeServerDraft(nodesRef.current, result.data.data.draft);
    setNodes(merged);
    writeDraft(location, merged, editsRef.current);
    void refreshLocations();

    const dropped = result.data.dropped;
    setServerNote({
      tone: 'ok',
      // ما أُسقط في التطبيع يُقال: عنصر يختفي بلا سبب يُقرأ عطلًا.
      text:
        dropped > 0
          ? `حُفظت على الخادم. أُسقط ${dropped} عنصرًا بلا نصّ أو بوجهة غير مقبولة — بقي في المحرّر لتصحيحه.`
          : 'حُفظت على الخادم.',
    });
    setAnnouncement('حُفظت القائمة على الخادم.');
  }, [adopt, location, refreshLocations, serverVersion]);

  const publishToServer = useCallback(async () => {
    setBusy(true);
    setServerNote(null);

    /*
     * `idempotencyKey` مطلوب على الخادم (`required|string|max:100`)؛ إغفاله
     * كان يجعل كل ضغطة على «نفّذ النشر» تعود 422 ولا تنشر شيئًا أبدًا.
     * و`baseRevision` يُقارَن بـ`menu->version` في الفحص المسبق، فهو نسخة
     * المسوّدة التي نُشرت منها لا النسخة المنشورة سابقًا.
     */
    const idempotencyKey = publishKeyRef.current ?? crypto.randomUUID();
    publishKeyRef.current = idempotencyKey;

    const result = await api.post<{ data: MenuPayload; operation: PublishOperation }>(
      `/admin/navigation/${location}/publish`,
      { idempotencyKey, baseRevision: serverVersion },
    );
    setBusy(false);

    if (!result.ok) {
      const operation = (result.body as { operation?: PublishOperation } | null)?.operation ?? null;
      const payload = (result.body as { data?: MenuPayload } | null)?.data ?? null;
      if (payload) adopt(payload);
      if (operation) {
        // الخادم روى الفشل: المفتاح يبطل حتى تكون المحاولة التالية عملية جديدة.
        publishKeyRef.current = null;
        setLastOperation(operation);
        setServerNote({ tone: 'error', text: `لم يُنشر: ${failureText(operation)}` });
      } else {
        setServerNote({ tone: 'error', text: firstError(result) });
      }
      return;
    }

    publishKeyRef.current = null;
    adopt(result.data.data);
    setLastOperation(result.data.operation);
    setNodes(mergeServerDraft(nodesRef.current, result.data.data.draft));
    void refreshLocations();
    setDialog(null);
    setServerNote({
      tone: 'ok',
      text: `نُشرت القائمة في «${locations?.[location] ?? location}» — عملية ${result.data.operation.id}.`,
    });
    setAnnouncement('نُشرت القائمة.');
  }, [adopt, location, locations, refreshLocations, serverVersion]);

  /** تراجع تعويضي: يعيد المنشور إلى ما كان قبل عملية بعينها. */
  const rollbackLastPublish = useCallback(async () => {
    const operation = lastOperation;
    if (operation === null || operation.status !== OPERATION_COMPLETED) return;
    setBusy(true);
    setServerNote(null);

    const result = await api.post<{ data: MenuPayload; operation: PublishOperation }>(
      `/admin/navigation/${location}/rollback`,
      { operationId: operation.id },
    );
    setBusy(false);

    if (!result.ok) {
      const failed = (result.body as { operation?: PublishOperation } | null)?.operation ?? null;
      setServerNote({
        tone: 'error',
        text: failed ? `لم يتراجع: ${failureText(failed)}` : firstError(result),
      });
      if (failed) setLastOperation(failed);
      return;
    }

    adopt(result.data.data);
    setLastOperation(result.data.operation);
    void refreshLocations();
    setServerNote({
      tone: 'ok',
      text:
        result.data.data.publishedVersion === null
          ? 'سُحبت القائمة من النشر — لم يكن لها إصدار منشور قبل هذه العملية.'
          : `عاد المنشور إلى الإصدار ${result.data.data.publishedVersion}.`,
    });
    setAnnouncement('تراجع عن آخر نشر.');
  }, [adopt, lastOperation, location, refreshLocations]);

  /** فحص شبكي فعلي لوجهات المسوّدة — بالضغط لا تلقائيًّا. */
  const runLinkCheck = useCallback(async () => {
    const urls = Array.from(
      new Set(nodesRef.current.filter((node) => !node.deleted && node.url.trim() !== '').map((node) => node.url.trim())),
    );
    if (urls.length === 0) return;

    setProbing(true);
    setProbeError(null);
    const found = new Map<string, ProbeResult>();

    // الخادم يقبل عشرين وجهة في الطلب الواحد؛ الباقي دفعات متتابعة.
    for (let at = 0; at < urls.length; at += LINK_CHECK_BATCH) {
      const batch = urls.slice(at, at + LINK_CHECK_BATCH);
      const result = await api.post<{ data: ProbeResult[] }>('/admin/navigation/link-check', {
        urls: batch,
      });
      if (!result.ok) {
        setProbing(false);
        setProbeError(firstError(result));
        // ما قِيس فعلًا يُعرض؛ ما لم يُقَس يبقى «لم يُفحص».
        if (found.size > 0) {
          setProbes(found);
          setProbeAt(Date.now());
        }
        return;
      }
      result.data.data.forEach((row, index) => found.set(batch[index] ?? row.url, row));
    }

    setProbing(false);
    setProbes(found);
    setProbeAt(Date.now());
    setAnnouncement('انتهى فحص الوجهات.');
  }, []);

  /** ما بين يدَي المحرّر يخالف مسوّدة الخادم. */
  const unsavedOnServer = useMemo(
    () => serverDraft !== null && !sameAsServer(nodes, serverDraft),
    [nodes, serverDraft],
  );

  const undo = useCallback(() => {
    const entry = pastRef.current[pastRef.current.length - 1];
    if (!entry) return;
    setPast(pastRef.current.slice(0, -1));
    setFuture([{ label: entry.label, nodes: nodesRef.current }, ...futureRef.current]);
    setNodes(entry.nodes);
    writeDraft(locationRef.current, entry.nodes, editsRef.current);
    setAnnouncement(`تراجع عن: ${entry.label}`);
  }, []);

  const redo = useCallback(() => {
    const entry = futureRef.current[0];
    if (!entry) return;
    setPast([...pastRef.current, { label: entry.label, nodes: nodesRef.current }]);
    setFuture(futureRef.current.slice(1));
    setNodes(entry.nodes);
    writeDraft(locationRef.current, entry.nodes, editsRef.current);
    setAnnouncement(`إعادة: ${entry.label}`);
  }, []);

  /* ─── استرجاع المسودة المحلية للموقع الحالي ─── */
  // تُقرأ مرّة لكل موقع بعد الإماهة (لا أثناء ترميز الخادم)، بنمط «تعديل الحالة أثناء الرسم».
  const hydrated = useHydrated();
  const [recoveredFor, setRecoveredFor] = useState<string | null>(null);
  if (hydrated && recoveredFor !== location) {
    setRecoveredFor(location);
    setRecovered(readDraft(location));
  }

  /*
   * البانر لا يُعرض إلّا حين تختلف النسخة المحلّية عن مسوّدة الخادم.
   *
   * كان يظهر لمجرّد وجود كتابةٍ محلّية — وهي تُكتب بعد كلّ تعديل — فيستقبل
   * المشغّل «وُجدت نسخة استرجاع» في كلّ زيارة، ويُعرض عليه أن «يستعيد» ما هو
   * معروضٌ أمامه أصلًا. إنذارٌ يتكرّر بلا سبب يُتجاهَل حين يكون له سبب.
   */
  const recovery = useMemo(
    () => (recovered !== null && serverDraft !== null && !sameAsServer(recovered.nodes, serverDraft) ? recovered : null),
    [recovered, serverDraft],
  );

  /* ─── أوامر العروض ─── */
  const commands: StudioCommands = useMemo(
    () => ({
      select(id, mode) {
        setSelection((current) => {
          if (mode === 'toggle') {
            return current.includes(id) ? current.filter((other) => other !== id) : [...current, id];
          }
          return [id];
        });
        setEditingId(null);
      },
      clearSelection() {
        setSelection([]);
      },
      beginRename(id) {
        setSelection([id]);
        setEditingId(id);
      },
      commitRename(id, label) {
        setEditingId(null);
        const node = byId(nodesRef.current, id);
        if (!node || node.label === label) return;
        commit(`تسمية «${label || 'بلا نص'}»`, patchNode(nodesRef.current, id, { label }));
      },
      cancelRename() {
        setEditingId(null);
      },
      addChild(id) {
        const parent = byId(nodesRef.current, id);
        if (!parent) return;
        const node = makeNode({ parentId: id, label: '', url: '', type: 'custom' });
        const siblings = childrenOf(nodesRef.current, id);
        const after = siblings[siblings.length - 1]?.id ?? id;
        commit('إضافة عنصر داخل عنصر', insertAfter(nodesRef.current, node, after));
        setSelection([node.id]);
        setEditingId(node.id);
      },
      insertAfter(id) {
        const anchor = id ? byId(nodesRef.current, id) : null;
        const node = makeNode({
          parentId: anchor?.parentId ?? null,
          label: '',
          url: '',
          type: 'custom',
        });
        commit('إضافة عنصر', insertAfter(nodesRef.current, node, id));
        setSelection([node.id]);
        setEditingId(node.id);
      },
      duplicate(id) {
        commit('تكرار فرع', duplicateBranch(nodesRef.current, id));
      },
      move(id, delta) {
        commit(delta < 0 ? 'تحريك للأعلى' : 'تحريك للأسفل', moveWithinSiblings(nodesRef.current, id, delta));
      },
      nest(id) {
        commit('تعشيش تحت السابق', nestUnderPrevious(nodesRef.current, id));
      },
      unnest(id) {
        commit('إخراج من الأب', unnest(nodesRef.current, id));
      },
      remove(id) {
        // أبٌ له أبناء لا يُحذف بصمت: الفرع كلّه يختفي بضغطة واحدة.
        const children = childrenOf(nodesRef.current, id).filter((child) => !child.deleted);
        if (children.length > 0) {
          setBranchDelete(id);
          return;
        }
        commit('حذف مؤجل', setDeleted(nodesRef.current, id, true));
      },
      restore(id) {
        commit('استرجاع عنصر', setDeleted(nodesRef.current, id, false));
      },
      toggleCollapse(id) {
        setCollapsed((current) => {
          const next = new Set(current);
          if (next.has(id)) next.delete(id);
          else next.add(id);
          return next;
        });
      },
      drop(dragId, targetId, position: DropPosition) {
        const list = nodesRef.current;
        if (targetId === null) {
          const roots = childrenOf(list, null);
          commit('نقل إلى المستوى الأعلى', reparent(list, dragId, null, roots.length));
          return;
        }
        const target = byId(list, targetId);
        if (!target) return;
        if (position === 'inside') {
          commit('تعشيش بالسحب', reparent(list, dragId, targetId, childrenOf(list, targetId).length));
          return;
        }
        // الفهرس يُحسب على الأشقاء بعد نزع المسحوب، وإلا انزاح بمقدار واحد
        // حين يكون المسحوب سابقًا للهدف في المستوى نفسه.
        const siblings = childrenOf(list, target.parentId).filter((sibling) => sibling.id !== dragId);
        const at = siblings.findIndex((sibling) => sibling.id === targetId);
        if (at < 0) return;
        commit('نقل بالسحب', reparent(list, dragId, target.parentId, position === 'before' ? at : at + 1));
      },
    }),
    [commit],
  );

  /* ─── اختصارات لوحة المفاتيح ─── */
  useEffect(() => {
    const handler = (event: KeyboardEvent): void => {
      const target = event.target as HTMLElement | null;
      const typing =
        target !== null &&
        (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable);
      const mod = event.metaKey || event.ctrlKey;
      const key = event.key.toLowerCase();

      if (event.key === 'Escape') {
        if (dialog) setDialog(null);
        else if (branchDelete) setBranchDelete(null);
        else if (editingId) setEditingId(null);
        return;
      }
      if (mod && key === 'z' && !event.shiftKey) {
        event.preventDefault();
        undo();
        return;
      }
      if (mod && (key === 'y' || (key === 'z' && event.shiftKey))) {
        event.preventDefault();
        redo();
        return;
      }
      if (mod && key === 's') {
        event.preventDefault();
        /*
         * كان يكتب نسخة الاسترجاع المحلّية وحدها ويقول «حُفظت»: المشغّل يضغط
         * الاختصار المعروف، يقرأ تأكيدًا، يغلق المتصفّح — ولم يبلغ الخادمَ
         * شيء. الاختصار يحفظ حيث يظنّ أنّه يحفظ.
         */
        writeDraft(locationRef.current, nodesRef.current, editsRef.current);
        setSavedAt(Date.now());
        void saveToServer();
        return;
      }
      if (typing) return;

      const id = selection.length === 1 ? selection[0] : undefined;
      if (!id) return;

      if (event.key === 'Enter') {
        event.preventDefault();
        commands.beginRename(id);
      } else if (event.key === 'Delete') {
        event.preventDefault();
        commands.remove(id);
      } else if (event.altKey && event.key === 'ArrowUp') {
        event.preventDefault();
        commands.move(id, -1);
      } else if (event.altKey && event.key === 'ArrowDown') {
        event.preventDefault();
        commands.move(id, 1);
      } else if (event.altKey && event.key === 'ArrowLeft') {
        event.preventDefault();
        commands.nest(id);
      } else if (event.altKey && event.key === 'ArrowRight') {
        event.preventDefault();
        commands.unnest(id);
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [branchDelete, commands, dialog, editingId, redo, saveToServer, selection, undo]);

  const addFromLibrary = useCallback(
    (
      candidate: {
        label: string;
        url: string;
        type: MenuNode['type'];
        sourceId: number | null;
        sourceApi: string | null;
      },
      position: InsertPosition,
    ) => {
      const list = nodesRef.current;
      const anchorId = selection.length === 1 ? (selection[0] ?? null) : null;
      const anchor = anchorId ? byId(list, anchorId) : null;
      const parentId = position === 'child' && anchor ? anchor.id : position === 'after' && anchor ? anchor.parentId : null;
      const node = makeNode({ ...candidate, parentId });
      const after = position === 'root' || !anchor ? null : anchor.id;
      commit(`إضافة «${candidate.label}»`, insertAfter(list, node, after));
      setSelection([node.id]);
    },
    [commit, selection],
  );

  /* ─── البحث عبر كل القوائم ───
   * النطاق صادق: مواقع القالب الثلاثة كما أعادها `GET /admin/navigation` —
   * الموقع الحالي يُقرأ من عقد المحرّر (الأحدث)، والبقية من مسوّدة الخادم.
   */
  const searchRows = useMemo((): SearchRow[] => {
    const needle = query.trim().toLowerCase();
    if (needle === '') return [];

    const sources: [string, MenuNode[]][] = menus.map((menu) => [
      menu.location,
      menu.location === location ? nodes : fromServerShape(menu.draft),
    ]);
    if (!menus.some((menu) => menu.location === location)) sources.push([location, nodes]);

    const out: SearchRow[] = [];
    for (const [loc, list] of sources) {
      const menuName = locations?.[loc] ?? loc;
      for (const node of list) {
        if (node.deleted) continue;
        const typeName = typeMeta(node.type).name;
        const hay = `${node.label}\n${node.url}\n${node.badge}\n${typeName}`.toLowerCase();
        if (!hay.includes(needle)) continue;
        out.push({
          key: `${loc}:${node.id}`,
          id: node.id,
          location: loc,
          menuName,
          label: node.label || '⟨بلا نص تنقل⟩',
          typeName,
          path: pathOf(list, node.id)
            .map((ancestor) => ancestor.label || '⟨بلا نص⟩')
            .join(' ← '),
          url: node.url,
        });
      }
    }
    return out;
  }, [query, menus, nodes, location, locations]);

  const jumpToResult = useCallback((row: SearchRow) => {
    setSearchOpen(false);
    if (row.location === locationRef.current) {
      // فكّ طيّ الأسلاف حتى لا يُحدَّد عنصر لا يُرى.
      setCollapsed((current) => {
        if (current.size === 0) return current;
        const next = new Set(current);
        for (const ancestor of pathOf(nodesRef.current, row.id)) next.delete(ancestor.id);
        return next;
      });
      setSelection([row.id]);
      setAnnouncement(`انتقل إلى «${row.label}».`);
      return;
    }
    pendingJumpRef.current = row.id;
    setLocation(row.location);
    setAnnouncement(`انتقال إلى «${row.label}» في «${row.menuName}»…`);
  }, []);

  /* ─── تصدير واستيراد المسوّدة — عمل محلّي بالكامل ─── */
  const downloadExport = useCallback(() => {
    const loc = locationRef.current;
    const json = buildExport(loc, locations?.[loc] ?? loc, nodesRef.current);
    const url = URL.createObjectURL(new Blob([json], { type: 'application/json' }));
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `menu-${loc}.json`;
    anchor.click();
    URL.revokeObjectURL(url);
    setAnnouncement('نُزّل ملف التصدير.');
  }, [locations]);

  const applyImport = useCallback(
    (imported: MenuNode[]) => {
      setDialog(null);
      setSelection([]);
      commit(`استيراد ${imported.length} عنصرًا من ملف — تراجعٌ واحد يعيد ما قبله`, imported);
    },
    [commit],
  );

  const viewProps = { nodes, selection, collapsed, editingId, issuesByNode, query, commands };
  const live = nodes.filter((node) => !node.deleted);
  const probeUrls = Array.from(
    new Set(live.filter((node) => node.url.trim() !== '').map((node) => node.url.trim())),
  );
  const topLevel = childrenOf(live, null).length;
  const deepest = rows(live).reduce((max, row) => Math.max(max, row.depth + 1), 0);
  const leaves = live.filter((node) => childrenOf(live, node.id).length === 0).length;
  const externals = live.filter((node) => /^https?:\/\//i.test(node.url)).length;
  const fromRealContent = live.filter((node) => node.sourceId !== null).length;

  /*
   * المنع يسبق كلّ شيء: محرّرٌ بلا مسوّدة ولا مواقع ولا حفظ ولا نشر ليس
   * محرّرًا. يُسمّى الحقّ الناقص كما يكتبه الخادم لا كما نترجمه.
   */
  if (denied) {
    return (
      <section className={styles.studio}>
        <PageHeader trail="استوديو القوائم" title="استوديو القوائم" />
        <div className="state-error" role="alert">
          <p>
            لا تملك صلاحية <bdi dir="ltr">appearance.manage</bdi> لقراءة قوائم التنقّل أو تحريرها.
            كلّ مسارات <bdi dir="ltr">/admin/navigation</bdi> خلفها.
          </p>
        </div>
      </section>
    );
  }

  return (
    <section className={styles.studio}>
      <PageHeader
        trail="استوديو القوائم"
        title="استوديو القوائم"
        actions={
          <>
            <button type="button" className="btn btn-ghost" onClick={() => setDialog('shortcuts')}>
              الاختصارات
            </button>
            <button type="button" className="btn btn-primary" onClick={() => setDialog('publish')}>
              عقد النشر الذرّي
            </button>
          </>
        }
      />

      <p className={styles.truthBanner} role="note">
        <Icon name="info" size={16} />
        <span>
          هذا الاستوديو <strong>موصول بالخادم</strong>: المسوّدة تُقرأ وتُحفظ لكل موقع من مواقع
          القالب، والنشر ذرّي (قفل · فحص مسبق · لقطة · التزام · إبطال كاش · تحقّق) وقابل للتراجع،
          وفاحص الوجهات يقيس فعلًا. المنشور وحده يصل الزائر — المسوّدة لا تظهر لأحد. ما لا يزال
          غائبًا (مكتبة قوائم متعددة، الأرشيف، قراءة المراجعات وسجل التدقيق) معدَّد بالاسم في قسم
          «النشر والدورة».
        </span>
      </p>

      {recovery ? (
        <div className={styles.recovery} role="status" aria-live="polite">
          <Icon name="restore" size={16} />
          <span className={styles.recoveryText}>
            نسخة الاسترجاع المحلّية لهذا الموقع تختلف عن مسوّدة الخادم: فيها{' '}
            <bdi dir="ltr">{recovery.nodes.length}</bdi> عنصرًا، آخر كتابة{' '}
            <bdi dir="ltr">
              {TIME_FORMAT.format(new Date(recovery.savedAt))}
            </bdi>
            .
          </span>
          <button
            type="button"
            className={styles.recoveryPrimary}
            onClick={() => {
              // نسخة قديمة قد تحمل معرّفات مؤقّتة يرفض الخادم نشرها.
              const adopted = adoptStableIds(recovery.nodes);
              setNodes(adopted.nodes);
              setEdits(recovery.edits);
              setPast([]);
              setFuture([]);
              setRecovered(null);
              setAnnouncement('استُعيدت نسخة الاسترجاع المحلية — احفظ على الخادم لتصير مسوّدةً حقيقية.');
            }}
          >
            استعادة المسودة
          </button>
          <button
            type="button"
            className={styles.recoverySecondary}
            onClick={() => {
              clearDraft(location);
              setRecovered(null);
              setAnnouncement('حُذفت نسخة الاسترجاع المحلية.');
            }}
          >
            تجاهل وحذف
          </button>
        </div>
      ) : null}

      <div className={styles.toolbar}>
        <label className={styles.locationPicker}>
          <span className={styles.locationLabel}>موقع القالب</span>
          <select
            value={location}
            disabled={locations === null}
            onChange={(event) => setLocation(event.target.value)}
          >
            {locations === null ? (
              <option value={location}>جارٍ قراءة المواقع…</option>
            ) : (
              Object.entries(locations).map(([key, name]) => (
                <option key={key} value={key}>
                  {name}
                </option>
              ))
            )}
          </select>
        </label>

        <div className={styles.toolGroup}>
          <button
            type="button"
            className={styles.toolBtn}
            onClick={() => commands.insertAfter(selection.length === 1 ? (selection[0] ?? null) : null)}
          >
            <Icon name="plus" size={13} />
            إضافة عنصر
          </button>
          <button type="button" className={styles.toolBtn} onClick={undo} disabled={past.length === 0}>
            <Icon name="undo" size={14} />
            تراجع
          </button>
          <button type="button" className={styles.toolBtn} onClick={redo} disabled={future.length === 0}>
            <Icon name="redo" size={14} />
            إعادة
          </button>
          <button
            type="button"
            className={styles.toolBtn}
            disabled={serverVersion === null || busy || !unsavedOnServer}
            onClick={() => void saveToServer()}
          >
            <Icon name="save" size={14} />
            {busy ? 'جارٍ الحفظ…' : 'حفظ على الخادم'}
          </button>
          <button
            type="button"
            className={styles.toolBtn}
            onClick={() => setCollapsed(new Set())}
            disabled={collapsed.size === 0}
          >
            توسيع الكل
          </button>
          <button
            type="button"
            className={styles.toolBtn}
            onClick={() => setCollapsed(new Set(nodes.filter((n) => childrenOf(nodes, n.id).length > 0).map((n) => n.id)))}
            disabled={nodes.length === 0}
          >
            طي الكل
          </button>
          <button
            type="button"
            className={styles.toolBtn}
            disabled={live.length === 0}
            title={live.length === 0 ? 'المسوّدة فارغة — لا شيء يُصدَّر.' : undefined}
            onClick={() => setDialog('export')}
          >
            <Icon name="download" size={13} />
            تصدير
          </button>
          <button type="button" className={styles.toolBtn} onClick={() => setDialog('import')}>
            <Icon name="upload" size={13} />
            استيراد
          </button>
        </div>

        <div className={styles.searchWrap}>
          <label className={styles.search}>
            <span className={styles.srOnly}>بحث في كل القوائم</span>
            <Icon name="search" size={14} />
            <input
              type="search"
              value={query}
              placeholder="ابحث في كل القوائم: نص، رابط، فئة، شارة…"
              onChange={(event) => {
                setQuery(event.target.value);
                setSearchOpen(event.target.value.trim() !== '');
              }}
              onKeyDown={(event) => {
                if (event.key === 'Escape' && searchOpen) {
                  event.stopPropagation();
                  setSearchOpen(false);
                }
              }}
            />
          </label>
          {searchOpen && query.trim() !== '' ? (
            <div className={styles.searchPop} role="region" aria-label="نتائج عبر كل القوائم">
              <div className={styles.searchPopHead}>
                <span>نتائج عبر كل القوائم</span>
                <button
                  type="button"
                  className={styles.searchPopClose}
                  onClick={() => setSearchOpen(false)}
                >
                  إغلاق
                </button>
              </div>
              {searchRows.length === 0 ? (
                <span className={styles.searchPopMore}>لا نتيجة في أي قائمة.</span>
              ) : (
                <>
                  {searchRows.slice(0, SEARCH_ROWS_SHOWN).map((row) => (
                    <button
                      key={row.key}
                      type="button"
                      className={styles.searchRow}
                      onClick={() => jumpToResult(row)}
                    >
                      <span className={styles.searchRowTop}>
                        <span className={styles.searchRowLabel}>{row.label}</span>
                        <span className={styles.searchChipType}>{row.typeName}</span>
                        <span className={styles.searchChipMenu}>{row.menuName}</span>
                      </span>
                      <span className={styles.searchRowPath}>{row.path}</span>
                      <bdi dir="ltr" className={styles.searchRowUrl}>
                        {row.url}
                      </bdi>
                    </button>
                  ))}
                  {searchRows.length > SEARCH_ROWS_SHOWN ? (
                    <span className={styles.searchPopMore}>
                      +<bdi dir="ltr">{searchRows.length - SEARCH_ROWS_SHOWN}</bdi> نتيجة أخرى —
                      ضيّق البحث لرؤيتها.
                    </span>
                  ) : null}
                </>
              )}
            </div>
          ) : null}
        </div>

        <label className={styles.expertToggle}>
          <input type="checkbox" checked={expert} onChange={(event) => setExpert(event.target.checked)} />
          <span>وضع الخبير (حقول CSS و rel و aria)</span>
        </label>

        <span className={styles.spacer} />

        <span className={styles.stateChip}>
          <bdi dir="ltr">{live.length}</bdi> عنصرًا · عمق أقصى <bdi dir="ltr">{MAX_DEPTH}</bdi>
        </span>
        <span className={unsavedOnServer ? styles.stateChipMuted : styles.stateChipOk}>
          {serverVersion === null
            ? 'جارٍ قراءة الخادم…'
            : unsavedOnServer
              ? 'تعديلات غير محفوظة'
              : publishedVersion === serverVersion
                ? 'محفوظة ومنشورة'
                : 'محفوظة — لم تُنشر'}
        </span>
        {savedAt === null ? null : (
          <span className={styles.stateChipMuted}>نسخة استرجاع محلّية محفوظة</span>
        )}
      </div>

      {locations === null ? null : (
        <ul className={styles.locationPills} aria-label="حالة مواقع القالب">
          {Object.entries(locations).map(([key, name]) => {
            const menu = menus.find((row) => row.location === key) ?? null;
            return (
              <li key={key}>
                <button
                  type="button"
                  className={styles.locationPill}
                  aria-current={key === location ? 'true' : undefined}
                  onClick={() => setLocation(key)}
                >
                  <span className={styles.locationPillName}>
                    {name}
                    {STOREFRONT_REACH[key]?.consumed === false ? (
                      <span className={styles.locationPillTag}>لا يقرؤه المتجر</span>
                    ) : null}
                  </span>
                  <span className={styles.locationPillMeta}>
                    {menu === null || menu.published === null ? (
                      'لم تُنشر بعد'
                    ) : (
                      <>
                        <bdi dir="ltr">{menu.published.length}</bdi> عنصرًا منشورًا
                        {menu.hasUnpublishedChanges ? ' · فيها تغييرات غير منشورة' : ''}
                      </>
                    )}
                  </span>
                </button>
              </li>
            );
          })}
        </ul>
      )}

      {/*
        مدى النشر يُقال قبله لا بعده.

        «نُشرت القائمة» صادقةٌ في القاعدة وكاذبةٌ في المتجر حين يكون الموقع
        غير مقروء: المشغّل ينشر التذييل، يفتح المتجر، لا يجد شيئًا، فيعيد
        النشر ظنًّا أنّ العطل في النشر. المرجع في `STOREFRONT_REACH`.
      */}
      {STOREFRONT_REACH[location] ? (
        <p
          className={STOREFRONT_REACH[location]!.consumed ? styles.reachNote : styles.reachWarn}
          role="note"
        >
          <Icon name={STOREFRONT_REACH[location]!.consumed ? 'info' : 'warn'} size={14} />
          <span>{STOREFRONT_REACH[location]!.note}</span>
        </p>
      ) : null}

      {/* تعذّرت قراءة مسوّدة الموقع — بسببها وبزرّها، لا رسالةٌ تُنسى. */}
      {loadError === null ? null : (
        <div className="state-error" role="alert">
          <p>{loadError}</p>
          <button type="button" className="btn btn-ghost" onClick={() => setReloadTick((tick) => tick + 1)}>
            إعادة المحاولة
          </button>
        </div>
      )}

      {/* نتيجة آخر نداء للخادم — تُعرض دائمًا: خطأ صامت يُقرأ نجاحًا. */}
      {serverNote ? (
        <p
          className={serverNote.tone === 'ok' ? 'state-ok' : 'state-error'}
          role={serverNote.tone === 'ok' ? 'status' : 'alert'}
        >
          {serverNote.text}
        </p>
      ) : null}

      {selection.length > 1 ? (
        <div className={styles.bulkBar}>
          <span>
            <bdi dir="ltr">{selection.length}</bdi> عناصر محددة
          </span>
          <button
            type="button"
            className={styles.bulkBtn}
            onClick={() => {
              let next: MenuNode[] = nodesRef.current;
              for (const id of selection) next = setDeleted(next, id, true);
              commit('حذف مؤجل للمحدد', next);
            }}
          >
            حذف مؤجل للمحدد
          </button>
          <button
            type="button"
            className={styles.bulkBtn}
            onClick={() => {
              let next: MenuNode[] = nodesRef.current;
              for (const id of selection) next = setDeleted(next, id, false);
              commit('استرجاع المحدد', next);
            }}
          >
            استرجاع المحدد
          </button>
          <span className={styles.spacer} />
          <button type="button" className={styles.bulkBtn} onClick={() => setSelection([])}>
            إلغاء التحديد
          </button>
        </div>
      ) : null}

      <nav className={styles.sectionTabs} aria-label="أقسام الاستوديو">
        {SECTION_TABS.map(([key, label, icon]) => (
          <button
            key={key}
            type="button"
            className={styles.sectionTab}
            aria-current={section === key ? 'page' : undefined}
            onClick={() => setSection(key)}
          >
            <Icon name={icon} size={14} />
            {label}
          </button>
        ))}
      </nav>

      <p className={styles.srOnly} role="status" aria-live="polite">
        {announcement}
      </p>

      {section === 'studio' ? (
        <div className={styles.studioGrid}>
          <main className={styles.stage}>
            <div className={styles.viewTabs} role="group" aria-label="عرض المسودة">
              {VIEW_TABS.map(([key, label, icon]) => (
                <button
                  key={key}
                  type="button"
                  className={styles.viewTab}
                  aria-pressed={view === key}
                  onClick={() => setView(key)}
                >
                  <Icon name={icon} size={13} />
                  {label}
                </button>
              ))}
            </div>

            {nodes.length === 0 ? (
              <div className={styles.emptyStudio}>
                <p className={styles.emptyTitle}>مسوّدة هذا الموقع فارغة على الخادم</p>
                <p>
                  ابدأ بإضافة عنصر، أو اختر محتوى حقيقيًا من مكتبة المحتوى في العمود الجانبي: المنتجات
                  تأتي من{' '}
                  <bdi dir="ltr">
                    <code>GET /api/v1/admin/products</code>
                  </bdi>{' '}
                  والمراحل والمواد من{' '}
                  <bdi dir="ltr">
                    <code>GET /api/v1/catalog/*</code>
                  </bdi>
                  .
                </p>
                <button type="button" className={styles.toolBtn} onClick={() => commands.insertAfter(null)}>
                  <Icon name="plus" size={13} />
                  إضافة أول عنصر
                </button>
              </div>
            ) : view === 'tree' ? (
              <TreeView {...viewProps} />
            ) : view === 'map' ? (
              <MapView {...viewProps} />
            ) : view === 'table' ? (
              <TableView {...viewProps} />
            ) : (
              <LiveView {...viewProps} />
            )}
          </main>

          <aside className={styles.rail}>
            <div className={styles.railTabs} role="group" aria-label="أدوات العمود الجانبي">
              {(
                [
                  ['inspector', 'المفتش', 'pen'],
                  ['library', 'إضافة محتوى', 'plus'],
                  ['health', 'الصحة', 'shield'],
                ] as [RailTab, string, string][]
              ).map(([key, label, icon]) => (
                <button
                  key={key}
                  type="button"
                  className={styles.railTab}
                  aria-pressed={railTab === key}
                  onClick={() => setRailTab(key)}
                >
                  <Icon name={icon} size={13} />
                  {label}
                </button>
              ))}
            </div>

            {railTab === 'inspector' ? (
              <Inspector
                nodes={nodes}
                selection={selection}
                issues={issues}
                expert={expert}
                onPatch={(id, patch) =>
                  commit(
                    'تعديل خصائص عنصر',
                    patchNode(nodesRef.current, id, patch),
                    // الحقل نفسه على العنصر نفسه = تعديلٌ واحد في التاريخ.
                    `patch:${id}:${Object.keys(patch).sort().join(',')}`,
                  )
                }
                onReparent={(id, parentId) =>
                  commit(
                    'تغيير الأب',
                    reparent(nodesRef.current, id, parentId, childrenOf(nodesRef.current, parentId).length),
                  )
                }
                onMove={commands.move}
                onNest={commands.nest}
                onUnnest={commands.unnest}
                onDuplicate={commands.duplicate}
                onRemove={commands.remove}
                onRestore={commands.restore}
                onClear={() => setSelection([])}
              />
            ) : railTab === 'library' ? (
              <LibraryRail nodes={nodes} selection={selection} onAdd={addFromLibrary} />
            ) : (
              <HealthRail
                summary={summary}
                issues={issues}
                nodes={nodes}
                onGo={(id) => {
                  setSelection([id]);
                  setRailTab('inspector');
                }}
              />
            )}
          </aside>
        </div>
      ) : null}

      {section === 'diagnostics' ? (
        <div className={styles.diagnostics}>
          <section className={styles.diagScore}>
            <div className={styles.ring}>
              <svg viewBox="0 0 100 100" width={112} height={112} aria-hidden="true">
                <circle cx="50" cy="50" r="45" fill="none" stroke="var(--ms-ring-track)" strokeWidth="9" />
                {live.length > 0 ? (
                  <circle
                    cx="50"
                    cy="50"
                    r="45"
                    fill="none"
                    stroke={
                      summary.score > 85
                        ? 'var(--primary)'
                        : summary.score > 60
                          ? 'var(--ms-warn)'
                          : 'var(--ms-danger)'
                    }
                    strokeWidth="9"
                    strokeLinecap="round"
                    strokeDasharray={`${(summary.score * 283) / 100} 283`}
                    transform="rotate(-90 50 50)"
                  />
                ) : null}
              </svg>
              <span className={styles.ringValue}>
                <bdi dir="ltr">{live.length === 0 ? '—' : summary.score}</bdi>
              </span>
            </div>
            <p className={styles.ringLabel}>درجة صحة المسودة</p>
            <p className={styles.ringNote}>
              محسوبة من الفحوص القابلة للحسم في المتصفح وحدها: حاجب −١٤، تحذير −٥، إرشاد −١٫٥. نتائج
              فحص الوجهات لا تدخل فيها عمدًا: وجهة خارجية بطيئة أو محجوبة اليوم ليست عيبًا في بنية
              القائمة، وخلطهما يجعل الدرجة تتأرجح مع الشبكة.
            </p>
          </section>

          <section className={styles.panelCard}>
            <h2>ملاحظات التحقق</h2>
            {live.length === 0 ? (
              <p className={styles.muted}>المسودة فارغة — لا شيء يُفحص.</p>
            ) : issues.length === 0 ? (
              <p className={styles.ok}>
                <Icon name="check" size={15} />
                لا ملاحظات على الفحوص المحلية.
              </p>
            ) : (
              (['error', 'warn', 'info'] as const).map((severity) => {
                const group = issues.filter((issue) => issue.severity === severity);
                if (group.length === 0) return null;
                return (
                  <div key={severity} className={styles.issueGroup}>
                    <h3 className={GROUP_CLASS[severity]}>
                      {severity === 'error' ? 'حاجب للنشر' : severity === 'warn' ? 'تحذير' : 'إرشاد فقط'}
                      <span className={styles.groupCount}>
                        <bdi dir="ltr">{group.length}</bdi>
                      </span>
                    </h3>
                    <ul className={styles.issueRows}>
                      {group.map((issue, index) => (
                        <li key={`${issue.code}-${index}`}>
                          <span className={styles.issueTitle}>{issue.title}</span>
                          <span className={styles.issueDetail}>{issue.detail}</span>
                          {issue.nodeId ? (
                            <button
                              type="button"
                              className={styles.linkBtn}
                              onClick={() => {
                                setSelection([issue.nodeId as string]);
                                setSection('studio');
                                setRailTab('inspector');
                              }}
                            >
                              اذهب إلى «{byId(nodes, issue.nodeId)?.label || '⟨بلا نص⟩'}»
                            </button>
                          ) : null}
                        </li>
                      ))}
                    </ul>
                  </div>
                );
              })
            )}
          </section>

          <section className={styles.panelCard}>
            <div className={styles.panelHead}>
              <h2>سلامة التنقل و SEO</h2>
              <span className={styles.scanStamp}>
                {probeAt === null ? (
                  'آخر فحص شبكي: لم يجرِ بعد'
                ) : (
                  <>
                    آخر فحص شبكي: <bdi dir="ltr">{TIME_FORMAT.format(new Date(probeAt))}</bdi>
                  </>
                )}
              </span>
              <button
                type="button"
                className={styles.toolBtn}
                disabled={probing || probeUrls.length === 0}
                onClick={() => void runLinkCheck()}
              >
                <Icon name="shield" size={13} />
                {probing ? 'جارٍ الفحص…' : probeAt === null ? 'افحص الوجهات' : 'إعادة الفحص'}
              </button>
            </div>

            {probeError ? (
              <p className="state-error" role="alert">
                {probeError}
              </p>
            ) : null}

            {probeUrls.length === 0 ? (
              <p className={styles.muted}>لا وجهة في المسودة تُفحص.</p>
            ) : (
              /* الجدول أعرض من عموده على الشاشات الضيّقة: طوقٌ يُمرَّر ويُبأَّر بلوحة المفاتيح. */
              <div className="table-scroll" tabIndex={0} role="region" aria-label="نتيجة فحص وجهات المسودة">
              <table className="data-table">
                <caption className={styles.srOnly}>نتيجة فحص وجهات المسودة</caption>
                <thead>
                  <tr>
                    <th scope="col">العنصر</th>
                    <th scope="col">الحالة</th>
                    <th scope="col">سلسلة التحويل</th>
                    <th scope="col">الفهرسة</th>
                    <th scope="col">الرابط الدائم</th>
                  </tr>
                </thead>
                <tbody>
                  {live
                    .filter((node) => node.url.trim() !== '')
                    .map((node) => {
                      const result = probes?.get(node.url.trim()) ?? null;
                      return (
                        <tr key={node.id}>
                          <th scope="row">
                            {node.label || '⟨بلا نص⟩'}
                            <span className={styles.rowUrl}>
                              <bdi dir="ltr">{node.url}</bdi>
                            </span>
                          </th>
                          <td>
                            {result === null ? (
                              <span className={styles.ncTag}>لم يُفحص</span>
                            ) : result.status === 'OK' ? (
                              <span className={styles.okTag}>
                                <bdi dir="ltr">{result.httpStatus ?? '—'}</bdi>
                              </span>
                            ) : (
                              <span className={styles.badTag} title={result.reason ?? undefined}>
                                {result.status === 'BLOCKED' ? 'محجوبة' : 'لا تستجيب'}
                              </span>
                            )}
                          </td>
                          <td>
                            {result === null ? (
                              '—'
                            ) : result.hops === 0 ? (
                              'بلا تحويل'
                            ) : (
                              <bdi dir="ltr">{`${result.hops} → ${result.finalUrl ?? ''}`}</bdi>
                            )}
                          </td>
                          <td>
                            {result === null
                              ? '—'
                              : result.indexable === null
                                ? 'غير معروفة'
                                : result.indexable
                                  ? 'مفهرَسة'
                                  : 'noindex'}
                          </td>
                          <td>
                            {result?.canonical ? (
                              <bdi dir="ltr">{result.canonical}</bdi>
                            ) : result === null ? (
                              '—'
                            ) : (
                              'بلا رابط دائم'
                            )}
                          </td>
                        </tr>
                      );
                    })}
                </tbody>
              </table>
              </div>
            )}

            <p className={styles.muted}>
              الفحص يجري على الخادم عبر{' '}
              <bdi dir="ltr">
                <code>POST /api/v1/admin/navigation/link-check</code>
              </bdi>{' '}
              بحماية SSRF: بروتوكولات http/https فقط، منفذا ٨٠ و٤٤٣، رفض كل عنوان خاص أو محلّي أو
              مرتبط بالوصلة، وتتبّع التحويلات خطوةً خطوة بحد ٥ قفزات مع فحص كل قفزة من جديد. الحدّ
              عشرون وجهة في الطلب الواحد، والمعدّل محدود. لا تدخل هذه النتائج في درجة الصحة أعلاه:
              وجهة خارجية بطيئة اليوم ليست عيبًا في بنية القائمة.
            </p>
          </section>

          <section className={styles.panelCard}>
            <h2>قراءة بنية المسودة</h2>
            {live.length === 0 ? (
              <p className={styles.muted}>لا بنية بعد.</p>
            ) : (
              <ul className={styles.insightGrid}>
                {[
                  ['عنصرًا نشطًا', live.length],
                  ['في المستوى الأعلى', topLevel],
                  ['أقصى عمق', deepest],
                  ['عنصرًا بلا أبناء', leaves],
                  ['رابطًا خارجيًا', externals],
                  ['من محتوى حقيقي', fromRealContent],
                ].map(([label, value]) => (
                  <li key={String(label)}>
                    <span className={styles.insightValue}>
                      <bdi dir="ltr">{value}</bdi>
                    </span>
                    <span>{label}</span>
                  </li>
                ))}
              </ul>
            )}
            <p className={styles.muted}>
              كل رقم هنا محسوب من المسودة التي أمامك الآن — لا مقياس أداء ولا إحصاء زيارات، فهذه تحتاج
              قياسًا حقيقيًا لا يوجد له مصدر.
            </p>
          </section>
        </div>
      ) : null}

      {section === 'publish' ? (
        <div className={styles.diagnostics}>
          <section className={styles.panelCard}>
            <h2>النشر الذرّي</h2>
            <p className={styles.muted}>
              النشر ينفّذه المنسّق على الخادم: قفل موزّع، فحص مسبق، لقطة، التزام في معاملة واحدة مع
              حدث في صندوق الصادر، إبطال كاش موجَّه، ثم تحقّق ببصمة ممّا يُخدَم فعلًا — وأي انحراف
              بعد الالتزام يُطلق تراجعًا تلقائيًّا. الحوار يعرض كل خطوة وحالتها الحقيقية.
            </p>
            <dl className={styles.factRows}>
              <div>
                <dt>الإصدار المحفوظ</dt>
                <dd>
                  <bdi dir="ltr">{serverVersion ?? '—'}</bdi>
                </dd>
              </div>
              <div>
                <dt>الإصدار المنشور</dt>
                <dd>
                  <bdi dir="ltr">{publishedVersion ?? 'لم يُنشر'}</bdi>
                </dd>
              </div>
              <div>
                <dt>وقت آخر نشر</dt>
                <dd>
                  {publishedAt === null ? (
                    '—'
                  ) : (
                    <bdi dir="ltr">{TIME_FORMAT.format(new Date(publishedAt))}</bdi>
                  )}
                </dd>
              </div>
            </dl>

            {lastOperation === null ? null : (
              <p className={styles.operationLine}>
                آخر عملية في هذه الجلسة:{' '}
                <bdi dir="ltr">
                  <code>{lastOperation.id}</code>
                </bdi>{' '}
                — الحالة <strong>{lastOperation.status}</strong>
                {lastOperation.failureDetail ? ` — ${lastOperation.failureDetail}` : ''}
              </p>
            )}

            <div className={styles.publishActions}>
              <button type="button" className="btn btn-primary" onClick={() => setDialog('publish')}>
                افتح عقد النشر
              </button>
              <button
                type="button"
                className={styles.dangerBtn}
                disabled={busy || lastOperation === null || lastOperation.status !== OPERATION_COMPLETED}
                onClick={() => void rollbackLastPublish()}
              >
                تراجع عن آخر نشر
              </button>
            </div>
            <p className={styles.muted}>
              {lastOperation === null
                ? 'التراجع يحتاج معرّف عملية نشر. النشر من هذه الصفحة يعيد المعرّف فيُفعَّل الزر؛ عملية نشرَها زميل في جلسة أخرى لا يُعرف معرّفها هنا لأن لا نقطة قراءة لدفتر العمليات بالقائمة.'
                : lastOperation.status === OPERATION_ROLLED_BACK
                  ? 'تراجعت هذه العملية فعلًا: المنشور عاد إلى لقطة ما قبلها.'
                  : 'التراجع يعيد المنشور إلى الإصدار الذي كان منشورًا قبل هذه العملية، ويتحقق من الاستعادة بالبصمة قبل أن يعلنها ناجحة.'}
            </p>
          </section>

          <section className={styles.panelCard}>
            <h2>تاريخ الجلسة (تراجع/إعادة)</h2>
            {past.length === 0 && future.length === 0 ? (
              <p className={styles.muted}>لا تغييرات في هذه الجلسة بعد.</p>
            ) : (
              <ol className={styles.historyList}>
                {past.map((entry, index) => (
                  <li key={`past-${index}`}>
                    <bdi dir="ltr" className={styles.historyIndex}>
                      {index + 1}
                    </bdi>
                    {entry.label}
                  </li>
                ))}
                {future.map((entry, index) => (
                  <li key={`future-${index}`} className={styles.historyFuture}>
                    <bdi dir="ltr" className={styles.historyIndex}>
                      ↷
                    </bdi>
                    {entry.label} — متاح للإعادة
                  </li>
                ))}
              </ol>
            )}
            <p className={styles.muted}>
              هذا تاريخ في ذاكرة الصفحة فقط، لا سجلّ تدقيق: يزول بإعادة التحميل ولا يحمل مَن ولا متى.
              الخادم يسجّل كل حفظ ونشر وتراجع في سجل التدقيق، لكن لا نقطة قراءة تعيده للعرض هنا.
            </p>
            <button
              type="button"
              className={styles.dangerBtn}
              disabled={nodes.length === 0}
              onClick={() => {
                commit('تجاهل المسودة كاملة', []);
                clearDraft(location);
                setSelection([]);
                setSavedAt(null);
              }}
            >
              تجاهل المسودة كاملة
            </button>
          </section>

          <section className={styles.panelCard}>
            <h2>ما تحتاجه بقية دورة حياة القوائم</h2>
            {/* أربعة أعمدة في لوحٍ ضيّق: طوقٌ يُمرَّر ويُبأَّر بلوحة المفاتيح. */}
            <div className="table-scroll" tabIndex={0} role="region" aria-label="الأسطح التي لا نقطة نهاية لها">
            <table className="data-table">
              <caption className={styles.srOnly}>الأسطح التي لا نقطة نهاية لها</caption>
              <thead>
                <tr>
                  <th scope="col">السطح</th>
                  <th scope="col">ما يفعله</th>
                  <th scope="col">الحالة</th>
                  <th scope="col">النقطة المطلوبة</th>
                </tr>
              </thead>
              <tbody>
                {MISSING_SURFACES.map((surface) => (
                  <tr key={surface.endpoint}>
                    <th scope="row">{surface.title}</th>
                    <td>{surface.detail}</td>
                    <td>
                      <span className={styles.ncTag}>NOT_CONFIGURED</span>
                    </td>
                    <td>
                      <bdi dir="ltr">
                        <code>{surface.endpoint}</code>
                      </bdi>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            </div>
          </section>
        </div>
      ) : null}

      {dialog === 'publish' ? (
        <PublishContractDialog
          nodes={nodes}
          issues={issues}
          locationName={locations?.[location] ?? location}
          operationId={lastOperation?.id ?? null}
          contract={publishContract}
          location={location}
          onClose={() => setDialog(null)}
          onPublish={() => void publishToServer()}
          publishing={busy}
          blockedReason={
            issues.some((issue) => issue.severity === 'error')
              ? 'يوجد فحص حاجب لم يُعالَج. أصلحه قبل النشر.'
              : serverVersion === null
                ? 'لم تُقرأ حالة الخادم بعد.'
                : unsavedOnServer
                  ? 'المسوّدة غير محفوظة على الخادم. النشر ينسخ المحفوظ، فاحفظ أوّلًا.'
                  : live.length === 0
                    ? 'المسوّدة فارغة: نشرها يترك هذا الموقع بلا روابط — والخادم يرفضها.'
                    : publishedVersion === serverVersion
                      ? 'لا تغيير عن المنشور الحالي.'
                      : null
          }
        />
      ) : null}
      {dialog === 'shortcuts' ? <ShortcutsDialog onClose={() => setDialog(null)} /> : null}
      {dialog === 'export' ? (
        <ExportDialog
          name={locations?.[location] ?? location}
          json={buildExport(location, locations?.[location] ?? location, nodes)}
          count={live.length}
          onClose={() => setDialog(null)}
          onDownload={downloadExport}
        />
      ) : null}
      {dialog === 'import' ? (
        <ImportDialog
          locationName={locations?.[location] ?? location}
          onClose={() => setDialog(null)}
          onApply={applyImport}
        />
      ) : null}
      {branchDelete === null ? null : (
        <BranchDeleteDialog
          name={byId(nodes, branchDelete)?.label || '⟨بلا نص⟩'}
          count={descendantIds(nodes, branchDelete).size}
          onClose={() => setBranchDelete(null)}
          onDeleteBranch={() => {
            commit('حذف الفرع كاملًا', setDeleted(nodesRef.current, branchDelete, true));
            setBranchDelete(null);
          }}
          onPromote={() => {
            commit('حذف الأب وترقية أبنائه', deleteWithPromotion(nodesRef.current, branchDelete));
            setBranchDelete(null);
          }}
        />
      )}
    </section>
  );
}
