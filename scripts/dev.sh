#!/usr/bin/env bash
# تشغيل المشروع كاملًا بأمر واحد: npm run dev
#
# يرفع الخدمات، وينتظر صحّتها فعليًّا (لا ينام عددًا من الثواني ويأمل)،
# ويهيّئ ما ينقص عند أوّل تشغيل، ثم يشغّل المتجر واللوحة معًا بمخرجات
# موسومة. Ctrl+C يوقف الاثنين معًا ويترك الخدمات تعمل.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

COMPOSE=(docker compose -f infrastructure/docker/docker-compose.local.yml)
API=http://127.0.0.1:58080
SF_PORT=3000
ADMIN_PORT=3001

b() { printf '\033[1m%s\033[0m\n' "$*"; }
ok() { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$*"; }
die() { printf '  \033[31m✗\033[0m %s\n' "$*"; exit 1; }

# ── تنظيف: Ctrl+C يقتل الخادمين معًا لا واحدًا ──────────────────────────
PIDS=()
cleanup() {
  printf '\n'
  b "إيقاف خوادم التطوير…"
  for pid in "${PIDS[@]:-}"; do kill "$pid" 2>/dev/null || true; done
  wait 2>/dev/null || true
  ok "أُوقفت. الخدمات ما زالت تعمل — أوقفها بـ npm run local:down"
  exit 0
}
trap cleanup INT TERM

# ── ١ · المتطلّبات ──────────────────────────────────────────────────────
docker info >/dev/null 2>&1 || die "Docker لا يعمل — شغّل Docker Desktop أوّلًا."
[ -d node_modules ] || { b "تثبيت الاعتماديات…"; npm ci; }

# ── ٢ · ملفّات البيئة (متجاهَلة في git، فقد تغيب في نسخة جديدة) ─────────
if [ ! -f apps/storefront/.env.local ]; then
  cat > apps/storefront/.env.local <<EOF
INTERNAL_API_URL=$API
NEXT_PUBLIC_API_URL=$API
NEXT_PUBLIC_SITE_URL=http://127.0.0.1:$SF_PORT
EOF
  ok "أُنشئ apps/storefront/.env.local"
fi
if [ ! -f apps/admin/.env.local ]; then
  cat > apps/admin/.env.local <<EOF
# ADMIN_API_URL بالضبط — وكيل اللوحة (app/api/v1/[...path]) يقرأ هذا الاسم
# وحده، وأي بديل يسقط على localhost:8000 فيعلق التحقّق من الجلسة بـ502.
ADMIN_API_URL=$API
NEXT_PUBLIC_API_URL=$API
STOREFRONT_URL=http://127.0.0.1:$SF_PORT
EOF
  ok "أُنشئ apps/admin/.env.local"
fi

# ── ٢٫٥ · تحرير المنافذ ──────────────────────────────────────────────────
# خادم تطوير قديم (من جلسة سابقة أو نافذة أخرى) يحتلّ 3000/3001 يجعل Next
# يقفز إلى منفذ آخر أو يسقط بـEADDRINUSE — والرسالة تضيع وسط المخرجات.
# نحرّرهما صراحةً: نقتل فقط ما ينتمي لهذا المشروع.
for port in $SF_PORT $ADMIN_PORT; do
  pid=$(lsof -ti:$port 2>/dev/null | head -1 || true)
  [ -z "$pid" ] && continue
  ps -p "$pid" -o command= 2>/dev/null | grep -q "next" \
    || die "المنفذ $port يحتلّه شيء ليس خادم Next (PID $pid) — أوقفه يدويًّا."
  kill "$pid" 2>/dev/null || true
  # ننتظر تحرير المقبس فعليًّا لا ثانيةً ونأمل: SIGTERM قد يحتاج وقتًا،
  # وبدء Next قبل التحرير يُسقطه بـEADDRINUSE ويترك القديم يخدم بصمت.
  freed=0
  for _ in $(seq 1 15); do
    lsof -ti:$port >/dev/null 2>&1 || { freed=1; break; }
    sleep 0.5
  done
  if [ "$freed" = "0" ]; then
    kill -9 "$pid" 2>/dev/null || true
    for _ in $(seq 1 10); do
      lsof -ti:$port >/dev/null 2>&1 || { freed=1; break; }
      sleep 0.5
    done
  fi
  [ "$freed" = "1" ] && ok "حُرّر المنفذ $port (خادم تطوير قديم)" \
                     || die "تعذّر تحرير المنفذ $port — أوقف العملية $pid يدويًّا."
done

# ── ٣ · الخدمات ────────────────────────────────────────────────────────
b "رفع الخدمات…"
"${COMPOSE[@]}" up -d >/dev/null 2>&1 || die "فشل رفع الخدمات — راجع: npm run local:logs"

printf '  انتظار الصحّة'
for i in $(seq 1 60); do
  # الحكم على الصحّة المعلَنة من Docker لا على مرور الوقت.
  #
  # والسطر الفارغ يعني «لا فحص صحّة معلَنًا» لا «غير صحّي»: `worker` و
  # `scheduler` يشغّلان أوامر artisan لا php-fpm، وفحص الصورة يكلّم مجمّع
  # fpm على 9000 فلا يصحّان أبدًا. وقبل استثنائهما كان هذا الانتظار يستنفد
  # دقيقتيه ثمّ يقتل `npm run dev` في كلّ تشغيل. ما لا يُعلن صحّته لا
  # يُنتظَر — وحيويّته تكفلها `restart: unless-stopped`.
  unhealthy=$("${COMPOSE[@]}" ps --format '{{.Health}}' 2>/dev/null | grep -v '^$' | grep -cv '^healthy$' || true)
  [ "${unhealthy:-1}" = "0" ] && { printf '\n'; ok "كل الخدمات صحّية"; break; }
  printf '.'; sleep 2
  [ "$i" = "60" ] && { printf '\n'; die "الخدمات لم تصبح صحّية خلال دقيقتين — npm run local:logs"; }
done

curl -sf -m 10 "$API/up" >/dev/null 2>&1 && ok "الـAPI يجيب" || warn "الـAPI لا يجيب بعد — قد يحتاج لحظات"

# ── ٤ · أوّل تشغيل: ترحيل وبذر تلقائيًّا ────────────────────────────────
tables=$(docker exec tahdheer-local-postgres psql -U tahdheer -d tahdheer -tAc \
  "select count(*) from information_schema.tables where table_schema='public'" 2>/dev/null || echo 0)
if [ "${tables:-0}" -lt 5 ]; then
  b "قاعدة فارغة — ترحيل وبذر (أوّل تشغيل)…"
  (cd backend/laravel && php artisan migrate --force >/dev/null 2>&1) && ok "الترحيلات" || warn "تعذّر الترحيل — npm run local:migrate"
  (cd backend/laravel && php artisan db:seed --force >/dev/null 2>&1) && ok "بيانات المعاينة" || warn "تعذّر البذر — npm run local:seed"
  # AdminUserSeeder ليس ضمن DatabaseSeeder عمدًا (حساب مشغّل لا بيانات
  # عرض)، فبدونه تبقى اللوحة بلا حساب يُدخَل به.
  #
  # ولا كلمة مرور مكتوبةً هنا: كانت `tahdheer_local_admin` حرفيًّا في هذا
  # السطر وفي `docker-compose.local.yml`، بينما `apps/admin/.env.example`
  # يقول «لا توجد كلمة مرور افتراضية في الكود». تُضبط في البيئة:
  #   ADMIN_SEED_PASSWORD=… npm run dev     (انظر .env.example في الجذر)
  if [ -z "${ADMIN_SEED_PASSWORD:-}" ]; then
    warn "لا ADMIN_SEED_PASSWORD في البيئة — تُخطّى بذرة حساب اللوحة"
    warn "اضبطها ثمّ: cd backend/laravel && php artisan db:seed --class=AdminUserSeeder --force"
  else
    (cd backend/laravel && ADMIN_SEED_PASSWORD="$ADMIN_SEED_PASSWORD" php artisan db:seed --class=AdminUserSeeder --force >/dev/null 2>&1) \
      && ok "حساب اللوحة: owner@localhost" || warn "تعذّر إنشاء حساب اللوحة"
  fi
fi

# ── ٤٫٥ · كاش Laravel ─────────────────────────────────────────────────
# صورة الإنتاج تخبز config/route/view cache. مع الوصل الحيّ يعني ذلك أنّ
# تعديل مسار أو إعداد لا يظهر إطلاقًا حتى يُمسح الكاش — مقيس لا مفترض.
docker exec tahdheer-local-api php artisan optimize:clear >/dev/null 2>&1 \
  && ok "كاش Laravel مُنظَّف (تعديلات PHP تظهر فورًا)" \
  || warn "تعذّر تنظيف كاش Laravel"

# ── ٥ · التطبيقان ──────────────────────────────────────────────────────
b "تشغيل المتجر واللوحة…"
npm run dev:storefront 2>&1 | sed $'s/^/\033[36m[متجر]\033[0m  /' &
PIDS+=($!)
npm run dev:admin 2>&1 | sed $'s/^/\033[35m[لوحة]\033[0m  /' &
PIDS+=($!)

for i in $(seq 1 45); do
  if curl -sf -m 3 "http://127.0.0.1:$SF_PORT" >/dev/null 2>&1 \
  && curl -sf -m 3 "http://127.0.0.1:$ADMIN_PORT" >/dev/null 2>&1; then
    printf '\n'
    b "جاهز"
    printf '  المتجر    \033[4mhttp://127.0.0.1:%s\033[0m\n' "$SF_PORT"
    printf '  اللوحة    \033[4mhttp://127.0.0.1:%s\033[0m\n' "$ADMIN_PORT"
    printf '  الاستوديو \033[4mhttp://127.0.0.1:%s/studio/catalog\033[0m\n' "$ADMIN_PORT"
    printf '  حساب اللوحة: owner@localhost — كلمة المرور هي ADMIN_SEED_PASSWORD من بيئتك\n\n'
    printf '  Ctrl+C يوقف الخادمين (الخدمات تبقى)\n\n'
    break
  fi
  sleep 2
done

wait
