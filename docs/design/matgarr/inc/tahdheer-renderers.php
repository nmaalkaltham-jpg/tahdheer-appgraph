<?php
/**
 * Tahdheer — مُصيِّرات المكوّنات المفتوحة.
 *
 * اثنا عشر مكوّنًا تفتح ٨٨ اختيارًا من الـ٣٠٠. مصنّفة بما أتاحها:
 *
 *  بلا تبعية غائبة (٧):  answer · quote · glossary · timeline · story · people · toc
 *  فتحتها أزمنة التشغيل (٣):  gallery · media_fx · nav_bar
 *  تقرأ من product-data (٢):  compare · facts
 *
 * القواعد التي يفرضها الطلب وتُنفَّذ هنا حرفيًا:
 *  ١) لا بيانات مُلفَّقة. المُصيِّر لا يخترع صفًّا ولا تقييمًا ولا رقمًا — يعرض
 *     ما أُعطي فقط، ويُخرج حالة فراغ صريحة إن لم يُعطَ.
 *  ٢) الدلالة قبل الشكل. الحقائق <dl>، والمقارنة <table> بـ<th scope>، والخط
 *     الزمني <ol>، والاقتباس <blockquote><cite>. لأن هذه المكوّنات مقروءة
 *     بالآلة أكثر من غيرها، والشكل يُبنى فوق الدلالة لا بدلًا عنها.
 *  ٣) التخطيط خاصية لا ملفّ. هذا هو أصل ٣٠٠ من ٤٥.
 *  ٤) كل مخرج مُهرَّب، وكل زمن تشغيل يُطلَب بالتصيير لا بالتحميل العام.
 *
 * @package Tahdheer
 */

defined( 'ABSPATH' ) || exit;

/** قراءة وسيط مع افتراض. */
function tahdheer_arg( array $args, string $key, $default = '' ) {
	return array_key_exists( $key, $args ) && '' !== $args[ $key ] ? $args[ $key ] : $default;
}

/** سمات الجذر المشتركة لكل مكوّن — الهوية والتخطيط وصنف مخصّص. */
function tahdheer_root_attrs( string $component, array $args, array $extra = array() ): string {
	$attrs = array(
		'data-tah-c'  => $component,
		'data-layout' => (string) tahdheer_arg( $args, 'layout', 'default' ),
	);

	if ( '' !== (string) tahdheer_arg( $args, 'id', '' ) ) {
		$attrs['id'] = (string) $args['id'];
	}
	if ( '' !== (string) tahdheer_arg( $args, 'class', '' ) ) {
		$attrs['class'] = (string) $args['class'];
	}

	$attrs = array_merge( $attrs, $extra );
	$out   = '';

	foreach ( $attrs as $key => $value ) {
		if ( null === $value || '' === $value ) {
			continue;
		}
		$out .= ' ' . esc_attr( $key ) . '="' . esc_attr( (string) $value ) . '"';
	}

	return $out;
}

/**
 * حالة الفراغ — صريحة لا صامتة.
 *
 * مكوّن بلا محتوى يجب أن يقول ذلك للمشرف في المحرّر، ولا يُخرج شيئًا للزائر.
 * الصمت التام يُخفي عيبًا في التهيئة إلى أن يراه عميل.
 */
function tahdheer_empty_state( string $component, string $need ): string {
	if ( ! is_admin() && ! ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) ) {
		return '';
	}

	return '<div data-tah-empty data-tah-c="' . esc_attr( $component ) . '">'
		. '<strong>' . esc_html( $component ) . '</strong> '
		. esc_html__( 'بلا محتوى — المطلوب: ', 'tahdheer' ) . esc_html( $need )
		. '</div>';
}

/** عنوان قسم اختياري بمستوى قابل للضبط — لا يفرض h2 ولا يُنشئ h1 ثانيًا. */
function tahdheer_heading( array $args, string $default_level = 'h2' ): string {
	$title = (string) tahdheer_arg( $args, 'title', '' );
	if ( '' === $title ) {
		return '';
	}

	$level = (string) tahdheer_arg( $args, 'level', $default_level );
	$level = in_array( $level, array( 'h2', 'h3', 'h4', 'h5', 'div' ), true ) ? $level : $default_level;
	$sub   = (string) tahdheer_arg( $args, 'subtitle', '' );

	$out = '<' . $level . ' data-tah-title>' . esc_html( $title ) . '</' . $level . '>';
	if ( '' !== $sub ) {
		$out .= '<p data-tah-sub>' . esc_html( $sub ) . '</p>';
	}

	return '<header data-tah-head>' . $out . '</header>';
}

/* ═══════════════════════ ١ · كتلة الإجابة — ١١ اختيارًا ═══════════════════════ */

/**
 * إجابة مباشرة · تعريف · توصية · ملخّص كيف · مشكلة وحل · ملخّص خبير.
 *
 * أهم قرار هنا: الإجابة نصّ مرئي في الصفحة لا بيانات مُهيكلة مخفيّة. لأن ما
 * يُخفى عن الزائر ويُعرض للآلة هو تحديدًا ما يمنعه الطلب وتعاقب عليه محرّكات
 * البحث. الترميز المُهيكل يُبنى من نفس النصّ في مكان آخر — لا هنا.
 *
 * @param array $args answer · title · question · points · source · layout.
 */
function tahdheer_render_answer( array $args ): string {
	$answer = (string) tahdheer_arg( $args, 'answer', '' );
	if ( '' === $answer ) {
		return tahdheer_empty_state( 'answer', 'answer' );
	}

	$layout   = (string) tahdheer_arg( $args, 'layout', 'direct' );
	$question = (string) tahdheer_arg( $args, 'question', '' );
	$points   = (array) tahdheer_arg( $args, 'points', array() );
	$source   = (string) tahdheer_arg( $args, 'source', '' );

	$out = '<section' . tahdheer_root_attrs( 'answer', $args ) . '>';

	if ( '' !== $question ) {
		$level = 'define' === $layout ? 'h3' : 'h2';
		$out  .= '<' . $level . ' data-tah-q>' . esc_html( $question ) . '</' . $level . '>';
	}

	$out .= '<p data-tah-a>' . esc_html( $answer ) . '</p>';

	if ( $points ) {
		// مشكلة/حل تحتاج ترتيبًا دلاليًّا؛ البقية لا.
		$tag  = in_array( $layout, array( 'howto', 'problem' ), true ) ? 'ol' : 'ul';
		$out .= '<' . $tag . ' data-tah-points>';
		foreach ( $points as $point ) {
			$label = is_array( $point ) ? (string) ( $point['label'] ?? '' ) : (string) $point;
			$body  = is_array( $point ) ? (string) ( $point['body'] ?? '' ) : '';
			if ( '' === $label ) {
				continue;
			}
			$out .= '<li><strong>' . esc_html( $label ) . '</strong>';
			$out .= '' !== $body ? ' <span>' . esc_html( $body ) . '</span>' : '';
			$out .= '</li>';
		}
		$out .= '</' . $tag . '>';
	}

	if ( '' !== $source ) {
		$out .= '<p data-tah-src>' . esc_html( $source ) . '</p>';
	}

	return $out . '</section>';
}

/* ═══════════════════════ ٢ · لوح الحقائق — ٢٢ اختيارًا ═══════════════════════ */

/**
 * حقائق مُهيكلة · مواصفات · خصائص كيان · إيجابيات وسلبيات · اعتبارات شراء.
 *
 * أكثر مكوّن يُخدَم في المكتبة (٢٢ اختيارًا) وأكثره حساسية للتلفيق. لذلك:
 * لا صفّ يُخترع، ولا «غير متوفّر» يُكتب مكان قيمة غائبة — الصفّ الفارغ يُحذف.
 *
 * البنية <dl> لا <table>: هذه أزواج مصطلح/قيمة لا شبكة ذات محورين، وقارئ
 * الشاشة يقرؤها «الصيغة: PPTX» بدل التنقّل في خلايا.
 *
 * @param array $args rows · title · layout (dl|cards|pills|table|proscons) · pros · cons.
 */
function tahdheer_render_facts( array $args ): string {
	$layout = (string) tahdheer_arg( $args, 'layout', 'dl' );
	$rows   = (array) tahdheer_arg( $args, 'rows', array() );
	$pros   = (array) tahdheer_arg( $args, 'pros', array() );
	$cons   = (array) tahdheer_arg( $args, 'cons', array() );

	if ( 'proscons' === $layout ) {
		if ( ! $pros && ! $cons ) {
			return tahdheer_empty_state( 'facts', 'pros / cons' );
		}

		$out = '<section' . tahdheer_root_attrs( 'facts', $args ) . '>' . tahdheer_heading( $args );
		$out .= '<div data-tah-pc>';

		foreach ( array( 'pro' => $pros, 'con' => $cons ) as $kind => $list ) {
			if ( ! $list ) {
				continue;
			}
			$heading = 'pro' === $kind
				? __( 'يناسبك إذا', 'tahdheer' )
				: __( 'لا يناسبك إذا', 'tahdheer' );

			$out .= '<div data-tah-' . esc_attr( $kind ) . '><h3>' . esc_html( $heading ) . '</h3><ul>';
			foreach ( $list as $item ) {
				$text = (string) ( is_array( $item ) ? ( $item['label'] ?? '' ) : $item );
				if ( '' !== $text ) {
					$out .= '<li>' . esc_html( $text ) . '</li>';
				}
			}
			$out .= '</ul></div>';
		}

		return $out . '</div></section>';
	}

	// تنقية قبل العدّ: صفّ بلا قيمة لا يُعرَض ولا يُحسَب.
	$clean = array();
	foreach ( $rows as $row ) {
		$key   = (string) ( is_array( $row ) ? ( $row['key'] ?? '' ) : '' );
		$value = (string) ( is_array( $row ) ? ( $row['value'] ?? '' ) : (string) $row );
		if ( '' === $key || '' === $value ) {
			continue;
		}
		$clean[] = array(
			'key'   => $key,
			'value' => $value,
			'note'  => (string) ( is_array( $row ) ? ( $row['note'] ?? '' ) : '' ),
		);
	}

	if ( ! $clean ) {
		return tahdheer_empty_state( 'facts', 'rows[key,value]' );
	}

	$out  = '<section' . tahdheer_root_attrs( 'facts', $args ) . '>' . tahdheer_heading( $args );

	if ( 'table' === $layout ) {
		$out .= '<table data-tah-facts-t><tbody>';
		foreach ( $clean as $row ) {
			$out .= '<tr><th scope="row">' . esc_html( $row['key'] ) . '</th>'
				. '<td>' . esc_html( $row['value'] ) . '</td></tr>';
		}
		$out .= '</tbody></table>';
	} else {
		$out .= '<dl data-tah-facts>';
		foreach ( $clean as $row ) {
			$out .= '<div data-tah-row>';
			$out .= '<dt>' . esc_html( $row['key'] ) . '</dt>';
			$out .= '<dd>' . esc_html( $row['value'] );
			$out .= '' !== $row['note'] ? ' <small>' . esc_html( $row['note'] ) . '</small>' : '';
			$out .= '</dd></div>';
		}
		$out .= '</dl>';
	}

	return $out . '</section>';
}

/* ═══════════════════════ ٣ · جدول المقارنة — ٦ اختيارات ═══════════════════════ */

/**
 * مقارنة منتجات · ميزات · ملخّص مقارنة.
 *
 * جدول حقيقي بمحورين: <th scope="col"> للأعمدة و<th scope="row"> للصفوف —
 * فيعرف قارئ الشاشة أي خلية تنتمي لأي منتج. أشيع خطأ في جداول المقارنة هو
 * بناؤها بـ<div>، فتصير شبكة بصرية بلا أي معنى للآلة.
 *
 * @param array $args items[label,values] · features · title · highlight.
 */
function tahdheer_render_compare( array $args ): string {
	$items    = (array) tahdheer_arg( $args, 'items', array() );
	$features = (array) tahdheer_arg( $args, 'features', array() );

	if ( ! $items || ! $features ) {
		return tahdheer_empty_state( 'compare', 'items + features' );
	}

	$highlight = (string) tahdheer_arg( $args, 'highlight', '' );
	$caption   = (string) tahdheer_arg( $args, 'title', '' );

	$out  = '<section' . tahdheer_root_attrs( 'compare', $args ) . '>';
	$out .= '<div data-tah-scroll><table data-tah-cmp>';

	if ( '' !== $caption ) {
		$out .= '<caption>' . esc_html( $caption ) . '</caption>';
	}

	$out .= '<thead><tr><td></td>';
	foreach ( $items as $item ) {
		$label = (string) ( is_array( $item ) ? ( $item['label'] ?? '' ) : (string) $item );
		$on    = '' !== $highlight && $label === $highlight;
		$out  .= '<th scope="col"' . ( $on ? ' data-tah-on="1"' : '' ) . '>' . esc_html( $label ) . '</th>';
	}
	$out .= '</tr></thead><tbody>';

	foreach ( $features as $index => $feature ) {
		$name = (string) ( is_array( $feature ) ? ( $feature['label'] ?? '' ) : (string) $feature );
		if ( '' === $name ) {
			continue;
		}

		$out .= '<tr><th scope="row">' . esc_html( $name ) . '</th>';

		foreach ( $items as $item ) {
			$values = (array) ( is_array( $item ) ? ( $item['values'] ?? array() ) : array() );
			$raw    = $values[ $index ] ?? '';

			// المنطقي يصير رمزًا مع نصّ بديل — الرمز وحده لا يُقرأ.
			if ( is_bool( $raw ) ) {
				$out .= '<td data-tah-bool="' . ( $raw ? '1' : '0' ) . '">'
					. '<span aria-hidden="true">' . ( $raw ? '✓' : '—' ) . '</span>'
					. '<span data-tah-sr>' . esc_html( $raw ? __( 'متوفّر', 'tahdheer' ) : __( 'غير متوفّر', 'tahdheer' ) ) . '</span>'
					. '</td>';
			} else {
				$text = (string) $raw;
				$out .= '<td>' . ( '' === $text ? '<span aria-hidden="true">—</span>' : esc_html( $text ) ) . '</td>';
			}
		}

		$out .= '</tr>';
	}

	return $out . '</tbody></table></div></section>';
}

/* ═══════════════════════ ٤ · الخط الزمني — ٨ اختيارات ═══════════════════════ */

/**
 * خط زمني · محطّات · خطوات · عملية · جدول عروض.
 *
 * <ol> لأن الترتيب هو المعنى — والخطوة ٣ قبل ٢ خطأ لا تفضيل عرض.
 *
 * @param array $args steps[label,body,meta,state] · title · layout (vertical|horizontal|steps).
 */
function tahdheer_render_timeline( array $args ): string {
	$steps = (array) tahdheer_arg( $args, 'steps', array() );
	if ( ! $steps ) {
		return tahdheer_empty_state( 'timeline', 'steps[label]' );
	}

	$out  = '<section' . tahdheer_root_attrs( 'timeline', $args ) . '>' . tahdheer_heading( $args );
	$out .= '<ol data-tah-tl>';

	foreach ( $steps as $i => $step ) {
		$label = (string) ( is_array( $step ) ? ( $step['label'] ?? '' ) : (string) $step );
		if ( '' === $label ) {
			continue;
		}

		$body  = (string) ( is_array( $step ) ? ( $step['body'] ?? '' ) : '' );
		$meta  = (string) ( is_array( $step ) ? ( $step['meta'] ?? '' ) : '' );
		$state = (string) ( is_array( $step ) ? ( $step['state'] ?? '' ) : '' );

		$out .= '<li data-tah-step' . ( '' !== $state ? ' data-tah-state="' . esc_attr( $state ) . '"' : '' ) . '>';
		$out .= '<span data-tah-n aria-hidden="true">' . esc_html( (string) ( $i + 1 ) ) . '</span>';
		$out .= '<div data-tah-body>';
		$out .= '<h3>' . esc_html( $label ) . '</h3>';
		$out .= '' !== $meta ? '<span data-tah-meta>' . esc_html( $meta ) . '</span>' : '';
		$out .= '' !== $body ? '<p>' . esc_html( $body ) . '</p>' : '';
		$out .= '</div></li>';
	}

	return $out . '</ol></section>';
}

/* ═══════════════════════ ٥ · كتلة القصّة — ١١ اختيارًا ═══════════════════════ */

/**
 * قصّة العلامة · من نحن · القيم · الرسالة · الرؤية · كلمة المؤسّس · وعد.
 *
 * @param array $args body · title · eyebrow · media · attribution · layout (split|centered|quote).
 */
function tahdheer_render_story( array $args ): string {
	$body = (string) tahdheer_arg( $args, 'body', '' );
	if ( '' === $body ) {
		return tahdheer_empty_state( 'story', 'body' );
	}

	$eyebrow = (string) tahdheer_arg( $args, 'eyebrow', '' );
	$media   = (array) tahdheer_arg( $args, 'media', array() );
	$attrib  = (string) tahdheer_arg( $args, 'attribution', '' );

	$out  = '<section' . tahdheer_root_attrs( 'story', $args ) . '>';
	$out .= '<div data-tah-text>';
	$out .= '' !== $eyebrow ? '<span data-tah-eyebrow>' . esc_html( $eyebrow ) . '</span>' : '';
	$out .= tahdheer_heading( $args );

	foreach ( preg_split( '/\n{2,}/', $body ) as $para ) {
		$para = trim( $para );
		if ( '' !== $para ) {
			$out .= '<p>' . esc_html( $para ) . '</p>';
		}
	}

	$out .= '' !== $attrib ? '<p data-tah-attrib>' . esc_html( $attrib ) . '</p>' : '';
	$out .= '</div>';

	if ( ! empty( $media['url'] ) ) {
		// alt فارغ مقصود عندما تكون الصورة زخرفية — لا نخترع وصفًا.
		$out .= '<figure data-tah-media><img src="' . esc_url( (string) $media['url'] ) . '"'
			. ' alt="' . esc_attr( (string) ( $media['alt'] ?? '' ) ) . '" loading="lazy" decoding="async" /></figure>';
	}

	return $out . '</section>';
}

/* ═══════════════════════ ٦ · الاقتباس — اختيار واحد ═══════════════════════ */

/**
 * @param array $args quote · author · role · layout (plain|card|large).
 */
function tahdheer_render_quote( array $args ): string {
	$quote = (string) tahdheer_arg( $args, 'quote', '' );
	if ( '' === $quote ) {
		return tahdheer_empty_state( 'quote', 'quote' );
	}

	$author = (string) tahdheer_arg( $args, 'author', '' );
	$role   = (string) tahdheer_arg( $args, 'role', '' );

	$out  = '<figure' . tahdheer_root_attrs( 'quote', $args ) . '>';
	$out .= '<blockquote><p>' . esc_html( $quote ) . '</p></blockquote>';

	if ( '' !== $author ) {
		$out .= '<figcaption><cite>' . esc_html( $author ) . '</cite>';
		$out .= '' !== $role ? '<span>' . esc_html( $role ) . '</span>' : '';
		$out .= '</figcaption>';
	}

	return $out . '</figure>';
}

/* ═══════════════════════ ٧ · المسرد — اختيار واحد ═══════════════════════ */

/**
 * @param array $args terms[term,define,anchor] · title.
 */
function tahdheer_render_glossary( array $args ): string {
	$terms = (array) tahdheer_arg( $args, 'terms', array() );
	if ( ! $terms ) {
		return tahdheer_empty_state( 'glossary', 'terms[term,define]' );
	}

	$out  = '<section' . tahdheer_root_attrs( 'glossary', $args ) . '>' . tahdheer_heading( $args );
	$out .= '<dl data-tah-gl>';

	foreach ( $terms as $entry ) {
		$term   = (string) ( is_array( $entry ) ? ( $entry['term'] ?? '' ) : '' );
		$define = (string) ( is_array( $entry ) ? ( $entry['define'] ?? '' ) : '' );
		if ( '' === $term || '' === $define ) {
			continue;
		}

		$anchor = (string) ( is_array( $entry ) ? ( $entry['anchor'] ?? '' ) : '' );
		$id     = '' !== $anchor ? ' id="' . esc_attr( sanitize_title( $anchor ) ) . '"' : '';

		$out .= '<div data-tah-row' . $id . '>';
		$out .= '<dt>' . esc_html( $term ) . '</dt>';
		$out .= '<dd>' . esc_html( $define ) . '</dd>';
		$out .= '</div>';
	}

	return $out . '</dl></section>';
}

/* ═══════════════════════ ٨ · الأشخاص — اختياران ═══════════════════════ */

/**
 * @param array $args people[name,role,photo,alt,link] · title · layout (grid|row|list).
 */
function tahdheer_render_people( array $args ): string {
	$people = (array) tahdheer_arg( $args, 'people', array() );
	if ( ! $people ) {
		return tahdheer_empty_state( 'people', 'people[name]' );
	}

	$out  = '<section' . tahdheer_root_attrs( 'people', $args ) . '>' . tahdheer_heading( $args );
	$out .= '<ul data-tah-people>';

	foreach ( $people as $person ) {
		$name = (string) ( is_array( $person ) ? ( $person['name'] ?? '' ) : (string) $person );
		if ( '' === $name ) {
			continue;
		}

		$role  = (string) ( is_array( $person ) ? ( $person['role'] ?? '' ) : '' );
		$photo = (string) ( is_array( $person ) ? ( $person['photo'] ?? '' ) : '' );
		$link  = (string) ( is_array( $person ) ? ( $person['link'] ?? '' ) : '' );

		$out .= '<li data-tah-person>';

		if ( '' !== $photo ) {
			// صورة الشخص وصفها اسمه — لا نصّ بديل مخترع.
			$out .= '<img data-tah-mslot src="' . esc_url( $photo ) . '" alt="' . esc_attr( $name ) . '"'
				. ' loading="lazy" decoding="async" />';
		}

		$out .= '<div data-tah-body><h3>';
		$out .= '' !== $link
			? '<a href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a>'
			: esc_html( $name );
		$out .= '</h3>';
		$out .= '' !== $role ? '<span>' . esc_html( $role ) . '</span>' : '';
		$out .= '</div></li>';
	}

	return $out . '</ul></section>';
}

/* ═══════════════════════ ٩ · فهرس المحتوى — ٣ اختيارات ═══════════════════════ */

/**
 * فهرس المحتوى · فهرس الصفحة · قفز الصفحة.
 *
 * يطلب rt-observer ليُفعِّل القسم الحالي أثناء التمرير.
 *
 * @param array $args entries[label,anchor,children] · title · layout (list|sticky|inline).
 */
function tahdheer_render_toc( array $args ): string {
	$entries = (array) tahdheer_arg( $args, 'entries', array() );
	if ( ! $entries ) {
		return tahdheer_empty_state( 'toc', 'entries[label,anchor]' );
	}

	tahdheer_component_boot( 'toc' );

	$sticky = 'sticky' === (string) tahdheer_arg( $args, 'layout', 'list' );
	$extra  = array(
		'data-tah-sectionnav' => '1',
		'data-watch'          => (string) tahdheer_arg( $args, 'watch', '[data-tah-section]' ),
		'data-offset'         => (string) tahdheer_arg( $args, 'offset', '0' ),
		'aria-label'          => (string) tahdheer_arg( $args, 'title', __( 'فهرس المحتوى', 'tahdheer' ) ),
	);

	if ( $sticky ) {
		$extra['data-tah-sticky'] = '1';
	}

	$out  = '<nav' . tahdheer_root_attrs( 'toc', $args, $extra ) . '>';
	$out .= tahdheer_heading( $args, 'h2' );
	$out .= '<ol data-tah-toc>';

	foreach ( $entries as $entry ) {
		$label  = (string) ( is_array( $entry ) ? ( $entry['label'] ?? '' ) : (string) $entry );
		$anchor = (string) ( is_array( $entry ) ? ( $entry['anchor'] ?? '' ) : '' );
		if ( '' === $label || '' === $anchor ) {
			continue;
		}

		$out .= '<li><a href="#' . esc_attr( sanitize_title( $anchor ) ) . '">' . esc_html( $label ) . '</a>';

		$children = (array) ( is_array( $entry ) ? ( $entry['children'] ?? array() ) : array() );
		if ( $children ) {
			$out .= '<ol>';
			foreach ( $children as $child ) {
				$c_label  = (string) ( is_array( $child ) ? ( $child['label'] ?? '' ) : (string) $child );
				$c_anchor = (string) ( is_array( $child ) ? ( $child['anchor'] ?? '' ) : '' );
				if ( '' !== $c_label && '' !== $c_anchor ) {
					$out .= '<li><a href="#' . esc_attr( sanitize_title( $c_anchor ) ) . '">'
						. esc_html( $c_label ) . '</a></li>';
				}
			}
			$out .= '</ol>';
		}

		$out .= '</li>';
	}

	return $out . '</ol></nav>';
}

/* ═══════════════════════ ١٠ · شريط التنقّل — ٩ اختيارات ═══════════════════════ */

/**
 * تنقّل بالمراسي · ملتصق · الأقسام · التصنيفات · المجموعات · ضخم · عائم · سفلي.
 *
 * @param array $args links[label,url,current] · layout · sticky · offset.
 */
function tahdheer_render_nav_bar( array $args ): string {
	$links = (array) tahdheer_arg( $args, 'links', array() );
	if ( ! $links ) {
		return tahdheer_empty_state( 'nav_bar', 'links[label,url]' );
	}

	$layout  = (string) tahdheer_arg( $args, 'layout', 'bar' );
	$anchors = false;

	foreach ( $links as $link ) {
		$url = (string) ( is_array( $link ) ? ( $link['url'] ?? '' ) : '' );
		if ( 0 === strpos( $url, '#' ) ) {
			$anchors = true;
			break;
		}
	}

	$extra = array(
		'aria-label' => (string) tahdheer_arg( $args, 'label', __( 'تنقّل', 'tahdheer' ) ),
	);

	// المراقب يُطلب فقط عند وجود مراسي فعلًا — لا زمن تشغيل بلا عمل.
	if ( $anchors ) {
		tahdheer_component_boot( 'nav_bar' );
		$extra['data-tah-sectionnav'] = '1';
		$extra['data-watch']          = (string) tahdheer_arg( $args, 'watch', '[data-tah-section]' );
		$extra['data-offset']         = (string) tahdheer_arg( $args, 'offset', '0' );
	}

	if ( ! empty( $args['sticky'] ) || in_array( $layout, array( 'sticky', 'floating', 'bottom' ), true ) ) {
		if ( ! $anchors ) {
			tahdheer_runtime_require( 'rt-observer' );
		}
		$extra['data-tah-sticky'] = '1';
	}

	$out  = '<nav' . tahdheer_root_attrs( 'nav_bar', $args, $extra ) . '>';
	$out .= '<ul data-tah-nav>';

	foreach ( $links as $link ) {
		$label = (string) ( is_array( $link ) ? ( $link['label'] ?? '' ) : (string) $link );
		$url   = (string) ( is_array( $link ) ? ( $link['url'] ?? '' ) : '' );
		if ( '' === $label || '' === $url ) {
			continue;
		}

		$current = ! empty( $link['current'] );
		$count   = (string) ( is_array( $link ) ? ( $link['count'] ?? '' ) : '' );

		$out .= '<li><a href="' . esc_url( $url ) . '"';
		$out .= $current ? ' aria-current="page" data-tah-on="1"' : '';
		$out .= '>' . esc_html( $label );
		$out .= '' !== $count ? ' <span data-tah-count>' . esc_html( $count ) . '</span>' : '';
		$out .= '</a></li>';
	}

	return $out . '</ul></nav>';
}

/* ═══════════════════════ ١١ · معرض الوسائط — ١٠ اختيارات ═══════════════════════ */

/**
 * شبكة · معرض · متراصّ · سلايدر · كاروسيل · معرض فيديو · كولاج.
 *
 * التخطيطات المتمرِّرة تستدعي rt-gallery؛ الشبكية لا تحتاج JS أبدًا. هذا هو
 * بند الأداء عمليًّا: شبكة صور لا تُحمِّل زمن تشغيل لا تستخدمه.
 *
 * @param array $args items[url,alt,caption,w,h] · layout · columns · title.
 */
function tahdheer_render_gallery( array $args ): string {
	$items = (array) tahdheer_arg( $args, 'items', array() );
	if ( ! $items ) {
		return tahdheer_empty_state( 'gallery', 'items[url]' );
	}

	$layout   = (string) tahdheer_arg( $args, 'layout', 'grid' );
	$scrolls  = in_array( $layout, array( 'slider', 'carousel', 'filmstrip' ), true );
	$columns  = (int) tahdheer_arg( $args, 'columns', 3 );
	$columns  = max( 1, min( 6, $columns ) );

	if ( $scrolls ) {
		tahdheer_component_boot( 'gallery' );
	}

	$extra = array( 'style' => '--tah-cols:' . $columns );

	if ( $scrolls ) {
		$extra['data-tah-gallery'] = '1';
		$extra['data-loop']        = ! empty( $args['loop'] ) ? '1' : '0';
		$extra['data-label']       = (string) tahdheer_arg( $args, 'label', __( 'معرض صور', 'tahdheer' ) );
	}

	$out  = '<section' . tahdheer_root_attrs( 'gallery', $args, $extra ) . '>';
	$out .= tahdheer_heading( $args );
	$out .= $scrolls ? '<div data-tah-track>' : '<ul data-tah-grid>';

	foreach ( $items as $item ) {
		$url = (string) ( is_array( $item ) ? ( $item['url'] ?? '' ) : (string) $item );
		if ( '' === $url ) {
			continue;
		}

		$alt     = (string) ( is_array( $item ) ? ( $item['alt'] ?? '' ) : '' );
		$caption = (string) ( is_array( $item ) ? ( $item['caption'] ?? '' ) : '' );
		$width   = (int) ( is_array( $item ) ? ( $item['w'] ?? 0 ) : 0 );
		$height  = (int) ( is_array( $item ) ? ( $item['h'] ?? 0 ) : 0 );

		$out .= $scrolls ? '<div data-tah-slide>' : '<li>';
		$out .= '<figure>';
		$out .= '<img data-tah-mslot src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"';
		// الأبعاد تمنع قفزة التخطيط — تُكتب فقط إن عُرفت، ولا تُخمَّن.
		$out .= $width > 0 ? ' width="' . $width . '"' : '';
		$out .= $height > 0 ? ' height="' . $height . '"' : '';
		$out .= ' loading="lazy" decoding="async" />';
		$out .= '' !== $caption ? '<figcaption>' . esc_html( $caption ) . '</figcaption>' : '';
		$out .= '</figure>';
		$out .= $scrolls ? '</div>' : '</li>';
	}

	$out .= $scrolls ? '</div>' : '</ul>';

	if ( $scrolls ) {
		$out .= '<div data-tah-ctrl>';
		$out .= '<button type="button" data-tah-prev aria-label="' . esc_attr__( 'السابق', 'tahdheer' ) . '"></button>';
		$out .= '<button type="button" data-tah-next aria-label="' . esc_attr__( 'التالي', 'tahdheer' ) . '"></button>';
		$out .= '<div data-tah-dots></div>';
		$out .= '</div>';
	}

	return $out . '</section>';
}

/* ═══════════════════════ ١٢ · الوسيط التفاعلي — ٤ اختيارات ═══════════════════════ */

/**
 * صورة بنقاط · قبل وبعد · معرض بالتمرير.
 *
 * @param array $args layout (hotspots|compare|hover) + بيانات كل نمط.
 */
function tahdheer_render_media_fx( array $args ): string {
	$layout = (string) tahdheer_arg( $args, 'layout', 'hotspots' );

	tahdheer_component_boot( 'media_fx' );

	if ( 'compare' === $layout ) {
		$before = (array) tahdheer_arg( $args, 'before', array() );
		$after  = (array) tahdheer_arg( $args, 'after', array() );

		if ( empty( $before['url'] ) || empty( $after['url'] ) ) {
			return tahdheer_empty_state( 'media_fx', 'before.url + after.url' );
		}

		$extra = array(
			'data-tah-compare' => '1',
			'data-start'       => (string) tahdheer_arg( $args, 'start', '50' ),
			'data-label'       => (string) tahdheer_arg( $args, 'label', __( 'مقارنة قبل وبعد', 'tahdheer' ) ),
		);

		$out  = '<div' . tahdheer_root_attrs( 'media_fx', $args, $extra ) . '>';
		$out .= '<img data-tah-mslot data-tah-before src="' . esc_url( (string) $before['url'] ) . '"'
			. ' alt="' . esc_attr( (string) ( $before['alt'] ?? '' ) ) . '" loading="lazy" decoding="async" />';
		$out .= '<img data-tah-mslot data-tah-after src="' . esc_url( (string) $after['url'] ) . '"'
			. ' alt="' . esc_attr( (string) ( $after['alt'] ?? '' ) ) . '" loading="lazy" decoding="async" />';

		return $out . '</div>';
	}

	if ( 'hover' === $layout ) {
		$frames = (array) tahdheer_arg( $args, 'frames', array() );
		if ( count( $frames ) < 2 ) {
			return tahdheer_empty_state( 'media_fx', 'frames[url] × 2+' );
		}

		$extra = array(
			'data-tah-hovergallery' => '1',
			'data-label'            => (string) tahdheer_arg( $args, 'label', __( 'زوايا متعدّدة', 'tahdheer' ) ),
		);

		$out = '<div' . tahdheer_root_attrs( 'media_fx', $args, $extra ) . '>';
		foreach ( $frames as $frame ) {
			$url = (string) ( is_array( $frame ) ? ( $frame['url'] ?? '' ) : (string) $frame );
			if ( '' === $url ) {
				continue;
			}
			$out .= '<img data-tah-mslot data-tah-frame src="' . esc_url( $url ) . '"'
				. ' alt="' . esc_attr( (string) ( is_array( $frame ) ? ( $frame['alt'] ?? '' ) : '' ) ) . '"'
				. ' loading="lazy" decoding="async" />';
		}

		return $out . '</div>';
	}

	$image = (array) tahdheer_arg( $args, 'image', array() );
	$spots = (array) tahdheer_arg( $args, 'spots', array() );

	if ( empty( $image['url'] ) || ! $spots ) {
		return tahdheer_empty_state( 'media_fx', 'image.url + spots[x,y,label]' );
	}

	$out  = '<div' . tahdheer_root_attrs( 'media_fx', $args, array( 'data-tah-hotspots' => '1' ) ) . '>';

	// الإطار هو مرجع الإحداثيات، لا الجذر: x و y مكتوبان كنسبة من الصورة،
	// وإن بقيا معلّقين بالجذر فأي حاضنة flex/grid تمدّ الجذر (align-items:stretch)
	// تفصلهما عن الصورة صامتةً. الإطار يلفُّ الصورة وحدها فيبقى المرجع صادقًا.
	$out .= '<figure data-tah-hotspot-frame>';
	$out .= '<img data-tah-mslot src="' . esc_url( (string) $image['url'] ) . '"'
		. ' alt="' . esc_attr( (string) ( $image['alt'] ?? '' ) ) . '" loading="lazy" decoding="async" />';

	$base = 'tah-spot-' . wp_rand( 1000, 9999 );

	foreach ( array_values( $spots ) as $i => $spot ) {
		$label = (string) ( is_array( $spot ) ? ( $spot['label'] ?? '' ) : (string) $spot );
		if ( '' === $label ) {
			continue;
		}

		$tip_id = $base . '-' . $i;
		$x      = max( 0, min( 100, (float) ( is_array( $spot ) ? ( $spot['x'] ?? 50 ) : 50 ) ) );
		$y      = max( 0, min( 100, (float) ( is_array( $spot ) ? ( $spot['y'] ?? 50 ) : 50 ) ) );

		$out .= '<button type="button" data-tah-hotspot'
			. ' data-x="' . esc_attr( (string) $x ) . '" data-y="' . esc_attr( (string) $y ) . '"'
			. ' aria-controls="' . esc_attr( $tip_id ) . '"'
			. ' aria-label="' . esc_attr( $label ) . '">'
			. esc_html( (string) ( $i + 1 ) ) . '</button>';

		$body  = (string) ( is_array( $spot ) ? ( $spot['body'] ?? '' ) : '' );
		$out  .= '<div id="' . esc_attr( $tip_id ) . '" data-tah-tip>';
		$out  .= '<strong>' . esc_html( $label ) . '</strong>';
		$out  .= '' !== $body ? '<span>' . esc_html( $body ) . '</span>' : '';
		$out  .= '</div>';
	}

	return $out . '</figure></div>';
}

/* ═══════════════════════ الموزّع ═══════════════════════ */

/**
 * تصيير مكوّن بمعرّفه — نقطة الدخول الوحيدة للبلوكات والقوالب.
 *
 * @param string $component معرّف المكوّن في السجل.
 * @param array  $args      وسائط المُصيِّر.
 */
function tahdheer_component_fn( string $component ): string {
	$snake = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $component );

	return strtolower( str_replace( '-', '_', (string) $snake ) );
}

function tahdheer_render( string $component, array $args = array() ): string {
	$fn = 'tahdheer_render_' . tahdheer_component_fn( $component );

	if ( ! function_exists( $fn ) ) {
		/**
		 * تصيير مكوّن غير مُنفَّذ في النواة — تستخدمه الإضافات.
		 *
		 * @param string $html      المخرج.
		 * @param string $component المعرّف.
		 * @param array  $args      الوسائط.
		 */
		return (string) apply_filters( 'tahdheer_render_fallback', '', $component, $args );
	}

	return (string) call_user_func( $fn, $args );
}

/** المكوّنات المُنفَّذة فعلًا — يقرأها الحارس ولوحة التشخيص. */
function tahdheer_rendered_components(): array {
	$out = array();

	foreach ( array_keys( tahdheer_components() ) as $id ) {
		if ( function_exists( 'tahdheer_render_' . tahdheer_component_fn( $id ) ) ) {
			$out[] = $id;
		}
	}

	return $out;
}

/**
 * عدد الاختيارات التي يفتحها ما نُفِّذ — محسوب من المكتبة لا مكتوبًا بيد.
 *
 * @return array{rendered:int,choices:int,total:int}
 */
function tahdheer_render_coverage(): array {
	$done    = tahdheer_rendered_components();
	$library = tahdheer_component_library();
	$hits    = 0;

	foreach ( $library as $entry ) {
		if ( in_array( (string) $entry['component'], $done, true ) ) {
			++$hits;
		}
	}

	return array(
		'rendered' => count( $done ),
		'choices'  => $hits,
		'total'    => count( $library ),
	);
}
