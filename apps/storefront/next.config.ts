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
  // Self-contained server bundle for the Docker runner stage. In an npm-workspace
  // monorepo Next roots the trace at the lockfile dir, so the artifact lands at
  // `.next/standalone/apps/storefront/server.js` with `.next/standalone/node_modules`.
  output: 'standalone',
  // Workspace packages ship raw TS/CSS-module sources (main: src/index.ts) —
  // Next must transpile them. api-sdk is types-only here (imported via
  // `@tahdheer/api-sdk/types` with `import type`, so nothing reaches the bundle).
  transpilePackages: ['@tahdheer/design-system', '@tahdheer/ui'],
  // رسوم `public/art/**` (lib/art.ts): AVIF أوّلًا ثمّ WebP؛ المقاسات على افتراض الإطار.
  images: { formats: ['image/avif', 'image/webp'] },
  /*
   * سجلّ طلبات `next dev` يكتب الرابط كاملًا، وجلساتٌ تحوّل مخرجه إلى ملفّات.
   * ورابط الطلب مفتاحُه (lib/private-paths.ts): لا يُكتب.
   */
  logging: { incomingRequests: { ignore: [/\/orders(\/|%2F)/i, /\/account\/orders\?.*token=/i] } },
};

export default nextConfig;
