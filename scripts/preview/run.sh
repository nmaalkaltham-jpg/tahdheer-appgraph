#!/usr/bin/env bash
# تشغيل المتجر ولوحة التحكم محليًّا ثم توليد لقطات المعاينة.
# Boot the store + admin locally, then regenerate the preview screenshots.
#
#   npm run preview          # يبني ويشغّل ويلتقط، ثم يوقف كل شيء
#   npm run preview -- keep  # يترك الخوادم تعمل لتتصفّحها بنفسك
#
# اللقطات تذهب إلى «معاينة الصفحات» داخل المستودع — مجلد ظاهر في Finder،
# لأن أي اسم يبدأ بنقطة يختفي عن المالك.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
API_PORT=8400
STORE_PORT=3100
ADMIN_PORT=3200
DB="/tmp/tahdheer-preview.sqlite"
KEEP="${1:-}"

say() { printf '\n\033[1m%s\033[0m\n' "$*"; }

cleanup() {
  [ "$KEEP" = "keep" ] && { say "الخوادم تعمل: المتجر http://127.0.0.1:$STORE_PORT · الإدارة http://127.0.0.1:$ADMIN_PORT"; return; }
  # يُكتم إشعار الإنهاء: قتل خادم خلفي يطبع "Killed: 9" وهو سلوك متوقَّع لا خطأ.
  for port in "$API_PORT" "$STORE_PORT" "$ADMIN_PORT"; do
    lsof -ti:"$port" 2>/dev/null | xargs kill -9 2>/dev/null || true
  done
  wait 2>/dev/null
  rm -f "$DB"
}
trap cleanup EXIT

# منافذ نظيفة قبل البدء — خادم قديم عالق يجعل اللقطات تصوّر بناءً سابقًا بصمت.
for port in "$API_PORT" "$STORE_PORT" "$ADMIN_PORT"; do
  lsof -ti:"$port" 2>/dev/null | xargs kill -9 2>/dev/null || true
done

say "١/٥ · قاعدة بيانات معاينة جديدة"
cd "$ROOT/backend/laravel" || exit 1
rm -f "$DB"; touch "$DB"
DB_CONNECTION=sqlite DB_DATABASE="$DB" php artisan migrate --seed --force >/dev/null || { echo "× فشل التهيئة"; exit 1; }
DB_CONNECTION=sqlite DB_DATABASE="$DB" ADMIN_SEED_PASSWORD="${ADMIN_SEED_PASSWORD:-PreviewAdmin!2026}" \
  php artisan db:seed --class=AdminUserSeeder --force >/dev/null 2>&1 || true

say "٢/٥ · تشغيل الواجهة الخلفية"
DB_CONNECTION=sqlite DB_DATABASE="$DB" APP_ENV=local \
  APP_URL="http://127.0.0.1:$API_PORT" FRONTEND_URL="http://127.0.0.1:$STORE_PORT" \
  php artisan serve --port="$API_PORT" >/tmp/tahdheer-api.log 2>&1 &
for _ in $(seq 1 30); do
  curl -sf -o /dev/null "http://127.0.0.1:$API_PORT/api/v1/health" && break
  sleep 1
done
curl -sf -o /dev/null "http://127.0.0.1:$API_PORT/api/v1/health" || { echo "× الخلفية لم تستجب"; exit 1; }

say "٣/٥ · بناء المتجر ولوحة التحكم"
cd "$ROOT" || exit 1
# NEXT_PUBLIC_* تُحقن وقت البناء، وكاش الجلب يُجمّد الاستجابات بين البناءات.
rm -rf apps/storefront/.next/cache
NEXT_PUBLIC_API_URL="http://127.0.0.1:$API_PORT" npm run build --workspace @tahdheer/storefront >/dev/null || { echo "× فشل بناء المتجر"; exit 1; }
npm run build --workspace @tahdheer/admin >/dev/null || { echo "× فشل بناء الإدارة"; exit 1; }

say "٤/٥ · تشغيل الواجهات"
cp -R apps/storefront/.next/static apps/storefront/.next/standalone/apps/storefront/.next/ 2>/dev/null || true
cp -R apps/storefront/public apps/storefront/.next/standalone/apps/storefront/ 2>/dev/null || true
NEXT_PUBLIC_API_URL="http://127.0.0.1:$API_PORT" PORT="$STORE_PORT" HOSTNAME=127.0.0.1 \
  node apps/storefront/.next/standalone/apps/storefront/server.js >/tmp/tahdheer-store.log 2>&1 &
#
# ─── اللوحة بـ`next start` لا بخادمٍ شامل ───
#
# `output: 'standalone'` **معطَّل عمدًا** في `apps/admin/next.config.ts`
# (خادمه لا يطابق المسار الشامل `app/api/v1/[...path]` فيردّ ٤٠٤ على كلّ
# نداء — قاعدة المستودع). فالمجلّد `apps/admin/.next/standalone`
# لا يُنتَج أصلًا: كان النسخ يفشل ويبتلعه `|| true`، ثمّ يموت `node` على
# ملفٍّ غير موجود — فلا تُرفع اللوحة في المعاينة أبدًا، ولا يُقال ذلك.
ADMIN_API_URL="http://127.0.0.1:$API_PORT" \
  npm run start --workspace @tahdheer/admin -- --port "$ADMIN_PORT" --hostname 127.0.0.1 \
  >/tmp/tahdheer-admin.log 2>&1 &
for _ in $(seq 1 30); do
  curl -sf -o /dev/null "http://127.0.0.1:$STORE_PORT/" && break
  sleep 1
done
# واللوحة تُنتظَر أيضًا: كان انتظارُ المتجر وحده يُخفي لوحةً لم تُرفع.
for _ in $(seq 1 30); do
  curl -sf -o /dev/null "http://127.0.0.1:$ADMIN_PORT/" && break
  sleep 1
done
curl -sf -o /dev/null "http://127.0.0.1:$ADMIN_PORT/" \
  || echo "× اللوحة لم تُرفع — راجع /tmp/tahdheer-admin.log"

say "٥/٥ · التقاط المعاينة"
node scripts/preview/capture.mjs --base "http://127.0.0.1:$STORE_PORT" || echo "× بعض اللقطات لم تُلتقط"
node scripts/preview/compare.mjs --base "http://127.0.0.1:$STORE_PORT" || echo "× بعض أزواج المقارنة لم تُلتقط"
node scripts/preview/index.mjs

say "تمّ · اللقطات في: معاينة الصفحات/"
echo "افتح: $ROOT/معاينة الصفحات/افتحني.html"
