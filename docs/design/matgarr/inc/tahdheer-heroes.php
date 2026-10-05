<?php
/**
 * Tahdheer — نظام الهيرو: ٣٠ هدفًا تجاريًا على ١٠ هياكل تخطيط.
 *
 * القرار المعماري الذي أثبته التدقيق: الفرق بين الثلاثين هيرو في **الهدف
 * والمحتوى ومصدر البيانات** لا في هيكل التخطيط. «الأكثر مبيعًا» و«الوصول
 * الجديد» نفس الهيكل حرفيًا — استعلام مختلف وعنوان مختلف. بناؤهما كبلوكين
 * ينسخ عقد الـ٧٥ خاصية مرتين، ثم يُصلَح عيب في أحدهما ويُنسى الآخر.
 *
 * لذلك: هيكل واحد لكل شكل تخطيط (١٠)، وسجل أهداف (٣٠) يختار هيكله ومصدره
 * وفتحاته. إضافة هدف حادي وثلاثين = سطر في السجل لا ملف جديد.
 *
 * التوافق مع بلوك الهيرو القائم: أسماء الوسائط تطابق خصائص block.json
 * (variant · layoutReverse · bgMode · overlayOpacity · glow · grain · cards ·
 * bentoPreset · parallax · floating) فينتقل البلوك إلى هذا المُصيِّر بلا
 * تحويل بيانات ولا انحدار بصري.
 *
 * @package Tahdheer
 */

defined( 'ABSPATH' ) || exit;

/**
 * الهياكل العشرة: المعرّف => التسمية وما يحتاجه من أزمنة تشغيل.
 *
 * @return array<string,array<string,mixed>>
 */
function tahdheer_hero_skeletons(): array {
	return array(
		'split'     => array( 'label' => 'منقسم — محتوى ↔ وسيط', 'deps' => array() ),
		'centered'  => array( 'label' => 'مركزي مُكدَّس', 'deps' => array() ),
		'grid'      => array( 'label' => 'شبكي / بينتو', 'deps' => array() ),
		'carousel'  => array( 'label' => 'كاروسيل', 'deps' => array( 'rt-gallery' ) ),
		'search'    => array( 'label' => 'البحث أولًا', 'deps' => array() ),
		'editorial' => array( 'label' => 'تحريري', 'deps' => array() ),
		'campaign'  => array( 'label' => 'حملة بعدّاد', 'deps' => array() ),
		'layered'   => array( 'label' => 'طبقي / بارالاكس', 'deps' => array( 'rt-observer' ) ),
		'listed'    => array( 'label' => 'قائم على قائمة', 'deps' => array() ),
		'adaptive'  => array( 'label' => 'ثقة / تكيّفي', 'deps' => array() ),
	);
}

/**
 * سجل الأهداف الثلاثين.
 *
 * كل مدخل: الهيكل · مصدر البيانات · الفتحات المُفعَّلة افتراضيًا · حالة التوفّر.
 * حالة 'needs' تعني أن الهدف يظهر في المتصفّح موسومًا ومعطّلًا حتى يوجد مصدره
 * الحقيقي — لا ذكاء مُلفَّق ولا تخصيص مُدَّعى.
 *
 * @return array<string,array<string,mixed>>
 */
function tahdheer_hero_objectives(): array {
	static $objectives = null;

	if ( null !== $objectives ) {
		return $objectives;
	}

	$objectives = array(
		// ── منقسم (٦)
		'product_split' => array( 'label' => 'منتج منقسم', 'skeleton' => 'split', 'source' => 'product', 'slots' => 'eyebrow,title,body,price,rating,cta,media' ),
		'spotlight'    => array( 'label' => 'إبراز منتج', 'skeleton' => 'split', 'source' => 'product', 'slots' => 'label,title,body,facts,cta,media' ),
		'feature_rich' => array( 'label' => 'منتج غنيّ بالميزات', 'skeleton' => 'split', 'source' => 'product', 'slots' => 'title,body,benefits,facts,cta,media' ),
		'digital'      => array( 'label' => 'المنتج الرقمي', 'skeleton' => 'split', 'source' => 'product', 'slots' => 'label,title,body,facts,cta,media' ),
		'comparison'   => array( 'label' => 'مقارنة', 'skeleton' => 'split', 'source' => 'products', 'slots' => 'title,body,compare,cta' ),
		'video'        => array( 'label' => 'فيديو تجاري', 'skeleton' => 'split', 'source' => 'manual', 'slots' => 'title,body,cta,video' ),

		// ── مركزي (٤)
		'classic'      => array( 'label' => 'كلاسيكي مركزي تجاري', 'skeleton' => 'centered', 'source' => 'manual', 'slots' => 'eyebrow,title,body,cta,cta2,trust' ),
		'seasonal'     => array( 'label' => 'ترويج موسمي', 'skeleton' => 'centered', 'source' => 'manual', 'slots' => 'label,title,body,cta,bg' ),
		'luxury'       => array( 'label' => 'فخم بسيط', 'skeleton' => 'centered', 'source' => 'manual', 'slots' => 'eyebrow,title,cta' ),
		'fullscreen'   => array( 'label' => 'شاشة كاملة', 'skeleton' => 'centered', 'source' => 'manual', 'slots' => 'title,body,cta,bg' ),

		// ── شبكي (٥)
		'category'     => array( 'label' => 'استكشاف التصنيفات', 'skeleton' => 'grid', 'source' => 'terms', 'slots' => 'title,body,cards' ),
		'mega'         => array( 'label' => 'مجموعة ضخمة', 'skeleton' => 'grid', 'source' => 'terms', 'slots' => 'title,cards' ),
		'bento'        => array( 'label' => 'بينتو تجاري', 'skeleton' => 'grid', 'source' => 'mixed', 'slots' => 'title,body,cards,cta' ),
		'multi'        => array( 'label' => 'عرض عدّة منتجات', 'skeleton' => 'grid', 'source' => 'products', 'slots' => 'title,body,products' ),
		'marketplace'  => array( 'label' => 'مستكشف السوق', 'skeleton' => 'grid', 'source' => 'terms', 'slots' => 'title,search,cards' ),

		// ── كاروسيل (٢)
		'carousel'     => array( 'label' => 'كاروسيل تجاري', 'skeleton' => 'carousel', 'source' => 'products', 'slots' => 'title,body,products' ),
		'gallery'      => array( 'label' => 'معرض منتجات تفاعلي', 'skeleton' => 'carousel', 'source' => 'product', 'slots' => 'title,media_fx,cta' ),

		// ── بحث (٢)
		'search_first' => array( 'label' => 'البحث أولًا', 'skeleton' => 'search', 'source' => 'manual', 'slots' => 'title,body,search,chips' ),
		'by_need'      => array( 'label' => 'تصفّح بالحاجة', 'skeleton' => 'search', 'source' => 'terms', 'slots' => 'title,body,chips' ),

		// ── تحريري (٣)
		'editorial'    => array( 'label' => 'قصّة تحريرية', 'skeleton' => 'editorial', 'source' => 'manual', 'slots' => 'eyebrow,title,body,media' ),
		'testimonial'  => array( 'label' => 'شهادات تجارية', 'skeleton' => 'editorial', 'source' => 'reviews', 'slots' => 'title,quote,cta' ),
		'educational'  => array( 'label' => 'تعليمي تجاري', 'skeleton' => 'editorial', 'source' => 'manual', 'slots' => 'title,body,steps,cta' ),

		// ── حملة (٢)
		'flash'        => array( 'label' => 'تخفيض سريع', 'skeleton' => 'campaign', 'source' => 'sale', 'slots' => 'label,title,countdown,products,cta' ),
		'campaign'     => array( 'label' => 'حملة بعدّاد', 'skeleton' => 'campaign', 'source' => 'sale', 'slots' => 'label,title,body,countdown,cta' ),

		// ── طبقي (١)
		'parallax'     => array( 'label' => 'بارالاكس طبقي', 'skeleton' => 'layered', 'source' => 'manual', 'slots' => 'title,body,cta,layers' ),

		// ── قائمة (٢)
		'bestseller'   => array( 'label' => 'الأكثر مبيعًا', 'skeleton' => 'listed', 'source' => 'products', 'slots' => 'label,title,products,cta' ),
		'new_arrival'  => array( 'label' => 'الوصول الجديد', 'skeleton' => 'listed', 'source' => 'products', 'slots' => 'label,title,products,cta' ),

		// ── تكيّفي (٣)
		'trust'        => array( 'label' => 'مدفوع بالثقة', 'skeleton' => 'adaptive', 'source' => 'manual', 'slots' => 'title,body,trust,facts,cta' ),
		'personal'     => array( 'label' => 'شخصي ديناميكي', 'skeleton' => 'adaptive', 'source' => 'session', 'slots' => 'title,body,products', 'needs' => 'مصدر جلسة العميل' ),
		'adaptive'     => array( 'label' => 'تكيّفي ذكي', 'skeleton' => 'adaptive', 'source' => 'ai', 'slots' => 'title,body,products', 'needs' => 'مصدر توصيات خارجي' ),
	);

	/**
	 * تسجيل هدف هيرو من إضافة.
	 *
	 * @param array<string,array<string,mixed>> $objectives الأهداف.
	 */
	return (array) apply_filters( 'tahdheer_hero_objectives', $objectives );
}

/** الأهداف المجموعة بهيكلها — يقرأها الحارس والمتصفّح. */
function tahdheer_hero_by_skeleton(): array {
	$out = array();

	foreach ( tahdheer_hero_objectives() as $id => $objective ) {
		$out[ (string) $objective['skeleton'] ][] = $id;
	}

	return $out;
}

/**
 * فتحات الهدف كمصفوفة — الفتحة غير المُعلَنة لا تُصيَّر أبدًا.
 *
 * @param string $objective معرّف الهدف.
 * @return array<int,string>
 */
function tahdheer_hero_slots( string $objective ): array {
	$objectives = tahdheer_hero_objectives();

	if ( ! isset( $objectives[ $objective ] ) ) {
		return array();
	}

	return array_map( 'trim', explode( ',', (string) $objectives[ $objective ]['slots'] ) );
}

/* ═══════════════════════ فتحات المحتوى ═══════════════════════ */

/**
 * فتحة واحدة — كل فتحة تُخرج شيئًا أو لا شيء، ولا تخترع بديلًا.
 *
 * @param string $slot الفتحة.
 * @param array  $args وسائط الهيرو.
 */
function tahdheer_hero_slot( string $slot, array $args ): string {
	switch ( $slot ) {
		case 'eyebrow':
		case 'label':
			$text = (string) tahdheer_arg( $args, $slot, '' );
			return '' !== $text
				? '<span data-tah-hero-' . esc_attr( $slot ) . '>' . esc_html( $text ) . '</span>'
				: '';

		case 'title':
			$title = (string) tahdheer_arg( $args, 'title', '' );
			if ( '' === $title ) {
				return '';
			}
			// h1 مرّة واحدة في الصفحة: الهيرو يأخذه على الأرشيف والرئيسية فقط،
			// وعلى صفحة المنتج يبقى العنوان لاسم المنتج.
			$tag       = (string) tahdheer_arg( $args, 'titleTag', is_singular() ? 'h2' : 'h1' );
			$tag       = in_array( $tag, array( 'h1', 'h2', 'h3' ), true ) ? $tag : 'h2';
			$highlight = (string) tahdheer_arg( $args, 'highlight', '' );
			$html      = esc_html( $title );

			if ( '' !== $highlight && false !== strpos( $title, $highlight ) ) {
				$html = str_replace(
					esc_html( $highlight ),
					'<mark data-tah-hl>' . esc_html( $highlight ) . '</mark>',
					$html
				);
			}

			return '<' . $tag . ' data-tah-hero-title>' . $html . '</' . $tag . '>';

		case 'body':
			$body = (string) tahdheer_arg( $args, 'body', '' );
			return '' !== $body ? '<p data-tah-hero-body>' . esc_html( $body ) . '</p>' : '';

		case 'price':
			return tahdheer_hero_price( $args );

		case 'rating':
			return tahdheer_hero_rating_slot( $args );

		case 'cta':
		case 'cta2':
		case 'cta3':
			return tahdheer_hero_cta( $args, $slot );

		case 'trust':
			return tahdheer_hero_trust( $args );

		case 'facts':
			$rows = (array) tahdheer_arg( $args, 'facts', array() );
			return $rows
				? tahdheer_render( 'facts', array( 'layout' => 'pills', 'rows' => $rows ) )
				: '';

		case 'benefits':
			return tahdheer_hero_benefits( $args );

		case 'chips':
			return tahdheer_hero_chips( $args );

		case 'search':
			return tahdheer_hero_search( $args );

		case 'countdown':
			return tahdheer_hero_countdown( $args );

		case 'quote':
			$quote = (array) tahdheer_arg( $args, 'quote', array() );
			return ! empty( $quote['quote'] ) ? tahdheer_render( 'quote', $quote ) : '';

		case 'steps':
			$steps = (array) tahdheer_arg( $args, 'steps', array() );
			return $steps ? tahdheer_render( 'timeline', array( 'steps' => $steps ) ) : '';

		case 'compare':
			$compare = (array) tahdheer_arg( $args, 'compare', array() );
			return ! empty( $compare['items'] ) ? tahdheer_render( 'compare', $compare ) : '';

		case 'media_fx':
			$fx = (array) tahdheer_arg( $args, 'media_fx', array() );
			return $fx ? tahdheer_render( 'media_fx', $fx ) : '';

		case 'media':
			return tahdheer_hero_media( $args );

		case 'video':
			return tahdheer_hero_video( $args );

		case 'products':
			return tahdheer_hero_products( $args );

		case 'cards':
			return tahdheer_hero_cards( $args );

		case 'layers':
			return tahdheer_hero_layers( $args );

		default:
			/**
			 * فتحة مخصّصة من إضافة.
			 *
			 * @param string $html المخرج.
			 * @param string $slot الفتحة.
			 * @param array  $args الوسائط.
			 */
			return (string) apply_filters( 'tahdheer_hero_slot', '', $slot, $args );
	}
}

/** السعر — من المنتج الحقيقي لا من نصّ يُكتب. */
function tahdheer_hero_price( array $args ): string {
	$product_id = (int) tahdheer_arg( $args, 'productId', 0 );

	if ( ! $product_id || ! function_exists( 'tahdheer_product_data' ) ) {
		return '';
	}

	$data = tahdheer_product_data( $product_id );
	if ( ! $data || empty( $data['price_html'] ) ) {
		return '';
	}

	$out = '<div data-tah-hero-price>' . wp_kses_post( (string) $data['price_html'] );

	if ( ! empty( $data['on_sale'] ) && ! empty( $data['discount_pct'] ) ) {
		$out .= '<span data-tah-hero-off>'
			. esc_html( sprintf( __( 'خصم %s٪', 'tahdheer' ), (string) $data['discount_pct'] ) )
			. '</span>';
	}

	return $out . '</div>';
}

/** التقييم — من القارئ الحقيقي، ولا يُخرج شيئًا إن لم توجد مراجعات. */
function tahdheer_hero_rating_slot( array $args ): string {
	$product_id = (int) tahdheer_arg( $args, 'productId', 0 );

	if ( ! $product_id || ! function_exists( 'tahdheer_hero_rating' ) ) {
		return '';
	}

	return (string) tahdheer_hero_rating( $product_id );
}

/** نداء لإجراء — لا زرّ بلا رابط، ولا رابط بلا نصّ. */
function tahdheer_hero_cta( array $args, string $key ): string {
	$map = array(
		'cta'  => array( 'ctaText', 'ctaUrl', 'primary' ),
		'cta2' => array( 'cta2Text', 'cta2Url', 'secondary' ),
		'cta3' => array( 'cta3Text', 'cta3Url', 'ghost' ),
	);

	if ( ! isset( $map[ $key ] ) ) {
		return '';
	}

	list( $text_key, $url_key, $tone ) = $map[ $key ];
	$text = (string) tahdheer_arg( $args, $text_key, '' );
	$url  = (string) tahdheer_arg( $args, $url_key, '' );

	if ( '' === $text || '' === $url ) {
		return '';
	}

	return '<a data-tah-hero-cta data-tone="' . esc_attr( $tone ) . '"'
		. ' href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
}

/** شارات الثقة — نصّ المشرف فقط، بلا أرقام مخترعة. */
function tahdheer_hero_trust( array $args ): string {
	$items = (array) tahdheer_arg( $args, 'trust', array() );
	if ( ! $items ) {
		return '';
	}

	$out = '<ul data-tah-hero-trust>';

	foreach ( $items as $item ) {
		$label = (string) ( is_array( $item ) ? ( $item['label'] ?? '' ) : (string) $item );
		if ( '' === $label ) {
			continue;
		}

		$icon = (string) ( is_array( $item ) ? ( $item['icon'] ?? '' ) : '' );
		$out .= '<li>';
		$out .= '' !== $icon && function_exists( 'tahdheer_icon' ) ? tahdheer_icon( $icon, 16 ) : '';
		$out .= '<span>' . esc_html( $label ) . '</span></li>';
	}

	return $out . '</ul>';
}

/** قائمة الفوائد. */
function tahdheer_hero_benefits( array $args ): string {
	$items = (array) tahdheer_arg( $args, 'benefits', array() );
	if ( ! $items ) {
		return '';
	}

	$out = '<ul data-tah-hero-benefits>';

	foreach ( $items as $item ) {
		$label = (string) ( is_array( $item ) ? ( $item['label'] ?? '' ) : (string) $item );
		if ( '' === $label ) {
			continue;
		}

		$body = (string) ( is_array( $item ) ? ( $item['body'] ?? '' ) : '' );
		$out .= '<li><strong>' . esc_html( $label ) . '</strong>';
		$out .= '' !== $body ? '<span>' . esc_html( $body ) . '</span>' : '';
		$out .= '</li>';
	}

	return $out . '</ul>';
}

/** شرائح مصطلحات — روابط حقيقية لأرشيفات موجودة. */
function tahdheer_hero_chips( array $args ): string {
	$chips = (array) tahdheer_arg( $args, 'chips', array() );
	if ( ! $chips ) {
		return '';
	}

	$out = '<ul data-tah-hero-chips>';

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

	return $out . '</ul>';
}

/**
 * حقل البحث — يُوجَّه إلى بحث المتجر القائم لا إلى نموذج جديد.
 *
 * البحث موجود فعلًا في القالب (inc/sections/render/search.php و ajax-search.php)
 * فيُستدعى ولا يُعاد بناؤه.
 */
function tahdheer_hero_search( array $args ): string {
	$placeholder = (string) tahdheer_arg( $args, 'searchPlaceholder', __( 'ابحث عن مادة أو مستوى…', 'tahdheer' ) );
	$label       = (string) tahdheer_arg( $args, 'searchLabel', __( 'ابحث في المتجر', 'tahdheer' ) );
	$id          = 'tah-hero-q-' . wp_rand( 1000, 9999 );

	$out  = '<form data-tah-hero-search role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '">';
	$out .= '<label for="' . esc_attr( $id ) . '" data-tah-sr>' . esc_html( $label ) . '</label>';
	$out .= '<input id="' . esc_attr( $id ) . '" type="search" name="s"'
		. ' placeholder="' . esc_attr( $placeholder ) . '"'
		. ' value="' . esc_attr( get_search_query() ) . '"'
		. ' autocomplete="off" />';

	if ( post_type_exists( 'product' ) ) {
		$out .= '<input type="hidden" name="post_type" value="product" />';
	}

	$out .= '<button type="submit">' . esc_html__( 'ابحث', 'tahdheer' ) . '</button>';

	return $out . '</form>';
}

/**
 * العدّاد التنازلي — يقرأ تاريخ التخفيض الحقيقي من ووكومرس.
 *
 * لا عدّاد بلا تاريخ: العدّاد المخترع هو ندرة مُلفَّقة يمنعها الطلب صراحةً.
 * ويكتب التاريخ بـISO في سمة فيقرأه زمن التشغيل، ونصًّا مقروءًا في <time>
 * فيبقى مفهومًا بلا جافاسكربت.
 */
function tahdheer_hero_countdown( array $args ): string {
	$until = (string) tahdheer_arg( $args, 'saleUntil', '' );

	if ( '' === $until ) {
		$product_id = (int) tahdheer_arg( $args, 'productId', 0 );
		if ( $product_id && function_exists( 'tahdheer_product_data' ) ) {
			$data  = tahdheer_product_data( $product_id );
			$until = (string) ( $data['sale_to'] ?? '' );
		}
	}

	if ( '' === $until ) {
		return tahdheer_empty_state( 'hero:countdown', 'saleUntil أو منتج بتاريخ تخفيض' );
	}

	$stamp = strtotime( $until );
	if ( ! $stamp || $stamp <= time() ) {
		return '';
	}

	$iso     = gmdate( 'c', $stamp );
	$readable = date_i18n( (string) get_option( 'date_format' ), $stamp );

	return '<div data-tah-hero-countdown data-until="' . esc_attr( $iso ) . '">'
		. '<span data-tah-cd-label>' . esc_html__( 'ينتهي العرض', 'tahdheer' ) . '</span>'
		. '<time datetime="' . esc_attr( $iso ) . '">' . esc_html( $readable ) . '</time>'
		. '</div>';
}

/** الوسيط — بصورة جوال منفصلة كما في عقد البلوك القائم. */
function tahdheer_hero_media( array $args ): string {
	$media = (array) tahdheer_arg( $args, 'media', array() );
	if ( empty( $media['url'] ) ) {
		return '';
	}

	$mobile = (string) ( $media['mobileUrl'] ?? '' );
	$alt    = (string) ( $media['alt'] ?? '' );
	$width  = (int) ( $media['w'] ?? 0 );
	$height = (int) ( $media['h'] ?? 0 );

	$out = '<figure data-tah-hero-media>';

	if ( '' !== $mobile ) {
		$out .= '<picture>';
		$out .= '<source media="(max-width: 767px)" srcset="' . esc_url( $mobile ) . '" />';
	}

	$out .= '<img data-tah-mslot src="' . esc_url( (string) $media['url'] ) . '" alt="' . esc_attr( $alt ) . '"';
	$out .= $width > 0 ? ' width="' . $width . '"' : '';
	$out .= $height > 0 ? ' height="' . $height . '"' : '';
	// وسيط الهيرو فوق الطيّة: تحميل فوري وأولوية عالية — عكس بقيّة الصور.
	$out .= ' loading="eager" fetchpriority="high" decoding="async" />';

	$out .= '' !== $mobile ? '</picture>' : '';

	$caption = (string) ( $media['caption'] ?? '' );
	$out    .= '' !== $caption ? '<figcaption>' . esc_html( $caption ) . '</figcaption>' : '';

	return $out . '</figure>';
}

/** الفيديو — بلا تشغيل تلقائي بصوت، وبلا تشغيل مع تقليل الحركة. */
function tahdheer_hero_video( array $args ): string {
	$video = (array) tahdheer_arg( $args, 'video', array() );
	if ( empty( $video['url'] ) ) {
		return '';
	}

	$poster = (string) ( $video['poster'] ?? '' );

	$out  = '<figure data-tah-hero-video>';
	$out .= '<video data-tah-mslot src="' . esc_url( (string) $video['url'] ) . '"';
	$out .= '' !== $poster ? ' poster="' . esc_url( $poster ) . '"' : '';
	$out .= ' controls preload="metadata" playsinline';
	$out .= '></video>';

	return $out . '</figure>';
}

/** بطاقات المنتجات — من الاستعلام الحقيقي، وبحالة فراغ صريحة. */
function tahdheer_hero_products( array $args ): string {
	$ids = array_map( 'absint', (array) tahdheer_arg( $args, 'productIds', array() ) );
	$ids = array_filter( $ids );

	if ( ! $ids || ! function_exists( 'tahdheer_product_data' ) ) {
		return tahdheer_empty_state( 'hero:products', 'productIds[]' );
	}

	$limit = (int) tahdheer_arg( $args, 'limit', 6 );
	$ids   = array_slice( $ids, 0, max( 1, min( 12, $limit ) ) );

	$out = '<ul data-tah-hero-products>';

	foreach ( $ids as $id ) {
		$data = tahdheer_product_data( $id );
		if ( ! $data ) {
			continue;
		}

		$out .= '<li><a href="' . esc_url( (string) $data['url'] ) . '">';

		if ( ! empty( $data['image'] ) ) {
			$out .= '<img data-tah-mslot src="' . esc_url( (string) $data['image'] ) . '"'
				. ' alt="' . esc_attr( (string) $data['title'] ) . '"'
				. ' loading="lazy" decoding="async" />';
		}

		$out .= '<span data-tah-p-title>' . esc_html( (string) $data['title'] ) . '</span>';
		$out .= '<span data-tah-p-price>' . wp_kses_post( (string) $data['price_html'] ) . '</span>';
		$out .= '</a></li>';
	}

	return $out . '</ul>';
}

/** بطاقات البينتو — تطابق مصفوفة cards في عقد البلوك القائم. */
function tahdheer_hero_cards( array $args ): string {
	$cards = (array) tahdheer_arg( $args, 'cards', array() );
	if ( ! $cards ) {
		return tahdheer_empty_state( 'hero:cards', 'cards[label,url]' );
	}

	$out = '<ul data-tah-hero-cards>';

	foreach ( $cards as $card ) {
		$label = (string) ( is_array( $card ) ? ( $card['label'] ?? '' ) : (string) $card );
		if ( '' === $label ) {
			continue;
		}

		$url   = (string) ( is_array( $card ) ? ( $card['url'] ?? '' ) : '' );
		$body  = (string) ( is_array( $card ) ? ( $card['body'] ?? '' ) : '' );
		$image = (string) ( is_array( $card ) ? ( $card['image'] ?? '' ) : '' );
		$count = (string) ( is_array( $card ) ? ( $card['count'] ?? '' ) : '' );
		$span  = (int) ( is_array( $card ) ? ( $card['span'] ?? 1 ) : 1 );

		$out .= '<li data-span="' . esc_attr( (string) max( 1, min( 3, $span ) ) ) . '">';
		$out .= '' !== $url ? '<a href="' . esc_url( $url ) . '">' : '<div>';

		if ( '' !== $image ) {
			$out .= '<img data-tah-mslot src="' . esc_url( $image ) . '" alt="" loading="lazy" decoding="async" />';
		}

		$out .= '<span data-tah-card-label>' . esc_html( $label ) . '</span>';
		$out .= '' !== $body ? '<span data-tah-card-body>' . esc_html( $body ) . '</span>' : '';
		$out .= '' !== $count ? '<span data-tah-card-count>' . esc_html( $count ) . '</span>' : '';
		$out .= '' !== $url ? '</a>' : '</div>';
		$out .= '</li>';
	}

	return $out . '</ul>';
}

/** الطبقات الزخرفية — aria-hidden دائمًا، فلا تصل قارئ الشاشة. */
function tahdheer_hero_layers( array $args ): string {
	$layers = (array) tahdheer_arg( $args, 'layers', array() );
	if ( ! $layers ) {
		return '';
	}

	$out = '<div data-tah-hero-layers aria-hidden="true">';

	foreach ( array_values( $layers ) as $i => $layer ) {
		$depth = (float) ( is_array( $layer ) ? ( $layer['depth'] ?? 1 ) : 1 );
		$url   = (string) ( is_array( $layer ) ? ( $layer['url'] ?? '' ) : '' );
		$style = '--tah-depth:' . max( 0.1, min( 3, $depth ) );

		if ( '' !== $url ) {
			$style .= ';background-image:url(' . esc_url_raw( $url ) . ')';
		}

		$out .= '<span data-tah-layer="' . esc_attr( (string) $i ) . '"'
			. ' style="' . esc_attr( $style ) . '"></span>';
	}

	return $out . '</div>';
}

/* ═══════════════════════ المُصيِّر ═══════════════════════ */

/**
 * تصيير هيرو بهدفه.
 *
 * @param string $objective معرّف الهدف في السجل.
 * @param array  $args      الوسائط — أسماؤها تطابق خصائص block.json.
 */
function tahdheer_render_hero( string $objective, array $args = array() ): string {
	$objectives = tahdheer_hero_objectives();

	if ( ! isset( $objectives[ $objective ] ) ) {
		return tahdheer_empty_state( 'hero', 'هدف معروف — ' . esc_html( $objective ) . ' غير مسجّل' );
	}

	$def      = $objectives[ $objective ];
	$skeleton = (string) $def['skeleton'];

	// هدف بلا مصدر حقيقي: يُعلَن للمشرف ولا يُخرج شيئًا للزائر.
	if ( ! empty( $def['needs'] ) ) {
		return tahdheer_empty_state( 'hero:' . $objective, (string) $def['needs'] );
	}

	foreach ( (array) ( tahdheer_hero_skeletons()[ $skeleton ]['deps'] ?? array() ) as $dep ) {
		if ( function_exists( 'tahdheer_runtime_require' ) ) {
			tahdheer_runtime_require( (string) $dep );
		}
	}

	// ترتيب الفتحات قابل للتغيير من المشرف؛ وأي فتحة غير مُعلَنة في الهدف تُرفض
	// فلا يعرض ترتيبٌ محرَّر محتوى لا ينتمي للهدف.
	$allowed = tahdheer_hero_slots( $objective );
	$order   = (array) tahdheer_arg( $args, 'slotOrder', $allowed );
	$order   = array_values( array_intersect( array_map( 'strval', $order ), $allowed ) );

	$panels = array( 'text' => '', 'media' => '', 'wide' => '', 'layers' => '' );
	$zone   = array(
		'media'    => 'media',
		'video'    => 'media',
		'media_fx' => 'media',
		'products' => 'wide',
		'cards'    => 'wide',
		'compare'  => 'wide',
		'steps'    => 'wide',
		'layers'   => 'layers',
	);

	foreach ( $order as $slot ) {
		$html = tahdheer_hero_slot( $slot, $args );
		if ( '' === $html ) {
			continue;
		}
		$panels[ $zone[ $slot ] ?? 'text' ] .= $html;
	}

	if ( '' === $panels['text'] && '' === $panels['media'] && '' === $panels['wide'] ) {
		return tahdheer_empty_state( 'hero:' . $objective, implode( ' · ', $allowed ) );
	}

	$extra = array(
		'data-tah-hero'  => $skeleton,
		'data-objective' => $objective,
		'data-bg'        => (string) tahdheer_arg( $args, 'bgMode', 'none' ),
		'data-reverse'   => ! empty( $args['layoutReverse'] ) ? '1' : '0',
	);

	if ( ! empty( $args['glow'] ) ) {
		$extra['data-glow'] = '1';
	}
	if ( ! empty( $args['grain'] ) ) {
		$extra['data-grain'] = '1';
	}
	if ( ! empty( $args['parallax'] ) ) {
		$extra['data-tah-reveal'] = '';
	}

	$style = array();

	$bg_image = (string) tahdheer_arg( $args, 'bgImage', '' );
	if ( '' !== $bg_image ) {
		$style[] = '--tah-hero-bg:url(' . esc_url_raw( $bg_image ) . ')';
	}

	$overlay = tahdheer_arg( $args, 'overlayOpacity', null );
	if ( null !== $overlay ) {
		$style[] = '--tah-hero-overlay:' . max( 0, min( 1, (float) $overlay ) );
	}

	$min_h = (string) tahdheer_arg( $args, 'minHeight', '' );
	if ( '' !== $min_h ) {
		$style[] = '--tah-hero-minh:' . preg_replace( '/[^0-9a-z.%]/i', '', $min_h );
	}

	$cols = (int) tahdheer_arg( $args, 'bentoCols', 0 );
	if ( $cols > 0 ) {
		$style[] = '--tah-hero-cols:' . max( 1, min( 6, $cols ) );
	}

	if ( $style ) {
		$extra['style'] = implode( ';', $style );
	}

	$out = '<section' . tahdheer_root_attrs( 'hero', $args, $extra ) . '>';

	$out .= '' !== $panels['layers'] ? $panels['layers'] : '';
	$out .= '<div data-tah-hero-inner>';
	$out .= '' !== $panels['text'] ? '<div data-tah-hero-text>' . $panels['text'] . '</div>' : '';
	$out .= '' !== $panels['media'] ? '<div data-tah-hero-figure>' . $panels['media'] . '</div>' : '';
	$out .= '</div>';
	$out .= '' !== $panels['wide'] ? '<div data-tah-hero-wide>' . $panels['wide'] . '</div>' : '';

	return $out . '</section>';
}

/**
 * تقرير الهيرو — يُحسَب من السجل لا يُكتب بيد.
 *
 * @return array{objectives:int,skeletons:int,ready:int,blocked:int,ratio:float}
 */
function tahdheer_hero_coverage(): array {
	$objectives = tahdheer_hero_objectives();
	$blocked    = 0;

	foreach ( $objectives as $objective ) {
		if ( ! empty( $objective['needs'] ) ) {
			++$blocked;
		}
	}

	$total     = count( $objectives );
	$skeletons = count( tahdheer_hero_skeletons() );

	return array(
		'objectives' => $total,
		'skeletons'  => $skeletons,
		'ready'      => $total - $blocked,
		'blocked'    => $blocked,
		'ratio'      => $skeletons > 0 ? round( $total / $skeletons, 1 ) : 0.0,
	);
}
