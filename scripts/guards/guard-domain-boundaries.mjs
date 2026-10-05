/**
 * حارس حدود النطاقات في وحدات تحكّم الإدارة.
 *
 * ─── العيب الذي يمنعه ───
 *
 * وحدةُ تحكّمٍ تعدّل نموذج نطاقٍ آخر مباشرةً تسحب سياسةَ ذلك النطاق إليها:
 * خريطةُ الانتقال، والقفل، والتدقيق، والحدث. وقع هذا فعلًا — كانت انتقالات
 * الشحن والإرجاع تُنفَّذ في `OperationsActionController` بتسلسل «اقرأ ·
 * تحقّق · عدّل» بلا معاملةٍ ولا قفل، فكان طلبان متزامنان يفسدان الحالة.
 *
 * والنمط لا يُكتشف بالمراجعة: يبدو سطرًا واحدًا بريئًا (`$shipment->update`)،
 * ويظهر أثرُه بعد أشهر بوصفه «حالةٌ غريبة» لا تُفسَّر.
 *
 * ─── ما يفحصه ───
 *
 * ١ — لا استدعاء تعديلٍ على نموذجٍ يملكه نطاقٌ آخر من وحدة تحكّم إدارة.
 * ٢ — لا نداء شبكيّ خارجيّ من وحدة تحكّم إدارة.
 *
 * القراءة مسموحة: وحدة التحكّم تُسلسِل ما يعيده النطاق.
 */

import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { ROOT, walk, read, rel, lineOf, makeResults, report } from './_lib.mjs';

const { results, pass, fail } = makeResults();

const CONTROLLERS = join(ROOT, 'backend/laravel/app/Http/Controllers/Api/V1/Admin');

/**
 * نماذج تملكها نطاقاتٌ أخرى — تعديلُها من وحدة تحكّمٍ إداريّة ممنوع.
 *
 * `Product` و`Order` مستثنيان: لهما وحدات تحكّمٍ إداريّة مخصّصة هي واجهة
 * إدارتهما الفعليّة، وإدراجُهما هنا كان سيطلب إعادة كتابةٍ لا علاقة لها
 * بالعيب الذي يعالجه هذا الحارس.
 */
const FOREIGN_MODELS = [
  'Shipment', 'ShipmentItem', 'ShipmentPackage',
  'ReturnRequest', 'ReturnRequestItem',
  'FulfillmentUnit',
  'StockLevel', 'StockMovement', 'InventoryReservation',
  'AssetSource', 'AssetSourceSync',
  'Subscription', 'SubscriptionPeriod',
  'GrantedLicense', 'LicenseKey',
  // القضيّة آلةُ حالاتٍ لها خدمتُها — لا تُعدَّل من وحدة تحكّم.
  'WorkCase', 'CaseEvent',
];

/** استدعاءاتٌ تكتب. `update` على بانِي استعلامٍ يُلتقط أيضًا. */
const MUTATIONS = ['update', 'create', 'delete', 'forceDelete', 'increment', 'decrement', 'save', 'insert', 'fill'];

const controllerFiles = existsSync(CONTROLLERS) ? walk(CONTROLLERS, ['.php']) : [];

if (controllerFiles.length === 0) {
  fail('controllers', 'لا وحدات تحكّم إدارة — لا شيء يُفحص');
  process.exit(report('حارس حدود النطاقات', results));
}

// ── ١ — تعديلٌ مباشر لنموذجٍ أجنبيّ ─────────────────────────────────────
const violations = [];

for (const file of controllerFiles) {
  const src = read(file);

  for (const model of FOREIGN_MODELS) {
    for (const method of MUTATIONS) {
      /*
       * نمطان: استدعاءٌ ساكن على النموذج (`Shipment::create`) وسلسلةُ
       * بانٍ تنتهي بتعديل (`Shipment::query()->…->update(`).
       */
      const statik = new RegExp(`\\b${model}::(?:query\\(\\)[^;]*?->)?${method}\\s*\\(`, 'g');

      for (const m of src.matchAll(statik)) {
        violations.push(`${rel(file)}:${lineOf(src, m.index)} → ${model}::${method}()`);
      }
    }
  }
}

if (violations.length > 0) {
  fail('mutations', `تعديلٌ مباشر لنموذجٍ أجنبيّ: ${violations.join(' · ')}`);
} else {
  pass('mutations', `${controllerFiles.length} وحدة تحكّم · لا تعديل مباشر لـ${FOREIGN_MODELS.length} نموذجًا أجنبيًّا`);
}

// ── ٢ — لا نداء شبكيّ خارجيّ من وحدة تحكّم ──────────────────────────────

/**
 * ─── العيب ───
 *
 * نداءٌ إلى مزوّدٍ داخل طلب HTTP إداريّ يربط زمن استجابة اللوحة بزمن استجابة
 * طرفٍ ثالث: تتعلّق الصفحة، ينتهي وقت الوكيل، يُعيد المشغّل النقر — فيُنفَّذ
 * الفعل مرّتين بينما هو يظنّه لم يقع مرّة. وقع هذا فعلًا في «إعادة المحاولة»
 * و«إعادة التحقّق» قبل نقلهما إلى الطابور.
 *
 * والأسوأ أنّ النداء داخل معاملةٍ يُبقي قفلًا مفتوحًا طوال انتظار الشبكة،
 * فيُجمّد كلّ من يلمس الصفّ ويستنزف تجمّع الاتّصالات.
 *
 * المكان الصحيح مهمّةُ طابورٍ تحت عقد إيجار — وهو ما يفرضه هذا الفحص.
 */
const NETWORK = [
  'Http::',                       // عميل Laravel
  'file_get_contents(\'http',     // ونظائره الخام
  'curl_init',
  'fsockopen',
  'stream_socket_client',
];

const calls = [];

for (const file of controllerFiles) {
  const src = read(file);

  for (const needle of NETWORK) {
    let index = src.indexOf(needle);

    while (index !== -1) {
      const line = lineOf(src, index);
      const text = src.split('\n')[line - 1] ?? '';

      // سطر تعليقٍ يشرح المنع ليس مخالفةً له.
      if (! /^\s*(\*|\/\/|\/\*)/.test(text)) {
        calls.push(`${rel(file)}:${line} → ${needle}`);
      }

      index = src.indexOf(needle, index + needle.length);
    }
  }
}

if (calls.length > 0) {
  fail('network-calls', `نداءٌ شبكيّ من وحدة تحكّم: ${calls.join(' · ')}`);
} else {
  pass('network-calls', `${controllerFiles.length} وحدة تحكّم · لا نداء شبكيّ خارجيّ`);
}

/*
 * فحص صلاحيات المسارات ليس هنا عمدًا.
 *
 * الصلاحية تُوضع غالبًا على **مجموعة** مسارات لا على المسار نفسه، وفحصٌ
 * نصّيّ لا يرى المجموعة المحيطة فيبلّغ عن ستّين مسارًا محروسًا بوصفها
 * مكشوفة. والتبليغ الكاذب أسوأ من غياب الفحص: يُدرَّب المشغّل على تجاهل
 * الحارس.
 *
 * محلُّه `tests/Feature/Architecture/AdminRouteGuardTest.php` حيث يُسأل
 * الموجّه نفسه عن الوسائط المُحلَّة فعلًا — وهو المصدر الوحيد الصادق.
 */

process.exit(report('حارس حدود النطاقات', results));
