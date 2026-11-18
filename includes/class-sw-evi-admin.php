<?php
/**
 * Admin: "Images by attribute" panel in the Variations tab and its AJAX actions.
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
		add_action( 'wp_ajax_sw_evi_autofill', array( __CLASS__, 'ajax_autofill' ) );
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
					'unsavedPanel' => __( 'Images chosen but not applied yet.', 'sw-easy-variation-images' ),
					'working'      => __( 'Applying…', 'sw-easy-variation-images' ),
					'error'        => __( 'Something went wrong. Reload the page and try again.', 'sw-easy-variation-images' ),
				),
			)
		);
	}

	/* ---------- Panel ---------- */

	public static function print_panel() {
		global $post;
		if ( ! $post ) {
			return;
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return;
		}
		echo self::panel_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building.
	}

	/**
	 * HTML of the panel. Also returned by AJAX to refresh it after changes.
	 */
	public static function panel_html( $product ) {
		$attributes = SW_EVI_Core::variation_attributes( $product );
		if ( ! $attributes ) {
			return '';
		}

		$current  = SW_EVI_Core::current_attribute( $product, $attributes );
		$map      = SW_EVI_Core::get_map( $product->get_id() );
		$has_gallery = '' !== SW_EVI_Core::gallery_provider();

		ob_start();
		?>
		<div class="toolbar sw-evi-panel" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
			<div class="sw-evi-head">
				<strong class="sw-evi-title"><?php esc_html_e( 'Images by attribute', 'sw-easy-variation-images' ); ?></strong>
				<label class="sw-evi-attribute-label">
					<span><?php esc_html_e( 'Share images by:', 'sw-easy-variation-images' ); ?></span>
					<select class="sw-evi-attribute">
						<?php foreach ( $attributes as $key => $data ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $current ); ?>><?php echo esc_html( $data['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="button" class="button-link sw-evi-toggle" aria-expanded="true">
					<span class="sw-evi-toggle-hide"><?php esc_html_e( 'Hide', 'sw-easy-variation-images' ); ?></span>
					<span class="sw-evi-toggle-show"><?php esc_html_e( 'Show', 'sw-easy-variation-images' ); ?></span>
				</button>
			</div>

			<div class="sw-evi-body">
				<?php
				foreach ( $attributes as $key => $data ) :
					$groups = SW_EVI_Core::groups( $product, $key );
					$saved  = $map['attribute'] === $key ? $map['values'] : array();
					$any    = 0;
					foreach ( $product->get_children() as $variation_id ) {
						$variation = wc_get_product( $variation_id );
						if ( $variation && '' === SW_EVI_Core::variation_value( $variation, $key ) ) {
							++$any;
						}
					}
					?>
					<div class="sw-evi-rows" data-attribute="<?php echo esc_attr( $key ); ?>" <?php echo $key === $current ? '' : 'hidden'; ?>>
						<table class="widefat striped sw-evi-table">
							<thead>
								<tr>
									<th><?php echo esc_html( $data['label'] ); ?></th>
									<th><?php esc_html_e( 'Image', 'sw-easy-variation-images' ); ?></th>
									<?php if ( $has_gallery ) : ?>
										<th>
											<?php
											if ( 'woodmart' === SW_EVI_Core::gallery_provider() ) {
												esc_html_e( 'Additional images (WoodMart)', 'sw-easy-variation-images' );
											} else {
												esc_html_e( 'Additional images', 'sw-easy-variation-images' );
											}
											?>
										</th>
									<?php endif; ?>
									<th class="sw-evi-count-col"><?php esc_html_e( 'Variations', 'sw-easy-variation-images' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $data['values'] as $value => $label ) :
									$variations = isset( $groups[ $value ] ) ? $groups[ $value ] : array();
									list( $image, $gallery ) = self::prefill( $saved, $value, $variations, $has_gallery );
									?>
									<tr data-value="<?php echo esc_attr( $value ); ?>">
										<td class="sw-evi-label"><?php echo esc_html( $label ); ?></td>
										<td>
											<div class="sw-evi-cell" data-multiple="0" data-cleared="0">
												<input type="hidden" class="sw-evi-ids" value="<?php echo esc_attr( $image ? $image : '' ); ?>">
												<span class="sw-evi-thumbs"><?php self::thumbs( $image ? array( $image ) : array() ); ?></span>
												<span class="sw-evi-buttons">
													<button type="button" class="button button-small sw-evi-choose"><?php esc_html_e( 'Choose', 'sw-easy-variation-images' ); ?></button>
													<button type="button" class="button-link sw-evi-remove" <?php echo $image ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'sw-easy-variation-images' ); ?></button>
												</span>
											</div>
										</td>
										<?php if ( $has_gallery ) : ?>
											<td>
												<div class="sw-evi-cell" data-multiple="1" data-cleared="0">
													<input type="hidden" class="sw-evi-ids" value="<?php echo esc_attr( implode( ',', $gallery ) ); ?>">
													<span class="sw-evi-thumbs"><?php self::thumbs( $gallery ); ?></span>
													<span class="sw-evi-buttons">
														<button type="button" class="button button-small sw-evi-choose"><?php esc_html_e( 'Choose', 'sw-easy-variation-images' ); ?></button>
														<button type="button" class="button-link sw-evi-remove" <?php echo $gallery ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'sw-easy-variation-images' ); ?></button>
													</span>
												</div>
											</td>
										<?php endif; ?>
										<td class="sw-evi-count-col"><?php echo esc_html( count( $variations ) ); ?></td>
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

				<p class="sw-evi-actions">
					<button type="button" class="button button-primary sw-evi-apply"><?php esc_html_e( 'Apply to variations', 'sw-easy-variation-images' ); ?></button>
					<span class="spinner"></span>
					<span class="sw-evi-status" role="status"></span>
				</p>
				<p class="description">
					<?php esc_html_e( 'Choose an image for each value and click “Apply to variations”: every variation with that value gets it, replacing its current image. New variations without an image get it automatically when you save them.', 'sw-easy-variation-images' ); ?>
				</p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Image and gallery to show for a value: the saved ones, otherwise those
	 * of the first variation with this value that has them.
	 *
	 * @return array( int, int[] )
	 */
	private static function prefill( $saved, $value, $variations, $has_gallery ) {
		$image   = isset( $saved[ $value ]['image'] ) ? absint( $saved[ $value ]['image'] ) : 0;
		$gallery = isset( $saved[ $value ]['gallery'] ) ? SW_EVI_Core::ids( $saved[ $value ]['gallery'] ) : array();

		if ( $image && ! wp_attachment_is_image( $image ) ) {
			$image = 0;
		}

		foreach ( $variations as $variation ) {
			if ( ! $image && $variation->get_image_id() ) {
				$image = (int) $variation->get_image_id();
			}
			if ( $has_gallery && ! $gallery ) {
				$gallery = SW_EVI_Core::get_gallery( $variation );
			}
		}

		return array( $image, $has_gallery ? $gallery : array() );
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

	public static function ajax_apply() {
		$product   = self::ajax_product();
		$attribute = isset( $_POST['attribute'] ) ? sanitize_title( wp_unslash( $_POST['attribute'] ) ) : '';
		$raw_rows  = isset( $_POST['rows'] ) ? json_decode( wp_unslash( $_POST['rows'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		$rows      = array();

		if ( is_array( $raw_rows ) ) {
			foreach ( $raw_rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['value'] ) ) {
					continue;
				}
				$rows[ SW_EVI_Core::value_key( $row['value'] ) ] = array(
					'image'         => isset( $row['image'] ) ? absint( $row['image'] ) : 0,
					'gallery'       => isset( $row['gallery'] ) ? (string) $row['gallery'] : '',
					'clear_image'   => ! empty( $row['clear_image'] ),
					'clear_gallery' => ! empty( $row['clear_gallery'] ),
				);
			}
		}

		$result = SW_EVI_Core::apply( $product, $attribute, $rows );
		$count  = $result['variations'];

		wp_send_json_success(
			array(
				'changed' => $count,
				'message' => $count
					/* translators: %d: number of variations. */
					? sprintf( _n( 'Done: %d variation updated.', 'Done: %d variations updated.', $count, 'sw-easy-variation-images' ), $count )
					: __( 'Done: all variations already had these images.', 'sw-easy-variation-images' ),
				'panel'   => self::panel_html( wc_get_product( $product->get_id() ) ),
			)
		);
	}

	public static function ajax_autofill() {
		$product = self::ajax_product();
		$count   = SW_EVI_Core::autofill( $product->get_id() );

		wp_send_json_success(
			array(
				'changed' => $count,
				'panel'   => self::panel_html( wc_get_product( $product->get_id() ) ),
			)
		);
	}

	public static function ajax_panel() {
		$product = self::ajax_product();

		wp_send_json_success(
			array(
				'panel' => self::panel_html( $product ),
			)
		);
	}
}
