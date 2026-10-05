# =============================================================================
# @tahdheer/admin — لوحة تشغيل «التحاضير الحديثة» (Next.js 16)
# =============================================================================
# BUILD CONTEXT MUST BE THE MONOREPO ROOT: admin transpiles the same workspace
# packages as the storefront (see next.config.ts `transpilePackages`), and npm
# hoists them into the root node_modules, so both must be in the context.
#
#   docker build -f apps/admin/Dockerfile -t tahdheer-admin .
#
# ── لماذا `next start` لا `standalone` ──────────────────────────────────────
#
# هذه الصورة كانت تنسخ `.next/standalone` وتشغّل `node server.js`. والمجلّد
# **لا يُنتَج أصلًا**: `output: 'standalone'` معطَّل عمدًا في `next.config.ts`
# لأنّ خادمه لا يطابق المسار الشامل `app/api/v1/[...path]` فيردّ ٤٠٤ على كلّ
# نداء واجهة خلفية. فكان البناء يفشل عند `COPY` — أي أنّ صورة اللوحة لم تكن
# قابلة للبناء منذ تعطيل الخيار.
#
# البديل الصحيح هو المسار الموثَّق نفسه: `next start`. وثمنُه أنّ الصورة
# تحمل `node_modules` كاملة بدل الشجرة المُقلَّمة — وهو ثمنٌ مقبول مقابل
# صورةٍ تُبنى وتعمل.
#
# المنفذ ٣٠٠٠ داخل الحاوية (لا ٣٠٠١ الذي يستعمله `npm run dev`)، وCaddy
# وTraefik يخاطبانها `admin:3000`.
# =============================================================================

# --- stage: deps -------------------------------------------------------------
FROM node:24-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
COPY apps/storefront/package.json         apps/storefront/
COPY apps/admin/package.json              apps/admin/
COPY packages/design-system/package.json  packages/design-system/
COPY packages/ui/package.json             packages/ui/
COPY packages/registry/package.json       packages/registry/
COPY packages/api-sdk/package.json        packages/api-sdk/
RUN npm ci --no-audit --no-fund

# --- stage: build ------------------------------------------------------------
FROM node:24-alpine AS build
WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1 \
    NODE_ENV=production
COPY --from=deps /app/node_modules ./node_modules
COPY . .
# عنوان المتجر لزرّ «زيارة المتجر». وسيط بناء للسبب نفسه في صورة المتجر:
# صفحات اللوحة تُصيَّر مسبقًا، فقيمة التشغيل وحدها لا تصل إلى HTML المُخبوز.
ARG STOREFRONT_URL=""
ENV STOREFRONT_URL=$STOREFRONT_URL
RUN npm run build --workspace @tahdheer/admin

# --- stage: runner -----------------------------------------------------------
FROM node:24-alpine AS runner
WORKDIR /app

ENV NODE_ENV=production \
    PORT=3000 \
    HOSTNAME=0.0.0.0 \
    NEXT_TELEMETRY_DISABLED=1

RUN addgroup -g 1001 -S nodejs \
 && adduser -u 1001 -S nextjs -G nodejs

# `next start` يقرأ من شجرة المشروع لا من مخرجٍ مُقلَّم، فتُنقل كما هي.
COPY --from=build --chown=nextjs:nodejs /app/node_modules  ./node_modules
COPY --from=build --chown=nextjs:nodejs /app/package.json  ./package.json
COPY --from=build --chown=nextjs:nodejs /app/apps/admin    ./apps/admin
COPY --from=build --chown=nextjs:nodejs /app/packages      ./packages
# ورقات CSS يستوردها الاستوديو من خارج شجرة التطبيق. غيابها يكسر البناء
# والتصيير معًا — وقد وقع ذلك فعلًا في هذا المستودع.
COPY --from=build --chown=nextjs:nodejs /app/.design-sync  ./.design-sync

USER nextjs
WORKDIR /app/apps/admin
EXPOSE 3000

# نقطة صحّة التطبيق لا جذرُه: الجذر يُعيد تحويلًا إلى تسجيل الدخول فيبدو
# سليمًا حتّى لو كانت الواجهة الخلفية غير متاحة.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:'+(process.env.PORT||3000)+'/api/health',{signal:AbortSignal.timeout(4000)}).then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

CMD ["npx", "next", "start", "--port", "3000", "--hostname", "0.0.0.0"]
