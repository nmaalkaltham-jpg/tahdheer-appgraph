# =============================================================================
# @tahdheer/storefront — واجهة «التحاضير الحديثة» (Next.js 16, standalone)
# =============================================================================
# BUILD CONTEXT MUST BE THE MONOREPO ROOT: the app depends on npm-workspace
# packages (@tahdheer/design-system, @tahdheer/ui, @tahdheer/registry,
# @tahdheer/api-sdk) that live in ../../packages/* and are transpiled by Next,
# so they must be inside the context.
#
#   docker build -f apps/storefront/Dockerfile -t tahdheer-storefront .
#
# Layout note (verified by building, not assumed): with `output: 'standalone'`
# in an npm-workspace repo Next roots the file trace at the lockfile directory,
# so the artifact is
#   apps/storefront/.next/standalone/apps/storefront/server.js
#   apps/storefront/.next/standalone/node_modules/
# WORKDIR + CMD below match that real layout.
# =============================================================================

# --- stage: deps -------------------------------------------------------------
# Only manifests + lockfile, so `npm ci` re-runs solely when dependencies move.
FROM node:24-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
COPY apps/storefront/package.json         apps/storefront/
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
# عنوان لوحة التشغيل لرابط التذييل. وسيط بناء لا متغيّر تشغيل فقط: ٢٤ مسارًا
# تُصيَّر مسبقًا وقت البناء، فقيمة تُمرَّر عند التشغيل وحدها لا تظهر فيها إطلاقًا.
# فراغه يُخفي الرابط — وهو السلوك الافتراضي المقصود.
ARG ADMIN_URL=""
ENV ADMIN_URL=$ADMIN_URL

# ───── متغيّرات العميل (NEXT_PUBLIC_*) ─────
#
# هذه تُخبز حرفيًّا داخل حزم جافاسكربت وقت `next build`. تمريرها في
# `environment:` وحده لا يفعل شيئًا للمتصفّح: الحزمة تكون قد بُنيت والقيمة
# فيها `undefined`.
#
# هذا بالضبط ما كسر سلة التسوق على الإنتاج: NEXT_PUBLIC_API_URL كان مضبوطًا
# وقت التشغيل فقط، فـ apiBase() تُعيد null في المتصفّح، فتُعلن السلة «تعذّر
# الاتصال بالخادم» دون أن تُرسل طلبًا واحدًا. والخادم كان سليمًا تمامًا طوال
# الوقت: GET /api/v1/cart يردّ 200 بسلة فارغة صالحة.
#
# ونفس السبب أفرغ خريطة الموقع: NEXT_PUBLIC_SITE_URL غائب ⇒ seo.ts يسقط إلى
# localhost:3000 ⇒ sitemap.xml بلا رابط واحد.
#
# كل متغيّر يستعمله كود العميل يجب أن يكون له وسيط هنا.
ARG NEXT_PUBLIC_API_URL=""
ARG NEXT_PUBLIC_SITE_URL=""
ARG NEXT_PUBLIC_SUPPORT_EMAIL=""
ARG NEXT_PUBLIC_WHATSAPP_NUMBER=""
ENV NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL \
    NEXT_PUBLIC_SITE_URL=$NEXT_PUBLIC_SITE_URL \
    NEXT_PUBLIC_SUPPORT_EMAIL=$NEXT_PUBLIC_SUPPORT_EMAIL \
    NEXT_PUBLIC_WHATSAPP_NUMBER=$NEXT_PUBLIC_WHATSAPP_NUMBER

RUN npm run build --workspace @tahdheer/storefront
# `public/` is optional in this app. Create it unconditionally so the runner's
# COPY below can never fail on a missing path.
RUN mkdir -p apps/storefront/public

# --- stage: runner -----------------------------------------------------------
FROM node:24-alpine AS runner
WORKDIR /app

ENV NODE_ENV=production \
    PORT=3000 \
    HOSTNAME=0.0.0.0 \
    NEXT_TELEMETRY_DISABLED=1

# Never run the server as root.
RUN addgroup -g 1001 -S nodejs \
 && adduser -u 1001 -S nextjs -G nodejs

# standalone/ already carries the pruned node_modules and the server entry.
COPY --from=build --chown=nextjs:nodejs /app/apps/storefront/.next/standalone ./
# Static assets and public/ are NOT traced into standalone — copy them beside
# the server entry so Next serves them without an external CDN.
COPY --from=build --chown=nextjs:nodejs /app/apps/storefront/.next/static ./apps/storefront/.next/static
COPY --from=build --chown=nextjs:nodejs /app/apps/storefront/public ./apps/storefront/public

USER nextjs
WORKDIR /app/apps/storefront
EXPOSE 3000

# Proves the Node server accepts connections and renders the root route.
# Any HTTP status < 500 counts as healthy: a 4xx is a routing answer, while a
# hard connect/timeout failure is what actually means "this container is down".
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:'+(process.env.PORT||3000)+'/',{signal:AbortSignal.timeout(4000)}).then(r=>process.exit(r.status<500?0:1)).catch(()=>process.exit(1))"

CMD ["node", "server.js"]
