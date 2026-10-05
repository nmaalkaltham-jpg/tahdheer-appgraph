<?php
/**
 * مولّد الصفحات المحورية للمنهج — عناقيد المادة والصف والفصل.
 *
 * المعلّم لا يبحث عن «تحاضير»، بل عن «تحضير رياضيات سادس». ذلك التقاطع موجود
 * في بيانات المنتجات أصلًا، فهذه الوحدة تحوّله إلى صفحات محورية تُبنى وتُحدَّث
 * وحدها بلا كتابة سطر محتوى واحد.
 *
 * ثلاث قواعد غير قابلة للتفاوض، وكلها منفَّذة هنا:
 *   1) لا صفحة محورية لعنقود فيه أقلّ من ثلاثة منتجات — الصفحة الفارغة بمظهر
 *      ممتلئ أسوأ من غيابها، وهي أسرع طريق إلى تصنيف «محتوى ضعيف».
 *   2) لا استعلام قاعدة بيانات في طلب الزائر — الفهرس يُبنى في cron ويُقرأ جاهزًا.
 *   3) لا تعارض مع صفحة أو رابط قائم — قاعدة المسار قابلة للتغيير، وأي تصادم
 *      يُترك لووردبريس ولا يُسرَق منه.
 *
 * @package Tahdheer
 */

defined( 'ABSPATH' ) || exit;

/** الخيار الذي يحمل فهرس العناقيد. غير autoloaded. */
const TAHDHEER_HUB_OPTION = 'tahdheer_curriculum_hubs';

/** خطّاف إعادة بناء الفهرس. */
const TAHDHEER_HUB_CRON = 'tahdheer_hub_rebuild';

/** إصدار شكل الفهرس — يُرفع عند تغيير بنيته فيُعاد البناء تلقائيًا. */
const TAHDHEER_HUB_SCHEMA = 2;

/**
 * قاعدة مسار الصفحات المحورية.
 *
 * قاعدة مُنفصلة بقصد: المسارات العربية المباشرة مثل /الرياضيات/الصف-السادس/
 * تتصادم مع الصفحات والتصنيفات القائمة، والتصادم يُسقط صفحة موجودة. تغييرها
 * يتطلّب إعادة توليد قواعد الروابط الدائمة من إعدادات ووردبريس.
 */
function tahdheer_hub_base(): string {
	$base = (string) apply_filters( 'tahdheer_hub_base', 'curriculum' );

	return sanitize_title( '' !== $base ? $base : 'curriculum' );
}

/** الحدّ الأدنى لعدد المنتجات في العنقود. */
function tahdheer_hub_min_products(): int {
	return max( 1, (int) apply_filters( 'tahdheer_hub_min_products', 3 ) );
}

/**
 * مفاتيح حقول المنهج.
 *
 * تُقرأ من فهرس البحث عند توفّره ليبقى مصدر الحقيقة واحدًا، ولها بديل معلن
 * حتى تعمل الوحدة قبل تهيئة الفهرس.
 *
 * @return array<string, string> النوع => مفتاح الميتا.
 */
function tahdheer_hub_meta_keys(): array {
	$defaults = array(
		'stage'    => '_tahdheer_stage',
		'grade'    => '_tahdheer_grade',
		'subject'  => '_tahdheer_subject',
		'semester' => '_tahdheer_semester',
	);

	if ( class_exists( 'Tahdheer_Search_Index' ) && method_exists( 'Tahdheer_Search_Index', 'education_meta_keys' ) ) {
		$known = (array) Tahdheer_Search_Index::education_meta_keys();
		foreach ( $defaults as $type => $key ) {
			foreach ( $known as $candidate ) {
				if ( false !== strpos( (string) $candidate, $type ) ) {
					$defaults[ $type ] = (string) $candidate;
					break;
				}
			}
		}
	}

	/**
	 * مفاتيح حقول المنهج المستخدمة في بناء العناقيد.
	 *
	 * @param array<string, string> $defaults النوع => مفتاح الميتا.
	 */
	return (array) apply_filters( 'tahdheer_hub_meta_keys', $defaults );
}

/**
 * أنواع العناقيد المولَّدة، بترتيب الأهمية البحثية.
 *
 * @return array<string, array{label:string, parts:array<int,string>}>
 */
function tahdheer_hub_types(): array {
	return (array) apply_filters(
		'tahdheer_hub_types',
		array(
			// شكل الاستعلام الحقيقي: «تحضير رياضيات سادس».
			'subject-grade' => array(
				'label' => __( 'مادة وصف', 'tahdheer' ),
				'parts' => array( 'subject', 'grade' ),
			),
			'grade'         => array(
				'label' => __( 'صف', 'tahdheer' ),
				'parts' => array( 'grade' ),
			),
			'subject'       => array(
				'label' => __( 'مادة', 'tahdheer' ),
				'parts' => array( 'subject' ),
			),
			'semester'      => array(
				'label' => __( 'فصل دراسي', 'tahdheer' ),
				'parts' => array( 'semester' ),
			),
		)
	);
}

/* ==================== بناء الفهرس ==================== */

/**
 * بناء فهرس العناقيد من المنتجات المنشورة.
 *
 * يعمل في cron أو عند طلب صريح، ولا يُستدعى في طلب زائر إطلاقًا.
 *
 * @return array<string, mixed> الفهرس المبنيّ.
 */
function tahdheer_hub_build_index(): array {
	$keys  = tahdheer_hub_meta_keys();
	$types = tahdheer_hub_types();
	$hubs  = array();
	$paged = 1;

	do {
		$batch = get_posts(
			array(
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'posts_per_page'         => 100,
				'paged'                  => $paged,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);

		foreach ( $batch as $product_id ) {
			$values = array();
			foreach ( $keys as $type => $meta_key ) {
				$raw = get_post_meta( (int) $product_id, $meta_key, true );
				$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
				if ( '' !== $raw ) {
					$values[ $type ] = $raw;
				}
			}

			foreach ( $types as $type_id => $type ) {
				$labels = array();
				foreach ( $type['parts'] as $part ) {
					if ( empty( $values[ $part ] ) ) {
						$labels = array();
						break;
					}
					$labels[] = $values[ $part ];
				}
				if ( ! $labels ) {
					continue;
				}

				$label = implode( ' — ', $labels );
				$slug  = sanitize_title( implode( '-', $labels ) );
				if ( '' === $slug ) {
					continue;
				}

				$id = $type_id . '/' . $slug;
				if ( ! isset( $hubs[ $id ] ) ) {
					$hubs[ $id ] = array(
						'type'     => $type_id,
						'slug'     => $slug,
						'label'    => $label,
						'parts'    => array_combine( $type['parts'], $labels ),
						'products' => array(),
					);
				}
				$hubs[ $id ]['products'][] = (int) $product_id;
			}
		}

		++$paged;
	} while ( count( $batch ) === 100 );

	// القاعدة الأولى: لا عنقود دون الحدّ الأدنى.
	$min = tahdheer_hub_min_products();
	foreach ( $hubs as $id => $hub ) {
		$hubs[ $id ]['products'] = array_values( array_unique( $hub['products'] ) );
		if ( count( $hubs[ $id ]['products'] ) < $min ) {
			unset( $hubs[ $id ] );
		}
	}

	$index = array(
		'schema'   => TAHDHEER_HUB_SCHEMA,
		'built_at' => time(),
		'min'      => $min,
		'hubs'     => $hubs,
	);

	update_option( TAHDHEER_HUB_OPTION, $index, false );

	/**
	 * إشعار بانتهاء بناء فهرس العناقيد.
	 *
	 * @param array<string, mixed> $index الفهرس.
	 */
	do_action( 'tahdheer_hub_index_built', $index );

	return $index;
}

/**
 * الفهرس المخزَّن، مع بناء أوّل عند الحاجة.
 *
 * @return array<string, mixed>
 */
function tahdheer_hub_index(): array {
	static $cache = null;
	if ( is_array( $cache ) ) {
		return $cache;
	}

	$index = get_option( TAHDHEER_HUB_OPTION, array() );

	if ( ! is_array( $index ) || (int) ( $index['schema'] ?? 0 ) !== TAHDHEER_HUB_SCHEMA ) {
		/*
		 * لا بناء في طلب زائر. الفهرس الفارغ يعني «لا صفحات محورية بعد»، وهو
		 * سلوك صحيح: أفضل من إبطاء الزائر أو خدمة صفحة نصف مبنيّة.
		 */
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$index = tahdheer_hub_build_index();
		} else {
			$index = array(
				'schema'   => TAHDHEER_HUB_SCHEMA,
				'built_at' => 0,
				'min'      => tahdheer_hub_min_products(),
				'hubs'     => array(),
			);
		}
	}

	$cache = $index;

	return $cache;
}

/**
 * كل العناقيد المؤهَّلة.
 *
 * @return array<string, array<string, mixed>>
 */
function tahdheer_hub_all(): array {
	$index = tahdheer_hub_index();

	return is_array( $index['hubs'] ?? null ) ? $index['hubs'] : array();
}

/**
 * عنقود واحد بنوعه ومعرّفه.
 *
 * @param string $type نوع العنقود.
 * @param string $slug معرّف العنقود.
 * @return array<string, mixed>|null
 */
function tahdheer_hub_get( string $type, string $slug ): ?array {
	$hubs = tahdheer_hub_all();
	$id   = sanitize_key( $type ) . '/' . sanitize_title( $slug );

	return isset( $hubs[ $id ] ) ? $hubs[ $id ] : null;
}

/**
 * رابط عنقود.
 *
 * @param array<string, mixed> $hub العنقود.
 */
function tahdheer_hub_url( array $hub ): string {
	return home_url(
		'/' . tahdheer_hub_base() . '/' . rawurlencode( (string) $hub['type'] ) . '/' . rawurlencode( (string) $hub['slug'] ) . '/'
	);
}

/**
 * روابط كل العناقيد — سطح تكامل خرائط الموقع.
 *
 * @return array<int, array{url:string, lastmod:int, count:int}>
 */
function tahdheer_hub_urls(): array {
	$out = array();
	foreach ( tahdheer_hub_all() as $hub ) {
		$modified = 0;
		foreach ( (array) $hub['products'] as $product_id ) {
			$stamp    = (int) get_post_modified_time( 'U', true, (int) $product_id );
			$modified = max( $modified, $stamp );
		}

		$out[] = array(
			'url'     => tahdheer_hub_url( $hub ),
			'lastmod' => $modified,
			'count'   => count( (array) $hub['products'] ),
		);
	}

	/**
	 * روابط الصفحات المحورية لإدراجها في خرائط الموقع.
	 *
	 * @param array<int, array<string, mixed>> $out الروابط.
	 */
	return (array) apply_filters( 'tahdheer_hub_sitemap_urls', $out );
}

/* ==================== الجدولة وإعادة البناء ==================== */

/**
 * جدولة إعادة البناء اليومية.
 */
function tahdheer_hub_schedule(): void {
	if ( ! wp_next_scheduled( TAHDHEER_HUB_CRON ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', TAHDHEER_HUB_CRON );
	}
}
add_action( 'init', 'tahdheer_hub_schedule', 6 );
add_action( TAHDHEER_HUB_CRON, 'tahdheer_hub_build_index' );

/**
 * تنظيف الجدولة عند تغيير القالب.
 */
function tahdheer_hub_unschedule(): void {
	wp_clear_scheduled_hook( TAHDHEER_HUB_CRON );
}
add_action( 'switch_theme', 'tahdheer_hub_unschedule' );

/**
 * إعادة بناء مؤجَّلة عند تغيّر منتج — مرة واحدة لكل طلب لا لكل تغيير ميتا.
 *
 * @param int $post_id معرّف المنشور.
 */
function tahdheer_hub_invalidate( $post_id ): void {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( 'product' !== get_post_type( (int) $post_id ) ) {
		return;
	}

	static $queued = false;
	if ( $queued ) {
		return;
	}
	$queued = true;

	// بعد دقيقة: تجميع عدّة تعديلات متتالية في إعادة بناء واحدة.
	if ( ! wp_next_scheduled( TAHDHEER_HUB_CRON ) || wp_next_scheduled( TAHDHEER_HUB_CRON ) > ( time() + MINUTE_IN_SECONDS ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, TAHDHEER_HUB_CRON );
	}
}
add_action( 'save_post_product', 'tahdheer_hub_invalidate', 20 );
add_action( 'deleted_post', 'tahdheer_hub_invalidate', 20 );
add_action( 'woocommerce_update_product', 'tahdheer_hub_invalidate', 20 );

/* ==================== المسارات ==================== */

/**
 * قواعد الروابط الدائمة للصفحات المحورية.
 */
function tahdheer_hub_rewrite(): void {
	$base = tahdheer_hub_base();

	add_rewrite_rule(
		'^' . $base . '/([^/]+)/([^/]+)/?$',
		'index.php?tahdheer_hub_type=$matches[1]&tahdheer_hub_slug=$matches[2]',
		'top'
	);
}
add_action( 'init', 'tahdheer_hub_rewrite', 7 );

/**
 * تسجيل متغيّرات الاستعلام.
 *
 * @param array<int, string> $vars المتغيّرات.
 * @return array<int, string>
 */
function tahdheer_hub_query_vars( array $vars ): array {
	$vars[] = 'tahdheer_hub_type';
	$vars[] = 'tahdheer_hub_slug';

	return $vars;
}
add_filter( 'query_vars', 'tahdheer_hub_query_vars' );

/**
 * سياق الصفحة المحورية الحالية، أو null.
 *
 * @return array<string, mixed>|null
 */
function tahdheer_hub_context(): ?array {
	$type = get_query_var( 'tahdheer_hub_type' );
	$slug = get_query_var( 'tahdheer_hub_slug' );

	if ( ! $type || ! $slug ) {
		return null;
	}

	return tahdheer_hub_get( (string) $type, (string) $slug );
}

/**
 * عنقود غير موجود يعيد 404 حقيقيًا — لا صفحة فارغة بحالة 200.
 */
function tahdheer_hub_access_check(): void {
	$type = get_query_var( 'tahdheer_hub_type' );
	if ( ! $type ) {
		return;
	}

	if ( null === tahdheer_hub_context() ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}
}
add_action( 'template_redirect', 'tahdheer_hub_access_check', 1 );

/**
 * تحميل قالب العنقود إن وُجد جزء قالب مخصّص.
 *
 * @param string $template القالب المحدَّد.
 */
function tahdheer_hub_template( $template ) {
	if ( null === tahdheer_hub_context() ) {
		return $template;
	}

	$found = locate_template( array( 'template-parts/curriculum-hub.php', 'curriculum-hub.php' ) );

	return $found ? $found : $template;
}
add_filter( 'template_include', 'tahdheer_hub_template', 20 );

/**
 * عنوان الصفحة المحورية — يُترك لمزوّد SEO عند ملكيته للقدرة.
 *
 * @param string $title العنوان.
 */
function tahdheer_hub_document_title( $title ) {
	$hub = tahdheer_hub_context();
	if ( ! $hub ) {
		return $title;
	}

	if ( function_exists( 'tahdheer_seo_capability_has_external' ) && tahdheer_seo_capability_has_external( 'title_tag' ) ) {
		return $title;
	}

	/* translators: %s: hub label such as "الرياضيات — الصف السادس". */
	return sprintf( __( 'تحاضير %s — التحاضير الحديثة', 'tahdheer' ), (string) $hub['label'] );
}
add_filter( 'pre_get_document_title', 'tahdheer_hub_document_title', 20 );

/**
 * فقرة الإجابة السريعة — مكتفية بذاتها بقصد.
 *
 * محرّكات الإجابة والنماذج اللغوية تقتطع أوّل إجابة كاملة تجدها، والفقرة
 * الغامضة تُترك. تُبنى من أرقام حقيقية فقط: عدد الملفات ونطاق السعر.
 *
 * @param array<string, mixed> $hub العنقود.
 */
function tahdheer_hub_answer( array $hub ): string {
	$count  = count( (array) $hub['products'] );
	$prices = array();

	foreach ( (array) $hub['products'] as $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			break;
		}
		$product = wc_get_product( (int) $product_id );
		if ( $product ) {
			$price = (float) $product->get_price();
			if ( $price > 0 ) {
				$prices[] = $price;
			}
		}
	}

	$range = '';
	if ( $prices ) {
		$min   = min( $prices );
		$max   = max( $prices );
		$range = $min === $max
			/* translators: %s: price. */
			? sprintf( __( 'بسعر %s', 'tahdheer' ), wp_strip_all_tags( wc_price( $min ) ) )
			/* translators: 1: lowest price, 2: highest price. */
			: sprintf( __( 'بأسعار من %1$s إلى %2$s', 'tahdheer' ), wp_strip_all_tags( wc_price( $min ) ), wp_strip_all_tags( wc_price( $max ) ) );
	}

	return trim(
		sprintf(
			/* translators: 1: hub label, 2: file count, 3: price range. */
			__( 'تضمّ هذه الصفحة %2$d ملفًا لـ%1$s من التحاضير الحديثة، جاهزة للتنزيل الفوري بصيغتَي PDF للطباعة و Word للتعديل %3$s.', 'tahdheer' ),
			(string) $hub['label'],
			$count,
			$range
		)
	);
}
