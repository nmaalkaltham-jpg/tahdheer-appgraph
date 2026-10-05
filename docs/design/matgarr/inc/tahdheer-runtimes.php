<?php
/**
 * Tahdheer — تسجيل أزمنة التشغيل وتحميلها الشرطي.
 *
 * القاعدة التي يفرضها بند الأداء: وجود ٣٠٠ عنصر لا يعني تحميل ٣٠٠ عنصر. ولا
 * حزمة عامة تُحمَّل في كل صفحة «احتياطًا». المكوّن يطلب زمن تشغيله عند تصييره
 * فقط، فتُدرَج الأصول في التذييل لهذه الصفحة وحدها.
 *
 * الاستخدام داخل مُصيِّر أي مكوّن:
 *   tahdheer_runtime_require( 'rt-gallery' );
 *
 * التركيب:
 *   require_once get_template_directory() . '/inc/tahdheer-runtimes.php';
 *
 * @package Tahdheer
 */

defined( 'ABSPATH' ) || exit;

/**
 * الأزمنة المسجّلة: المعرّف => الملف والتسمية وعدد الاختيارات التي يفتحها.
 *
 * @return array<string,array<string,mixed>>
 */
function tahdheer_runtimes(): array {
	static $runtimes = null;

	if ( null !== $runtimes ) {
		return $runtimes;
	}

	$runtimes = array(
		'rt-gallery'    => array(
			'label'   => 'المعرض والكاروسيل',
			'file'    => 'assets/js/tah-rt-gallery.js',
			'unlocks' => 21,
			'global'  => 'TahGallery',
		),
		'rt-disclosure' => array(
			'label'   => 'الكشف (تبويبات وأكورديون)',
			'file'    => 'assets/js/tah-rt-disclosure.js',
			'unlocks' => 14,
			'global'  => 'TahDisclosure',
		),
		'rt-observer'   => array(
			'label'   => 'مراقب التقاطع',
			'file'    => 'assets/js/tah-rt-observer.js',
			'unlocks' => 9,
			'global'  => 'TahObserver',
		),
		'rt-pointer'    => array(
			'label'   => 'المؤشّر (نقاط وقبل/بعد)',
			'file'    => 'assets/js/tah-rt-pointer.js',
			'unlocks' => 4,
			'global'  => 'TahPointer',
		),
		'rt-dialog'     => array(
			'label'   => 'الحوار (معاينة ودُرج)',
			'file'    => 'assets/js/tah-rt-dialog.js',
			'unlocks' => 3,
			'global'  => 'TahDialog',
		),
	);

	/**
	 * تسجيل زمن تشغيل من إضافة.
	 *
	 * @param array<string,array<string,mixed>> $runtimes الأزمنة.
	 */
	return (array) apply_filters( 'tahdheer_runtimes', $runtimes );
}

/** إصدار الأصل من وقت التعديل — يمنع كاشًا قديمًا بعد كل تحديث. */
function tahdheer_runtime_ver( string $rel ): string {
	$path = get_template_directory() . '/' . ltrim( $rel, '/' );

	return file_exists( $path ) ? (string) filemtime( $path ) : '1.0.0';
}

/** تسجيل كل الأصول بلا إدراج — الإدراج يقرّره المُصيِّر. */
function tahdheer_runtimes_register(): void {
	$css = 'assets/css/tah-runtimes.css';

	wp_register_style(
		'tah-runtimes',
		get_template_directory_uri() . '/' . $css,
		array(),
		tahdheer_runtime_ver( $css )
	);

	foreach ( tahdheer_runtimes() as $id => $runtime ) {
		wp_register_script(
			'tah-' . $id,
			get_template_directory_uri() . '/' . $runtime['file'],
			array(),
			tahdheer_runtime_ver( (string) $runtime['file'] ),
			true
		);
	}
}
add_action( 'wp_enqueue_scripts', 'tahdheer_runtimes_register', 5 );

/**
 * طلب زمن تشغيل من داخل مُصيِّر مكوّن.
 *
 * آمن للاستدعاء المتكرّر: عشرة معارض في صفحة واحدة تُدرج الملف مرّة واحدة.
 *
 * @param string $id معرّف الزمن.
 */
function tahdheer_runtime_require( string $id ): bool {
	if ( ! isset( tahdheer_runtimes()[ $id ] ) ) {
		return false;
	}

	if ( ! wp_style_is( 'tah-runtimes', 'enqueued' ) ) {
		wp_enqueue_style( 'tah-runtimes' );
	}

	if ( ! wp_script_is( 'tah-' . $id, 'enqueued' ) ) {
		wp_enqueue_script( 'tah-' . $id );
	}

	return true;
}

/**
 * طلب عدّة أزمنة معًا — يستخدمه مكوّن يعتمد على أكثر من واحد.
 *
 * @param array<int,string> $ids المعرّفات.
 */
function tahdheer_runtime_require_many( array $ids ): void {
	foreach ( $ids as $id ) {
		tahdheer_runtime_require( (string) $id );
	}
}

/**
 * أزمنة التشغيل التي يحتاجها مكوّن من سجل المكوّنات، مشتقّة من تبعياته.
 *
 * فلا تُكتب قائمة ثانية تنحرف عن الأولى.
 *
 * @param string $component معرّف المكوّن.
 * @return array<int,string>
 */
function tahdheer_component_runtime_ids( string $component ): array {
	if ( ! function_exists( 'tahdheer_components' ) ) {
		return array();
	}

	$components = tahdheer_components();
	if ( ! isset( $components[ $component ] ) ) {
		return array();
	}

	$known = array_keys( tahdheer_runtimes() );

	return array_values( array_intersect( (array) $components[ $component ]['deps'], $known ) );
}

/**
 * طلب كل ما يحتاجه مكوّن بسطر واحد داخل مُصيِّره.
 *
 * @param string $component معرّف المكوّن.
 */
function tahdheer_component_boot( string $component ): void {
	tahdheer_runtime_require_many( tahdheer_component_runtime_ids( $component ) );
}

/**
 * تقرير الأزمنة — يغذّي لوحة التشخيص ولا يخترع رقمًا.
 *
 * @return array<string,array<string,mixed>>
 */
function tahdheer_runtimes_report(): array {
	$out = array();

	foreach ( tahdheer_runtimes() as $id => $runtime ) {
		$path = get_template_directory() . '/' . $runtime['file'];

		$out[ $id ] = array(
			'label'    => (string) $runtime['label'],
			'file'     => (string) $runtime['file'],
			'unlocks'  => (int) $runtime['unlocks'],
			'exists'   => file_exists( $path ),
			'bytes'    => file_exists( $path ) ? (int) filesize( $path ) : 0,
			'enqueued' => wp_script_is( 'tah-' . $id, 'enqueued' ),
		);
	}

	return $out;
}
