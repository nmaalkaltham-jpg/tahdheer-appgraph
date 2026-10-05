#!/usr/bin/env bash
#
# تنزيل أوزان النموذج والتحقّق من بصمتها.
#
# ─── لماذا سكربتٌ لا أمرُ curl في وثيقة ───
#
# الأوزان ملفٌّ ثنائيّ يُنفَّذ فعليًّا داخل الخادم. وتنزيلٌ بلا تحقّقٍ من
# البصمة يقبل أيّ شيءٍ يصل: وسيطًا يعبث، أو ملفًّا مبتورًا يبدأ التشغيل ثمّ
# ينهار عند أوّل استدلال. البصمة هنا مثبّتة في الشيفرة لا مقروءةً من الشبكة —
# بصمةٌ تأتي من المصدر نفسه لا تُثبت شيئًا.
#
# ─── لماذا هذا البناء بالذات ───
#
# لا تنشر Qwen بناء GGUF رسميًّا لمقاس ٢ب (‏huggingface.co/Qwen/Qwen3.5-2B-GGUF
# تردّ ٤٠١). و`unsloth` جهةُ تكميمٍ معروفة، والرخصة Apache-2.0 على الأوزان
# الأصليّة وعلى البناء معًا، وهي تبيح الاستعمال التجاريّ.
#
#   الاستعمال:  bash infrastructure/ai/download-model.sh
#   التحقّق فقط: bash infrastructure/ai/download-model.sh --verify
set -euo pipefail

REPO="unsloth/Qwen3.5-2B-GGUF"
REVISION="f6d5376be1edb4d416d56da11e5397a961aca8ae"
FILE="Qwen3.5-2B-Q4_K_M.gguf"
SHA256="aaf42c8b7c3cab2bf3d69c355048d4a0ee9973d48f16c731c0520ee914699223"

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/models"
TARGET="${DIR}/${FILE}"
URL="https://huggingface.co/${REPO}/resolve/${REVISION}/${FILE}"

# المراجعة مثبّتة لا `main`: الوسم المتحرّك يجعل نشرًا لاحقًا يجلب أوزانًا أخرى
# بصمتُها لا تطابق، فيفشل التحقّق بلا سببٍ مفهوم.

digest() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1
  else shasum -a 256 "$1" | cut -d' ' -f1; fi
}

verify() {
  [ -f "$TARGET" ] || { echo "× لا ملفّ في ${TARGET}"; return 1; }
  local got; got="$(digest "$TARGET")"
  if [ "$got" != "$SHA256" ]; then
    echo "× بصمة غير مطابقة"
    echo "  المتوقَّع: ${SHA256}"
    echo "  الموجود:  ${got}"
    return 1
  fi
  echo "✓ البصمة مطابقة · ${FILE} ($(du -h "$TARGET" | cut -f1))"
}

if [ "${1:-}" = "--verify" ]; then verify; exit $?; fi

mkdir -p "$DIR"

if [ -f "$TARGET" ] && verify >/dev/null 2>&1; then
  echo "✓ الأوزان موجودة ومطابقة — لا تنزيل."
  verify
  exit 0
fi

echo "» تنزيل ${FILE} من ${REPO}@${REVISION:0:7}"
# `--continue-at -` يستأنف تنزيلًا مقطوعًا بدل إعادة ١٫٢٨ غيغابايت من الصفر.
curl -fL --continue-at - --retry 3 --retry-delay 2 -o "${TARGET}.part" "$URL"
mv "${TARGET}.part" "$TARGET"

# التحقّق بعد النقل لا قبله: ملفٌّ فاشل التحقّق يُحذف ولا يُترك ليُحمَّل.
if ! verify; then
  rm -f "$TARGET"
  echo "× حُذفت الأوزان — لا تُشغَّل أوزانٌ لم تُتحقَّق."
  exit 1
fi
