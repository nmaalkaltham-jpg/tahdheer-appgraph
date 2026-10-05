#!/usr/bin/env bash
# يشغّل البيئة ثم يفتح المتصفّح — يستدعيه اختصارا سطح المكتب.
cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1
( for _ in $(seq 1 90); do
    if curl -sf -m 2 http://127.0.0.1:3000 >/dev/null 2>&1 \
    && curl -sf -m 2 http://127.0.0.1:3001 >/dev/null 2>&1; then
      open "http://127.0.0.1:3000" "http://127.0.0.1:3001"; break
    fi; sleep 2
  done ) &
exec npm run dev
