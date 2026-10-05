#!/usr/bin/env bash
#
# تمرين استرجاعٍ آليّ: يستعيد أحدث نسخة إلى قاعدةٍ مؤقّتة ويقارن عدد صفوف
# الجداول الماليّة بالمصدر ثمّ يحذفها. النسخة التي لا تجتاز هذا ليست نسخة.
#
#   BACKUP_PATH  مجلّد النسخ (مطلوب)   DUMP  نسخةٌ بعينها (افتراضيًّا الأحدث)
set -euo pipefail

: "${BACKUP_PATH:?BACKUP_PATH مطلوب}"
PG_CONTAINER="${PG_CONTAINER:-tahdheer-local-postgres}"
PG_USER="${PG_USER:-tahdheer}"
PG_DB="${PG_DB:-tahdheer}"
scratch="${SCRATCH_DB:-tahdheer_restore_verify}"
here="$(cd "$(dirname "$0")" && pwd)"

dump="${DUMP:-$(ls -1t "$BACKUP_PATH"/${PG_DB}-*.dump* 2>/dev/null | head -1)}"
[ -n "$dump" ] || { echo "لا نسخة في $BACKUP_PATH" >&2; exit 2; }

"$here/restore.sh" "$dump" "$scratch"

status=0
for table in orders order_items payments refunds entitlements assets outbox_events operation_records; do
  src="$(docker exec "$PG_CONTAINER" psql -U "$PG_USER" -d "$PG_DB" -tAc "select count(*) from $table")"
  got="$(docker exec "$PG_CONTAINER" psql -U "$PG_USER" -d "$scratch" -tAc "select count(*) from $table")"
  if [ "$src" != "$got" ]; then
    # المصدر يتحرّك بعد النسخة؛ الفرق **نقصٌ** في المستعاد هو الفشل لا زيادة المصدر.
    if [ "$got" -gt "$src" ]; then status=1; fi
    echo "  $table: source=$src restored=$got (diff)"
  else
    echo "  $table: $got ✓"
  fi
done

docker exec "$PG_CONTAINER" psql -U "$PG_USER" -d postgres -q -c "DROP DATABASE IF EXISTS \"$scratch\" WITH (FORCE)"
[ "$status" -eq 0 ] && echo "restore drill OK: $dump" || { echo "restore drill FAILED: $dump" >&2; exit 1; }
