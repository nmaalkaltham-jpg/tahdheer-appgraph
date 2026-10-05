<?php
/**
 * Tahdheer — سجل المكوّنات ومكتبة العناصر.
 *
 * المبدأ المعماري الذي يحلّ توتّر «٣٠٠ عنصر» مع قاعدة «لا تكرار»:
 * ٣٠٠ اختيار معروض للمشرف = ~٤٥ مكوّنًا حقيقيًا × تهيئات متمايزة.
 * هذا ما يفعله القالب أصلًا (الأقسام ٧ بلوكات × تصميمين، الحركة ٤ × ٣)، وما
 * يطلبه بند ١٠٢ صراحة: «أساسات قابلة لإعادة الاستخدام بتهيئات متمايزة بقوّة».
 *
 * الفرق العملي: «شبكة منتجات» و«كاروسيل منتجات» و«سلايدر منتجات» ثلاثة مدخلات
 * في المكتبة، ومكوّن واحد باستعلام واحد وبطاقة واحدة وحالة فراغ واحدة. إصلاح
 * منطق السعر يُكتب مرّة لا ثلاثًا.
 *
 * التركيب:
 *   require_once get_template_directory() . '/inc/tahdheer-component-registry.php';
 *
 * @package Tahdheer
 */

defined( 'ABSPATH' ) || exit;

/**
 * المكوّنات الحقيقية: معرّف => [تسمية, حالة, تبعيات, هيكل افتراضي].
 *
 * الحالة صادقة ولا تُجمَّل: exists = مبنيّ في القالب اليوم · partial = موجود
 * جزئيًا ويحتاج إكمالًا · missing = لا كود له بعد.
 *
 * @return array<string,array<string,mixed>>
 */
function tahdheer_components(): array {
	static $components = null;

	if ( null !== $components ) {
		return $components;
	}

	$c = static function ( string $label, string $status, array $deps = array(), string $wire = 'text' ): array {
		return array(
			'label'  => $label,
			'status' => $status,
			'deps'   => $deps,
			'wire'   => $wire,
		);
	};

	$components = array(
		'collection'   => $c( 'مجموعة مُستعلَمة', 'partial', array( 'query', 'card' ), 'grid' ),
		'card'         => $c( 'بطاقة منتج', 'partial', array( 'product-data' ), 'card' ),
		'compare'      => $c( 'جدول مقارنة', 'missing', array( 'product-data' ), 'table' ),
		'bundle'       => $c( 'حزمة', 'missing', array( 'product-data', 'cart' ), 'cards' ),
		'disclosure'   => $c( 'كشف وتبويب', 'partial', array( 'rt-disclosure' ), 'tabs' ),
		'preview'      => $c( 'معاينة سريعة', 'missing', array( 'rt-dialog', 'product-data' ), 'spot' ),
		'search_field' => $c( 'حقل بحث', 'exists', array( 'ajax-search' ), 'bar' ),
		'term_chips'   => $c( 'شرائح مصطلحات', 'partial', array( 'search-log' ), 'chips' ),
		'filter_set'   => $c( 'طقم مرشّحات', 'partial', array( 'query' ), 'drawer' ),
		'countdown'    => $c( 'عدّاد تنازلي', 'partial', array( 'rt-countdown', 'sale-dates' ), 'count' ),
		'promo'        => $c( 'شارة وشريط عرض', 'exists', array( 'promo-engine' ), 'banner' ),
		'coupon'       => $c( 'قسيمة', 'missing', array( 'wc-coupons' ), 'banner' ),
		'cta'          => $c( 'نداء لإجراء', 'exists', array(), 'cta' ),
		'benefits'     => $c( 'قائمة فوائد', 'partial', array(), 'list' ),
		'helper'       => $c( 'مساعد قرار', 'missing', array( 'rt-state' ), 'cards' ),
		'trust'        => $c( 'شارات ثقة', 'exists', array( 'store-trust' ), 'badge' ),
		'reviews'      => $c( 'تقييمات', 'exists', array( 'wc-reviews' ), 'list' ),
		'policy'       => $c( 'كتلة سياسة', 'partial', array( 'policy-pages' ), 'notice' ),
		'media_one'    => $c( 'وسيط مفرد', 'partial', array( 'media' ), 'spot' ),
		'gallery'      => $c( 'معرض وسائط', 'missing', array( 'rt-gallery', 'media' ), 'gal' ),
		'media_fx'     => $c( 'وسيط تفاعلي', 'missing', array( 'rt-pointer' ), 'spot' ),
		'media_bg'     => $c( 'خلفية وسائطية', 'partial', array(), 'spot' ),
		'rich_text'    => $c( 'نص غني', 'exists', array(), 'text' ),
		'content_cards' => $c( 'بطاقات محتوى', 'partial', array( 'query' ), 'cards' ),
		'split'        => $c( 'نص وصورة', 'partial', array( 'media' ), 'split' ),
		'quote'        => $c( 'اقتباس', 'missing', array(), 'quote' ),
		'faq'          => $c( 'أسئلة شائعة', 'exists', array( 'rt-disclosure', 'schema-faq' ), 'faq' ),
		'answer'       => $c( 'كتلة إجابة', 'missing', array(), 'text' ),
		'facts'        => $c( 'لوح حقائق', 'partial', array( 'product-meta' ), 'table' ),
		'link_cluster' => $c( 'عنقود روابط', 'exists', array( 'internal-linking' ), 'list' ),
		'toc'          => $c( 'فهرس محتوى', 'missing', array( 'anchors' ), 'toc' ),
		'glossary'     => $c( 'مسرد', 'missing', array(), 'list' ),
		'nav_bar'      => $c( 'شريط تنقّل', 'partial', array( 'rt-observer' ), 'nav' ),
		'crumbs'       => $c( 'مسار تنقّل', 'exists', array( 'breadcrumbs', 'schema-breadcrumb' ), 'nav' ),
		'quick_links'  => $c( 'روابط سريعة', 'partial', array(), 'chips' ),
		'social'       => $c( 'روابط اجتماعية', 'exists', array( 'share' ), 'badge' ),
		'ugc'          => $c( 'معرض العملاء', 'missing', array( 'media' ), 'gal' ),
		'story'        => $c( 'كتلة قصّة', 'missing', array(), 'split' ),
		'timeline'     => $c( 'خط زمني', 'missing', array(), 'tline' ),
		'people'       => $c( 'أشخاص', 'missing', array(), 'person' ),
		'file_preview' => $c( 'معاينة ملف', 'missing', array( 'digital-assets' ), 'file' ),
		'form'         => $c( 'نموذج', 'partial', array( 'nonce', 'validation' ), 'form' ),
		'notice'       => $c( 'تنبيه', 'partial', array(), 'notice' ),
		'stats'        => $c( 'أرقام ومقاييس', 'partial', array( 'real-metric' ), 'stats' ),
		'smart'        => $c( 'فتحة ذكية', 'missing', array( 'external-api' ), 'smart' ),
	);

	/**
	 * تسجيل مكوّن من إضافة — بدل تعديل النواة.
	 *
	 * @param array<string,array<string,mixed>> $components المكوّنات.
	 */
	$components = (array) apply_filters( 'tahdheer_components', $components );

	return $components;
}

/**
 * العائلات العشرون × ١٥ اختيارًا = ٣٠٠.
 *
 * كل اختيار: 'التسمية|المكوّن' — والمكوّن هو ما يُصيِّرها فعلًا.
 *
 * @return array<string,array<string,mixed>>
 */
function tahdheer_component_families(): array {
	static $families = null;

	if ( null !== $families ) {
		return $families;
	}

	$families = array(
		'products'   => array( 'label' => 'المنتجات', 'items' => 'شبكة منتجات|collection,كاروسيل منتجات|collection,سلايدر منتجات|collection,منتج مُبرَز|collection,منتج مميّز|collection,الأكثر مبيعًا|collection,الجديد|collection,الرائج|collection,شوهد مؤخّرًا|collection,منتجات ذات صلة|collection,يُشترى معًا|bundle,حزم المنتجات|bundle,مقارنة المنتجات|compare,تبويبات المنتج|disclosure,معاينة سريعة|preview' ),
		'categories' => array( 'label' => 'التصنيفات', 'items' => 'شبكة تصنيفات|collection,بطاقات تصنيف|collection,كاروسيل تصنيفات|collection,أقراص تصنيف|collection,أيقونات تصنيف|collection,بانرات تصنيف|collection,فسيفساء تصنيفات|collection,بينتو تصنيفات|collection,تصنيفات مميّزة|collection,تصنيفات شائعة|collection,مستكشف فرعي|collection,مستكشف تصنيفي|collection,شجرة التصنيفات|collection,تبويبات تصنيف|disclosure,استكشاف ذكي|smart' ),
		'collections' => array( 'label' => 'المجموعات', 'items' => 'مجموعات منسّقة|collection,مجموعات موسمية|collection,الأفضل قيمة|collection,مجموعات مواضيعية|collection,مجموعات تعليمية|collection,موصى بها|collection,مجموعات بصرية|collection,سلايدر مجموعات|collection,بطاقات مجموعة|collection,تبويبات مجموعة|disclosure,بانر مجموعة|promo,عرض مجموعة|collection,حزم مجموعات|bundle,تنقّل المجموعات|nav_bar,مجموعات ذكية|smart' ),
		'search'     => array( 'label' => 'البحث', 'items' => 'شريط بحث|search_field,بحث ذكي|search_field,بحث فوري|search_field,اقتراحات البحث|term_chips,بحوث رائجة|term_chips,بحوث شائعة|term_chips,سجل البحث|term_chips,شرائح كلمات|term_chips,بحث بالتصنيف|search_field,بحث بالمنتج|search_field,بحث بالخصائص|filter_set,تصفّح بالحاجة|term_chips,بطاقات النيّة|term_chips,كتلة هبوط البحث|answer,مساعد البحث|smart' ),
		'filtering'  => array( 'label' => 'التصفية', 'items' => 'مرشّح السعر|filter_set,مرشّح تصنيفي|filter_set,مرشّح التصنيف|filter_set,مرشّح الخصائص|filter_set,مرشّح الوسوم|filter_set,مرشّح التقييم|filter_set,مرشّح نوع المنتج|filter_set,مرشّح التوفّر|filter_set,مرشّحات ديناميكية|filter_set,شرائح مرشّحات|filter_set,دُرج مرشّحات|filter_set,شريط مرشّحات|filter_set,مرشّحات بصرية|filter_set,مرشّحات مجمّعة|filter_set,مرشّحات ذكية|smart' ),
		'promotions' => array( 'label' => 'العروض', 'items' => 'تخفيض سريع|promo,عدّاد العرض|countdown,شريط ترويجي|promo,كتلة قسيمة|coupon,بانر حملة|promo,بطاقات صفقة|collection,كاروسيل صفقات|collection,عرض محدود|countdown,إبراز الخصم|promo,خصم الحزمة|bundle,خصم التصنيف|promo,ترويج مجموعة|promo,ترويج إطلاق|promo,ترويج موسمي|promo,جدول العروض|timeline' ),
		'conversion' => array( 'label' => 'التحويل', 'items' => 'كتلة نداء|cta,نداء ملتصق|cta,بطاقة اشتر الآن|cta,بطاقة أضف للسلة|cta,شريط التحويل|cta,فوائد المنتج|benefits,أسباب الشراء|benefits,كتلة ضمان|policy,مساعد القرار|helper,مقارنة الميزات|compare,مساعد الاختيار|helper,ملخّص العرض|facts,عرض القيمة|benefits,ترقية البيع|collection,بيع متقاطع|collection' ),
		'trust'      => array( 'label' => 'الثقة', 'items' => 'شارات ثقة|trust,ملخّص التقييمات|reviews,حائط التقييمات|reviews,شهادات العملاء|reviews,مشترٍ موثَّق|reviews,كتلة الضمان|policy,سياسة الاسترجاع|policy,دفع آمن|policy,توفّر الدعم|policy,ضمان التواصل|policy,ثقة العلامة|story,مزايا المتجر|benefits,مؤشّرات الخبرة|stats,مؤشّرات الجودة|facts,وعد العميل|story' ),
		'media'      => array( 'label' => 'الوسائط', 'items' => 'صورة مفردة|media_one,شبكة صور|gallery,معرض صور|gallery,معرض متراصّ|gallery,سلايدر صور|gallery,كاروسيل صور|gallery,صورة تفاعلية|media_fx,صورة بنقاط|media_fx,قبل وبعد|media_fx,كتلة فيديو|media_one,معرض فيديو|gallery,فيديو خلفي|media_bg,فيديو المنتج|media_one,رسم متحرّك|media_one,كولاج وسائط|gallery' ),
		'content'    => array( 'label' => 'المحتوى', 'items' => 'نص غني|rich_text,بطاقات محتوى|content_cards,شبكة مقالات|content_cards,كاروسيل مدوّنة|content_cards,قسم دليل|content_cards,مقال مُبرَز|content_cards,كتلة كيف|answer,قسم تعليمي|split,نص وصورة|split,تبويبات محتوى|disclosure,أكورديون محتوى|disclosure,كتلة اقتباس|quote,مقال رئيسي|content_cards,بطاقات موارد|link_cluster,مركز معرفة|content_cards' ),
		'seo'        => array( 'label' => 'بنية البحث', 'items' => 'قسم أسئلة|faq,عنقود موضوعي|link_cluster,روابط داخلية|link_cluster,مواضيع ذات صلة|link_cluster,منتجات ذات صلة|collection,تصنيفات ذات صلة|collection,مقدّمة مُهيكلة|answer,فهرس المحتوى|toc,ميزات دلالية|facts,سؤال وجواب|faq,مسرد|glossary,ملخّص الكيان|facts,جدول مقارنة|compare,قسم نيّة البحث|answer,موارد مفيدة|link_cluster' ),
		'aeo'        => array( 'label' => 'الإجابة والذكاء', 'items' => 'إجابة مباشرة|answer,كتلة تعريف|answer,حقائق مُهيكلة|facts,أجوبة الأسئلة|faq,خصائص الكيان|facts,حقائق المنتج|facts,ملخّص المقارنة|compare,توصية موجزة|answer,ملخّص كيف|answer,مشكلة وحل|answer,معلومة سياقية|notice,مراجع مُهيكلة|link_cluster,إيجابيات وسلبيات|facts,اعتبارات الشراء|facts,ملخّص خبير|answer' ),
		'navigation' => array( 'label' => 'التنقّل', 'items' => 'مسار تنقّل|crumbs,تنقّل بالمراسي|nav_bar,تنقّل ملتصق|nav_bar,تنقّل الأقسام|nav_bar,تنقّل التصنيفات|nav_bar,تنقّل المجموعات|nav_bar,تنقّل بصري|collection,روابط سريعة|quick_links,فهرس الصفحة|toc,قفز الصفحة|toc,تنقّل ضخم|nav_bar,تنقّل عائم|nav_bar,تنقّل سفلي|nav_bar,صفحات ذات صلة|link_cluster,تنقّل ذكي|smart' ),
		'social'     => array( 'label' => 'الاجتماعي', 'items' => 'أيقونات اجتماعية|social,مشاركة|social,دليل اجتماعي|reviews,معرض اجتماعي|ugc,بطاقات منشئين|people,كتلة مجتمع|story,صور العملاء|ugc,محتوى العملاء|ugc,تقييمات اجتماعية|reviews,كتلة إحالة|cta,نداء المشاركة|social,كتلة متابعة|social,خلاصة فيديو|gallery,عدّادات اجتماعية|stats,أبرز المجتمع|ugc' ),
		'brand'      => array( 'label' => 'قصّة العلامة', 'items' => 'قصّة العلامة|story,من نحن|story,القيم|story,الرسالة|story,الرؤية|story,خط زمني|timeline,محطّات|timeline,كلمة المؤسّس|story,الفريق|people,العملية|timeline,فلسفة الجودة|story,وعد العلامة|story,وراء الكواليس|gallery,لماذا نحن|benefits,معرض القصّة|gallery' ),
		'digital'    => array( 'label' => 'المنتج الرقمي', 'items' => 'نوع الملف|facts,الصيغ|facts,معلومات التحميل|facts,معلومات الوصول|facts,التوافق|facts,متطلّبات البرنامج|facts,الأجهزة المدعومة|facts,معلومات الإصدار|facts,معلومات التحديث|facts,نوع الترخيص|facts,عدد الملفات|facts,معاينة الملف|file_preview,محتوى تجريبي|file_preview,دليل الاستخدام|answer,طريقة التسليم|facts' ),
		'forms'      => array( 'label' => 'النماذج', 'items' => 'النشرة البريدية|form,تواصل|form,جذب عميل|form,قائمة انتظار|form,تعليق|form,استفسار|form,طلب دعم|form,استبيان سريع|form,سؤال عن المنتج|form,تصويت|form,تنبيه إشعار|form,تنبيه إطلاق|form,جمع بريد|form,طلب معلومات|form,نموذج مخصّص|form' ),
		'info'       => array( 'label' => 'المعلومات', 'items' => 'صندوق معلومة|notice,ملاحظة|notice,تحذير|notice,نصيحة|notice,رسالة نجاح|notice,قائمة تحقّق|benefits,خطوات|timeline,عملية|timeline,أسئلة شائعة|faq,خط زمني|timeline,إحصائيات|stats,مقاييس|stats,صندوق إبراز|notice,مقارنة|compare,حقائق أساسية|facts' ),
		'interactive' => array( 'label' => 'التفاعلي', 'items' => 'تبويبات|disclosure,أكورديون|disclosure,مفتاح تبديل|disclosure,بطاقات تفاعلية|card,بطاقات مقلوبة|card,بطاقات تمرير|card,بطاقات موسّعة|disclosure,خط زمني تفاعلي|timeline,معرض بالتمرير|media_fx,مُحدِّد منتج|helper,اختبار منتج|helper,مُحدِّد بصري|helper,منتقي خيارات|helper,رحلة موجّهة|helper,مقارنة تفاعلية|compare' ),
		'smart'      => array( 'label' => 'الذكي', 'items' => 'توصيات ذكية|smart,مساعد بحث|smart,استكشاف ذكي|smart,اقتراح تصنيفات|smart,ذات صلة ذكية|smart,أسئلة ذكية|smart,اقتراح محتوى|smart,عرض تجاري ذكي|smart,تخصيص|smart,كشف النيّة|smart,توصية الجلسة|smart,نداء سياقي|smart,محتوى ديناميكي|smart,كتلة رؤية|smart,مساعد شراء|smart' ),
	);

	/**
	 * إضافة عائلة أو تعديل اختياراتها.
	 *
	 * @param array<string,array<string,mixed>> $families العائلات.
	 */
	$families = (array) apply_filters( 'tahdheer_component_families', $families );

	return $families;
}

/**
 * كلمات مفتاحية تشتقّ الهيكل من تسمية الاختيار — فالتسمية تصف التخطيط.
 *
 * @return array<string,string>
 */
function tahdheer_wire_keywords(): array {
	return array(
		'كاروسيل'  => 'carousel',
		'سلايدر'   => 'slider',
		'بينتو'    => 'bento',
		'فسيفساء'  => 'mosaic',
		'متراصّ'   => 'mosaic',
		'حائط'     => 'mosaic',
		'شجرة'     => 'tree',
		'أيقونات'  => 'icons',
		'أقراص'    => 'chips',
		'شرائح'    => 'chips',
		'بانر'     => 'banner',
		'شبكة'     => 'grid',
		'جدول'     => 'table',
		'دُرج'     => 'drawer',
		'شريط'     => 'bar',
		'ملتصق'    => 'bar',
		'تبويبات'  => 'tabs',
		'أكورديون' => 'accordion',
		'عدّاد'    => 'count',
		'خط زمني'  => 'tline',
		'معرض'     => 'gallery',
		'مُبرَز'   => 'spot',
		'مميّز'    => 'spot',
		'قائمة'    => 'list',
	);
}

/**
 * المكتبة كاملة: ٣٠٠ مدخل، كل مدخل يشير لمكوّنه وهيكله وحالته.
 *
 * @return array<int,array<string,mixed>>
 */
function tahdheer_component_library(): array {
	static $library = null;

	if ( null !== $library ) {
		return $library;
	}

	$components = tahdheer_components();
	$keywords   = tahdheer_wire_keywords();
	$library    = array();
	$family_i   = 0;

	foreach ( tahdheer_component_families() as $family_key => $family ) {
		++$family_i;
		$item_i = 0;

		foreach ( explode( ',', (string) $family['items'] ) as $raw ) {
			++$item_i;
			$parts = explode( '|', $raw );
			$label = trim( $parts[0] );
			$comp  = isset( $parts[1] ) ? trim( $parts[1] ) : '';

			if ( '' === $label || ! isset( $components[ $comp ] ) ) {
				continue;
			}

			$wire = (string) $components[ $comp ]['wire'];
			foreach ( $keywords as $needle => $shape ) {
				if ( false !== strpos( $label, $needle ) ) {
					$wire = $shape;
					break;
				}
			}

			$library[] = array(
				'id'        => sprintf( 'tah/%s.%02d%02d', $comp, $family_i, $item_i ),
				'label'     => $label,
				'family'    => $family_key,
				'family_label' => (string) $family['label'],
				'component' => $comp,
				'wire'      => $wire,
				'status'    => (string) $components[ $comp ]['status'],
				'deps'      => (array) $components[ $comp ]['deps'],
				'schema'    => 1,
			);
		}
	}

	return $library;
}

/**
 * توصيات بقواعد لا بذكاء — مسموح صراحة في بند ٩١، ومحرّم أن تُقدَّم كذكاء.
 *
 * @param string $page_type home|product|category|search|offers|digital.
 * @return array<int,string> تسميات مقترحة.
 */
function tahdheer_recommended_for( string $page_type ): array {
	$map = array(
		'home'     => array( 'شبكة تصنيفات', 'الأكثر مبيعًا', 'تخفيض سريع', 'مجموعات منسّقة', 'شارات ثقة', 'ملخّص التقييمات', 'قسم دليل', 'النشرة البريدية' ),
		'product'  => array( 'إجابة مباشرة', 'حقائق المنتج', 'قسم أسئلة', 'ملخّص التقييمات', 'شارات ثقة', 'فوائد المنتج', 'منتجات ذات صلة', 'يُشترى معًا' ),
		'category' => array( 'مرشّحات مجمّعة', 'شبكة منتجات', 'مستكشف فرعي', 'مسار تنقّل', 'مقدّمة مُهيكلة', 'روابط داخلية' ),
		'search'   => array( 'شريط بحث', 'بحوث رائجة', 'بطاقات النيّة', 'شبكة منتجات', 'مرشّحات ديناميكية', 'كتلة هبوط البحث' ),
		'offers'   => array( 'عدّاد العرض', 'بطاقات صفقة', 'إبراز الخصم', 'خصم الحزمة', 'جدول العروض' ),
		'digital'  => array( 'نوع الملف', 'الصيغ', 'معلومات التحميل', 'معاينة الملف', 'نوع الترخيص', 'طريقة التسليم', 'دليل الاستخدام' ),
	);

	return $map[ $page_type ] ?? array();
}

/**
 * إحصاء المكتبة — يغذّي لوحة التدقيق ولا يخترع رقمًا.
 *
 * @return array{choices:int,components:int,exists:int,partial:int,missing:int,ratio:float}
 */
function tahdheer_library_stats(): array {
	$library    = tahdheer_component_library();
	$components = tahdheer_components();

	$counts = array(
		'exists'  => 0,
		'partial' => 0,
		'missing' => 0,
	);

	foreach ( $components as $component ) {
		$status = (string) $component['status'];
		if ( isset( $counts[ $status ] ) ) {
			++$counts[ $status ];
		}
	}

	$choices    = count( $library );
	$comp_count = count( $components );

	return array(
		'choices'    => $choices,
		'components' => $comp_count,
		'exists'     => $counts['exists'],
		'partial'    => $counts['partial'],
		'missing'    => $counts['missing'],
		'ratio'      => $comp_count > 0 ? round( $choices / $comp_count, 1 ) : 0.0,
	);
}

/**
 * أزمنة التشغيل المشتركة — تُحمَّل عند وجود مكوّن يحتاجها فقط، لا عالميًا.
 * أربعة منها تفتح أكثر من نصف الاختيارات الناقصة.
 *
 * @return array<string,array<string,mixed>>
 */
function tahdheer_component_runtimes(): array {
	return array(
		'rt-disclosure' => array( 'label' => 'الكشف (تبويبات وأكورديون)', 'unlocks' => 14, 'status' => 'partial' ),
		'rt-gallery'    => array( 'label' => 'المعرض والكاروسيل', 'unlocks' => 21, 'status' => 'missing' ),
		'rt-countdown'  => array( 'label' => 'العدّاد التنازلي', 'unlocks' => 4, 'status' => 'partial' ),
		'rt-dialog'     => array( 'label' => 'الحوار والمعاينة السريعة', 'unlocks' => 3, 'status' => 'missing' ),
		'rt-observer'   => array( 'label' => 'مراقب التقاطع (تنقّل ولزوجة)', 'unlocks' => 9, 'status' => 'partial' ),
		'rt-pointer'    => array( 'label' => 'المؤشّر (نقاط ساخنة وقبل/بعد)', 'unlocks' => 4, 'status' => 'missing' ),
	);
}
