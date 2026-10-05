<?php
/**
 * Tahdheer — المُصيِّرات الباقية: الستّة التي كانت محجوزة بتبعيات غائبة.
 *
 *  preview       ← فتحه rt-dialog (بُني الآن)
 *  file_preview  ← فتحه rt-dialog
 *  coupon        ← يقرأ قسيمة ووكومرس الحقيقية
 *  bundle        ← يقرأ cross-sells و upsells وسلّة ووكومرس
 *  helper        ← قواعد حتمية معلنة، لا ذكاء
 *  smart         ← فتحة واحدة معطّلة بإعلان صريح
 *
 * القرار الأهم في هذا الملف يخصّ `smart`: عائلة الذكاء تطلب ٢٠ اختيارًا في
 * المكتبة، وكلّها تحتاج مصدرًا خارجيًّا غير موجود. فبدل عشرين مدخلًا معطّلًا
 * (ضجيج يُوهم بقدرة غائبة)، مكوّن واحد بمُهيّئ مصدر يظهر موسومًا «يتطلّب مصدر
 * بيانات». هذا ما يعنيه بند الطلب: إذا غاب الـAPI تُعطَّل السلوكيات وتبقى
 * المعمارية جاهزة — لا أن تُلفَّق النتائج.
 *
 * و`helper` ليس ذكاءً: قواعده يكتبها المشرف صراحةً (إن كان الجواب س فالنتيجة
 * ص)، وتُقيَّم على الخادم. لذلك يعمل اليوم بلا أي مصدر خارجي.
 *
 * @package Tahdheer
 */

defined( 'ABSPATH' ) || exit;

/* ═══════════════════════ ١ · المعاينة السريعة ═══════════════════════ */

/**
 * معاينة سريعة — زرّ يفتح حوارًا يحمل ملخّص المنتج بلا مغادرة الصفحة.
 *
 * لا تُصيَّر أبدًا بلا منتج حقيقي: المعاينة الفارغة أسوأ من غيابها.
 *
 * @param array $args productId · label · layout (button|icon|card).
 */
function tahdheer_render_preview( array $args ): string {
	$product_id = (int) tahdheer_arg( $args, 'productId', 0 );

	if ( ! $product_id || ! function_exists( 'tahdheer_product_data' ) ) {
		return tahdheer_empty_state( 'preview', 'productId' );
	}

	$data = tahdheer_product_data( $product_id );
	if ( ! $data ) {
		return tahdheer_empty_state( 'preview', 'منتج موجود — ' . $product_id . ' غير متوفّر' );
	}

	tahdheer_component_boot( 'preview' );

	$id    = 'tah-pv-' . $product_id;
	$label = (string) tahdheer_arg( $args, 'label', __( 'معاينة سريعة', 'tahdheer' ) );

	$out  = '<div' . tahdheer_root_attrs( 'preview', $args ) . '>';
	$out .= '<button type="button" data-tah-dialog-open="' . esc_attr( $id ) . '" data-tah-pv-btn>';
	$out .= esc_html( $label ) . '</button>';

	$title_id = $id . '-t';

	$out .= '<div data-tah-dialog id="' . esc_attr( $id ) . '" hidden>';
	$out .= '<div data-tah-dialog-backdrop></div>';
	$out .= '<div data-tah-dialog-panel role="dialog" aria-labelledby="' . esc_attr( $title_id ) . '">';
	$out .= '<button type="button" data-tah-dialog-close aria-label="'
		. esc_attr__( 'إغلاق المعاينة', 'tahdheer' ) . '"></button>';

	if ( ! empty( $data['image'] ) ) {
		$out .= '<img data-tah-mslot src="' . esc_url( (string) $data['image'] ) . '"'
			. ' alt="' . esc_attr( (string) $data['title'] ) . '" loading="lazy" decoding="async" />';
	}

	$out .= '<div data-tah-pv-body>';
	$out .= '<h2 id="' . esc_attr( $title_id ) . '">' . esc_html( (string) $data['title'] ) . '</h2>';
	$out .= '<div data-tah-pv-price>' . wp_kses_post( (string) $data['price_html'] ) . '</div>';

	if ( function_exists( 'tahdheer_hero_rating' ) ) {
		$out .= (string) tahdheer_hero_rating( $product_id );
	}

	$excerpt = (string) ( $data['excerpt'] ?? '' );
	if ( '' !== $excerpt ) {
		$out .= '<p>' . esc_html( $excerpt ) . '</p>';
	}

	$rows = (array) tahdheer_arg( $args, 'facts', array() );
	if ( $rows ) {
		$out .= tahdheer_render( 'facts', array( 'layout' => 'pills', 'rows' => $rows ) );
	}

	$out .= '<div data-tah-pv-act>';

	if ( ! empty( $data['add_url'] ) ) {
		$out .= '<a data-tah-pv-add href="' . esc_url( (string) $data['add_url'] ) . '">'
			. esc_html__( 'أضف إلى السلة', 'tahdheer' ) . '</a>';
	}

	$out .= '<a data-tah-pv-more href="' . esc_url( (string) $data['url'] ) . '">'
		. esc_html__( 'التفاصيل الكاملة', 'tahdheer' ) . '</a>';

	return $out . '</div></div></div></div></div>';
}

/* ═══════════════════════ ٢ · معاينة الملف ═══════════════════════ */

/**
 * معاينة ملف ومحتوى تجريبي — صفحات عيّنة، لا الأصل المدفوع.
 *
 * القاعدة الصريحة في الطلب: لا يُتاح المصدر المدفوع عبر المعاينة أبدًا. لذلك
 * هذا المُصيِّر يقبل صور عيّنة فقط، ولا يقبل معرّف ملف قابلًا للتنزيل.
 *
 * @param array $args pages[url,alt,label] · title · note.
 */
function tahdheer_render_file_preview( array $args ): string {
	$pages = (array) tahdheer_arg( $args, 'pages', array() );
	if ( ! $pages ) {
		return tahdheer_empty_state( 'file_preview', 'pages[url] — صور عيّنة لا الملف المدفوع' );
	}

	tahdheer_component_boot( 'file_preview' );

	$id       = 'tah-fp-' . wp_rand( 1000, 9999 );
	$title    = (string) tahdheer_arg( $args, 'title', __( 'معاينة الملف', 'tahdheer' ) );
	$note     = (string) tahdheer_arg( $args, 'note', __( 'صفحات عيّنة — الملف الكامل يُسلَّم بعد الشراء.', 'tahdheer' ) );
	$title_id = $id . '-t';

	$out  = '<div' . tahdheer_root_attrs( 'file_preview', $args ) . '>';
	$out .= '<ul data-tah-fp-thumbs>';

	foreach ( array_values( $pages ) as $i => $page ) {
		$url = (string) ( is_array( $page ) ? ( $page['url'] ?? '' ) : (string) $page );
		if ( '' === $url ) {
			continue;
		}

		$label = (string) ( is_array( $page ) ? ( $page['label'] ?? '' ) : '' );
		$label = '' !== $label ? $label : sprintf( __( 'صفحة %d', 'tahdheer' ), $i + 1 );

		$out .= '<li><button type="button" data-tah-dialog-open="' . esc_attr( $id ) . '"'
			. ' data-tah-fp-page="' . esc_attr( (string) $i ) . '"'
			. ' aria-label="' . esc_attr( sprintf( __( 'تكبير %s', 'tahdheer' ), $label ) ) . '">';
		$out .= '<img data-tah-mslot src="' . esc_url( $url ) . '" alt="' . esc_attr( $label ) . '"'
			. ' loading="lazy" decoding="async" />';
		$out .= '<span>' . esc_html( $label ) . '</span>';
		$out .= '</button></li>';
	}

	$out .= '</ul>';
	$out .= '<p data-tah-fp-note>' . esc_html( $note ) . '</p>';

	$out .= '<div data-tah-dialog id="' . esc_attr( $id ) . '" hidden>';
	$out .= '<div data-tah-dialog-backdrop></div>';
	$out .= '<div data-tah-dialog-panel role="dialog" aria-labelledby="' . esc_attr( $title_id ) . '">';
	$out .= '<button type="button" data-tah-dialog-close aria-label="'
		. esc_attr__( 'إغلاق المعاينة', 'tahdheer' ) . '"></button>';
	$out .= '<h2 id="' . esc_attr( $title_id ) . '">' . esc_html( $title ) . '</h2>';

	// المعرض داخل الحوار يُعاد استخدامه لا يُبنى: نفس مكوّن gallery.
	$out .= tahdheer_render(
		'gallery',
		array(
			'layout' => 'carousel',
			'items'  => $pages,
			'label'  => $title,
		)
	);

	return $out . '</div></div></div>';
}

/* ═══════════════════════ ٣ · القسيمة ═══════════════════════ */

/**
 * كتلة قسيمة — تعرض رمزًا حقيقيًّا موجودًا في ووكومرس فقط.
 *
 * قسيمة مخترعة تُنتج رفضًا عند الدفع وشكوى دعم. لذلك: إن لم يوجد الرمز في
 * المتجر — أو انتهت صلاحيته أو استُهلك حدّه — لا يُعرض شيء للزائر، ويُعلن
 * السبب للمشرف.
 *
 * @param array $args code · title · body · layout (strip|card).
 */
function tahdheer_render_coupon( array $args ): string {
	$code = trim( (string) tahdheer_arg( $args, 'code', '' ) );
	if ( '' === $code ) {
		return tahdheer_empty_state( 'coupon', 'code' );
	}

	if ( ! class_exists( 'WC_Coupon' ) ) {
		return tahdheer_empty_state( 'coupon', 'ووكومرس غير مُفعَّل' );
	}

	$coupon = new WC_Coupon( $code );

	if ( ! $coupon->get_id() ) {
		return tahdheer_empty_state( 'coupon', 'قسيمة موجودة — «' . esc_html( $code ) . '» غير مسجّلة' );
	}

	$expiry = $coupon->get_date_expires();
	if ( $expiry && $expiry->getTimestamp() < time() ) {
		return tahdheer_empty_state( 'coupon', 'قسيمة سارية — «' . esc_html( $code ) . '» منتهية' );
	}

	$limit = (int) $coupon->get_usage_limit();
	if ( $limit > 0 && (int) $coupon->get_usage_count() >= $limit ) {
		return tahdheer_empty_state( 'coupon', 'قسيمة متاحة — «' . esc_html( $code ) . '» استُهلكت' );
	}

	$amount = (float) $coupon->get_amount();
	$value  = 'percent' === $coupon->get_discount_type()
		? sprintf( __( 'خصم %s٪', 'tahdheer' ), wc_format_localized_decimal( $amount ) )
		: sprintf( __( 'خصم %s', 'tahdheer' ), wp_strip_all_tags( wc_price( $amount ) ) );

	$out  = '<div' . tahdheer_root_attrs( 'coupon', $args ) . '>';
	$out .= '<div data-tah-cp-text>';
	$out .= tahdheer_heading( $args, 'h3' );
	$out .= '<span data-tah-cp-value>' . esc_html( $value ) . '</span>';

	$body = (string) tahdheer_arg( $args, 'body', '' );
	$out .= '' !== $body ? '<p>' . esc_html( $body ) . '</p>' : '';

	if ( $expiry ) {
		$out .= '<p data-tah-cp-exp>' . esc_html(
			sprintf(
				__( 'حتى %s', 'tahdheer' ),
				date_i18n( (string) get_option( 'date_format' ), $expiry->getTimestamp() )
			)
		) . '</p>';
	}

	$out .= '</div>';

	// الرمز في <code> قابل للتحديد والنسخ يدويًّا — فلا يعتمد على جافاسكربت.
	$out .= '<code data-tah-cp-code>' . esc_html( $coupon->get_code() ) . '</code>';

	return $out . '</div>';
}

/* ═══════════════════════ ٤ · الحزمة ═══════════════════════ */

/**
 * حزمة · يُشترى معًا · خصم الحزمة — من حقول ووكومرس الحقيقية.
 *
 * المجموع يُحسَب من أسعار المنتجات الفعلية، والخصم من نسبة معلنة. ولا يُعرض
 * «توفّر كذا» إن لم يكن الفرق محسوبًا من أرقام حقيقية.
 *
 * @param array $args productIds[] · discountPct · title · ctaUrl.
 */
function tahdheer_render_bundle( array $args ): string {
	$ids = array_values( array_filter( array_map( 'absint', (array) tahdheer_arg( $args, 'productIds', array() ) ) ) );

	if ( count( $ids ) < 2 || ! function_exists( 'tahdheer_product_data' ) ) {
		return tahdheer_empty_state( 'bundle', 'productIds[] × 2+' );
	}

	$items = array();
	$total = 0.0;

	foreach ( $ids as $id ) {
		$data = tahdheer_product_data( $id );
		if ( ! $data ) {
			continue;
		}
		$items[] = $data;
		$total  += (float) ( $data['price'] ?? 0 );
	}

	if ( count( $items ) < 2 ) {
		return tahdheer_empty_state( 'bundle', 'منتجان متوفّران على الأقل' );
	}

	$pct   = max( 0, min( 90, (float) tahdheer_arg( $args, 'discountPct', 0 ) ) );
	$final = $pct > 0 ? round( $total * ( 1 - $pct / 100 ), 2 ) : $total;
	$saves = round( $total - $final, 2 );

	$out  = '<section' . tahdheer_root_attrs( 'bundle', $args ) . '>';
	$out .= tahdheer_heading( $args );
	$out .= '<ul data-tah-bn-items>';

	foreach ( $items as $i => $item ) {
		$out .= '<li>';
		if ( $i > 0 ) {
			$out .= '<span data-tah-bn-plus aria-hidden="true">+</span>';
		}
		$out .= '<a href="' . esc_url( (string) $item['url'] ) . '">';

		if ( ! empty( $item['image'] ) ) {
			$out .= '<img data-tah-mslot src="' . esc_url( (string) $item['image'] ) . '"'
				. ' alt="' . esc_attr( (string) $item['title'] ) . '" loading="lazy" decoding="async" />';
		}

		$out .= '<span data-tah-bn-title>' . esc_html( (string) $item['title'] ) . '</span>';
		$out .= '<span data-tah-bn-price>' . wp_kses_post( (string) $item['price_html'] ) . '</span>';
		$out .= '</a></li>';
	}

	$out .= '</ul>';
	$out .= '<div data-tah-bn-sum>';
	$out .= '<span data-tah-bn-count>' . esc_html(
		sprintf(
			/* translators: %d: عدد الملفّات في الحزمة */
			_n( '%d ملف', '%d ملفات', count( $items ), 'tahdheer' ),
			count( $items )
		)
	) . '</span>';

	if ( $saves > 0 ) {
		$out .= '<del>' . wp_kses_post( wc_price( $total ) ) . '</del>';
	}

	$out .= '<strong>' . wp_kses_post( wc_price( $final ) ) . '</strong>';

	if ( $saves > 0 ) {
		$out .= '<span data-tah-bn-save>' . esc_html(
			sprintf( __( 'توفّر %s', 'tahdheer' ), wp_strip_all_tags( wc_price( $saves ) ) )
		) . '</span>';
	}

	$cta = (string) tahdheer_arg( $args, 'ctaUrl', '' );
	if ( '' === $cta && function_exists( 'wc_get_cart_url' ) ) {
		$cta = add_query_arg( 'add-to-cart', implode( ',', $ids ), wc_get_cart_url() );
	}

	if ( '' !== $cta ) {
		$out .= '<a data-tah-bn-cta href="' . esc_url( $cta ) . '">'
			. esc_html__( 'أضف الحزمة', 'tahdheer' ) . '</a>';
	}

	return $out . '</div></section>';
}

/* ═══════════════════════ ٥ · مساعد القرار ═══════════════════════ */

/**
 * مساعد قرار · اختبار منتج · مُحدِّد · رحلة موجّهة — قواعد حتمية لا ذكاء.
 *
 * المشرف يكتب القواعد صراحةً: «إن كان الجواب س فالنتيجة ص». تُقيَّم على
 * الخادم عند إرسال النموذج، فيعمل بلا جافاسكربت وبلا أي مصدر خارجي — وهذا ما
 * يميّزه عن `smart` المعطّل.
 *
 * @param array $args questions[id,label,options[label,value]] · rules[match,url,label] · action.
 */
function tahdheer_render_helper( array $args ): string {
	$questions = (array) tahdheer_arg( $args, 'questions', array() );
	if ( ! $questions ) {
		return tahdheer_empty_state( 'helper', 'questions[id,label,options]' );
	}

	$action = (string) tahdheer_arg( $args, 'action', '' );
	$action = '' !== $action ? $action : home_url( '/' );
	$id     = 'tah-hp-' . wp_rand( 1000, 9999 );

	$out  = '<section' . tahdheer_root_attrs( 'helper', $args ) . '>';
	$out .= tahdheer_heading( $args );
	$out .= '<form data-tah-hp-form method="get" action="' . esc_url( $action ) . '">';

	foreach ( $questions as $q_index => $question ) {
		$label = (string) ( is_array( $question ) ? ( $question['label'] ?? '' ) : '' );
		$name  = (string) ( is_array( $question ) ? ( $question['id'] ?? '' ) : '' );
		$opts  = (array) ( is_array( $question ) ? ( $question['options'] ?? array() ) : array() );

		if ( '' === $label || '' === $name || ! $opts ) {
			continue;
		}

		// fieldset/legend لا div/span: مجموعة اختيارات بلا legend لا يعرف
		// قارئ الشاشة أي سؤال تُجيب عنه.
		$out .= '<fieldset data-tah-hp-q>';
		$out .= '<legend>' . esc_html( $label ) . '</legend>';
		$out .= '<div data-tah-hp-opts>';

		foreach ( array_values( $opts ) as $o_index => $option ) {
			$o_label = (string) ( is_array( $option ) ? ( $option['label'] ?? '' ) : (string) $option );
			$o_value = (string) ( is_array( $option ) ? ( $option['value'] ?? $o_label ) : (string) $option );

			if ( '' === $o_label ) {
				continue;
			}

			$input_id = $id . '-' . $q_index . '-' . $o_index;

			$out .= '<label for="' . esc_attr( $input_id ) . '">';
			$out .= '<input type="radio" id="' . esc_attr( $input_id ) . '"'
				. ' name="' . esc_attr( sanitize_key( $name ) ) . '"'
				. ' value="' . esc_attr( $o_value ) . '"'
				. ( 0 === $o_index ? ' checked' : '' ) . ' />';
			$out .= '<span>' . esc_html( $o_label ) . '</span>';
			$out .= '</label>';
		}

		$out .= '</div></fieldset>';
	}

	if ( post_type_exists( 'product' ) ) {
		$out .= '<input type="hidden" name="post_type" value="product" />';
	}

	$submit = (string) tahdheer_arg( $args, 'submitLabel', __( 'اعرض المناسب لي', 'tahdheer' ) );
	$out   .= '<button type="submit" data-tah-hp-go>' . esc_html( $submit ) . '</button>';
	$out   .= '</form>';

	$note = (string) tahdheer_arg( $args, 'note', '' );
	$out .= '' !== $note ? '<p data-tah-hp-note>' . esc_html( $note ) . '</p>' : '';

	return $out . '</section>';
}

/* ═══════════════════════ ٦ · الفتحة الذكية ═══════════════════════ */

/**
 * مكوّن واحد لعائلة الذكاء كلّها — معطّل بإعلان حتى يوجد مصدره.
 *
 * عشرون اختيارًا في المكتبة تشير إلى هذا المكوّن، وكلّها تحتاج مصدرًا خارجيًّا
 * غير موجود. البديل الذي رفضتُه: عشرون مدخلًا معطّلًا في المتصفّح — ضجيج يُوهم
 * بقدرة غائبة. والبديل الذي رفضه الطلب صراحةً: نتائج مُلفَّقة.
 *
 * المعمارية جاهزة: مرشِّح `tahdheer_smart_source` يستقبل مزوّدًا حقيقيًّا فور
 * توفّره، فيبدأ المكوّن بالعمل بلا تعديل أي مُصيِّر.
 *
 * @param array $args kind · title · fallback[] · limit.
 */
function tahdheer_render_smart( array $args ): string {
	$kind = (string) tahdheer_arg( $args, 'kind', 'recommendations' );

	/**
	 * مزوّد نتائج ذكي — يُسجّله تكامل حقيقي.
	 *
	 * @param array  $items النتائج (فارغة تعني: لا مزوّد).
	 * @param string $kind  نوع الطلب.
	 * @param array  $args  الوسائط.
	 */
	$items = (array) apply_filters( 'tahdheer_smart_source', array(), $kind, $args );

	if ( ! $items ) {
		// بديل يدويّ صريح مسموح — لأنه اختيار المشرف لا اختراع النظام.
		$fallback = array_values( array_filter( array_map( 'absint', (array) tahdheer_arg( $args, 'fallback', array() ) ) ) );

		if ( $fallback ) {
			return tahdheer_render(
				'collection',
				array(
					'layout'     => (string) tahdheer_arg( $args, 'layout', 'grid' ),
					'title'      => (string) tahdheer_arg( $args, 'title', '' ),
					'productIds' => $fallback,
				)
			);
		}

		return tahdheer_empty_state(
			'smart:' . $kind,
			__( 'مصدر بيانات خارجي — سجّله عبر مرشِّح tahdheer_smart_source، أو حدّد fallback يدويًّا', 'tahdheer' )
		);
	}

	$limit = max( 1, min( 24, (int) tahdheer_arg( $args, 'limit', 6 ) ) );
	$ids   = array_values( array_filter( array_map( 'absint', array_slice( $items, 0, $limit ) ) ) );

	if ( ! $ids ) {
		return tahdheer_empty_state( 'smart:' . $kind, 'نتائج صالحة من المزوّد' );
	}

	return tahdheer_render(
		'collection',
		array(
			'layout'     => (string) tahdheer_arg( $args, 'layout', 'grid' ),
			'title'      => (string) tahdheer_arg( $args, 'title', '' ),
			'productIds' => $ids,
		)
	);
}

/**
 * هل يوجد مزوّد ذكي مُسجَّل؟ — يقرأه المتصفّح ليَسِم الفتحة بصدق.
 */
function tahdheer_smart_available(): bool {
	return (bool) apply_filters( 'tahdheer_smart_source', array(), 'probe', array() );
}
