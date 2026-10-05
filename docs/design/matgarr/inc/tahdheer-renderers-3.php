<?php
/**
 * Tahdheer — المُصيِّرات الخمسة والعشرون الباقية.
 *
 * `collection` هو الأثقل وزنًا في المكتبة كلّها: عشرات الاختيارات في عائلات
 * المنتجات والتصنيفات والمجموعات والترقية والبيع المتقاطع تشير إليه، وكلّها
 * نفس الاستعلام ونفس البطاقة ونفس حالة الفراغ — يتغيّر التخطيط والمصدر فقط.
 * بناؤها ملفّات منفصلة كان سينسخ منطق السعر والخصم عشرات المرّات.
 *
 * ثلاث قواعد تحكم هذا الملف:
 *  ١) التخطيط خاصية لا ملفّ — وهذا أصل ٣٠٠ من ٤٥.
 *  ٢) لا رقم يُعرض إلا محسوبًا من مصدر حقيقي: التقييمات من ووكومرس، والعدّاد
 *     من تواريخ التخفيض، والإحصاءات من إدخال المشرف صراحةً.
 *  ٣) الدلالة أولًا: nav+ol للمسار، ul للقوائم، form+label للنماذج،
 *     blockquote للمراجعات، dl للأزواج.
 *
 * @package Tahdheer
 */

defined( 'ABSPATH' ) || exit;

/* ═══════════════ ١ · المجموعة — العمود الفقري ═══════════════ */

/**
 * شبكة · كاروسيل · سلايدر · قائمة · مُبرَز · بينتو · أفقي.
 *
 * مصدر واحد (معرّفات منتجات) وبطاقة واحدة، والتخطيط خاصية. التخطيطات
 * المتمرِّرة وحدها تطلب rt-gallery؛ الشبكية صفر جافاسكربت.
 *
 * @param array $args productIds[] · layout · columns · title · badge · showPrice · showRating.
 */
function tahdheer_render_collection( array $args ): string {
	$ids = array_values( array_filter( array_map( 'absint', (array) tahdheer_arg( $args, 'productIds', array() ) ) ) );

	if ( ! $ids || ! function_exists( 'tahdheer_product_data' ) ) {
		return tahdheer_empty_state( 'collection', 'productIds[]' );
	}

	$layout  = (string) tahdheer_arg( $args, 'layout', 'grid' );
	$scrolls = in_array( $layout, array( 'carousel', 'slider', 'filmstrip' ), true );
	$columns = max( 1, min( 6, (int) tahdheer_arg( $args, 'columns', 4 ) ) );
	$limit   = max( 1, min( 24, (int) tahdheer_arg( $args, 'limit', 12 ) ) );
	$ids     = array_slice( $ids, 0, $limit );

	$items = array();
	foreach ( $ids as $id ) {
		$data = tahdheer_product_data( $id );
		if ( $data ) {
			$items[] = $data;
		}
	}

	if ( ! $items ) {
		return tahdheer_empty_state( 'collection', 'منتج متوفّر واحد على الأقل' );
	}

	if ( $scrolls ) {
		tahdheer_component_boot( 'collection' );
	}

	$extra = array( 'style' => '--tah-cols:' . $columns );
	if ( $scrolls ) {
		$extra['data-tah-gallery'] = '1';
		$extra['data-loop']        = ! empty( $args['loop'] ) ? '1' : '0';
		$extra['data-label']       = (string) tahdheer_arg( $args, 'title', __( 'مجموعة منتجات', 'tahdheer' ) );
	}

	$badge  = (string) tahdheer_arg( $args, 'badge', '' );
	$rating = ! empty( $args['showRating'] ) && function_exists( 'tahdheer_hero_rating' );

	$out  = '<section' . tahdheer_root_attrs( 'collection', $args, $extra ) . '>';
	$out .= tahdheer_heading( $args );
	$out .= $scrolls ? '<div data-tah-track>' : '<ul data-tah-list>';

	foreach ( $items as $item ) {
		$out .= $scrolls ? '<div data-tah-slide>' : '<li>';
		$out .= '<article data-tah-item>';
		$out .= '<a href="' . esc_url( (string) ( $item['url'] ?? '' ) ) . '">';

		if ( ! empty( $item['image'] ) ) {
			$out .= '<img data-tah-mslot src="' . esc_url( (string) $item['image'] ) . '"'
				. ' alt="' . esc_attr( (string) ( $item['title'] ?? '' ) ) . '"'
				. ' loading="lazy" decoding="async" />';
		}

		// الشارة إمّا نصّ المشرف أو خصم محسوب — لا ندرة مخترعة.
		if ( '' !== $badge ) {
			$out .= '<span data-tah-badge>' . esc_html( $badge ) . '</span>';
		} elseif ( ! empty( $item['on_sale'] ) && ! empty( $item['discount_pct'] ) ) {
			$out .= '<span data-tah-badge>' . esc_html(
				sprintf( __( '−%s٪', 'tahdheer' ), (string) $item['discount_pct'] )
			) . '</span>';
		}

		$out .= '<span data-tah-p-title>' . esc_html( (string) ( $item['title'] ?? '' ) ) . '</span>';

		if ( empty( $args['showPrice'] ) || $args['showPrice'] ) {
			$out .= '<span data-tah-p-price>' . wp_kses_post( (string) ( $item['price_html'] ?? '' ) ) . '</span>';
		}

		$out .= '</a>';

		if ( $rating ) {
			$out .= (string) tahdheer_hero_rating( (int) ( $item['id'] ?? 0 ) );
		}

		if ( ! empty( $args['showCart'] ) && ! empty( $item['add_url'] ) ) {
			$out .= '<a data-tah-item-cart href="' . esc_url( (string) $item['add_url'] ) . '">'
				. esc_html__( 'أضف', 'tahdheer' ) . '</a>';
		}

		$out .= '</article>';
		$out .= $scrolls ? '</div>' : '</li>';
	}

	$out .= $scrolls ? '</div>' : '</ul>';

	if ( $scrolls ) {
		$out .= '<div data-tah-ctrl>';
		$out .= '<button type="button" data-tah-prev aria-label="' . esc_attr__( 'السابق', 'tahdheer' ) . '"></button>';
		$out .= '<button type="button" data-tah-next aria-label="' . esc_attr__( 'التالي', 'tahdheer' ) . '"></button>';
		$out .= '<div data-tah-dots></div></div>';
	}

	$cta = tahdheer_hero_cta( $args, 'cta' );
	$out .= '' !== $cta ? '<div data-tah-list-foot>' . $cta . '</div>' : '';

	return $out . '</section>';
}

/* ═══════════════ ٢ · البطاقة العامة ═══════════════ */

/**
 * بطاقات تفاعلية · مقلوبة · بالتمرير.
 *
 * القلب بـ:focus-within لا بجافاسكربت، فيعمل بلوحة المفاتيح مجانًا. ومع تقليل
 * الحركة يُعرض الوجهان معًا بلا دوران — لأن محتوى الظهر لا يجوز أن يُحجب.
 *
 * @param array $args cards[label,body,back,icon,url] · layout (flat|flip|hover) · columns.
 */
function tahdheer_render_card( array $args ): string {
	$cards = (array) tahdheer_arg( $args, 'cards', array() );
	if ( ! $cards ) {
		return tahdheer_empty_state( 'card', 'cards[label]' );
	}

	$columns = max( 1, min( 4, (int) tahdheer_arg( $args, 'columns', 3 ) ) );

	$out  = '<section' . tahdheer_root_attrs( 'card', $args, array( 'style' => '--tah-cols:' . $columns ) ) . '>';
	$out .= tahdheer_heading( $args );
	$out .= '<ul data-tah-cards>';

	foreach ( $cards as $card ) {
		$label = (string) ( is_array( $card ) ? ( $card['label'] ?? '' ) : (string) $card );
		if ( '' === $label ) {
			continue;
		}

		$body = (string) ( is_array( $card ) ? ( $card['body'] ?? '' ) : '' );
		$back = (string) ( is_array( $card ) ? ( $card['back'] ?? '' ) : '' );
		$url  = (string) ( is_array( $card ) ? ( $card['url'] ?? '' ) : '' );

		$out .= '<li>';
		$out .= '' !== $url ? '<a href="' . esc_url( $url ) . '">' : '<div tabindex="0">';
		$out .= '<span data-tah-card-face>';
		$out .= '<strong>' . esc_html( $label ) . '</strong>';
		$out .= '' !== $body ? '<span>' . esc_html( $body ) . '</span>' : '';
		$out .= '</span>';

		if ( '' !== $back ) {
			$out .= '<span data-tah-card-back>' . esc_html( $back ) . '</span>';
		}

		$out .= '' !== $url ? '</a>' : '</div>';
		$out .= '</li>';
	}

	return $out . '</ul></section>';
}

/* ═══════════════ ٣ · شرائح المصطلحات ═══════════════ */

/**
 * اقتراحات · رائج · شائع · كلمات · تصفّح بالحاجة · نيّة البحث.
 *
 * روابط حقيقية لأرشيفات موجودة — لا كلمات مفتاحية معروضة للعنكبوت.
 *
 * @param array $args chips[label,url,count] · layout (pills|list|cloud) · title.
 */
function tahdheer_render_term_chips( array $args ): string {
	$chips = (array) tahdheer_arg( $args, 'chips', array() );
	if ( ! $chips ) {
		return tahdheer_empty_state( 'term_chips', 'chips[label,url]' );
	}

	$out  = '<nav' . tahdheer_root_attrs( 'term_chips', $args, array(
		'aria-label' => (string) tahdheer_arg( $args, 'title', __( 'تصفّح سريع', 'tahdheer' ) ),
	) ) . '>';
	$out .= tahdheer_heading( $args, 'h3' );
	$out .= '<ul data-tah-chips>';

	foreach ( $chips as $chip ) {
		$label = (string) ( is_array( $chip ) ? ( $chip['label'] ?? '' ) : (string) $chip );
		$url   = (string) ( is_array( $chip ) ? ( $chip['url'] ?? '' ) : '' );
		if ( '' === $label || '' === $url ) {
			continue;
		}

		$count = (string) ( is_array( $chip ) ? ( $chip['count'] ?? '' ) : '' );
		$out  .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label );
		$out  .= '' !== $count ? ' <span>' . esc_html( $count ) . '</span>' : '';
		$out  .= '</a></li>';
	}

	return $out . '</ul></nav>';
}

/* ═══════════════ ٤ · الكشف والأسئلة ═══════════════ */

/**
 * تبويبات المنتج والتصنيف والمجموعة والمحتوى · أكورديون · بطاقات موسّعة.
 *
 * @param array $args panels[key,label,body] · layout (tabs|accordion) · multi.
 */
function tahdheer_render_disclosure( array $args ): string {
	$panels = (array) tahdheer_arg( $args, 'panels', array() );
	if ( ! $panels ) {
		return tahdheer_empty_state( 'disclosure', 'panels[key,label,body]' );
	}

	tahdheer_component_boot( 'disclosure' );

	$accordion = 'accordion' === (string) tahdheer_arg( $args, 'layout', 'tabs' );
	$clean     = array();

	foreach ( $panels as $panel ) {
		$key   = (string) ( is_array( $panel ) ? ( $panel['key'] ?? '' ) : '' );
		$label = (string) ( is_array( $panel ) ? ( $panel['label'] ?? '' ) : '' );
		$body  = (string) ( is_array( $panel ) ? ( $panel['body'] ?? '' ) : '' );
		if ( '' === $key || '' === $label ) {
			continue;
		}
		$clean[] = array( 'key' => sanitize_key( $key ), 'label' => $label, 'body' => $body );
	}

	if ( ! $clean ) {
		return tahdheer_empty_state( 'disclosure', 'لوح واحد صالح على الأقل' );
	}

	$extra = $accordion
		? array( 'data-tah-accordion' => '1', 'data-multi' => ! empty( $args['multi'] ) ? '1' : '0', 'data-open-first' => '1' )
		: array( 'data-tah-tabs' => '1', 'data-label' => (string) tahdheer_arg( $args, 'title', __( 'تبويبات', 'tahdheer' ) ) );

	$out  = '<section' . tahdheer_root_attrs( 'disclosure', $args, $extra ) . '>';
	$out .= tahdheer_heading( $args );

	if ( ! $accordion ) {
		$out .= '<div data-tah-tablist>';
		foreach ( $clean as $panel ) {
			$out .= '<button type="button" data-tah-tab="' . esc_attr( $panel['key'] ) . '">'
				. esc_html( $panel['label'] ) . '</button>';
		}
		$out .= '</div>';

		foreach ( $clean as $panel ) {
			$out .= '<div data-tah-panel="' . esc_attr( $panel['key'] ) . '">'
				. wp_kses_post( wpautop( $panel['body'] ) ) . '</div>';
		}

		return $out . '</section>';
	}

	foreach ( $clean as $panel ) {
		$out .= '<div data-tah-acc-item>';
		$out .= '<h3><button type="button" data-tah-summary="' . esc_attr( $panel['key'] ) . '">'
			. esc_html( $panel['label'] ) . '</button></h3>';
		$out .= '<div data-tah-details="' . esc_attr( $panel['key'] ) . '">'
			. wp_kses_post( wpautop( $panel['body'] ) ) . '</div>';
		$out .= '</div>';
	}

	return $out . '</section>';
}

/**
 * أسئلة شائعة · سؤال وجواب · أجوبة.
 *
 * المحتوى في الشجرة دائمًا — فترميز FAQPage صادق: ما تراه العنكبوت هو ما
 * يراه الزائر.
 *
 * @param array $args items[q,a] · title · schema.
 */
function tahdheer_render_faq( array $args ): string {
	$items = (array) tahdheer_arg( $args, 'items', array() );
	$clean = array();

	foreach ( $items as $item ) {
		$q = (string) ( is_array( $item ) ? ( $item['q'] ?? '' ) : '' );
		$a = (string) ( is_array( $item ) ? ( $item['a'] ?? '' ) : '' );
		if ( '' !== $q && '' !== $a ) {
			$clean[] = array( 'q' => $q, 'a' => $a );
		}
	}

	if ( ! $clean ) {
		return tahdheer_empty_state( 'faq', 'items[q,a]' );
	}

	$panels = array();
	foreach ( $clean as $i => $item ) {
		$panels[] = array( 'key' => 'q' . ( $i + 1 ), 'label' => $item['q'], 'body' => $item['a'] );
	}

	$out = tahdheer_render(
		'disclosure',
		array(
			'layout' => 'accordion',
			'title'  => (string) tahdheer_arg( $args, 'title', '' ),
			'level'  => (string) tahdheer_arg( $args, 'level', 'h2' ),
			'panels' => $panels,
			'class'  => 'tah-faq',
		)
	);

	// الترميز يُبنى من نفس النصّ المرئي — لا من حقل ثانٍ ينحرف عنه.
	if ( ! empty( $args['schema'] ) ) {
		$graph = array();
		foreach ( $clean as $item ) {
			$graph[] = array(
				'@type'          => 'Question',
				'name'           => wp_strip_all_tags( $item['q'] ),
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => wp_strip_all_tags( $item['a'] ),
				),
			);
		}

		$json = wp_json_encode(
			array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $graph ),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$out .= '<script type="application/ld+json">' . $json . '</script>';
	}

	return $out;
}

/* ═══════════════ ٥ · النداء والفوائد والثقة ═══════════════ */

/**
 * كتلة نداء · ملتصق · اشترِ الآن · شريط تحويل · عرض القيمة.
 *
 * @param array $args title · body · ctaText · ctaUrl · cta2* · note · layout.
 */
function tahdheer_render_cta( array $args ): string {
	$primary = tahdheer_hero_cta( $args, 'cta' );
	$title   = (string) tahdheer_arg( $args, 'title', '' );

	if ( '' === $primary && '' === $title ) {
		return tahdheer_empty_state( 'cta', 'title أو ctaText+ctaUrl' );
	}

	$out  = '<section' . tahdheer_root_attrs( 'cta', $args ) . '>';
	$out .= '<div data-tah-cta-text>';
	$out .= tahdheer_heading( $args );
	$out .= '</div>';
	$out .= '<div data-tah-cta-act>' . $primary . tahdheer_hero_cta( $args, 'cta2' ) . '</div>';

	$note = (string) tahdheer_arg( $args, 'note', '' );
	$out .= '' !== $note ? '<p data-tah-cta-note>' . esc_html( $note ) . '</p>' : '';

	return $out . '</section>';
}

/**
 * فوائد · أسباب الشراء · مزايا المتجر · قائمة تحقّق · لماذا نحن.
 *
 * @param array $args items[label,body,icon] · layout (list|grid|check) · title.
 */
function tahdheer_render_benefits( array $args ): string {
	$items = (array) tahdheer_arg( $args, 'items', array() );
	if ( ! $items ) {
		return tahdheer_empty_state( 'benefits', 'items[label]' );
	}

	$out  = '<section' . tahdheer_root_attrs( 'benefits', $args ) . '>';
	$out .= tahdheer_heading( $args );
	$out .= '<ul data-tah-benefits>';

	foreach ( $items as $item ) {
		$label = (string) ( is_array( $item ) ? ( $item['label'] ?? '' ) : (string) $item );
		if ( '' === $label ) {
			continue;
		}

		$body = (string) ( is_array( $item ) ? ( $item['body'] ?? '' ) : '' );
		$icon = (string) ( is_array( $item ) ? ( $item['icon'] ?? '' ) : '' );

		$out .= '<li>';
		$out .= '' !== $icon && function_exists( 'tahdheer_icon' ) ? tahdheer_icon( $icon, 18 ) : '';
		$out .= '<div><strong>' . esc_html( $label ) . '</strong>';
		$out .= '' !== $body ? '<span>' . esc_html( $body ) . '</span>' : '';
		$out .= '</div></li>';
	}

	return $out . '</ul></section>';
}

/**
 * شارات ثقة · دفع آمن · دعم · ضمان تواصل.
 *
 * @param array $args items[label,icon] · layout (row|grid) · title.
 */
function tahdheer_render_trust( array $args ): string {
	$items = (array) tahdheer_arg( $args, 'items', array() );
	if ( ! $items ) {
		return tahdheer_empty_state( 'trust', 'items[label]' );
	}

	$out  = '<section' . tahdheer_root_attrs( 'trust', $args ) . '>';
	$out .= tahdheer_heading( $args, 'h3' );
	$out .= '<ul data-tah-trust>';

	foreach ( $items as $item ) {
		$label = (string) ( is_array( $item ) ? ( $item['label'] ?? '' ) : (string) $item );
		if ( '' === $label ) {
			continue;
		}

		$icon = (string) ( is_array( $item ) ? ( $item['icon'] ?? '' ) : '' );
		$note = (string) ( is_array( $item ) ? ( $item['note'] ?? '' ) : '' );

		$out .= '<li>';
		$out .= '' !== $icon && function_exists( 'tahdheer_icon' ) ? tahdheer_icon( $icon, 20 ) : '';
		$out .= '<div><strong>' . esc_html( $label ) . '</strong>';
		$out .= '' !== $note ? '<span>' . esc_html( $note ) . '</span>' : '';
		$out .= '</div></li>';
	}

	return $out . '</ul></section>';
}

/**
 * سياسة · ضمان · استرجاع · وعد.
 *
 * @param array $args title · body · points[] · link[label,url].
 */
function tahdheer_render_policy( array $args ): string {
	$body   = (string) tahdheer_arg( $args, 'body', '' );
	$points = (array) tahdheer_arg( $args, 'points', array() );

	if ( '' === $body && ! $points ) {
		return tahdheer_empty_state( 'policy', 'body أو points[]' );
	}

	$out  = '<section' . tahdheer_root_attrs( 'policy', $args ) . '>';
	$out .= '<div data-tah-policy>';
	$out .= tahdheer_heading( $args, 'h3' );
	$out .= '' !== $body ? '<p>' . esc_html( $body ) . '</p>' : '';

	if ( $points ) {
		$out .= '<ul>';
		foreach ( $points as $point ) {
			$text = (string) ( is_array( $point ) ? ( $point['label'] ?? '' ) : (string) $point );
			if ( '' !== $text ) {
				$out .= '<li>' . esc_html( $text ) . '</li>';
			}
		}
		$out .= '</ul>';
	}

	$link = (array) tahdheer_arg( $args, 'link', array() );
	if ( ! empty( $link['url'] ) && ! empty( $link['label'] ) ) {
		$out .= '<a href="' . esc_url( (string) $link['url'] ) . '">'
			. esc_html( (string) $link['label'] ) . '</a>';
	}

	return $out . '</div></section>';
}

/* ═══════════════ ٦ · المراجعات ═══════════════ */

/**
 * ملخّص · حائط · شهادات · مشترٍ موثَّق · دليل اجتماعي.
 *
 * من مراجعات ووكومرس الحقيقية فقط. بلا مراجعات لا يُعرض شيء — ولا نجوم فارغة
 * ولا «كن أوّل من يراجع» في موضع دليل اجتماعي.
 *
 * @param array $args productId · limit · layout (summary|wall|carousel) · title.
 */
function tahdheer_render_reviews( array $args ): string {
	$product_id = (int) tahdheer_arg( $args, 'productId', 0 );

	if ( ! $product_id ) {
		return tahdheer_empty_state( 'reviews', 'productId' );
	}

	$limit    = max( 1, min( 12, (int) tahdheer_arg( $args, 'limit', 4 ) ) );
	$comments = get_comments(
		array(
			'post_id' => $product_id,
			'status'  => 'approve',
			'type'    => 'review',
			'number'  => $limit,
			'meta_key' => 'rating',
		)
	);

	if ( ! $comments ) {
		return tahdheer_empty_state( 'reviews', 'مراجعة معتمدة واحدة على الأقل' );
	}

	$out  = '<section' . tahdheer_root_attrs( 'reviews', $args ) . '>';
	$out .= tahdheer_heading( $args );

	if ( function_exists( 'tahdheer_hero_rating' ) ) {
		$out .= (string) tahdheer_hero_rating( $product_id );
	}

	$out .= '<ul data-tah-reviews>';

	foreach ( $comments as $comment ) {
		$stars = (int) get_comment_meta( $comment->comment_ID, 'rating', true );
		$text  = wp_strip_all_tags( (string) $comment->comment_content );

		if ( '' === $text ) {
			continue;
		}

		$out .= '<li data-tah-review>';

		if ( $stars > 0 ) {
			$out .= '<span data-tah-stars>';
			$out .= '<span aria-hidden="true">' . esc_html( str_repeat( '★', $stars ) . str_repeat( '☆', 5 - $stars ) ) . '</span>';
			$out .= '<span data-tah-sr>' . esc_html(
				sprintf( __( '%1$d من ٥', 'tahdheer' ), $stars )
			) . '</span>';
			$out .= '</span>';
		}

		$out .= '<blockquote><p>' . esc_html( $text ) . '</p></blockquote>';
		$out .= '<cite>' . esc_html( (string) $comment->comment_author ) . '</cite>';
		$out .= '</li>';
	}

	return $out . '</ul></section>';
}

/* ═══════════════ ٧ · المعلومات والأرقام ═══════════════ */

/**
 * صندوق معلومة · ملاحظة · تحذير · نصيحة · نجاح · إبراز.
 *
 * @param array $args body · title · tone (info|warn|tip|ok) · icon.
 */
function tahdheer_render_notice( array $args ): string {
	$body = (string) tahdheer_arg( $args, 'body', '' );
	if ( '' === $body ) {
		return tahdheer_empty_state( 'notice', 'body' );
	}

	$tone = (string) tahdheer_arg( $args, 'tone', 'info' );
	$tone = in_array( $tone, array( 'info', 'warn', 'tip', 'ok' ), true ) ? $tone : 'info';

	// التحذير يحمل role=alert؛ البقية لا — وإلا صار كل صندوق مقاطعةً لقارئ الشاشة.
	$extra = array( 'data-tone' => $tone );
	if ( 'warn' === $tone ) {
		$extra['role'] = 'alert';
	}

	$out  = '<aside' . tahdheer_root_attrs( 'notice', $args, $extra ) . '>';
	$out .= '<div data-tah-notice>';
	$out .= tahdheer_heading( $args, 'h3' );
	$out .= '<p>' . esc_html( $body ) . '</p>';

	return $out . '</div></aside>';
}

/**
 * إحصائيات · مقاييس · مؤشّرات خبرة · عدّادات.
 *
 * كل رقم يكتبه المشرف صراحةً. لا رقم يُشتقّ من عدّ عشوائي ولا يُقرَّب لأعلى.
 *
 * @param array $args stats[value,label,note] · layout (row|grid) · title.
 */
function tahdheer_render_stats( array $args ): string {
	$stats = (array) tahdheer_arg( $args, 'stats', array() );
	$clean = array();

	foreach ( $stats as $stat ) {
		$value = (string) ( is_array( $stat ) ? ( $stat['value'] ?? '' ) : '' );
		$label = (string) ( is_array( $stat ) ? ( $stat['label'] ?? '' ) : '' );
		if ( '' !== $value && '' !== $label ) {
			$clean[] = array( 'value' => $value, 'label' => $label, 'note' => (string) ( $stat['note'] ?? '' ) );
		}
	}

	if ( ! $clean ) {
		return tahdheer_empty_state( 'stats', 'stats[value,label]' );
	}

	$out  = '<section' . tahdheer_root_attrs( 'stats', $args ) . '>';
	$out .= tahdheer_heading( $args );
	$out .= '<dl data-tah-stats>';

	foreach ( $clean as $stat ) {
		// dt قبل dd — كما تفرض المواصفة داخل dl > div. الترتيب البصري (الرقم
		// فوق التسمية) يتحقّق بـorder في CSS لا بقلب الترميز.
		$out .= '<div>';
		$out .= '<dt data-tah-stat-k>' . esc_html( $stat['label'] );
		$out .= '' !== $stat['note'] ? ' <small>' . esc_html( $stat['note'] ) . '</small>' : '';
		$out .= '</dt>';
		$out .= '<dd data-tah-stat-n>' . esc_html( $stat['value'] ) . '</dd>';
		$out .= '</div>';
	}

	return $out . '</dl></section>';
}

/* ═══════════════ ٨ · التنقّل والروابط ═══════════════ */

/**
 * مسار تنقّل — بترميز BreadcrumbList صادق مبنيّ من نفس الروابط المرئية.
 *
 * @param array $args items[label,url] · schema.
 */
function tahdheer_render_crumbs( array $args ): string {
	$items = (array) tahdheer_arg( $args, 'items', array() );
	$clean = array();

	foreach ( $items as $item ) {
		$label = (string) ( is_array( $item ) ? ( $item['label'] ?? '' ) : (string) $item );
		if ( '' !== $label ) {
			$clean[] = array( 'label' => $label, 'url' => (string) ( $item['url'] ?? '' ) );
		}
	}

	if ( ! $clean ) {
		return tahdheer_empty_state( 'crumbs', 'items[label]' );
	}

	$out  = '<nav' . tahdheer_root_attrs( 'crumbs', $args, array(
		'aria-label' => __( 'مسار التنقّل', 'tahdheer' ),
	) ) . '>';
	$out .= '<ol data-tah-crumbs>';

	$last = count( $clean ) - 1;

	foreach ( $clean as $i => $item ) {
		$out .= '<li>';
		if ( '' !== $item['url'] && $i !== $last ) {
			$out .= '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['label'] ) . '</a>';
		} else {
			$out .= '<span aria-current="page">' . esc_html( $item['label'] ) . '</span>';
		}
		$out .= '</li>';
	}

	$out .= '</ol>';

	if ( ! empty( $args['schema'] ) ) {
		$list = array();
		foreach ( $clean as $i => $item ) {
			$entry = array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => wp_strip_all_tags( $item['label'] ) );
			if ( '' !== $item['url'] ) {
				$entry['item'] = esc_url_raw( $item['url'] );
			}
			$list[] = $entry;
		}

		$json = wp_json_encode(
			array( '@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list ),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$out .= '<script type="application/ld+json">' . $json . '</script>';
	}

	return $out . '</nav>';
}

/**
 * روابط داخلية · عنقود موضوعي · مواضيع ذات صلة · موارد · صفحات ذات صلة.
 *
 * @param array $args links[label,url,note] · layout (grid|list|inline) · title.
 */
function tahdheer_render_link_cluster( array $args ): string {
	$links = (array) tahdheer_arg( $args, 'links', array() );
	if ( ! $links ) {
		return tahdheer_empty_state( 'link_cluster', 'links[label,url]' );
	}

	$out  = '<nav' . tahdheer_root_attrs( 'link_cluster', $args, array(
		'aria-label' => (string) tahdheer_arg( $args, 'title', __( 'روابط ذات صلة', 'tahdheer' ) ),
	) ) . '>';
	$out .= tahdheer_heading( $args, 'h3' );
	$out .= '<ul data-tah-links>';

	foreach ( $links as $link ) {
		$label = (string) ( is_array( $link ) ? ( $link['label'] ?? '' ) : (string) $link );
		$url   = (string) ( is_array( $link ) ? ( $link['url'] ?? '' ) : '' );
		if ( '' === $label || '' === $url ) {
			continue;
		}

		$note = (string) ( is_array( $link ) ? ( $link['note'] ?? '' ) : '' );

		$out .= '<li><a href="' . esc_url( $url ) . '">';
		$out .= '<strong>' . esc_html( $label ) . '</strong>';
		$out .= '' !== $note ? '<span>' . esc_html( $note ) . '</span>' : '';
		$out .= '</a></li>';
	}

	return $out . '</ul></nav>';
}

/**
 * روابط اجتماعية · متابعة · مشاركة.
 *
 * لا عدّادات متابعين — لا مصدر حقيقي لها، وعرضها تلفيق.
 *
 * @param array $args links[network,url] · layout (icons|labels) · title.
 */
function tahdheer_render_social( array $args ): string {
	$links = (array) tahdheer_arg( $args, 'links', array() );
	if ( ! $links ) {
		return tahdheer_empty_state( 'social', 'links[network,url]' );
	}

	$out  = '<nav' . tahdheer_root_attrs( 'social', $args, array(
		'aria-label' => (string) tahdheer_arg( $args, 'title', __( 'حساباتنا', 'tahdheer' ) ),
	) ) . '>';
	$out .= '<ul data-tah-social>';

	foreach ( $links as $link ) {
		$network = (string) ( is_array( $link ) ? ( $link['network'] ?? '' ) : '' );
		$url     = (string) ( is_array( $link ) ? ( $link['url'] ?? '' ) : '' );
		if ( '' === $network || '' === $url ) {
			continue;
		}

		$out .= '<li><a href="' . esc_url( $url ) . '" rel="noopener noreferrer" target="_blank">';
		$out .= '' !== $network && function_exists( 'tahdheer_icon' ) ? tahdheer_icon( $network, 20 ) : '';
		$out .= '<span>' . esc_html( $network ) . '</span>';
		$out .= '</a></li>';
	}

	return $out . '</ul></nav>';
}

/* ═══════════════ ٩ · المحتوى ═══════════════ */

/**
 * نصّ غني · اقتباس · مقدّمة مُهيكلة.
 *
 * @param array $args body · title · layout (prose|narrow|lead).
 */
function tahdheer_render_rich_text( array $args ): string {
	$body = (string) tahdheer_arg( $args, 'body', '' );
	if ( '' === $body ) {
		return tahdheer_empty_state( 'rich_text', 'body' );
	}

	$out  = '<section' . tahdheer_root_attrs( 'rich_text', $args ) . '>';
	$out .= tahdheer_heading( $args );
	$out .= '<div data-tah-rich>' . wp_kses_post( wpautop( $body ) ) . '</div>';

	return $out . '</section>';
}

/**
 * بطاقات محتوى · مقالات · مدوّنة · دليل · موارد · مركز معرفة.
 *
 * @param array $args items[title,url,excerpt,image,meta] · layout · columns · sectionTitle.
 */
function tahdheer_render_content_cards( array $args ): string {
	$items = (array) tahdheer_arg( $args, 'items', array() );
	if ( ! $items ) {
		return tahdheer_empty_state( 'content_cards', 'items[title,url]' );
	}

	$columns = max( 1, min( 4, (int) tahdheer_arg( $args, 'columns', 3 ) ) );

	$out  = '<section' . tahdheer_root_attrs( 'content_cards', $args, array( 'style' => '--tah-cols:' . $columns ) ) . '>';
	$out .= tahdheer_heading( $args );
	$out .= '<ul data-tah-ccards>';

	foreach ( $items as $item ) {
		$title = (string) ( is_array( $item ) ? ( $item['title'] ?? '' ) : '' );
		$url   = (string) ( is_array( $item ) ? ( $item['url'] ?? '' ) : '' );
		if ( '' === $title || '' === $url ) {
			continue;
		}

		$image   = (string) ( is_array( $item ) ? ( $item['image'] ?? '' ) : '' );
		$excerpt = (string) ( is_array( $item ) ? ( $item['excerpt'] ?? '' ) : '' );
		$meta    = (string) ( is_array( $item ) ? ( $item['meta'] ?? '' ) : '' );

		$out .= '<li><article><a href="' . esc_url( $url ) . '">';

		if ( '' !== $image ) {
			$out .= '<img data-tah-mslot src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '"'
				. ' loading="lazy" decoding="async" />';
		}

		$out .= '' !== $meta ? '<span data-tah-cc-meta>' . esc_html( $meta ) . '</span>' : '';
		$out .= '<h3>' . esc_html( $title ) . '</h3>';
		$out .= '' !== $excerpt ? '<p>' . esc_html( $excerpt ) . '</p>' : '';
		$out .= '</a></article></li>';
	}

	return $out . '</ul></section>';
}

/**
 * نصّ + صورة · قسم تعليمي · تحريري منقسم.
 *
 * @param array $args title · body · media[url,alt] · reverse · ctaText · ctaUrl.
 */
function tahdheer_render_split( array $args ): string {
	$body  = (string) tahdheer_arg( $args, 'body', '' );
	$media = (array) tahdheer_arg( $args, 'media', array() );

	if ( '' === $body && empty( $media['url'] ) ) {
		return tahdheer_empty_state( 'split', 'body أو media.url' );
	}

	$out  = '<section' . tahdheer_root_attrs( 'split', $args, array(
		'data-reverse' => ! empty( $args['reverse'] ) ? '1' : '0',
	) ) . '>';

	$out .= '<div data-tah-split-text>';
	$out .= tahdheer_heading( $args );
	$out .= '' !== $body ? wp_kses_post( wpautop( $body ) ) : '';
	$out .= tahdheer_hero_cta( $args, 'cta' );
	$out .= '</div>';

	if ( ! empty( $media['url'] ) ) {
		$out .= '<figure data-tah-split-media><img src="' . esc_url( (string) $media['url'] ) . '"'
			. ' alt="' . esc_attr( (string) ( $media['alt'] ?? '' ) ) . '"'
			. ' loading="lazy" decoding="async" /></figure>';
	}

	return $out . '</section>';
}

/* ═══════════════ ١٠ · الترويج والعدّاد ═══════════════ */

/**
 * شريط ترويجي · بانر حملة · إبراز خصم · ترويج موسمي وإطلاق.
 *
 * @param array $args title · body · ctaText · ctaUrl · tone · saleUntil.
 */
function tahdheer_render_promo( array $args ): string {
	$title = (string) tahdheer_arg( $args, 'title', '' );
	if ( '' === $title ) {
		return tahdheer_empty_state( 'promo', 'title' );
	}

	$out  = '<aside' . tahdheer_root_attrs( 'promo', $args, array(
		'data-tone' => (string) tahdheer_arg( $args, 'tone', 'accent' ),
	) ) . '>';
	$out .= '<div data-tah-promo>';
	$out .= '<strong>' . esc_html( $title ) . '</strong>';

	$body = (string) tahdheer_arg( $args, 'body', '' );
	$out .= '' !== $body ? '<span>' . esc_html( $body ) . '</span>' : '';

	$out .= tahdheer_render_countdown( $args );
	$out .= tahdheer_hero_cta( $args, 'cta' );

	return $out . '</div></aside>';
}

/**
 * عدّاد العرض · عرض محدود · جدول العروض.
 *
 * لا عدّاد بلا تاريخ حقيقي — العدّاد المخترع ندرة مُلفَّقة. والتاريخ في <time>
 * فيبقى مفهومًا بلا جافاسكربت.
 *
 * @param array $args saleUntil · productId · label.
 */
function tahdheer_render_countdown( array $args ): string {
	$until = (string) tahdheer_arg( $args, 'saleUntil', '' );

	if ( '' === $until ) {
		$product_id = (int) tahdheer_arg( $args, 'productId', 0 );
		if ( $product_id && function_exists( 'tahdheer_product_data' ) ) {
			$data  = tahdheer_product_data( $product_id );
			$until = (string) ( $data['sale_to'] ?? '' );
		}
	}

	if ( '' === $until ) {
		return '';
	}

	$stamp = strtotime( $until );
	if ( ! $stamp || $stamp <= time() ) {
		return '';
	}

	$iso = gmdate( 'c', $stamp );

	return '<span data-tah-cd data-until="' . esc_attr( $iso ) . '">'
		. '<span data-tah-cd-note>' . esc_html( (string) tahdheer_arg( $args, 'label', __( 'ينتهي', 'tahdheer' ) ) ) . '</span>'
		. '<time datetime="' . esc_attr( $iso ) . '">'
		. esc_html( date_i18n( (string) get_option( 'date_format' ), $stamp ) )
		. '</time></span>';
}

/* ═══════════════ ١١ · النماذج والبحث والتصفية ═══════════════ */

/**
 * نشرة · تواصل · استفسار · دعم · انتظار · تنبيه · نموذج مخصّص.
 *
 * label لكل حقل — لا placeholder بديلًا عنها: الـplaceholder يختفي عند الكتابة
 * فيفقد المستخدم اسم الحقل، ولا يقرؤه بعض قارئات الشاشة أصلًا.
 *
 * @param array $args fields[name,label,type,required,options] · action · submitLabel · title.
 */
function tahdheer_render_form( array $args ): string {
	$fields = (array) tahdheer_arg( $args, 'fields', array() );
	if ( ! $fields ) {
		return tahdheer_empty_state( 'form', 'fields[name,label]' );
	}

	$action = (string) tahdheer_arg( $args, 'action', '' );
	if ( '' === $action ) {
		return tahdheer_empty_state( 'form', 'action — مسار معالجة حقيقي' );
	}

	$base = 'tah-f-' . wp_rand( 1000, 9999 );

	$out  = '<section' . tahdheer_root_attrs( 'form', $args ) . '>';
	$out .= tahdheer_heading( $args );
	$out .= '<form data-tah-form method="post" action="' . esc_url( $action ) . '">';

	foreach ( array_values( $fields ) as $i => $field ) {
		$name  = (string) ( is_array( $field ) ? ( $field['name'] ?? '' ) : '' );
		$label = (string) ( is_array( $field ) ? ( $field['label'] ?? '' ) : '' );
		if ( '' === $name || '' === $label ) {
			continue;
		}

		$type     = (string) ( is_array( $field ) ? ( $field['type'] ?? 'text' ) : 'text' );
		$type     = in_array( $type, array( 'text', 'email', 'tel', 'url', 'number', 'textarea', 'select' ), true ) ? $type : 'text';
		$required = ! empty( $field['required'] );
		$field_id = $base . '-' . $i;

		$out .= '<div data-tah-field>';
		$out .= '<label for="' . esc_attr( $field_id ) . '">' . esc_html( $label );
		$out .= $required ? ' <span aria-hidden="true">*</span>' : '';
		$out .= '</label>';

		if ( 'textarea' === $type ) {
			$out .= '<textarea id="' . esc_attr( $field_id ) . '" name="' . esc_attr( sanitize_key( $name ) ) . '"'
				. ' rows="4"' . ( $required ? ' required' : '' ) . '></textarea>';
		} elseif ( 'select' === $type ) {
			$out .= '<select id="' . esc_attr( $field_id ) . '" name="' . esc_attr( sanitize_key( $name ) ) . '"'
				. ( $required ? ' required' : '' ) . '>';
			foreach ( (array) ( $field['options'] ?? array() ) as $option ) {
				$o = (string) ( is_array( $option ) ? ( $option['label'] ?? '' ) : (string) $option );
				if ( '' !== $o ) {
					$out .= '<option value="' . esc_attr( $o ) . '">' . esc_html( $o ) . '</option>';
				}
			}
			$out .= '</select>';
		} else {
			$out .= '<input id="' . esc_attr( $field_id ) . '" type="' . esc_attr( $type ) . '"'
				. ' name="' . esc_attr( sanitize_key( $name ) ) . '"'
				. ( $required ? ' required' : '' ) . ' />';
		}

		$out .= '</div>';
	}

	$out .= wp_nonce_field( 'tahdheer_form', '_tah_nonce', true, false );
	$out .= '<button type="submit">' . esc_html(
		(string) tahdheer_arg( $args, 'submitLabel', __( 'إرسال', 'tahdheer' ) )
	) . '</button>';
	$out .= '</form>';

	$note = (string) tahdheer_arg( $args, 'note', '' );
	$out .= '' !== $note ? '<p data-tah-form-note>' . esc_html( $note ) . '</p>' : '';

	return $out . '</section>';
}

/**
 * شريط بحث · فوري · بالتصنيف · بالمنتج.
 *
 * يُوجَّه إلى بحث المتجر القائم — لا مسار بحث ثانٍ.
 *
 * @param array $args placeholder · label · scope · layout.
 */
function tahdheer_render_search_field( array $args ): string {
	$id = 'tah-q-' . wp_rand( 1000, 9999 );

	$out  = '<div' . tahdheer_root_attrs( 'search_field', $args ) . '>';
	$out .= '<form data-tah-search role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '">';
	$out .= '<label for="' . esc_attr( $id ) . '" data-tah-sr>' . esc_html(
		(string) tahdheer_arg( $args, 'label', __( 'ابحث في المتجر', 'tahdheer' ) )
	) . '</label>';
	$out .= '<input id="' . esc_attr( $id ) . '" type="search" name="s"'
		. ' placeholder="' . esc_attr( (string) tahdheer_arg( $args, 'placeholder', __( 'ابحث…', 'tahdheer' ) ) ) . '"'
		. ' value="' . esc_attr( get_search_query() ) . '" autocomplete="off" />';

	if ( 'product' === (string) tahdheer_arg( $args, 'scope', 'product' ) && post_type_exists( 'product' ) ) {
		$out .= '<input type="hidden" name="post_type" value="product" />';
	}

	$out .= '<button type="submit">' . esc_html__( 'ابحث', 'tahdheer' ) . '</button>';

	return $out . '</form></div>';
}

/**
 * مرشّحات السعر والتصنيف والخصائص والوسوم والتوفّر — كلّها روابط استعلام حقيقية.
 *
 * روابط لا جافاسكربت: كل مرشّح رابط يمكن حفظه ومشاركته وأرشفته، ويعمل بلا JS.
 *
 * @param array $args groups[label,options[label,url,count,active]] · layout · title.
 */
function tahdheer_render_filter_set( array $args ): string {
	$groups = (array) tahdheer_arg( $args, 'groups', array() );
	if ( ! $groups ) {
		return tahdheer_empty_state( 'filter_set', 'groups[label,options[label,url]]' );
	}

	$out  = '<aside' . tahdheer_root_attrs( 'filter_set', $args, array(
		'aria-label' => (string) tahdheer_arg( $args, 'title', __( 'تصفية النتائج', 'tahdheer' ) ),
	) ) . '>';
	$out .= tahdheer_heading( $args, 'h3' );
	$out .= '<div data-tah-filters>';

	foreach ( $groups as $group ) {
		$label   = (string) ( is_array( $group ) ? ( $group['label'] ?? '' ) : '' );
		$options = (array) ( is_array( $group ) ? ( $group['options'] ?? array() ) : array() );
		if ( '' === $label || ! $options ) {
			continue;
		}

		$out .= '<div data-tah-filter-group>';
		$out .= '<h4>' . esc_html( $label ) . '</h4><ul>';

		foreach ( $options as $option ) {
			$o_label = (string) ( is_array( $option ) ? ( $option['label'] ?? '' ) : (string) $option );
			$o_url   = (string) ( is_array( $option ) ? ( $option['url'] ?? '' ) : '' );
			if ( '' === $o_label || '' === $o_url ) {
				continue;
			}

			$active = ! empty( $option['active'] );
			$count  = (string) ( is_array( $option ) ? ( $option['count'] ?? '' ) : '' );

			$out .= '<li><a href="' . esc_url( $o_url ) . '"';
			$out .= $active ? ' aria-current="true" data-tah-on="1"' : '';
			$out .= '>' . esc_html( $o_label );
			$out .= '' !== $count ? ' <span>' . esc_html( $count ) . '</span>' : '';
			$out .= '</a></li>';
		}

		$out .= '</ul></div>';
	}

	return $out . '</div></aside>';
}

/* ═══════════════ ١٢ · الوسائط ═══════════════ */

/**
 * صورة مفردة · فيديو · رسم · فيديو المنتج.
 *
 * @param array $args url · alt · caption · kind (image|video) · poster · w · h.
 */
function tahdheer_render_media_one( array $args ): string {
	$url = (string) tahdheer_arg( $args, 'url', '' );
	if ( '' === $url ) {
		return tahdheer_empty_state( 'media_one', 'url' );
	}

	$kind = 'video' === (string) tahdheer_arg( $args, 'kind', 'image' ) ? 'video' : 'image';

	$out = '<figure' . tahdheer_root_attrs( 'media_one', $args ) . '>';

	if ( 'video' === $kind ) {
		$poster = (string) tahdheer_arg( $args, 'poster', '' );
		$out   .= '<video data-tah-mslot src="' . esc_url( $url ) . '"';
		$out   .= '' !== $poster ? ' poster="' . esc_url( $poster ) . '"' : '';
		$out   .= ' controls preload="metadata" playsinline></video>';
	} else {
		$width  = (int) tahdheer_arg( $args, 'w', 0 );
		$height = (int) tahdheer_arg( $args, 'h', 0 );
		$out   .= '<img data-tah-mslot src="' . esc_url( $url ) . '"'
			. ' alt="' . esc_attr( (string) tahdheer_arg( $args, 'alt', '' ) ) . '"';
		$out   .= $width > 0 ? ' width="' . $width . '"' : '';
		$out   .= $height > 0 ? ' height="' . $height . '"' : '';
		$out   .= ' loading="lazy" decoding="async" />';
	}

	$caption = (string) tahdheer_arg( $args, 'caption', '' );
	$out    .= '' !== $caption ? '<figcaption>' . esc_html( $caption ) . '</figcaption>' : '';

	return $out . '</figure>';
}

/**
 * خلفية وسائطية · فيديو خلفي.
 *
 * الفيديو الخلفي صامت وبلا ضوابط — ومع تقليل الحركة يُستبدل بالملصق ثابتًا.
 *
 * @param array $args url · poster · kind · overlay · children.
 */
function tahdheer_render_media_bg( array $args ): string {
	$url = (string) tahdheer_arg( $args, 'url', '' );
	if ( '' === $url ) {
		return tahdheer_empty_state( 'media_bg', 'url' );
	}

	$kind    = 'video' === (string) tahdheer_arg( $args, 'kind', 'image' ) ? 'video' : 'image';
	$overlay = max( 0, min( 1, (float) tahdheer_arg( $args, 'overlay', 0.45 ) ) );
	$style   = '--tah-ov:' . $overlay;

	if ( 'image' === $kind ) {
		$style .= ';--tah-bg:url(' . esc_url_raw( $url ) . ')';
	}

	$out = '<div' . tahdheer_root_attrs( 'media_bg', $args, array(
		'data-kind' => $kind,
		'style'     => $style,
	) ) . '>';

	if ( 'video' === $kind ) {
		$poster = (string) tahdheer_arg( $args, 'poster', '' );
		$out   .= '<video data-tah-mslot data-tah-mediabg src="' . esc_url( $url ) . '"';
		$out   .= '' !== $poster ? ' poster="' . esc_url( $poster ) . '"' : '';
		$out   .= ' autoplay muted loop playsinline preload="metadata" aria-hidden="true"></video>';
	}

	$body = (string) tahdheer_arg( $args, 'body', '' );
	$out .= '<div data-tah-mediabg-in>';
	$out .= tahdheer_heading( $args );
	$out .= '' !== $body ? '<p>' . esc_html( $body ) . '</p>' : '';
	$out .= tahdheer_hero_cta( $args, 'cta' );

	return $out . '</div></div>';
}

/**
 * صور العملاء · محتوى العملاء · معرض اجتماعي · أبرز المجتمع.
 *
 * صور يرفعها المشرف بإذن — لا سحب تلقائي من حسابات العملاء.
 *
 * @param array $args items[url,alt,credit,link] · columns · title.
 */
function tahdheer_render_ugc( array $args ): string {
	$items = (array) tahdheer_arg( $args, 'items', array() );
	if ( ! $items ) {
		return tahdheer_empty_state( 'ugc', 'items[url] — صور بإذن أصحابها' );
	}

	$columns = max( 2, min( 6, (int) tahdheer_arg( $args, 'columns', 4 ) ) );

	$out  = '<section' . tahdheer_root_attrs( 'ugc', $args, array( 'style' => '--tah-cols:' . $columns ) ) . '>';
	$out .= tahdheer_heading( $args );
	$out .= '<ul data-tah-ugc>';

	foreach ( $items as $item ) {
		$url = (string) ( is_array( $item ) ? ( $item['url'] ?? '' ) : (string) $item );
		if ( '' === $url ) {
			continue;
		}

		$credit = (string) ( is_array( $item ) ? ( $item['credit'] ?? '' ) : '' );
		$link   = (string) ( is_array( $item ) ? ( $item['link'] ?? '' ) : '' );

		$out .= '<li><figure>';
		$out .= '' !== $link ? '<a href="' . esc_url( $link ) . '" rel="noopener noreferrer">' : '';
		$out .= '<img data-tah-mslot src="' . esc_url( $url ) . '"'
			. ' alt="' . esc_attr( (string) ( is_array( $item ) ? ( $item['alt'] ?? '' ) : '' ) ) . '"'
			. ' loading="lazy" decoding="async" />';
		$out .= '' !== $link ? '</a>' : '';
		$out .= '' !== $credit ? '<figcaption>' . esc_html( $credit ) . '</figcaption>' : '';
		$out .= '</figure></li>';
	}

	return $out . '</ul></section>';
}
