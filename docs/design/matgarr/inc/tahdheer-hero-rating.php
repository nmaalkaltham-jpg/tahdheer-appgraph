<?php
/**
 * Tahdheer — ربط تقييم الهيرو بالبيانات الحقيقية.
 *
 * العيب: بلوك الهيرو يعرّف rating و ratingCount كخاصيّتين نصّيتين يكتبهما المشرف
 * بحرّية (inc/hero/block.json). أي أن الواجهة تسمح بكتابة «٤٫٩ من ١٢٨ مراجعة»
 * على منتج بلا مراجعة واحدة. وهذا يخالف القاعدة الصريحة: لا تلفيق تقييمات ولا
 * مبيعات ولا ندرة ولا أرقام زوّار.
 *
 * والمفارقة أن القارئ الحقيقي موجود وجاهز: get_average_rating() و
 * get_review_count() من ووكومرس، وكلاهما يعبره tahdheer_product_data().
 *
 * السلوك بعد التركيب:
 *   1) إذا كان للبلوك productId ولمنتجه تقييمات فعلية → تُستبدل القيم اليدوية
 *      بالحقيقية دائمًا.
 *   2) إذا لم يكن للمنتج تقييمات → تُفرَّغ القيم ولا يُعرض شيء.
 *   3) إذا لم يُحدَّد منتج → تُفرَّغ القيم، ويُسجَّل تنبيه للمشرف في المحرّر.
 *   4) لا حذف للخاصيّتين من block.json — فالتوافق الخلفي محفوظ ويمكن التراجع.
 *
 * التركيب:
 *   require_once get_template_directory() . '/inc/tahdheer-hero-rating.php';
 *   (يتطلّب inc/tahdheer-product-data.php)
 *
 * @package Tahdheer
 */

defined( 'ABSPATH' ) || exit;

/**
 * هل يُسمح بإدخال تقييم يدويًا؟ افتراضيًا لا.
 *
 * يمكن لمالك المتجر السماح بذلك عبر المرشّح، لكن حينها يظهر تنبيه في المحرّر
 * يوضّح المسؤولية — القرار يبقى قرارًا معلنًا لا سلوكًا صامتًا.
 */
function tahdheer_hero_manual_rating_allowed(): bool {
	return (bool) apply_filters( 'tahdheer_hero_allow_manual_rating', false );
}

/**
 * استبدال قيم التقييم في خصائص بلوك الهيرو بالقيم الحقيقية.
 *
 * @param array<string,mixed> $attributes خصائص البلوك.
 * @return array<string,mixed>
 */
function tahdheer_hero_resolve_rating( array $attributes ): array {
	$product_id = isset( $attributes['productId'] ) ? (int) $attributes['productId'] : 0;

	if ( $product_id > 0 && function_exists( 'tahdheer_product_rating_pair' ) ) {
		$pair = tahdheer_product_rating_pair( $product_id );

		$attributes['rating']      = $pair['rating'];
		$attributes['ratingCount'] = $pair['count'];

		return $attributes;
	}

	if ( ! tahdheer_hero_manual_rating_allowed() ) {
		$attributes['rating']      = '';
		$attributes['ratingCount'] = '';
	}

	return $attributes;
}

/**
 * تطبيق الاستبدال قبل تصيير البلوك.
 *
 * pre_render_block لا يكفي وحده لأنه يعيد المحتوى؛ نستخدم render_block_data
 * الذي يعدّل الخصائص ثم يترك التصيير للقالب الأصلي كما هو.
 *
 * @param array<string,mixed> $parsed_block البلوك المُحلَّل.
 * @return array<string,mixed>
 */
function tahdheer_hero_filter_block_data( $parsed_block ) {
	if ( ! is_array( $parsed_block ) || 'tahdheer/hero' !== ( $parsed_block['blockName'] ?? '' ) ) {
		return $parsed_block;
	}

	$attrs                 = is_array( $parsed_block['attrs'] ?? null ) ? $parsed_block['attrs'] : array();
	$parsed_block['attrs'] = tahdheer_hero_resolve_rating( $attrs );

	return $parsed_block;
}
add_filter( 'render_block_data', 'tahdheer_hero_filter_block_data', 10, 1 );

/**
 * فحص التقييمات المكتوبة يدويًا في كل الهيروات المنشورة.
 *
 * يُستخدم في لوحة التدقيق: يقول أي صفحة تحمل رقمًا لا مصدر له، بدل انتظار أن
 * يكتشفه زائر أو مراجعة قانونية.
 *
 * @param int $limit أقصى عدد نتائج.
 * @return array<int,array{id:int,title:string,rating:string,count:string,product:int}>
 */
function tahdheer_hero_manual_rating_audit( int $limit = 50 ): array {
	$limit = max( 1, min( 200, $limit ) );

	$posts = get_posts(
		array(
			'post_type'      => array( 'page', 'post', 'product' ),
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => $limit,
			's'              => 'tahdheer/hero',
			'fields'         => 'ids',
		)
	);

	$found = array();

	foreach ( $posts as $post_id ) {
		$content = (string) get_post_field( 'post_content', $post_id );
		if ( ! has_blocks( $content ) ) {
			continue;
		}

		foreach ( parse_blocks( $content ) as $block ) {
			if ( 'tahdheer/hero' !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}

			$attrs  = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$rating = trim( (string) ( $attrs['rating'] ?? '' ) );
			$count  = trim( (string) ( $attrs['ratingCount'] ?? '' ) );
			$pid    = (int) ( $attrs['productId'] ?? 0 );

			if ( '' === $rating && '' === $count ) {
				continue;
			}

			// رقم مكتوب بلا منتج مرتبط = لا مصدر له إطلاقًا.
			if ( $pid > 0 && function_exists( 'tahdheer_product_rating_pair' ) ) {
				$real = tahdheer_product_rating_pair( $pid );
				if ( $real['rating'] === $rating && $real['count'] === $count ) {
					continue;
				}
			}

			$found[] = array(
				'id'      => (int) $post_id,
				'title'   => (string) get_the_title( $post_id ),
				'rating'  => $rating,
				'count'   => $count,
				'product' => $pid,
			);
		}
	}

	return $found;
}

/**
 * تنبيه في المحرّر عند وجود تقييمات بلا مصدر.
 *
 * تنبيه لا حاجب: لا يمنع النشر، لكنه لا يسمح بأن يمرّ الأمر بصمت.
 */
function tahdheer_hero_rating_admin_notice(): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'post' !== $screen->base || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$rows = tahdheer_hero_manual_rating_audit( 20 );
	if ( empty( $rows ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p><strong>%s</strong> %s</p><p>%s</p></div>',
		esc_html__( 'تقييمات مكتوبة يدويًا في الهيرو:', 'tahdheer' ),
		esc_html( number_format_i18n( count( $rows ) ) ),
		esc_html__( 'هذه الأرقام لا مصدر لها في ووكومرس ولن تُعرض في الواجهة. اربط الهيرو بمنتج ليأتي التقييم من مراجعاته الحقيقية، أو اتركه فارغًا.', 'tahdheer' )
	);
}
add_action( 'admin_notices', 'tahdheer_hero_rating_admin_notice' );
