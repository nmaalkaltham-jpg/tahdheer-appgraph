<?php

use Illuminate\Support\Facades\Schedule;

/*
 * الجدولة — حاوية scheduler تشغّل `schedule:work` وكانت تدور فارغة:
 * «No scheduled commands are ready to run». أي أن صندوق الصادر يُملأ ولا
 * يُصرَّف أبدًا، فلا فهرس بحث يُحدَّث ولا تجميع تقييمات يُعاد بناؤه.
 *
 * `outbox:process` أحادي المرور بالتصميم (يعالج الدفعة المستحقّة ثم يخرج)،
 * فيلزمه نبض. withoutOverlapping يمنع تشغيلين متزامنين يتنازعان الصفوف
 * نفسها، وonOneServer يمنع تكرار المعالجة لو تعدّدت النسخ لاحقًا.
 */
Schedule::command('outbox:process')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground();

/*
 * خريطة الموقع تُبنى ليلًا لا عند كل طلب: بناؤها يمرّ على الكتالوج كلّه،
 * ونقطة عامّة تفعل ذلك لكل زائر هي باب استنزاف.
 */
Schedule::command('sitemap:build')->dailyAt('03:10')->onOneServer();
Schedule::command('sitemap:audit')->dailyAt('03:40')->onOneServer();

/*
 * التعافي: يسأل المزوّدين عن العمليّات التي مات عاملها قبل أن يسجّل نتيجتها.
 *
 * كلّ دقيقة لأنّ العمليّة المجهولة تحجز موضوعَها: وحدةُ تنفيذٍ مجهولة لا
 * يُعاد محاولتها، وصفُّ مخزونٍ مجهول لا يُصحَّح. وتأخيرُ الحسم ساعةً يعني
 * ساعةً من متجرٍ لا يعرف ما باع.
 *
 * والأمر آمنٌ للتكرار بالتصميم — يسأل ولا يُعيد — فالنبض السريع لا يخاطر.
 */
Schedule::command('operations:recover')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground();

/*
 * سؤال مزوّد الدفع عمّا لم يصل منه: رسمٌ مقبوض ضاع خطّافه، واستردادٌ لم
 * يُحسم. كلّ خمس دقائق — زبونٌ دفع ولم يستلم ملفّه لا ينتظر ساعة. يسأل
 * ويحسم ولا يُنشئ حركة مالٍ جديدة، فالتكرار آمن.
 */
Schedule::command('payments:requery')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();

/*
 * استرداداتٌ نُفِّذت خارج التطبيق (لوحة Tap): يُسأل Tap عن تحصيلات آخر
 * ثلاثين يومًا كلّ ساعة، ويُسجَّل ما لا نعرفه — فلا يبقى مالٌ خرج ولا يعرفه
 * الدفتر، ولا يُعرض قابلًا للردّ مرّةً ثانية. الخطّاف أسرع، وهذا شبكته.
 */
Schedule::command('payments:requery --refunds')
    ->hourly()
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground();

/*
 * مقاييس صحّة الدفع وإنذاراتها — سطرٌ مُهيكل كلّ خمس دقائق للرصد.
 */
Schedule::command('payments:health')
    ->everyFiveMinutes()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground();

/*
 * مسح القضايا: يحوّل ما تكشفه التسوية وتحليل المخاطرة إلى قضايا.
 *
 * كلّ ربع ساعة لا كلّ دقيقة: الكاشفان يمسحان جداول كاملة، والفرق الماليّ
 * لا يتغيّر في ستّين ثانية. والمفاتيح مستقرّة فالتكرار لا يُنتج ضجيجًا.
 *
 * ولا يُغلق شيئًا: زوالُ الشرط من المسح لا يعني أنّ العيب عولج، والإغلاق
 * الآليّ يمحو أثر عيبٍ ماليّ لم ينظر فيه أحد.
 */
Schedule::command('cases:scan')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();

/*
 * تصعيد القضايا: يجعل التأخّر مرئيًّا.
 *
 * كلّ خمس دقائق لأنّ مهلة الاستجابة للحرج ربع ساعة — فحصٌ كلّ ساعةٍ يجعل
 * ثلثَي المهلة يمرّ قبل أن يُلاحظ الخرق أصلًا.
 */
Schedule::command('cases:escalate')
    ->everyFiveMinutes()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground();

/*
 * تشذيب أحداث القياس — تنفيذُ الوعد المنشور بحذف التفاصيل بعد تسعين يومًا.
 *
 * الثالثة فجرًا: خارج ذروة المتجر، وبعد أن تكون تقارير اليوم السابق قد
 * قُرئت. والمدّة تأتي من `retention_policies` لا من هذا السطر.
 */
Schedule::command('analytics:prune')->dailyAt('03:25')->withoutOverlapping();

/*
 * «يُشترى معه عادةً» يُعاد حسابه ليلًا لا عند كلّ طلب: النمط يتحرّك بمقياس
 * أيّام، وإعادةُ الحساب مسحٌ كاملٌ لسطور الطلبات. بعد `analytics:prune`
 * فلا يتزاحمان على الجدول نفسه.
 */
Schedule::command('catalog:mine-co-purchase')->dailyAt('03:50')->onOneServer()->withoutOverlapping();

/*
 * تشذيب سجلّ العمليّات أسبوعيًّا: المنتهية الأقدم من تسعين يومًا. الحيّة
 * والمجهولة لا تُمسّ — الأمر نفسه يرفض ذلك.
 */
Schedule::command('operations:prune --days=90')->weeklyOn(0, '03:30')->onOneServer()->withoutOverlapping();

/*
 * توفيقُ الأصول يوميًّا: كائنٌ مدفوعٌ غائبٌ عن التخزين كان لا يُكتشف إلّا
 * حين ينقر مشترٍ «تحميل» فيتلقّى FILE_MISSING. الأمر للقراءة وحدها، ويكتب
 * `assets.reconcile.mismatch` خطأً إلى السجلّ حين يجد غائبًا — فيصل إلى
 * الجامع قبل العميل. بلا `--checksum`: الوجود يوميًّا، والبصمة يدويًّا.
 */
Schedule::command('assets:reconcile')->dailyAt('04:10')->onOneServer()->withoutOverlapping();

/*
 * توفيقُ فهرس البحث كلَّ ساعة: تغييرُ السعر والكلمات المفتاحيّة واستخراجُ
 * الملفّات لا يُعلنها أحدٌ للمحرّك، فيبقى Meilisearch على القديم. الأمر يُصلح
 * المنحرف وحده، ويعزل فشلَ الوثيقة الواحدة ويُعيدها في التشغيل التالي، ويكتب
 * `search.reconcile` سطرًا مُهيكلًا للرصد.
 */
Schedule::command('search:reconcile')->hourly()->onOneServer()->withoutOverlapping(50)->runInBackground();
