<?php
/**
 * Tahdheer — نقطة تحميل واحدة لكل ملفّات هذه الدفعة.
 *
 * التركيب سطر واحد في نهاية functions.php:
 *
 *     require_once get_template_directory() . '/inc/tahdheer-loader.php';
 *
 * الملف يتحقّق من وجود كل ملف قبل تحميله، ويحمّلها بالترتيب الإلزامي
 * (التبعيات من أعلى لأسفل)، ويسجّل في سجل الأخطاء أي ملف ناقص بدل السقوط —
 * فنسخة ناقصة تُعلن نفسها ولا تُفجّر الموقع.
 *
 * @package Tahdheer
 */

defined( 'ABSPATH' ) || exit;

/**
 * الترتيب إلزامي ولا يجوز القلب:
 * icons/product-data/hero-rating/motion-scale لا تعتمد على شيء؛
 * registry يقرأ منها؛ runtimes يقرأ السجل؛ renderers يقرأ الاثنين؛
 * renderers-2/-3 يستدعيان دوالّ renderers المشتركة؛ heroes آخرها.
 */
$tahdheer_batch = array(
	'inc/tahdheer-icons.php',
	'inc/tahdheer-product-data.php',
	'inc/tahdheer-hero-rating.php',
	'inc/tahdheer-motion-scale.php',
	'inc/tahdheer-component-registry.php',
	'inc/tahdheer-runtimes.php',
	'inc/tahdheer-renderers.php',
	'inc/tahdheer-renderers-2.php',
	'inc/tahdheer-renderers-3.php',
	'inc/tahdheer-heroes.php',
);

$tahdheer_missing = array();

foreach ( $tahdheer_batch as $tahdheer_file ) {
	$tahdheer_path = get_template_directory() . '/' . $tahdheer_file;

	if ( file_exists( $tahdheer_path ) ) {
		require_once $tahdheer_path;
	} else {
		$tahdheer_missing[] = $tahdheer_file;
	}
}

if ( $tahdheer_missing ) {
	// إعلان لا سقوط: الموقع يعمل، والمشرف يرى ما نقص في سجل الأخطاء
	// وفي تنبيه لوحة التحكم.
	error_log( 'Tahdheer loader — ملفّات ناقصة: ' . implode( ' · ', $tahdheer_missing ) );

	add_action(
		'admin_notices',
		function () use ( $tahdheer_missing ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p><strong>Tahdheer:</strong> '
				. esc_html__( 'ملفّات ناقصة من دفعة نظام التجارة البصرية — انسخ مجلد inc/ كاملًا: ', 'tahdheer' )
				. esc_html( implode( ' · ', $tahdheer_missing ) )
				. '</p></div>';
		}
	);
}

unset( $tahdheer_batch, $tahdheer_file, $tahdheer_path, $tahdheer_missing );
