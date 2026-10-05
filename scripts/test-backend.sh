#!/usr/bin/env bash
#
# تشغيل حزمة الواجهة الخلفية على قاعدةٍ منفصلة.
#
# `RefreshDatabase` يُجري `migrate:fresh`، أي يُسقط كلّ الجداول. وتوجيهه بعيدًا
# عن قاعدة التطوير لا يصحّ من `phpunit.xml` وحده: docker-compose يضبط
# `DB_DATABASE=tahdheer` متغيّرًا بيئيًّا حقيقيًّا، و`force="true"` لا يَغلِبه
# عند حلّ الإعداد، و`bootstrap/cache/config.php` — إن وُجد — يُجمّد القيمة
# أصلًا فلا تُقرأ البيئة. المتغيّر الصريح هنا هو ما يعمل فعلًا.
#
# وحارس `tests/TestCase.php` يبقى خطّ الدفاع الأخير: يرفض التشغيل على قاعدةٍ
# ليست في قائمة السماح بدل أن يمسحها.
#
# ─── قاعدةٌ لكلّ تشغيل ───
# كانت القاعدة الافتراضيّة واحدةً ثابتة (`tahdheer_test`)، و`migrate:fresh`
# يُسقط جداولها أوّل كلّ تشغيل. فتشغيلان متزامنان (جلستان، أو بشريٌّ وجلسة)
# يُسقط أحدهما جداول الآخر في منتصفه: عشرات «relation does not exist» بلا
# عطلٍ في الكود. الآن يُنشئ كلّ تشغيلٍ قاعدته `tahdheer_test_run_<زمن>_<رقم>`
# ويُسقطها عند الخروج، ويحمل اسمُها رمزَ التشغيل الذي يعزل الأقراص المزيَّفة
# (`TEST_RUN_TOKEN`). و`TEST_DB=...` صريحًا قاعدةٌ مُسمّاة لا تُسقط.
set -euo pipefail

PG_CONTAINER="${PG_CONTAINER:-tahdheer-local-postgres}"
API_CONTAINER="${API_CONTAINER:-tahdheer-local-api}"
PG_USER="${PG_USER:-tahdheer}"
psql_admin() { docker exec "$PG_CONTAINER" psql -U "$PG_USER" -d postgres -tAq -c "$1"; }

if [ -n "${TEST_DB:-}" ]; then
  DB="$TEST_DB"
  OWNED=0
else
  DB="tahdheer_test_run_$(date +%s)_$$"
  OWNED=1
  # بقايا تشغيلاتٍ قُتلت قبل فخّ الخروج: أقدم من ستّ ساعات.
  cutoff=$(( $(date +%s) - 21600 ))
  for stale in $(psql_admin "select datname from pg_database where datname ~ '^tahdheer_test_run_[0-9]+_[0-9]+\$'"); do
    started=$(echo "$stale" | cut -d_ -f4)
    if [ "$started" -lt "$cutoff" ]; then
      psql_admin "DROP DATABASE IF EXISTS ${stale} WITH (FORCE)" >/dev/null 2>&1 || true
    fi
  done
fi

if ! psql_admin "select 1 from pg_database where datname='${DB}'" | grep -q 1; then
  echo "» إنشاء قاعدة الاختبار ${DB}"
  psql_admin "CREATE DATABASE ${DB} OWNER ${PG_USER}"
fi

if [ "$OWNED" = 1 ]; then
  cleanup() {
    psql_admin "DROP DATABASE IF EXISTS ${DB} WITH (FORCE)" >/dev/null 2>&1 || true
    # جذورُ الأقراص المزيَّفة لهذا التشغيل وحده (انظر tests/TestCase.php).
    docker exec "$API_CONTAINER" sh -c "rm -rf storage/framework/testing/disks/*_test_${DB}" >/dev/null 2>&1 || true
  }
  trap cleanup EXIT
fi

#
# العزل ثلاثيّ لأنّ الحاوية تُصدّر بيئة التطوير كاملةً، وPHPUnit لا يَغلِب
# متغيّرًا بيئيًّا قائمًا. كلّ واحدٍ منها كان يُسقط اختبارات بصمت:
#
#   DB_DATABASE         `migrate:fresh` كان يمسح قاعدة التطوير.
#   CACHE_STORE         عدّادات تحديد المعدّل تبقى في Redis بين التشغيلات،
#                       فيردّ الخادم 429 على اختباراتٍ سليمة. هذا وحده كان
#                       سبب ١٧٥ من ١٧٧ إخفاقًا.
#   QUEUE_CONNECTION    الحاوية تضبطه redis: مهامُّ الاختبار كانت تصل إلى طابور
#   SESSION_DRIVER      عامل التطوير في Valkey المشترك، والجلساتُ تُكتب فيه.
#   ADMIN_SEED_PASSWORD الحاوية تضبطه، فاختبارُ «الإنتاج يرفض البذر بلا
#                       كلمة مرور» كان يجد كلمةً ويفقد فرضيّته.
#
# صورةُ الواجهة لا تحوي `.env` — البيئة تأتي من docker-compose. وphpdotenv
# يحاول قراءته على أيّ حال، فيُصدر PHPUnit تحذيرًا في **كلّ** اختبار. والنتيجة
# أنّ الحزمة كلّها تُبلَّغ «تحذيرات» لا «نجاح»، فيضيع أيّ تحذيرٍ حقيقيّ بينها.
# ملفٌّ فارغ يُسكت المحاولة ولا يَضبط شيئًا.
docker exec "$API_CONTAINER" sh -c '[ -f /var/www/html/.env ] || : > /var/www/html/.env'

docker exec \
  -e DB_DATABASE="$DB" \
  -e TEST_RUN_TOKEN="$DB" \
  -e CACHE_STORE=array \
  -e QUEUE_CONNECTION=sync \
  -e SESSION_DRIVER=array \
  -e ADMIN_SEED_PASSWORD= \
  -e ADMIN_SEED_EMAIL= \
  "$API_CONTAINER" php artisan test "$@"
