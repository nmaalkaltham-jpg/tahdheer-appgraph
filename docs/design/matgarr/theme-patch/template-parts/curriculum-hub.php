<?php
/**
 * قالب الصفحة المحورية للمنهج.
 *
 * يُحمَّل تلقائيًا من inc/curriculum-hubs.php عند وجوده. آمن للتعديل بحرّية:
 * المنطق كله في الوحدة، وهذا العرض وحده.
 *
 * @package Tahdheer
 */

defined( 'ABSPATH' ) || exit;

$tah_hub = function_exists( 'tahdheer_hub_context' ) ? tahdheer_hub_context() : null;

if ( ! $tah_hub ) {
	get_template_part( '404' );
	return;
}

$tah_products = (array) $tah_hub['products'];
$tah_answer   = function_exists( 'tahdheer_hub_answer' ) ? tahdheer_hub_answer( $tah_hub ) : '';
$tah_parts    = isset( $tah_hub['parts'] ) && is_array( $tah_hub['parts'] ) ? $tah_hub['parts'] : array();

get_header();
?>

<main id="primary" class="site-main tah-hub">
	<div class="container">

		<?php if ( function_exists( 'tahdheer_breadcrumbs' ) ) : ?>
			<?php tahdheer_breadcrumbs(); ?>
		<?php endif; ?>

		<header class="tah-hub__head">
			<h1 class="tah-hub__title">
				<?php
				printf(
					/* translators: %s: hub label. */
					esc_html__( 'تحاضير %s', 'tahdheer' ),
					esc_html( (string) $tah_hub['label'] )
				);
				?>
			</h1>

			<?php if ( '' !== $tah_answer ) : ?>
				<p class="tah-hub__answer"><?php echo esc_html( $tah_answer ); ?></p>
			<?php endif; ?>

			<?php if ( $tah_parts ) : ?>
				<dl class="tah-hub__facts">
					<?php foreach ( $tah_parts as $tah_key => $tah_value ) : ?>
						<div class="tah-hub__fact">
							<dt><?php echo esc_html( ucfirst( (string) $tah_key ) ); ?></dt>
							<dd><?php echo esc_html( (string) $tah_value ); ?></dd>
						</div>
					<?php endforeach; ?>
					<div class="tah-hub__fact">
						<dt><?php esc_html_e( 'عدد الملفات', 'tahdheer' ); ?></dt>
						<dd><?php echo esc_html( number_format_i18n( count( $tah_products ) ) ); ?></dd>
					</div>
				</dl>
			<?php endif; ?>
		</header>

		<?php
		$tah_query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'post__in'       => array_map( 'absint', $tah_products ),
				'orderby'        => 'post__in',
				'posts_per_page' => 48,
				'no_found_rows'  => true,
			)
		);

		if ( $tah_query->have_posts() ) :
			?>
			<div class="tah-hub__grid products">
				<?php
				while ( $tah_query->have_posts() ) :
					$tah_query->the_post();
					wc_get_template_part( 'content', 'product' );
				endwhile;
				?>
			</div>
			<?php
			wp_reset_postdata();
		else :
			?>
			<p class="tah-hub__empty"><?php esc_html_e( 'لا ملفات منشورة في هذا القسم حاليًا.', 'tahdheer' ); ?></p>
			<?php
		endif;
		?>

		<?php
		/*
		 * عناقيد مجاورة: توزيع الروابط الداخلية هو نصف قيمة الصفحة المحورية.
		 * تُعرض العناقيد التي تشترك في جزء واحد على الأقل مع العنقود الحالي.
		 */
		$tah_siblings = array();
		if ( function_exists( 'tahdheer_hub_all' ) ) {
			$tah_current = tahdheer_hub_url( $tah_hub );
			foreach ( tahdheer_hub_all() as $tah_other ) {
				$tah_url = tahdheer_hub_url( $tah_other );
				if ( $tah_url === $tah_current ) {
					continue;
				}
				$tah_shared = array_intersect(
					array_map( 'strval', (array) ( $tah_other['parts'] ?? array() ) ),
					array_map( 'strval', $tah_parts )
				);
				if ( $tah_shared ) {
					$tah_siblings[] = array(
						'url'   => $tah_url,
						'label' => (string) $tah_other['label'],
						'count' => count( (array) $tah_other['products'] ),
					);
				}
			}
			$tah_siblings = array_slice( $tah_siblings, 0, 12 );
		}

		if ( $tah_siblings ) :
			?>
			<nav class="tah-hub__related" aria-label="<?php esc_attr_e( 'أقسام ذات صلة', 'tahdheer' ); ?>">
				<h2 class="tah-hub__related-title"><?php esc_html_e( 'أقسام ذات صلة', 'tahdheer' ); ?></h2>
				<ul class="tah-hub__chips">
					<?php foreach ( $tah_siblings as $tah_sibling ) : ?>
						<li>
							<a href="<?php echo esc_url( $tah_sibling['url'] ); ?>">
								<?php echo esc_html( $tah_sibling['label'] ); ?>
								<span class="tah-hub__chip-count"><?php echo esc_html( number_format_i18n( $tah_sibling['count'] ) ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</nav>
			<?php
		endif;
		?>

	</div>
</main>

<?php
get_footer();
