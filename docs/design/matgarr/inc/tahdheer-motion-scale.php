<?php
/**
 * Tahdheer — سلّم شدّة الحركة ٠ ← ١٠.
 *
 * القالب يملك المفردات أصلًا: عشرة منحنيات تسارع في tahdheer_ds_easings()
 * وخمس سرعات مسمّاة في tahdheer_ds_speeds() وتحقّقًا نمطيًا من cubic-bezier
 * المخصّص. الناقص كان المقبض الواحد الذي يحرّكها كلها معًا.
 *
 * المبدأ: المستوى ليس رقمًا في قائمة — بل صفًّا في جدول يشتقّ منه كل رمز حركي
 * قيمته. المشرف يحرّك رقمًا واحدًا فتتحرّك المسافة والمدّة والمنحنى والتتالي
 * والضباب والتكبير معًا بنسب مدروسة، بدل ستّة مقابض تُترك غير متناسقة.
 *
 * التركيب:
 *   require_once get_template_directory() . '/inc/tahdheer-motion-scale.php';
 *
 * @package Tahdheer
 */

defined( 'ABSPATH' ) || exit;

const TAHDHEER_MOTION_MAX = 10;

/** المستوى الافتراضي لكل مقاس — الجوال أخفض دائمًا لأن الشاشة أقرب واليد تنتظر. */
function tahdheer_motion_defaults(): array {
	return array(
		'desktop' => 5,
		'tablet'  => 3,
		'mobile'  => 2,
	);
}

/**
 * جدول السلّم كاملًا.
 *
 * distance بالبكسل · duration بالمللي ثانية · easing مفتاح من منحنيات نظام
 * التصميم · stagger تتالي الأبناء · blur بالبكسل · scale نقطة البداية.
 *
 * @return array<int,array<string,mixed>>
 */
function tahdheer_motion_scale_table(): array {
	static $table = null;

	if ( null !== $table ) {
		return $table;
	}

	$table = array(
		0  => array( 'label' => 'معطّل', 'distance' => 0, 'duration' => 0, 'easing' => 'linear', 'stagger' => 0, 'blur' => 0, 'scale' => 1.0, 'loops' => false ),
		1  => array( 'label' => 'دقيق', 'distance' => 4, 'duration' => 200, 'easing' => 'ease-out', 'stagger' => 20, 'blur' => 0, 'scale' => 1.0, 'loops' => false ),
		2  => array( 'label' => 'خفيف جدًا', 'distance' => 8, 'duration' => 260, 'easing' => 'ease-out', 'stagger' => 30, 'blur' => 0, 'scale' => 1.0, 'loops' => false ),
		3  => array( 'label' => 'خفيف', 'distance' => 12, 'duration' => 320, 'easing' => 'smooth', 'stagger' => 40, 'blur' => 0, 'scale' => 1.0, 'loops' => false ),
		4  => array( 'label' => 'معتدل', 'distance' => 18, 'duration' => 400, 'easing' => 'smooth', 'stagger' => 50, 'blur' => 2, 'scale' => 1.0, 'loops' => false ),
		5  => array( 'label' => 'متوسط', 'distance' => 24, 'duration' => 480, 'easing' => 'smooth', 'stagger' => 60, 'blur' => 4, 'scale' => 1.0, 'loops' => false ),
		6  => array( 'label' => 'قوي', 'distance' => 34, 'duration' => 560, 'easing' => 'soft-spring', 'stagger' => 80, 'blur' => 6, 'scale' => 0.98, 'loops' => true ),
		7  => array( 'label' => 'قوي جدًا', 'distance' => 46, 'duration' => 640, 'easing' => 'soft-spring', 'stagger' => 100, 'blur' => 8, 'scale' => 0.96, 'loops' => true ),
		8  => array( 'label' => 'أقصى', 'distance' => 62, 'duration' => 760, 'easing' => 'spring', 'stagger' => 120, 'blur' => 12, 'scale' => 0.94, 'loops' => true ),
		9  => array( 'label' => 'عنيف', 'distance' => 84, 'duration' => 880, 'easing' => 'spring', 'stagger' => 150, 'blur' => 16, 'scale' => 0.90, 'loops' => true ),
		10 => array( 'label' => 'سينمائي', 'distance' => 110, 'duration' => 1200, 'easing' => 'custom', 'stagger' => 200, 'blur' => 24, 'scale' => 0.86, 'loops' => true ),
	);

	return $table;
}

/** المستوى الذي يبدأ عنده تحذير الكلفة في المحرّر — ما فوقه متاح لكن ليس افتراضيًا. */
function tahdheer_motion_safe_ceiling(): int {
	return (int) apply_filters( 'tahdheer_motion_safe_ceiling', 7 );
}

/**
 * صف السلّم لمستوى معطى.
 *
 * @param int $level المستوى 0..10.
 * @return array<string,mixed>
 */
function tahdheer_motion_level( int $level ): array {
	$table = tahdheer_motion_scale_table();
	$level = max( 0, min( TAHDHEER_MOTION_MAX, $level ) );

	$row          = $table[ $level ];
	$row['level'] = $level;
	$row['above_ceiling'] = $level > tahdheer_motion_safe_ceiling();

	return $row;
}

/**
 * متغيّرات CSS لمستوى — تُكتب على :root أو على نطاق عنصر واحد.
 *
 * الأسماء متوافقة مع بادئة نظام التصميم القائم (--tah-*) فلا تتعارض مع
 * --tm-* و --ts-* الخاصّتين بالمنظومتين.
 *
 * @param int $level المستوى.
 * @return array<string,string>
 */
function tahdheer_motion_vars( int $level ): array {
	$row  = tahdheer_motion_level( $level );
	$ease = function_exists( 'tahdheer_ds_easing_css' )
		? tahdheer_ds_easing_css( $row['easing'], 'cubic-bezier(.2,1.4,.3,1)' )
		: 'ease-out';

	if ( '' === $ease ) {
		$ease = 'ease-out';
	}

	return array(
		'--tah-mo-level'    => (string) $row['level'],
		'--tah-mo-distance' => $row['distance'] . 'px',
		'--tah-mo-duration' => $row['duration'] . 'ms',
		'--tah-mo-easing'   => $ease,
		'--tah-mo-stagger'  => $row['stagger'] . 'ms',
		'--tah-mo-blur'     => $row['blur'] . 'px',
		'--tah-mo-scale'    => (string) $row['scale'],
	);
}

/**
 * تحويل مصفوفة متغيّرات إلى نصّ إعلانات.
 *
 * @param array<string,string> $vars المتغيّرات.
 */
function tahdheer_motion_vars_css( array $vars ): string {
	$out = '';
	foreach ( $vars as $name => $value ) {
		$out .= $name . ':' . $value . ';';
	}

	return $out;
}

/**
 * سمة style جاهزة لعنصر واحد — يستخدمها المُصيِّر لتجاوز المستوى العام.
 *
 * @param int $level المستوى.
 */
function tahdheer_motion_style_attr( int $level ): string {
	return esc_attr( tahdheer_motion_vars_css( tahdheer_motion_vars( $level ) ) );
}

/**
 * كل CSS السلّم: المتغيّرات لكل مقاس + الإطارات المفتاحية + احترام تقليل الحركة.
 *
 * قاعدة غير قابلة للتفاوض: prefers-reduced-motion يفرض المستوى صفرًا ويتجاوز
 * كل إعداد إداري. لا استثناء ولا تجاوز — هذا شرط وصولية لا تفضيل ذوق.
 */
function tahdheer_motion_css(): string {
	$defaults = tahdheer_motion_defaults();
	$breaks   = function_exists( 'tahdheer_ds_breakpoints' )
		? tahdheer_ds_breakpoints()
		: array(
			'tablet' => '(max-width: 1024px)',
			'mobile' => '(max-width: 767px)',
		);

	$css  = ':root{' . tahdheer_motion_vars_css( tahdheer_motion_vars( (int) $defaults['desktop'] ) ) . '}';
	$css .= '@media ' . $breaks['tablet'] . '{:root{' . tahdheer_motion_vars_css( tahdheer_motion_vars( (int) $defaults['tablet'] ) ) . '}}';
	$css .= '@media ' . $breaks['mobile'] . '{:root{' . tahdheer_motion_vars_css( tahdheer_motion_vars( (int) $defaults['mobile'] ) ) . '}}';

	$css .= '@keyframes tah-mo-in{from{opacity:0;transform:translateY(var(--tah-mo-distance)) scale(var(--tah-mo-scale));filter:blur(var(--tah-mo-blur))}to{opacity:1;transform:none;filter:none}}';
	$css .= '@keyframes tah-mo-fade{from{opacity:0}to{opacity:1}}';

	$css .= '[data-tah-mo]{animation:tah-mo-in var(--tah-mo-duration) var(--tah-mo-easing) both}';
	$css .= '[data-tah-mo="fade"]{animation-name:tah-mo-fade}';
	$css .= '[data-tah-mo-stagger]>*{animation:tah-mo-in var(--tah-mo-duration) var(--tah-mo-easing) both;animation-delay:calc(var(--tah-mo-i,0) * var(--tah-mo-stagger))}';

	// المستوى صفر يوقف الحركة دون حذف الإعلانات — فيبقى العنصر ظاهرًا.
	$css .= '[style*="--tah-mo-level:0"] [data-tah-mo],[style*="--tah-mo-level:0"][data-tah-mo]{animation:none}';

	$css .= '@media (prefers-reduced-motion:reduce){';
	$css .= ':root{--tah-mo-distance:0px;--tah-mo-duration:1ms;--tah-mo-stagger:0ms;--tah-mo-blur:0px;--tah-mo-scale:1}';
	$css .= '[data-tah-mo],[data-tah-mo-stagger]>*{animation:none!important;opacity:1!important;transform:none!important;filter:none!important}';
	$css .= '}';

	return $css;
}

/**
 * درجة كلفة الحركة — نفس الحساب الذي يعرضه المحرّر، منقولًا للخادم ليكون
 * المصدر واحدًا. يُستخدم في تحذيرات المحرّر لا في منع النشر.
 *
 * @param int  $level    المستوى.
 * @param int  $elements عدد العناصر المتحرّكة.
 * @param bool $carousel هل فيه زمن تشغيل كاروسيل.
 * @return array{score:int,band:string,label:string}
 */
function tahdheer_motion_cost( int $level, int $elements, bool $carousel = false ): array {
	$row   = tahdheer_motion_level( $level );
	$score = (int) round( $elements * ( 1 + $level * 0.5 ) + ( $row['blur'] > 0 ? 8 : 0 ) + ( $carousel ? 6 : 0 ) );

	if ( $score < 22 ) {
		$band  = 'low';
		$label = 'منخفضة';
	} elseif ( $score < 45 ) {
		$band  = 'medium';
		$label = 'متوسطة';
	} elseif ( $score < 80 ) {
		$band  = 'high';
		$label = 'مرتفعة';
	} else {
		$band  = 'extreme';
		$label = 'أقصى';
	}

	return array(
		'score' => $score,
		'band'  => $band,
		'label' => $label,
	);
}

/** إخراج CSS السلّم مرّة واحدة في head. */
function tahdheer_motion_print_css(): void {
	wp_register_style( 'tahdheer-motion-scale', false, array(), '1.0.0' ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_enqueue_style( 'tahdheer-motion-scale' );
	wp_add_inline_style( 'tahdheer-motion-scale', tahdheer_motion_css() );
}
add_action( 'wp_enqueue_scripts', 'tahdheer_motion_print_css', 5 );
