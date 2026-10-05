#!/usr/bin/env bash
#
# يستعيد نسخةً (dump أو dump.age) إلى قاعدةٍ **مسمّاة**. لا يلمس القاعدة
# الحيّة إلّا إن سُمّيت صراحةً — والاستعادة فوقها تُسقط ما فيها.
#
#   restore.sh <ملفّ النسخة> <اسم القاعدة الهدف>
#   AGE_IDENTITY  ملفّ مفتاح age الخاصّ حين تكون النسخة مشفّرة
set -euo pipefail

dump="${1:?ملفّ النسخة}"
target="${2:?اسم القاعدة الهدف}"
PG_CONTAINER="${PG_CONTAINER:-tahdheer-local-postgres}"
PG_USER="${PG_USER:-tahdheer}"

src="$dump"
if [[ "$dump" == *.age ]]; then
  : "${AGE_IDENTITY:?AGE_IDENTITY مطلوب لنسخةٍ مشفّرة}"
  src="$(mktemp)"
  age -d -i "$AGE_IDENTITY" -o "$src" "$dump"
  trap 'rm -f "$src"' EXIT
fi

docker exec "$PG_CONTAINER" psql -U "$PG_USER" -d postgres -v ON_ERROR_STOP=1 -q \
  -c "DROP DATABASE IF EXISTS \"$target\" WITH (FORCE)" \
  -c "CREATE DATABASE \"$target\" OWNER \"$PG_USER\""
docker exec -i "$PG_CONTAINER" pg_restore -U "$PG_USER" -d "$target" --no-owner --no-privileges --exit-on-error < "$src"
echo "restored $dump → $target"
