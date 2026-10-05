<?php
/**
 * ترويسات أمان إضافية — تكملة لِـ inc/security.php لا بديل عنه.
 *
 * الملف القائم يضع ثلاث ترويسات: nosniff و Referrer-Policy و X-Frame-Options.
 * هذه الوحدة تضيف ما ينقص، وتفعل ذلك بحذر مقصود:
 *
 *   Permissions-Policy      تُطبَّق فورًا — لا شيء في المتجر يحتاج كاميرا ولا
 *                           ميكروفون ولا مستشعرات، فتقييدها بلا مخاطرة.
 *   Content-Security-Policy تبدأ بوضع report-only. لا تكسر شيئًا، وتُظهر كل
 *                           خرق في وحدة تحكّم المتصفح لأسبوع، ثم تُنفَّذ بقرار.
 *   Cross-Origin-Opener-Policy  same-origin-allow-popups: تعزل النافذة عن أي
 *                           نافذة تفتحها، وتُبقي نوافذ الدفع المنبثقة تعمل.
 *
 * لا تُطبَّق أي ترويسة على لوحة الإدارة: سياسة صارمة هناك تكسر الإضافات.
 *
 * @package Tahdheer
 */

defined( 'ABSPATH' ) || exit;

/**
 * سياسة الأذونات: كل ما لا يحتاجه المتجر يُقيَّد.
 */
function tahdheer_permissions_policy(): string {
	$denied = array(
		'camera',
		'microphone',
		'geolocation',
		'magnetometer',
		'gyroscope',
		'accelerometer',
		'usb',
		'serial',
		'midi',
		'display-capture',
		'idle-detection',
		'browsing-topics',
	);

	/**
	 * الميزات المقيَّدة كليًا.
	 *
	 * @param array<int, string> $denied أسماء الميزات.
	 */
	$denied = (array) apply_filters( 'tahdheer_permissions_policy_denied', $denied );

	$parts = array();
	foreach ( $denied as $feature ) {
		$feature = preg_replace( '/[^a-z-]/', '', (string) $feature );
		if ( '' !== $feature ) {
			$parts[] = $feature . '=()';
		}
	}

	// الدفع والشاشة الكاملة مسموحان للأصل نفسه فقط.
	$parts[] = 'payment=(self)';
	$parts[] = 'fullscreen=(self)';

	return implode( ', ', $parts );
}

/**
 * سياسة المحتوى الافتراضية — متسامحة بقصد، لأنها تبدأ بالرصد لا بالمنع.
 *
 * تفترض متجرًا يُحمِّل أنماطه وسكربتاته من أصله، ويسمح بالصور والخطوط من
 * data: و https: لأن ووكومرس والإضافات تفعل ذلك. تُشدَّد بعد قراءة تقارير أسبوع.
 */
function tahdheer_csp_policy(): string {
	$policy = array(
		"default-src 'self'",
		"base-uri 'self'",
		"object-src 'none'",
		"frame-ancestors 'self'",
		"form-action 'self'",
		"img-src 'self' data: https:",
		"font-src 'self' data: https:",
		"style-src 'self' 'unsafe-inline' https:",
		"script-src 'self' 'unsafe-inline' https:",
		"connect-src 'self' https:",
		"frame-src 'self' https:",
	);

	/**
	 * تعديل توجيهات سياسة المحتوى.
	 *
	 * @param array<int, string> $policy التوجيهات.
	 */
	return implode( '; ', (array) apply_filters( 'tahdheer_csp_directives', $policy ) );
}

/**
 * وضع سياسة المحتوى: 'report-only' أو 'enforce' أو 'off'.
 *
 * الافتراضي report-only بقصد. لا تُحوَّل إلى enforce قبل قراءة تقارير حقيقية
 * من الإنتاج، وإلا فهي كسر مؤجَّل لا حماية.
 */
function tahdheer_csp_mode(): string {
	$mode = (string) apply_filters( 'tahdheer_csp_mode', 'report-only' );

	return in_array( $mode, array( 'report-only', 'enforce', 'off' ), true ) ? $mode : 'report-only';
}

/**
 * إرسال الترويسات الإضافية على الواجهة فقط.
 */
function tahdheer_extra_security_headers(): void {
	if ( is_admin() || headers_sent() ) {
		return;
	}

	// لا تلمس مسارات الدفع والحساب بسياسة قد تعطّل نافذة بوابة الدفع.
	$is_checkout = function_exists( 'is_checkout' ) && is_checkout();

	header( 'Permissions-Policy: ' . tahdheer_permissions_policy() );
	header( 'Cross-Origin-Opener-Policy: same-origin-allow-popups' );
	header( 'X-Permitted-Cross-Domain-Policies: none' );

	$mode = tahdheer_csp_mode();
	if ( 'off' === $mode || $is_checkout ) {
		return;
	}

	$header = 'enforce' === $mode
		? 'Content-Security-Policy: '
		: 'Content-Security-Policy-Report-Only: ';

	header( $header . tahdheer_csp_policy() );
}
add_action( 'send_headers', 'tahdheer_extra_security_headers', 20 );

/**
 * تقرير مختصر لمركز الصحّة: ما هو مُطبَّق الآن فعلًا.
 *
 * @return array<string, string>
 */
function tahdheer_security_headers_report(): array {
	return array(
		'permissions_policy' => tahdheer_permissions_policy(),
		'csp_mode'           => tahdheer_csp_mode(),
		'csp_policy'         => tahdheer_csp_policy(),
		'coop'               => 'same-origin-allow-popups',
		'note'               => __( 'لا ترويسة من هذه الوحدة تُطبَّق في لوحة الإدارة ولا على صفحة الدفع.', 'tahdheer' ),
	);
}
