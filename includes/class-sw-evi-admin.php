<?php
/**
 * Admin: "Images by attribute" panel in the Variations tab and its AJAX actions.
 * The panel only shows data; products change only when a button is pressed.
 *
 * @package SW_Easy_Variation_Images
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SW_EVI_Admin {

	const NONCE = 'sw_evi';

	public static function init() {
		add_action( 'woocommerce_variable_product_before_variations', array( __CLASS__, 'print_panel' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_sw_evi_apply', array( __CLASS__, 'ajax_apply' ) );
		add_action( 'wp_ajax_sw_evi_undo', array( __CLASS__, 'ajax_undo' ) );
		add_action( 'wp_ajax_sw_evi_fill_new', array( __CLASS__, 'ajax_fill_new' ) );
		add_action( 'wp_ajax_sw_evi_panel', array( __CLASS__, 'ajax_panel' ) );
	}

	/* ---------- Assets ---------- */

	public static function assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}

		wp_enqueue_media();

		$url = plugin_dir_url( SW_EVI_FILE );
		wp_enqueue_style( 'sw-evi-admin', $url . 'assets/admin.css', array(), SW_EVI_VERSION );
		wp_enqueue_script( 'sw-evi-admin', $url . 'assets/admin.js', array( 'jquery' ), SW_EVI_VERSION, true );
		wp_localize_script(
			'sw-evi-admin',
			'swEviData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'chooseImage'  => __( 'Choose image', 'sw-easy-variation-images' ),
					'chooseImages' => __( 'Choose additional images', 'sw-easy-variation-images' ),
					'useImage'     => __( 'Use this image', 'sw-easy-variation-images' ),
					'useImages'    => __( 'Use these images', 'sw-easy-variation-images' ),
					'unsaved'      => __( 'There are unsaved changes in the variations. Save them first, then apply the images.', 'sw-easy-variation-images' ),
					'changed'      => __( 'Changed, not applied yet', 'sw-easy-variation-images' ),
					'working'      => __( 'Working…', 'sw-easy-variation-images' ),
					'error'        => __( 'Something went wrong. Reload the page and try again.', 'sw-easy-variation-images' ),
				),
			)
		);
	}

	/* ---------- Panel ---------- */

	/**
	 * Prints only a light placeholder: no variation is read on page load.
	 * The panel content is loaded by AJAX when it is opened.
	 */
	public static function print_panel() {
		global $post;
		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}
		$used = SW_EVI_Core::is_used( $post->ID );
		?>
		<div class="toolbar sw-evi-panel<?php echo $used ? ' is-autoload' : ' is-collapsed'; ?>" data-product-id="<?php echo esc_attr( $post->ID ); ?>">
			<div class="sw-evi-head">
				<strong class="sw-evi-title"><?php esc_html_e( 'Images by attribute', 'sw-easy-variation-images' ); ?></strong>
				<button type="button" class="button-link sw-evi-toggle" aria-expanded="false">
					<span class="sw-evi-toggle-hide"><?php esc_html_e( 'Hide', 'sw-easy-variation-images' ); ?></span>
					<span class="sw-evi-toggle-show"><?php esc_html_e( 'Show', 'sw-easy-variation-images' ); ?></span>
				</button>
			</div>
			<div class="sw-evi-body"><span class="spinner is-active sw-evi-loading"></span></div>
		</div>
		<?php
	}

	/**
	 * Human name of the place where additional images are stored.
	 */
	private static function provider_label() {
		switch ( SW_EVI_Core::gallery_provider() ) {
			case 'woocommerce':
				return __( 'WooCommerce variation gallery', 'sw-easy-variation-images' );
			case 'woodmart':
				return __( 'WoodMart variation gallery', 'sw-easy-variation-images' );
			case 'wvg':
				return __( 'Additional Variation Images Gallery plugin', 'sw-easy-variation-images' );
		}
		return '';
	}

	/**
	 * Inner HTML of the panel (loaded by AJAX).
	 *
	 * @param WC_Product $product
	 * @param string     $message Optional message to show under the header.
	 */
	public static function panel_html( $product, $message = '' ) {
		$attributes = SW_EVI_Core::variation_attributes( $product );
		if ( ! $attributes ) {
			return '<p class="description">' . esc_html__( 'Add attributes used for variations first and save them.', 'sw-easy-variation-images' ) . '</p>';
		}

		$current     = SW_EVI_Core::current_attribute( $product, $attributes );
		$has_gallery = '' !== SW_EVI_Core::gallery_provider();
		$undo        = SW_EVI_Core::get_undo( $product->get_id() );
		$variations  = SW_EVI_Core::read_variations( $product->get_id() );

		ob_start();
		?>
			<p class="sw-evi-attribute-row">
				<label class="sw-evi-attribute-label">
					<span><?php esc_html_e( 'Share images by:', 'sw-easy-variation-images' ); ?></span>
					<select class="sw-evi-attribute">
						<?php foreach ( $attributes as $key => $data ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $current ); ?>><?php echo esc_html( $data['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</p>
				<p class="description">
					<?php esc_html_e( 'The panel shows the current images of the variations. Nothing changes until you press “Apply” in a row: only the variations of that value are changed.', 'sw-easy-variation-images' ); ?>
					<?php if ( $has_gallery ) : ?>
						<br>
						<?php
						/* translators: %s: where the additional images are stored. */
						echo esc_html( sprintf( __( 'Additional images are saved to: %s.', 'sw-easy-variation-images' ), self::provider_label() ) );
						?>
					<?php else : ?>
						<br><?php esc_html_e( 'This site has no variation gallery, so only the main image of the variations is shared.', 'sw-easy-variation-images' ); ?>
					<?php endif; ?>
				</p>

				<?php if ( $message ) : ?>
					<div class="sw-evi-message" role="status"><?php echo esc_html( $message ); ?></div>
				<?php endif; ?>

				<?php if ( $undo ) : ?>
					<div class="sw-evi-undo-bar">
						<span>
							<?php
							/* translators: 1: attribute value, e.g. "Blanco"; 2: number of variations. */
							echo esc_html( sprintf( _n( 'Last change: %1$s, %2$d variation.', 'Last change: %1$s, %2$d variations.', count( $undo['items'] ), 'sw-easy-variation-images' ), $undo['label'], count( $undo['items'] ) ) );
							?>
						</span>
						<button type="button" class="button button-small sw-evi-undo"><?php esc_html_e( 'Undo', 'sw-easy-variation-images' ); ?></button>
					</div>
				<?php endif; ?>

				<?php
				foreach ( $attributes as $key => $data ) :
					$groups = SW_EVI_Core::group_ids( $variations, $key );
					$any    = SW_EVI_Core::count_any( $variations, $key );
					?>
					<div class="sw-evi-rows" data-attribute="<?php echo esc_attr( $key ); ?>" <?php echo $key === $current ? '' : 'hidden'; ?>>
						<table class="widefat striped sw-evi-table">
							<thead>
								<tr>
									<th><?php echo esc_html( $data['label'] ); ?></th>
									<th><?php esc_html_e( 'Main image', 'sw-easy-variation-images' ); ?></th>
									<?php if ( $has_gallery ) : ?>
										<th><?php esc_html_e( 'Additional images (gallery)', 'sw-easy-variation-images' ); ?></th>
									<?php endif; ?>
									<th><?php esc_html_e( 'Variations', 'sw-easy-variation-images' ); ?></th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $data['values'] as $value => $label ) :
									$ids   = isset( $groups[ $value ] ) ? $groups[ $value ] : array();
									$state = SW_EVI_Core::summarize( array_intersect_key( $variations, array_flip( $ids ) ) );
									?>
									<tr data-value="<?php echo esc_attr( $value ); ?>">
										<td class="sw-evi-label"><?php echo esc_html( $label ); ?></td>
										<td>
											<div class="sw-evi-cell" data-multiple="0">
												<input type="hidden" class="sw-evi-ids" value="<?php echo esc_attr( $state['image'] ? $state['image'] : '' ); ?>">
												<span class="sw-evi-thumbs"><?php self::thumbs( $state['image'] ? array( $state['image'] ) : array() ); ?></span>
												<span class="sw-evi-buttons">
													<button type="button" class="button button-small sw-evi-choose"><?php esc_html_e( 'Choose', 'sw-easy-variation-images' ); ?></button>
													<button type="button" class="button-link sw-evi-remove" <?php echo $state['image'] ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'sw-easy-variation-images' ); ?></button>
												</span>
											</div>
										</td>
										<?php if ( $has_gallery ) : ?>
											<td>
												<div class="sw-evi-cell" data-multiple="1">
													<input type="hidden" class="sw-evi-ids" value="<?php echo esc_attr( implode( ',', $state['gallery'] ) ); ?>">
													<span class="sw-evi-thumbs"><?php self::thumbs( $state['gallery'] ); ?></span>
													<span class="sw-evi-buttons">
														<button type="button" class="button button-small sw-evi-choose"><?php esc_html_e( 'Choose', 'sw-easy-variation-images' ); ?></button>
														<button type="button" class="button-link sw-evi-remove" <?php echo $state['gallery'] ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'sw-easy-variation-images' ); ?></button>
													</span>
												</div>
											</td>
										<?php endif; ?>
										<td class="sw-evi-state"><?php self::state_text( $state ); ?></td>
										<td class="sw-evi-row-actions">
											<?php if ( $state['total'] ) : ?>
												<button type="button" class="button sw-evi-apply">
													<?php
													/* translators: %d: number of variations. */
													echo esc_html( sprintf( _n( 'Apply to %d variation', 'Apply to %d variations', $state['total'], 'sw-easy-variation-images' ), $state['total'] ) );
													?>
												</button>
												<div class="sw-evi-confirm" hidden>
													<p class="sw-evi-confirm-text"></p>
													<button type="button" class="button button-primary button-small sw-evi-apply-all"><?php esc_html_e( 'Replace in all', 'sw-easy-variation-images' ); ?></button>
													<button type="button" class="button button-small sw-evi-apply-empty"><?php esc_html_e( 'Only empty ones', 'sw-easy-variation-images' ); ?></button>
													<button type="button" class="button-link sw-evi-cancel"><?php esc_html_e( 'Cancel', 'sw-easy-variation-images' ); ?></button>
												</div>
											<?php endif; ?>
											<span class="spinner"></span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<?php if ( $any ) : ?>
							<p class="description sw-evi-any">
								<?php
								/* translators: %d: number of variations. */
								echo esc_html( sprintf( _n( '%d variation uses “Any” for this attribute and is not changed.', '%d variations use “Any” for this attribute and are not changed.', $any, 'sw-easy-variation-images' ), $any ) );
								?>
							</p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
		<?php
		return ob_get_clean();
	}

	private static function state_text( $state ) {
		if ( ! $state['total'] ) {
			esc_html_e( 'No variations', 'sw-easy-variation-images' );
			return;
		}
		$parts = array(
			/* translators: %d: number of variations. */
			sprintf( _n( '%d variation', '%d variations', $state['total'], 'sw-easy-variation-images' ), $state['total'] ),
		);
		if ( $state['same'] === $state['total'] ) {
			$parts[] = __( 'all the same', 'sw-easy-variation-images' );
		} else {
			if ( $state['different'] ) {
				/* translators: %d: number of variations. */
				$parts[] = sprintf( _n( '%d with other images', '%d with other images', $state['different'], 'sw-easy-variation-images' ), $state['different'] );
			}
			if ( $state['empty'] ) {
				/* translators: %d: number of variations. */
				$parts[] = sprintf( _n( '%d without images', '%d without images', $state['empty'], 'sw-easy-variation-images' ), $state['empty'] );
			}
		}
		echo esc_html( implode( ' · ', $parts ) );
	}

	/**
	 * @param int[] $ids
	 */
	private static function thumbs( $ids ) {
		foreach ( $ids as $id ) {
			$src = wp_get_attachment_image_url( $id, 'thumbnail' );
			if ( $src ) {
				echo '<img src="' . esc_url( $src ) . '" alt="" width="40" height="40">';
			}
		}
	}

	/* ---------- AJAX ---------- */

	/**
	 * Checks nonce and capability, returns the product or ends the request.
	 */
	private static function ajax_product() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : null;

		if ( ! $product || ! $product->is_type( 'variable' ) || ! current_user_can( 'edit_product', $product_id ) ) {
			wp_send_json_error( null, 403 );
		}
		return $product;
	}

	private static function fresh_panel( $product, $message = '' ) {
		return self::panel_html( $product, $message );
	}

	/**
	 * Runs an AJAX action and turns any PHP error into a JSON error, so a
	 * problem never breaks the product page.
	 */
	private static function guard( $callback ) {
		try {
			call_user_func( $callback );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	public static function ajax_apply() {
		self::guard( array( __CLASS__, 'do_apply' ) );
	}

	public static function do_apply() {
		$product   = self::ajax_product();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in ajax_product().
		$attribute = isset( $_POST['attribute'] ) ? sanitize_title( wp_unslash( $_POST['attribute'] ) ) : '';
		$value     = isset( $_POST['value'] ) ? SW_EVI_Core::value_key( wp_unslash( $_POST['value'] ) ) : '';
		$image     = isset( $_POST['image'] ) ? absint( $_POST['image'] ) : 0;
		$image     = $image && wp_attachment_is_image( $image ) ? $image : 0;
		$gallery   = isset( $_POST['gallery'] ) ? SW_EVI_Core::valid_images( sanitize_text_field( wp_unslash( $_POST['gallery'] ) ) ) : array();
		$mode      = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : '';
		// phpcs:enable

		if ( ! in_array( $mode, array( 'all', 'empty' ), true ) ) {
			$conflicts = SW_EVI_Core::conflicts( $product->get_id(), $attribute, $value, $image, $gallery );
			if ( $conflicts ) {
				wp_send_json_success(
					array(
						'confirm' => true,
						/* translators: %d: number of variations. */
						'message' => sprintf( _n( '%d variation already has other images. Replace them too?', '%d variations already have other images. Replace them too?', $conflicts, 'sw-easy-variation-images' ), $conflicts ),
					)
				);
			}
			$mode = 'all';
		}

		$count = SW_EVI_Core::apply( $product, $attribute, $value, $image, $gallery, $mode );

		wp_send_json_success(
			array(
				'changed' => $count,
				'panel'   => self::fresh_panel(
					$product,
					$count
						/* translators: %d: number of variations. */
						? sprintf( _n( 'Done: %d variation updated.', 'Done: %d variations updated.', $count, 'sw-easy-variation-images' ), $count )
						: __( 'Nothing to change: the variations already had these images.', 'sw-easy-variation-images' )
				),
			)
		);
	}

	public static function ajax_undo() {
		self::guard( array( __CLASS__, 'do_undo' ) );
	}

	public static function do_undo() {
		$product = self::ajax_product();
		$count   = SW_EVI_Core::undo( $product );

		wp_send_json_success(
			array(
				'changed' => $count,
				/* translators: %d: number of variations. */
				'panel'   => self::fresh_panel( $product, sprintf( _n( 'Undone: %d variation restored.', 'Undone: %d variations restored.', $count, 'sw-easy-variation-images' ), $count ) ),
			)
		);
	}

	public static function ajax_fill_new() {
		self::guard( array( __CLASS__, 'do_fill_new' ) );
	}

	public static function do_fill_new() {
		$product = self::ajax_product();
		$count   = SW_EVI_Core::fill_new( $product );

		wp_send_json_success(
			array(
				'changed' => $count,
				'panel'   => self::fresh_panel(
					$product,
					/* translators: %d: number of variations. */
					$count ? sprintf( _n( 'New variations: %d got the images of their value.', 'New variations: %d got the images of their value.', $count, 'sw-easy-variation-images' ), $count ) : ''
				),
			)
		);
	}

	public static function ajax_panel() {
		self::guard( array( __CLASS__, 'do_panel' ) );
	}

	public static function do_panel() {
		$product = self::ajax_product();
		wp_send_json_success( array( 'panel' => self::panel_html( $product ) ) );
	}
}
