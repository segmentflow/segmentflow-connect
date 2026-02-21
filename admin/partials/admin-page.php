<?php
/**
 * Segmentflow admin page shell template.
 *
 * Renders the page wrapper with tab navigation. Tab content is loaded
 * from separate partial files based on the active tab.
 *
 * @package Segmentflow_Connect
 *
 * @var string $current_tab The currently active tab slug.
 * @var array  $tabs        Available tabs (slug => label).
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap segmentflow-settings">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php if ( count( $tabs ) > 1 ) : ?>
		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_slug => $tab_label ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=segmentflow&tab=' . $tab_slug ) ); ?>"
				   class="nav-tab <?php echo $current_tab === $tab_slug ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html( $tab_label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<div class="segmentflow-tab-content">
		<?php
		switch ( $current_tab ) {
			case 'settings':
				include SEGMENTFLOW_PATH . 'admin/partials/admin-settings.php';
				break;
			case 'woocommerce':
				if ( Segmentflow_Helper::is_woocommerce_active() ) {
					include SEGMENTFLOW_PATH . 'admin/partials/admin-woocommerce.php';
				}
				break;
			case 'connection':
			default:
				include SEGMENTFLOW_PATH . 'admin/partials/admin-connection.php';
				break;
		}
		?>
	</div>
</div>
