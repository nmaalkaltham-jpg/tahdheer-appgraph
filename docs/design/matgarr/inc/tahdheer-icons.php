<?php
/**
 * Tahdheer — سجل الأيقونات الموحّد.
 *
 * كان الطقم مكرّرًا حرفيًا بين tsec_icon() و tmo_icon(): السلّة والسهم والصح
 * والصاعقة والدرع والشاحنة والتشغيل والزائد والناقص والنجمة كلها معرّفة مرّتين
 * بنفس مسار SVG. وهذا يخالف بند معمارية CSS (لا تعريفات مكرّرة) ويجعل تصحيح
 * مسار واحد عملًا في ملفّين.
 *
 * إضافةً لذلك: مسار 'user' في نسخة الأقسام كان يحمل قوسًا زائدًا يُفسد الرسم،
 * وكان يُصحَّح بسطر ترقيعي بعد تعريف المصفوفة. النسخة هنا صحيحة من الأصل.
 *
 * التركيب:
 *   require_once get_template_directory() . '/inc/tahdheer-icons.php';
 * ثم فوّض القديمتين:
 *   function tsec_icon( $n, $s = 20 ) { return tahdheer_icon( $n, $s, array( 'class' => 'ts-i' ) ); }
 *   function tmo_icon( $n, $s = 20 )  { return tahdheer_icon( $n, $s, array( 'class' => 'tm-i' ) ); }
 *
 * @package Tahdheer
 */

defined( 'ABSPATH' ) || exit;

/**
 * كل مسارات الأيقونات — مصدر حقيقة واحد.
 *
 * الطقم خطّي بـ currentColor و stroke-width قابلين للتوريث، فلا لون مثبّت هنا.
 *
 * @return array<string,string> اسم الأيقونة => مسار SVG.
 */
function tahdheer_icon_paths(): array {
	static $paths = null;

	if ( null !== $paths ) {
		return $paths;
	}

	$paths = array(
		// تنقّل وتحكّم.
		'search'   => 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14Zm10 17-4.35-4.35',
		'arrow'    => 'M14 6l-6 6 6 6',
		'chev'     => 'M14 6l-6 6 6 6',
		'x'        => 'M6 6l12 12M18 6L6 18',
		'plus'     => 'M12 5v14M5 12h14',
		'minus'    => 'M5 12h14',
		'check'    => 'M4 12.5 9.5 18 20 6.5',
		'expand'   => 'M4 9V4h5M20 15v5h-5M20 9V4h-5M4 15v5h5',
		'filter'   => 'M4 5h16l-6 7v6l-4-2v-4L4 5Z',
		'trash'    => 'M4 7h16M9 7V5h6v2m-8 0 1 13h8l1-13',

		// تجارة.
		'cart'     => 'M4 5h2l2.2 11h9.6l2-8H7.4M10 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm8 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z',
		'bag'      => 'M6 8h12l-1 12H7L6 8Zm3 0V6a3 3 0 0 1 6 0v2',
		'wallet'   => 'M3 8h18v11H3zM3 8l2-4h14l2 4M16 13.5h2',
		'tag'      => 'M3 12V4h8l9 9-8 8-9-9Zm5-4.5h.01',
		'truck'    => 'M3 7h11v8H3zM14 10h4l3 3v2h-7M7.5 18a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm10 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z',
		'shield'   => 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3Z',

		// حالة وتفاعل.
		'star'     => 'M12 3l2.7 5.5 6 .9-4.3 4.2 1 6-5.4-2.8L6.6 19.6l1-6L3.3 9.4l6-.9L12 3Z',
		'heart'    => 'M12 20s-7-4.3-7-9.2A3.8 3.8 0 0 1 12 8a3.8 3.8 0 0 1 7 2.8C19 15.7 12 20 12 20Z',
		'bolt'     => 'M13 2 4 14h6l-1 8 9-12h-6l1-8Z',
		'sparkle'  => 'M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8L12 3Z',
		'clock'    => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm0 4v5l3.5 2',
		'eye'      => 'M2.5 12S6 6 12 6s9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Zm9.5 2.6a2.6 2.6 0 1 0 0-5.2 2.6 2.6 0 0 0 0 5.2Z',
		'quote'    => 'M8 6C5.5 7.5 4 10 4 13.5A4.5 4.5 0 1 0 8.5 9M19 6c-2.5 1.5-4 4-4 7.5A4.5 4.5 0 1 0 19.5 9',
		// كان يحمل قوسًا زائدًا في نسخة الأقسام — مُصحَّح هنا.
		'user'     => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-8 9c1.5-4 5-5.5 8-5.5s6.5 1.5 8 5.5',

		// وسائط وملفات.
		'play'     => 'M8 5.5v13l11-6.5-11-6.5Z',
		'pause'    => 'M9 5v14M15 5v14',
		'replay'   => 'M4 12a8 8 0 1 0 3-6.2M4 4v5h5',
		'image'    => 'M4 5h16v14H4zM8.5 11a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM4 17l5-5 4 4 3-3 4 4',
		'file'     => 'M6 3h8l4 4v14H6zM14 3v4h4',
		'download' => 'M12 4v10m0 0 4-4m-4 4-4-4M4 19h16',
	);

	/**
	 * إضافة أيقونات من إضافة أو قالب ابن — بدل تعريف طقم ثانٍ.
	 *
	 * @param array<string,string> $paths المسارات.
	 */
	$paths = (array) apply_filters( 'tahdheer_icon_paths', $paths );

	return $paths;
}

/**
 * هل الأيقونة مسجّلة؟ يستخدمه فاحص الجودة في المحرّر قبل العرض.
 *
 * @param string $name الاسم.
 */
function tahdheer_icon_exists( string $name ): bool {
	return isset( tahdheer_icon_paths()[ $name ] );
}

/**
 * أسماء الأيقونات المتاحة — يغذّي قائمة الاختيار في المحرّر.
 *
 * @return array<int,string>
 */
function tahdheer_icon_names(): array {
	return array_keys( tahdheer_icon_paths() );
}

/**
 * بناء وسم SVG لأيقونة.
 *
 * الأيقونة زخرفية افتراضيًا (aria-hidden). تمرير label يحوّلها إلى صورة ذات
 * معنى مع دور ونص بديل — لأن أيقونة تحمل معنى وحدها بلا نص تحتاج اسمًا.
 *
 * @param string               $name الاسم.
 * @param int                  $size المقاس بالبكسل (10..64).
 * @param array<string,string> $args class · label · stroke.
 * @return string وسم SVG أو '' إذا كان الاسم غير مسجّل.
 */
function tahdheer_icon( string $name, int $size = 20, array $args = array() ): string {
	$paths = tahdheer_icon_paths();
	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}

	$size   = max( 10, min( 64, $size ) );
	$class  = isset( $args['class'] ) ? sanitize_html_class( (string) $args['class'] ) : 'tah-i';
	$stroke = isset( $args['stroke'] ) ? (float) $args['stroke'] : 0;
	$label  = isset( $args['label'] ) ? trim( (string) $args['label'] ) : '';

	$attrs = sprintf(
		'class="%s" viewBox="0 0 24 24" width="%d" height="%d" fill="none" stroke="currentColor"',
		esc_attr( $class ),
		$size,
		$size
	);

	if ( $stroke > 0 ) {
		$attrs .= ' stroke-width="' . esc_attr( (string) $stroke ) . '"';
	}

	$attrs .= '' === $label
		? ' aria-hidden="true" focusable="false"'
		: ' role="img" aria-label="' . esc_attr( $label ) . '"';

	return '<svg ' . $attrs . '><path d="' . esc_attr( $paths[ $name ] ) . '" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}

/**
 * نجوم التقييم — من قيمة حقيقية فقط.
 *
 * تُرجع '' عند عدم وجود تقييمات، فلا يظهر خمس نجوم فارغة تُقرأ كتقييم صفر.
 *
 * @param float $value القيمة 0..5.
 * @param int   $size  مقاس النجمة.
 */
function tahdheer_stars( float $value, int $size = 15 ): string {
	$value = max( 0, min( 5, $value ) );
	if ( $value <= 0 ) {
		return '';
	}

	$label = sprintf(
		/* translators: %s: rating value out of five. */
		esc_html__( 'التقييم %s من 5', 'tahdheer' ),
		number_format_i18n( $value, 1 )
	);

	$out = '<span class="tah-stars" role="img" aria-label="' . esc_attr( $label ) . '">';
	for ( $i = 1; $i <= 5; $i++ ) {
		$fill    = min( 1, max( 0, $value - $i + 1 ) );
		$opacity = $fill > 0 ? max( 0.35, $fill ) : 0.2;
		$out    .= '<svg viewBox="0 0 24 24" width="' . (int) $size . '" height="' . (int) $size . '" aria-hidden="true">'
			. '<path fill="currentColor" opacity="' . esc_attr( (string) round( $opacity, 2 ) ) . '" d="'
			. esc_attr( tahdheer_icon_paths()['star'] ) . '"/></svg>';
	}

	return $out . '</span>';
}

if ( ! function_exists( 'tsec_icon' ) ) {
	/**
	 * @param string $name الاسم.
	 * @param int    $size المقاس.
	 */
	function tsec_icon( $name, $size = 20 ) {
		return tahdheer_icon( (string) $name, (int) $size, array( 'class' => 'ts-i' ) );
	}
}

if ( ! function_exists( 'tmo_icon' ) ) {
	/**
	 * @param string $name الاسم.
	 * @param int    $size المقاس.
	 */
	function tmo_icon( $name, $size = 20 ) {
		return tahdheer_icon( (string) $name, (int) $size, array( 'class' => 'tm-i' ) );
	}
}
