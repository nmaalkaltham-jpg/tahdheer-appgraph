import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
  /*
   * توليد ملفّات وكلاء الذكاء مُعطَّل — `AGENTS.md` و`CLAUDE.md` لا يُكتبان.
   *
   * `next dev` يكشف وكيلًا برمجيًّا من متغيّرات البيئة (CLAUDECODE · CURSOR ·
   * CODEX · …) ثمّ يكتب الملفّين في جذر التطبيق، ويُعيد كتابتهما في كلّ
   * إقلاع. والأخطر: إن غاب الاثنان أنشأهما من الصفر
   * (`generate-agent-files.js` → «Neither file exists — scaffold both»).
   * فحذفُهما بلا هذا المفتاح حذفٌ يدوم حتّى أوّل `npm run dev`.
   *
   * والمفتاح مدعومٌ من الإطار لا ترقيعًا في `node_modules`:
   * `start-server.js` يفحص `agentRules !== false` قبل الكتابة. وموضعُه هنا
   * يعني أنّه ينجو من `npm ci` الذي يمسح أيّ تعديلٍ على الحزم.
   */
  agentRules: false,
  /*
   * Next 16 يرفض الأصول غير المُعلَنة في التطوير: فتح الموقع على
   * 127.0.0.1 بدل localhost يجعله يردّ 403 على موارد العميل، فلا
   * يُحمَّل JS ولا يعمل أي useEffect — والصفحة تبدو عالقة بلا خطأ ظاهر.
   * (هذا ما عطّل زرّ الدخول: بقيت حالة الجلسة loading أبدًا.)
   */
  allowedDevOrigins: ["127.0.0.1", "localhost"],
  /*
   * ‎output: 'standalone'‎ **معطَّل عمدًا** — لا تُعِده بلا قراءة هذا.
   *
   * سببان، أوّلهما قاتل:
   *
   * ١) خادم standalone في Next 16.3.3 لا يطابق المسار الشامل
   *    ‎app/api/v1/[...path]‎، فيردّ 404 (HTML من Next نفسه) على كلّ نداء
   *    واجهة خلفية. مقيس على البناء نفسه وبمعرّف بناء واحد:
   *
   *      المسار                standalone   next start   dev
   *      /api/v1/admin/me         404          401        401
   *      /api/v1/products         404          200        200
   *      /api/health   (ثابت)     200          200        200
   *
   *    المسارات الثابتة وصفحات ‎[id]‎ تعمل في الوضعين؛ العطب في الـcatch-all
   *    وحده. أثره في الواجهة: «تعذّر التحقّق من الجلسة — فشل الطلب (404)».
   *
   * ٢) الوضع ينسخ شجرة المصدر كاملةً إلى ‎.next/standalone/apps/admin‎
   *    (‏52 م.ب) فيبدو المشروع وكأن فيه ‎apps/admin‎ مرّتين.
   *
   * التشغيل هنا ‎next start‎ (انظر ‎package.json‎). و‎Dockerfile‎ المجاور يشغّل
   * ‎node server.js‎ أي المسار المكسور نفسه، فبناؤه سيفشل الآن بوضوح عند
   * ‎COPY … .next/standalone‎ — وهذا مقصود خيرٌ من لوحةٍ تُقلع ولا تُصادق.
   */
  /*
   * خرائط المصدر لبناء القياس وحده (‎ATLAS_MEASURE=1‎).
   *
   * نسبةُ بايتات الحزمة إلى الملفّات التي أنتجتها تحتاج خريطة: قطع الإنتاج
   * مُصغَّرة بلا أثر واحد لمسار وحدة، فبلا خريطة لا سبيل إلى النسبة إلّا
   * التخمين — وهو ما لا يُقبل هنا.
   *
   * ولا يُسمّى هنا أداةُ قياسٍ بعينها: كان التعليق يحيل إلى
   * ‎scripts/atlas/bundle.mjs‎ وهو **غير موجود** في المستودع، فيقرأ اللاحقُ
   * إحالةً إلى ملفٍّ يبحث عنه ولا يجده. المفتاح يصف ما يفعل، وأداةُ التحليل
   * تُختار وقت القياس (‎source-map-explorer‎ أو غيرها).
   *
   * ومحروسة بمتغيّر بيئة لا مفعَّلة دائمًا: الخرائط تضاعف حجم ‎.next/static‎
   * تقريبًا وتُنشر مع الحزمة، فتكشف الشيفرة المصدريّة للوحة لأيّ زائر.
   * بناء الإنتاج العاديّ يبقى بلا خرائط.
   */
  productionBrowserSourceMaps: process.env.ATLAS_MEASURE === '1',
  // حزم المستودع تُحوَّل داخل بناء التطبيق — نفس قائمة storefront.
  transpilePackages: [
    '@tahdheer/design-system',
    '@tahdheer/ui',
    '@tahdheer/api-sdk',
  ],
  /*
   * «المنتجات» اندمجت في «استوديو المنتج»، فتُحوَّل مساراتها القديمة بدل أن
   * تُترك 404: المفضّلات والروابط في شاشات التشخيص والتقارير تشير إليها.
   *
   * 307 لا 308: التحويل الدائم يُخزَّن في المتصفّح بلا انتهاء، فلو عاد المسار
   * يومًا بقيت الأجهزة القديمة محوَّلة عنه بلا سبيل لإبطال ذلك من الخادم.
   */
  async redirects() {
    /*
     * استوديو التصميم حُذف في ٢٠٢٦-٠٩-١٧ (قرار المالك): المنضدة والرصيف
     * ومحرّر الصفحة ومحرّر المكوّن ومحرّر الرمز وشاشات المظهر كلّها.
     *
     * ومساراته لا تُترك ٤٠٤: هي في مفضّلات المشغّلين وفي روابط داخل شاشات
     * أخرى وفي وثائق المستودع. تهبط في «القوائم» — الوجهة الوحيدة الباقية
     * في مساحة «استوديو المتجر».
     *
     * واستوديو المنتجات `/studio/catalog` باقٍ، فلا تُحوَّل بادئة `/studio`
     * كلّها: تحويلها كان سيبتلعه.
     */
    return [
      { source: '/studio', destination: '/navigation', permanent: false },
      { source: '/studio/design', destination: '/navigation', permanent: false },
      { source: '/studio/design/:path*', destination: '/navigation', permanent: false },
      { source: '/studio/components', destination: '/navigation', permanent: false },
      { source: '/studio/components/:path*', destination: '/navigation', permanent: false },
      { source: '/studio/symbols/:path*', destination: '/navigation', permanent: false },
      { source: '/appearance', destination: '/navigation', permanent: false },
      { source: '/appearance/:path*', destination: '/navigation', permanent: false },
      { source: '/studio/appearance', destination: '/navigation', permanent: false },
      { source: '/studio/appearance/:path*', destination: '/navigation', permanent: false },
      { source: '/design/components', destination: '/navigation', permanent: false },
      { source: '/design/components/:path*', destination: '/navigation', permanent: false },
      { source: '/atlas', destination: '/navigation', permanent: false },
      { source: '/ui-lab', destination: '/navigation', permanent: false },
      { source: '/products', destination: '/studio/catalog', permanent: false },
      { source: '/products/:path*', destination: '/studio/catalog/:path*', permanent: false },
      { source: '/catalog-studio', destination: '/studio/catalog', permanent: false },
      { source: '/catalog-studio/:path*', destination: '/studio/catalog/:path*', permanent: false },
    ];
  },
  // لوحة التشغيل لا تُفهرس أبدًا.
  async headers() {
    return [
      {
        source: '/:path*',
        headers: [{ key: 'X-Robots-Tag', value: 'noindex, nofollow' }],
      },
    ];
  },
};

export default nextConfig;
