<?php
/**
 * Tahdheer — قارئ بيانات المنتج الموحّد.
 *
 * يستبدل التكرار الحرفي بين tsec_product() في inc/sections/sections-loader.php
 * و tmo_product() في inc/motion/motion-loader.php. الدالّتان كانتا تفعلان الشيء
 * نفسه بفرقين فقط: مقاس الصورة (medium_large مقابل large)، ووجود stock و add_url
 * في الثانية. النتيجة أن أي إصلاح في منطق السعر أو الخصم كان يجب أن يُكتب مرّتين.
 *
 * الشكل المُرجَع هو اتحاد الشكلين معًا، فلا يكسر أي مستدعٍ قائم.
 *
 * التركيب:
 *   require_once get_template_directory() . '/inc/tahdheer-product-data.php';
 * ثم استبدل جسم الدالّتين القديمتين بسطر تفويض واحد لكل منهما:
 *   function tsec_product( $id ) { return tahdheer_product_data( $id, 'medium_large' ); }
 *   function tmo_product( $id )  { return tahdheer_product_data( $id, 'large' ); }
 *
 * @package Tahdheer
 */

defined( 'ABSPATH' ) || exit;

/** مقاسات الصور المسموحة — قائمة مغلقة تمنع تمرير مقاس غير مسجّل. */
function tahdheer_product_image_sizes(): array {
	return array( 'thumbnail', 'medium', 'medium_large', 'large', 'full' );
}

/**
 * بيانات عرض آمنة لمنتج واحد.
 *
 * لا تُرجع أبدًا بيانات منتج غير منشور، ولا تخترع قيمة غائبة: التقييم صفر يعني
 * لا تقييمات، ولا يُعرض على أنه تقييم.
 *
 * @param int    $id   معرّف المنتج.
 * @param string $size مقاس الصورة المطلوب.
 * @return array<string,mixed>|null null إذا لم يكن منتجًا منشورًا.
 */
function tahdheer_product_data( $id, string $size = 'medium_large' ) {
	$id = (int) $id;
	if ( $id <= 0 || ! function_exists( 'wc_get_product' ) ) {
		return null;
	}

	$product = wc_get_product( $id );
	if ( ! $product instanceof WC_Product || 'publish' !== get_post_status( $id ) ) {
		return null;
	}

	if ( ! in_array( $size, tahdheer_product_image_sizes(), true ) ) {
		$size = 'medium_large';
	}

	$thumb   = (int) get_post_thumbnail_id( $id );
	$regular = (float) $product->get_regular_price();
	$current = (float) $product->get_price();
	$title   = (string) $product->get_name();

	$alt = '';
	if ( $thumb > 0 ) {
		$alt = (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true );
		if ( '' === trim( $alt ) ) {
			$alt = $title;
		}
	}

	$data = array(
		'id'           => $id,
		'title'        => $title,
		'url'          => (string) get_permalink( $id ),
		'price'        => (string) $product->get_price_html(),
		'regular'      => $regular,
		'current'      => $current,
		// اسم قديم كان يستخدمه tmo_product — يبقى للتوافق الخلفي.
		'raw'          => $current,
		'off'          => ( $regular > 0 && $current < $regular ) ? (int) round( ( 1 - $current / $regular ) * 100 ) : 0,
		'rating'       => (float) $product->get_average_rating(),
		'count'        => (int) $product->get_review_count(),
		'img'          => $thumb > 0 ? (string) wp_get_attachment_image_url( $thumb, $size ) : '',
		'alt'          => $alt,
		'stock'        => (bool) $product->is_in_stock(),
		'downloadable' => (bool) $product->is_downloadable(),
		'virtual'      => (bool) $product->is_virtual(),
		'add_url'      => function_exists( 'wc_get_cart_url' )
			? esc_url_raw( add_query_arg( 'add-to-cart', $id, wc_get_cart_url() ) )
			: '',
	);

	/**
	 * نقطة توسيع واحدة لكل من يحتاج حقلًا إضافيًا — بدل إضافة قارئ ثالث.
	 *
	 * @param array<string,mixed> $data    البيانات.
	 * @param WC_Product          $product المنتج.
	 * @param string              $size    مقاس الصورة.
	 */
	return apply_filters( 'tahdheer_product_data', $data, $product, $size );
}

/**
 * زوج التقييم الحقيقي لعنصر واجهة: القيمة وعدد المراجعات، أو فراغ عند عدم وجود
 * تقييمات فعلية. هذه هي الدالّة التي تمنع كتابة تقييم يدويًا في أي بلوك.
 *
 * @param int $id معرّف المنتج.
 * @return array{rating:string,count:string} نصوص جاهزة للعرض ('' عند الغياب).
 */
function tahdheer_product_rating_pair( $id ): array {
	$data = tahdheer_product_data( $id );
	if ( null === $data || $data['count'] < 1 || $data['rating'] <= 0 ) {
		return array(
			'rating' => '',
			'count'  => '',
		);
	}

	return array(
		'rating' => number_format_i18n( $data['rating'], 1 ),
		'count'  => number_format_i18n( $data['count'] ),
	);
}

/**
 * قائمة منتجات بمصدر مسمّى — الاستعلام الوحيد الذي تحتاجه عائلات المنتجات
 * والتصنيفات والمجموعات في سجل المكوّنات. لا SQL خام ولا معاملات حرّة.
 *
 * @param string $source best|new|sale|featured|related|manual.
 * @param int    $limit  الحدّ (1..24).
 * @param array  $args   include (للمصدر اليدوي) · exclude · category · size.
 * @return array<int,array<string,mixed>> بيانات عرض جاهزة.
 */
function tahdheer_product_query( string $source = 'best', int $limit = 6, array $args = array() ): array {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return array();
	}

	$limit = max( 1, min( 24, $limit ) );
	$size  = isset( $args['size'] ) ? (string) $args['size'] : 'medium_large';

	$query = array(
		'status'  => 'publish',
		'limit'   => $limit,
		'return'  => 'ids',
		'exclude' => array_map( 'absint', (array) ( $args['exclude'] ?? array() ) ),
	);

	if ( ! empty( $args['category'] ) ) {
		$query['category'] = array_map( 'sanitize_title', (array) $args['category'] );
	}

	switch ( $source ) {
		case 'manual':
			$include = array_map( 'absint', (array) ( $args['include'] ?? array() ) );
			if ( empty( $include ) ) {
				return array();
			}
			$query['include'] = $include;
			$query['orderby'] = 'include';
			$query['limit']   = count( $include );
			break;
		case 'new':
			$query['orderby'] = 'date';
			$query['order']   = 'DESC';
			break;
		case 'sale':
			$query['on_sale'] = true;
			break;
		case 'featured':
			$query['featured'] = true;
			break;
		case 'best':
		default:
			$query['orderby']  = 'meta_value_num';
			$query['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query['order']    = 'DESC';
			break;
	}

	$out = array();
	foreach ( (array) wc_get_products( $query ) as $product_id ) {
		$data = tahdheer_product_data( (int) $product_id, $size );
		if ( null !== $data ) {
			$out[] = $data;
		}
	}

	return $out;
}

/*
 * جسور توافق: تُعرَّف فقط إذا لم تكن الدالّة القديمة موجودة، حتى يمكن حذف
 * النسختين القديمتين دون كسر أي مستدعٍ في القوالب.
 */
if ( ! function_exists( 'tsec_product' ) ) {
	/**
	 * @param int $id معرّف المنتج.
	 * @return array<string,mixed>|null
	 */
	function tsec_product( $id ) {
		return tahdheer_product_data( $id, 'medium_large' );
	}
}

if ( ! function_exists( 'tmo_product' ) ) {
	/**
	 * @param int $id معرّف المنتج.
	 * @return array<string,mixed>|null
	 */
	function tmo_product( $id ) {
		return tahdheer_product_data( $id, 'large' );
	}
}
