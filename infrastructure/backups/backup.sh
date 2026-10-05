#!/usr/bin/env bash
#
# نسخةٌ منطقيّة مضغوطة من PostgreSQL (pg_dump -Fc) إلى BACKUP_PATH، مع تشفيرٍ
# اختياريّ بـage، وتشذيبٍ للاحتفاظ، وملفّ مقياسٍ يقرؤه التنبيه.
#
#   BACKUP_PATH      مجلّد الوجهة (مطلوب)
#   PG_CONTAINER     حاوية PostgreSQL (افتراضيًّا tahdheer-local-postgres)
#   PG_USER / PG_DB  المستخدم والقاعدة (tahdheer)
#   AGE_RECIPIENT    مفتاح age عامّ — إن ضُبط شُفّرت النسخة ولم يبقَ النصّ الخامّ
#   KEEP_DAYS        أيّام الاحتفاظ (30)
#
# لا نسخةَ تُعدّ ناجحة إلّا بعد `pg_restore --list` عليها، ولا تُعدّ موثوقة
# إلّا بعد `verify-restore.sh`.
set -euo pipefail

: "${BACKUP_PATH:?BACKUP_PATH مطلوب}"
PG_CONTAINER="${PG_CONTAINER:-tahdheer-local-postgres}"
PG_USER="${PG_USER:-tahdheer}"
PG_DB="${PG_DB:-tahdheer}"
KEEP_DAYS="${KEEP_DAYS:-30}"

mkdir -p "$BACKUP_PATH"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
out="$BACKUP_PATH/${PG_DB}-${stamp}.dump"
tmp="${out}.partial"

docker exec "$PG_CONTAINER" pg_dump -U "$PG_USER" -d "$PG_DB" -Fc --no-owner --no-privileges > "$tmp"
# جزئيٌّ لا يُسمّى نسخة: الاسم النهائيّ يُمنح بعد التحقّق من قابليّة القراءة.
docker exec -i "$PG_CONTAINER" pg_restore --list < "$tmp" > /dev/null
mv "$tmp" "$out"

if [ -n "${AGE_RECIPIENT:-}" ]; then
  age -r "$AGE_RECIPIENT" -o "${out}.age" "$out"
  rm -f "$out"
  out="${out}.age"
fi

find "$BACKUP_PATH" -maxdepth 1 -type f \( -name "${PG_DB}-*.dump" -o -name "${PG_DB}-*.dump.age" \) -mtime "+${KEEP_DAYS}" -delete

# يقرؤه التنبيه StaleBackup (prometheus-rules.yml) عبر node_exporter textfile.
printf 'tahdheer_backup_last_success_timestamp %s\n' "$(date +%s)" > "$BACKUP_PATH/last_success.prom"
echo "$out"
