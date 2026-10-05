/**
 * سجلّ التنقّل — اختبارات المطابقة بعد دمج استوديو التصميم.
 *
 * ثلاثة أسطح تقرأ هذا السجلّ (الشريط الجانبي · شريطا التبويبات · مركز
 * الاختصارات)، وكلّها تعتمد على `workspaceFor` و`allDestinations`. وبعد
 * إضافة طبقة الأقسام صار المنطق غير بديهيّ: القسم يُطابَق كوجهةٍ كاملة ثمّ
 * يُنسب إلى أبيه، والأب يفوز بطول مطابقة ابنه لا بطول مساره.
 *
 * كلّ حالةٍ هنا مسارٌ حقيقيّ في اللوحة، لا مسارٌ مُختلَق ليمرّ.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { WORKSPACES, workspaceFor, allDestinations } from '../nav.ts';

const at = (p) => {
  const m = workspaceFor(p);
  assert.notEqual(m, null, `لا مطابقة لـ${p}`);
  return { ws: m.ws.label, dest: m.destination.label, section: m.activeSection };
};

/*
 * ٢٠٢٦-٠٩-١٥: «المنتجات» خرجت من الاستوديو إلى مساحة «الكتالوج».
 *
 * الاختباران أدناه يثبّتان شكل شريط الاستوديو عمدًا، ليكون تغييرُه قرارًا
 * يُراجَع لا انزلاقًا. وهذا التغيير رُوجع: الكتالوج بعد النموذج الكونيّ
 * صار صيغًا وعروضًا وسماتٍ وحزمًا واكتمالَ بيانات، فلم يعد سطحَ تحريرٍ
 * واحدًا يليق بتبويبٍ تحت «كيف يبدو المتجر».
 */
/*
 * ─── لماذا وجهتان لا واحدة (٢٠٢٦-٠٩-١٨) ───
 *
 * كان هذا الاختبار يثبّت وجهةً واحدة بعد حذف استوديو التصميم. وعاد سطح
 * «الرئيسية» — وهو أحد الخمسة التي يذكر `nav.ts` أنّها دُمجت في الاستوديو
 * ثمّ ذهبت معه — لأنّ محتوى الصفحة الرئيسة صار له محرّرٌ فعليّ
 * (`content.manage` · `/home-content`)، بعد أن كان يُقرأ من `settings` ولا
 * يكتبه إلّا البذّار.
 *
 * ونيّة الاختبار محفوظة: القائمة ما تزال **مثبَّتة بالضبط**، فنموّها العابر
 * يبقى فشلًا لا مرورًا صامتًا.
 */
test('مساحة الاستوديو وجهتان: محتوى الرئيسة والقوائم', () => {
  const studio = WORKSPACES.find((w) => w.id === 'studio');
  const menus = studio.destinations.find((d) => d.href === '/navigation');
  assert.ok(menus, 'القوائم غائبة عن مساحة الاستوديو');
  /* وجهةُ المساحة الافتراضية هي الوجهة الوحيدة الباقية — لا مسارٌ محذوف. */
  assert.equal(studio.href, '/navigation');
  assert.equal(menus.sections, undefined);
  assert.deepEqual(
    allDestinations()
      .filter((d) => d.ws.id === 'studio')
      .map((d) => d.dest.href),
    ['/home-content', '/navigation'],
  );
});

test('شريط الاستوديو: ماذا يقول المتجر، وكيف يُتنقّل فيه', () => {
  const studio = WORKSPACES.find((w) => w.id === 'studio');
  assert.deepEqual(
    studio.destinations.map((d) => d.label),
    ['الرئيسية', 'القوائم'],
  );

  /* و«ماذا يبيع المتجر» صارت مساحةً قائمةً بذاتها لا تبويبًا فيه. */
  const catalog = WORKSPACES.find((w) => w.id === 'catalog');
  /*
   * وجهةٌ واحدة بعد الدمج: «اكتمال البيانات» صارت تبويبًا في القائمة نفسها،
   * وكلاهما يقرأ المنتجات ويجيب سؤالًا واحدًا — «ما الناقص قبل النشر؟».
   */
  assert.deepEqual(
    catalog.destinations.map((d) => d.label),
    ['المنتجات'],
  );
});

test('مساحة العمليّات تغطّي ما بين «دُفع» و«وصل»', () => {
  const ops = WORKSPACES.find((w) => w.id === 'operations');

  /*
   * وجهتان بعد الدمج: «طابور العمليّات» يحمل الشحنات والإرجاع والمخاطر
   * ومصادر الأصول تبويباتٍ، و«المخزون» يبقى مستقلًّا لأنّه يكتب.
   *
   * والتغطية لم تنقص: المسارات القديمة تحوّل إلى تبويباتها، وهو ما يفحصه
   * مطابقةُ الشاشات بالسجلّ.
   */
  assert.deepEqual(
    ops.destinations.map((d) => d.href),
    ['/operations/fulfillment', '/operations/inventory'],
  );

  /* كلّ وجهةٍ تعلن صلاحيتها: لا وجهة تُعرض في الشريط ثمّ تردّ ٤٠٣. */
  for (const d of ops.destinations) {
    assert.ok(d.permission, `${d.href} بلا صلاحية`);
  }
});

test('أطول مطابقة تفوز — والأب لا يبتلع ابنه ولا العكس', () => {
  assert.equal(at('/studio/catalog').dest, 'المنتجات');
  assert.equal(at('/studio/catalog/9').dest, 'المنتجات');
  /* `/studio` وحده لم يعد وجهةً: استوديو التصميم حُذف، ويُحوَّل إلى القوائم. */
  assert.equal(at('/navigation').dest, 'القوائم');
});

test('لا قسم يُطابَق بالمسار — التبويب في الاستعلام', () => {
  for (const p of ['/studio/catalog', '/orders']) {
    assert.equal(at(p).section, null, p);
  }
});



test('المساحات الأخرى لم تتأثّر بالدمج', () => {
  assert.equal(at('/orders').ws, 'المبيعات والعملاء');
  assert.equal(at('/customers').dest, 'العملاء');
  assert.equal(at('/analytics/os').dest, 'نظام تشغيل النمو');
  assert.equal(at('/navigation').dest, 'القوائم');
});

test('مسارٌ خارج السجلّ لا يُطابَق ولا يرمي', () => {
  assert.equal(workspaceFor('/lا-يوجد'), null);
  assert.equal(workspaceFor(''), null);
});

test('الفهرس يشمل الأقسام ولا يكرّر مسارًا', () => {
  const all = allDestinations();
  const hrefs = all.map((d) => d.dest.href);
  assert.equal(new Set(hrefs).size, hrefs.length, 'مسار مكرّر في الفهرس');
});

test('كلّ وجهةٍ في الاستوديو تعلن صلاحيتها — لا وجهة تقود إلى ٤٠٣', () => {
  const studio = WORKSPACES.find((w) => w.id === 'studio');
  const menus = studio.destinations.find((d) => d.href === '/navigation');
  assert.equal(menus.permission, 'appearance.manage');
  /* بادئة التفصيل تشمل المحرّرات والرصيف الكامل — كلّها خلف الصلاحية نفسها. */
});
