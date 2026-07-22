<?php
/**
 * Single member template.
 *
 * Deliberately minimal: no sidebar, no author byline, no post date — just the
 * business name and the member details. The theme's header and footer are
 * still used, so the page keeps site branding and navigation.
 *
 * To customize, copy this file into your theme as single-paccc_member.php;
 * the plugin detects that and steps aside in favor of yours.
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div id="primary" class="paccc-single-wrap content-area">
	<main id="main" class="paccc-single-main site-main" role="main">
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<article <?php post_class( 'paccc-single-article' ); ?>>
				<header class="paccc-single-header">
					<h1 class="paccc-single-title"><?php the_title(); ?></h1>
				</header>

				<div class="paccc-single-content">
					<?php the_content(); ?>
				</div>
			</article>
			<?php
		endwhile;
		?>
	</main>
</div>

<?php
get_footer();
