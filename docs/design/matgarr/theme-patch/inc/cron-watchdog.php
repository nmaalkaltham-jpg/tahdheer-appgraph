<?php
/**
 * كلب حراسة المهام المجدولة.
 *
 * القالب يسجّل تسع مهام cron، وفشل أيٍّ منها صامت تمامًا: لا خرائط تُبنى، ولا
 * تلخيص يومي، ولا فحص تغييرات — ولا أحد يعرف حتى يكتشف نتيجة ناقصة بعد أسابيع.
 *
 * هذه الوحدة تفعل ثلاثة أشياء فقط:
 *   1) تسجّل آخر تنفيذ ناجح لكل مهمة مراقَبة.
 *   2) تعيد جدولة مهمة فُقد جدولها — مرة واحدة، بلا تنفيذ فوري.
 *   3) تُنبّه المسؤول عند فشل مهمة نافذتين متتاليتين، لا قبل ذلك.
 *
 * ولا تفعل شيئًا غير ذلك: لا تشغّل مهمة، ولا تحذف بيانات، ولا تعدّل إعدادًا.
 *
 * @package Tahdheer
 */

defined( 'ABSPATH' ) || exit;

/**
 * الخيار الذي يحمل حالة المراقبة. غير autoloaded.
 */
const TAHDHEER_CRON_WATCHDOG_OPTION = 'tahdheer_cron_watchdog';

/**
 * خطّاف الفحص الدوري الخاص بالوحدة.
 */
const TAHDHEER_CRON_WATCHDOG_HOOK = 'tahdheer_cron_watchdog_check';

/**
 * المهام المراقَبة: الخطّاف => النافذة المتوقّعة بالثواني.
 *
 * النافذة هي أقصى فترة مقبولة بين تنفيذَين ناجحَين. التنبيه لا يُرفع إلا بعد
 * مرور نافذتين، فلا يُزعج المسؤول بتأخير واحد عابر.
 *
 * @return array<string, array{window:int, label:string}>
 */
function tahdheer_cron_watchdog_registry(): array {
	$day  = DAY_IN_SECONDS;
	$week = WEEK_IN_SECONDS;

	/**
	 * تعديل قائمة المهام المراقَبة.
	 *
	 * @param array<string, array{window:int, label:string}> $registry القائمة.
	 */
	return (array) apply_filters(
		'tahdheer_cron_watchdog_registry',
		array(
			'tahdheer_seo_workflow_cron'          => array(
				'window' => 6 * HOUR_IN_SECONDS,
				'label'  => __( 'محرك سير عمل SEO', 'tahdheer' ),
			),
			'tahdheer_proactive_refresh_sitemaps' => array(
				'window' => $day,
				'label'  => __( 'تحديث خرائط الموقع', 'tahdheer' ),
			),
			'tahdheer_seo_analytics_daily'        => array(
				'window' => $day,
				'label'  => __( 'التلخيص اليومي للقياس', 'tahdheer' ),
			),
			'tahdheer_seo_intel_daily'            => array(
				'window' => $day,
				'label'  => __( 'الاستخلاص اليومي', 'tahdheer' ),
			),
			'tahdheer_seo_daily_snapshot'         => array(
				'window' => $day,
				'label'  => __( 'اللقطة اليومية', 'tahdheer' ),
			),
			'tahdheer_seo_change_scan'            => array(
				'window' => $day,
				'label'  => __( 'فحص التغييرات', 'tahdheer' ),
			),
			'tahdheer_seo_email_report'           => array(
				'window' => $day,
				'label'  => __( 'تقرير البريد', 'tahdheer' ),
			),
			'tahdheer_seo_analytics_weekly_audit' => array(
				'window' => $week,
				'label'  => __( 'التدقيق الأسبوعي', 'tahdheer' ),
			),
			'tahdheer_hm_cleanup'                 => array(
				'window' => $day,
				'label'  => __( 'تنظيف الخريطة الحرارية', 'tahdheer' ),
			),
		)
	);
}

/**
 * حالة المراقبة المخزَّنة.
 *
 * @return array<string, array<string, mixed>>
 */
function tahdheer_cron_watchdog_state(): array {
	$state = get_option( TAHDHEER_CRON_WATCHDOG_OPTION, array() );

	return is_array( $state ) ? $state : array();
}

/**
 * كتابة الحالة. تُستدعى نادرًا، ودائمًا بـ autoload = false.
 *
 * @param array<string, array<string, mixed>> $state الحالة.
 */
function tahdheer_cron_watchdog_save( array $state ): void {
	update_option( TAHDHEER_CRON_WATCHDOG_OPTION, $state, false );
}

/**
 * تسجيل تنفيذ ناجح لمهمة مراقَبة.
 *
 * مربوطة على كل خطّاف مراقَب بأولوية دنيا جدًا، فتعمل بعد المعالج الحقيقي: إن
 * سقط المعالج بخطأ فاتل لا يُسجَّل نجاح، وهو المطلوب بالضبط.
 *
 * @param string $hook الخطّاف.
 */
function tahdheer_cron_watchdog_mark_success( string $hook ): void {
	$state = tahdheer_cron_watchdog_state();

	$state[ $hook ] = array(
		'last_success' => time(),
		'strikes'      => 0,
		'nudged'       => (int) ( $state[ $hook ]['nudged'] ?? 0 ),
	);

	tahdheer_cron_watchdog_save( $state );
}

/**
 * ربط مُسجِّل النجاح على كل مهمة مراقَبة، وجدولة الفحص الدوري.
 */
function tahdheer_cron_watchdog_bootstrap(): void {
	foreach ( array_keys( tahdheer_cron_watchdog_registry() ) as $hook ) {
		add_action(
			$hook,
			static function () use ( $hook ): void {
				tahdheer_cron_watchdog_mark_success( $hook );
			},
			PHP_INT_MAX
		);
	}

	if ( ! wp_next_scheduled( TAHDHEER_CRON_WATCHDOG_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', TAHDHEER_CRON_WATCHDOG_HOOK );
	}
}
add_action( 'init', 'tahdheer_cron_watchdog_bootstrap', 5 );

/**
 * تنظيف جدول الوحدة عند تغيير القالب.
 */
function tahdheer_cron_watchdog_cleanup(): void {
	wp_clear_scheduled_hook( TAHDHEER_CRON_WATCHDOG_HOOK );
}
add_action( 'switch_theme', 'tahdheer_cron_watchdog_cleanup' );

/**
 * الفحص الدوري: إعادة الجدولة المفقودة، وإحصاء التأخير.
 */
function tahdheer_cron_watchdog_run(): void {
	$registry = tahdheer_cron_watchdog_registry();
	$state    = tahdheer_cron_watchdog_state();
	$now      = time();

	foreach ( $registry as $hook => $meta ) {
		$window  = max( HOUR_IN_SECONDS, (int) $meta['window'] );
		$entry   = is_array( $state[ $hook ] ?? null ) ? $state[ $hook ] : array();
		$last    = (int) ( $entry['last_success'] ?? 0 );
		$strikes = (int) ( $entry['strikes'] ?? 0 );

		/*
		 * جدول مفقود: نبضة مفردة واحدة فقط، لا إعادة تعريف للتكرار.
		 *
		 * إعادة الجدولة بتكرار مُخمَّن كانت ستغيّر إيقاع المهمة صامتة — مهمة سير
		 * العمل تعمل كل ست ساعات بفاصل مخصّص لا يعرفه هذا الملف، وجدولتها بـ
		 * twicedaily تُبطئها للنصف بلا أن يلاحظ أحد. النبضة المفردة تُشغّل المهمة
		 * مرة، ومنطق التسجيل في القالب نفسه يعيد بناء الحدث المتكرّر بفاصله الصحيح.
		 */
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_single_event( $now + ( 5 * MINUTE_IN_SECONDS ), $hook );

			$entry['nudged']     = (int) ( $entry['nudged'] ?? 0 ) + 1;
			$entry['last_action'] = 'nudged';
		}

		/*
		 * أول تشغيل للوحدة: لا تاريخ نجاح سابق، فلا يُحتسب ذلك فشلًا. تُسجَّل
		 * لحظة البداية ليُقاس التأخير من الآن لا من الصفر.
		 */
		if ( 0 === $last ) {
			$entry['observed_from'] = (int) ( $entry['observed_from'] ?? $now );
			$reference              = (int) $entry['observed_from'];
		} else {
			$reference = $last;
		}

		$overdue_by = $now - $reference;

		if ( $overdue_by > ( 2 * $window ) ) {
			++$strikes;
		} else {
			$strikes = 0;
		}

		$entry['strikes']    = $strikes;
		$entry['overdue_by'] = max( 0, $overdue_by );
		$state[ $hook ]      = $entry;
	}

	tahdheer_cron_watchdog_save( $state );

	/**
	 * إشعار بانتهاء فحص المهام — نقطة ربط لمركز الصحّة أو التسجيل.
	 *
	 * @param array<string, array<string, mixed>> $state الحالة بعد الفحص.
	 */
	do_action( 'tahdheer_cron_watchdog_checked', $state );
}
add_action( TAHDHEER_CRON_WATCHDOG_HOOK, 'tahdheer_cron_watchdog_run' );

/**
 * المهام المتأخّرة فعلًا: نافذتان متتاليتان على الأقل.
 *
 * @return array<int, array<string, mixed>>
 */
function tahdheer_cron_watchdog_alerts(): array {
	$registry = tahdheer_cron_watchdog_registry();
	$state    = tahdheer_cron_watchdog_state();
	$alerts   = array();

	foreach ( $state as $hook => $entry ) {
		if ( ! isset( $registry[ $hook ] ) ) {
			continue;
		}
		if ( (int) ( $entry['strikes'] ?? 0 ) < 2 ) {
			continue;
		}

		$alerts[] = array(
			'hook'       => $hook,
			'label'      => (string) $registry[ $hook ]['label'],
			'strikes'    => (int) $entry['strikes'],
			'overdue_by' => (int) ( $entry['overdue_by'] ?? 0 ),
			'last'       => (int) ( $entry['last_success'] ?? 0 ),
		);
	}

	return $alerts;
}

/**
 * تنبيه إداري واحد مجمَّع، للمسؤولين فقط.
 */
function tahdheer_cron_watchdog_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$alerts = tahdheer_cron_watchdog_alerts();
	if ( ! $alerts ) {
		return;
	}

	echo '<div class="notice notice-warning"><p><strong>';
	esc_html_e( 'مهام مجدولة متأخّرة في قالب التحاضير الحديثة', 'tahdheer' );
	echo '</strong></p><ul style="margin-inline-start:18px;list-style:disc">';

	foreach ( $alerts as $alert ) {
		printf(
			'<li>%1$s — %2$s</li>',
			esc_html( $alert['label'] ),
			esc_html(
				$alert['last']
					/* translators: %s: human readable time difference. */
					? sprintf( __( 'آخر تنفيذ ناجح قبل %s', 'tahdheer' ), human_time_diff( $alert['last'] ) )
					: __( 'لم يُسجَّل تنفيذ ناجح واحد منذ بدء المراقبة', 'tahdheer' )
			)
		);
	}

	$url = wp_nonce_url(
		admin_url( 'admin-post.php?action=tahdheer_cron_watchdog_recheck' ),
		'tahdheer_cron_watchdog_recheck'
	);

	echo '</ul><p>';
	printf(
		'<a class="button button-secondary" href="%1$s">%2$s</a>',
		esc_url( $url ),
		esc_html__( 'أعد الفحص الآن', 'tahdheer' )
	);
	echo '</p></div>';
}
add_action( 'admin_notices', 'tahdheer_cron_watchdog_notice' );

/**
 * إعادة الفحص يدويًا. لا تشغّل أي مهمة — تفحص الجدولة والتأخير فقط.
 */
function tahdheer_cron_watchdog_handle_recheck(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'صلاحية غير كافية.', 'tahdheer' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'tahdheer_cron_watchdog_recheck' );

	tahdheer_cron_watchdog_run();

	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
}
add_action( 'admin_post_tahdheer_cron_watchdog_recheck', 'tahdheer_cron_watchdog_handle_recheck' );
