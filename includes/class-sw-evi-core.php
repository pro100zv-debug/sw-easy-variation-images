<?php
/**
 * Core logic. Nothing here runs on its own: every change to products happens
 * only from an explicit action in the admin panel ("Apply", "Undo") or right
 * after new variations are created in a product where the panel was used.
 *
 * @package SW_Easy_Variation_Images
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SW_EVI_Core {

	/** Product meta: array( 'attribute' => key ). Exists only where the panel was used. */
	const META = '_sw_evi_map';

	/** Product meta: snapshot of the last "Apply", for "Undo". */
	const UNDO_META = '_sw_evi_undo';

	/** WoodMart (legacy, deprecated in WoodMart 8.6): per-variation gallery, "1,2,3". */
	const WD_VARIATION_META = 'wd_additional_variation_images_data';

	/** WoodMart (older legacy storage): array on the parent product, variation_id => "1,2,3". */
	const WD_PARENT_META = 'woodmart_variation_gallery_data';

	/** "Additional Variation Images Gallery for WooCommerce" plugin: array of IDs on the variation. */
	const WVG_META = 'woo_variation_gallery_images';

	/** Detected gallery provider, cached per request. */
	private static $provider = null;

	public static function init() {
		// Intentionally no hooks on product save: the plugin never changes
		// products unless the shop manager presses a button in the panel.
	}

	/* ---------- Environment ---------- */

	/**
	 * Is the WoodMart theme (or a child theme of it) active?
	 */
	public static function woodmart_active() {
		$active = defined( 'WOODMART_THEME_DIR' ) || 'woodmart' === get_template();

		/**
		 * Force the WoodMart detection on or off.
		 *
		 * @param bool $active
		 */
		return (bool) apply_filters( 'sw_evi_woodmart_gallery', $active );
	}

	/**
	 * Where the additional images (gallery) of a variation are stored:
	 * - "wvg":         "Additional Variation Images Gallery for WooCommerce" plugin (if installed);
	 * - "woocommerce": native variation gallery of WooCommerce 11.1+ (also used by WoodMart 8.6+);
	 * - "woodmart":    WoodMart's own legacy gallery, only with WooCommerce older than 11.1;
	 * - "":            no variation gallery, only the main image is shared.
	 */
	public static function gallery_provider() {
		if ( null !== self::$provider ) {
			return self::$provider;
		}

		$provider = '';
		if ( class_exists( 'Woo_Variation_Gallery' ) || function_exists( 'woo_variation_gallery' ) ) {
			$provider = 'wvg';
		} elseif ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '11.1', '>=' ) ) {
			$provider = 'woocommerce';
		} elseif ( self::woodmart_active() ) {
			$provider = 'woodmart';
		}

		/**
		 * Change or disable the variation gallery support.
		 *
		 * @param string $provider "woocommerce", "woodmart", "wvg" or "" (off).
		 */
		$provider = (string) apply_filters( 'sw_evi_gallery_provider', $provider );

		self::$provider = in_array( $provider, array( 'woodmart', 'wvg', 'woocommerce' ), true ) ? $provider : '';
		return self::$provider;
	}

	/**
	 * Forget the detected provider (used by tests).
	 */
	public static function reset_provider() {
		self::$provider = null;
	}

	/* ---------- Attributes ---------- */

	/**
	 * Attributes of the product that are used for variations.
	 *
	 * @return array key => array( 'label' => string, 'values' => array( value_key => label ) )
	 */
	public static function variation_attributes( $product ) {
		$out = array();
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return $out;
		}

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute->get_variation() ) {
				continue;
			}
			$name   = $attribute->get_name();
			$key    = sanitize_title( $name );
			$values = array();

			if ( $attribute->is_taxonomy() ) {
				$terms = wc_get_product_terms( $product->get_id(), $name, array( 'fields' => 'all' ) );
				foreach ( $terms as $term ) {
					$values[ self::value_key( $term->slug ) ] = $term->name;
				}
			} else {
				foreach ( $attribute->get_options() as $option ) {
					$values[ self::value_key( $option ) ] = $option;
				}
			}

			$out[ $key ] = array(
				'label'  => wc_attribute_label( $name, $product ),
				'values' => $values,
			);
		}

		return $out;
	}

	/**
	 * Normalized key of an attribute value, the same for terms and custom values.
	 */
	public static function value_key( $value ) {
		return sanitize_title( (string) $value );
	}

	/**
	 * Was the panel already used in this product?
	 */
	public static function is_used( $product_id ) {
		$map = get_post_meta( $product_id, self::META, true );
		return is_array( $map ) && ! empty( $map['attribute'] );
	}

	/**
	 * Attribute used to share images: the saved one, otherwise the first one
	 * that looks like a color, otherwise the first variation attribute.
	 */
	public static function current_attribute( $product, $attributes = null ) {
		if ( null === $attributes ) {
			$attributes = self::variation_attributes( $product );
		}
		if ( ! $attributes ) {
			return '';
		}

		$map = get_post_meta( $product->get_id(), self::META, true );
		if ( is_array( $map ) && ! empty( $map['attribute'] ) && isset( $attributes[ $map['attribute'] ] ) ) {
			return $map['attribute'];
		}

		$default = '';
		foreach ( $attributes as $key => $data ) {
			if ( preg_match( '/colou?r|couleur|farbe|kolor|kleur|цвет|колір|\\bcor\\b/iu', $key . ' ' . $data['label'] ) ) {
				$default = $key;
				break;
			}
		}
		if ( '' === $default ) {
			$keys    = array_keys( $attributes );
			$default = $keys[0];
		}

		/**
		 * Default attribute used to share images between variations.
		 *
		 * @param string     $default    Attribute key, e.g. "pa_color".
		 * @param WC_Product $product
		 * @param array      $attributes Variation attributes of the product.
		 */
		$default = (string) apply_filters( 'sw_evi_default_attribute', $default, $product, $attributes );

		return isset( $attributes[ $default ] ) ? $default : '';
	}

	/* ---------- Variations ---------- */

	/**
	 * Value of an attribute in a variation ('' = "any").
	 */
	public static function variation_value( $variation, $attribute ) {
		$attrs = $variation->get_attributes();
		return isset( $attrs[ $attribute ] ) ? self::value_key( $attrs[ $attribute ] ) : '';
	}

	/**
	 * Variations grouped by the value of the attribute, in the admin order.
	 * Variations with "any" value are not included.
	 *
	 * @return array value_key => WC_Product_Variation[]
	 */
	public static function groups( $product, $attribute ) {
		$groups = array();
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
				continue;
			}
			$value = self::variation_value( $variation, $attribute );
			if ( '' === $value ) {
				continue;
			}
			$groups[ $value ][] = $variation;
		}
		return $groups;
	}

	/**
	 * Current state of a group of variations: the most common image and
	 * gallery, and how many variations match it.
	 *
	 * @return array( image, gallery, total, same, empty, different )
	 */
	public static function summarize( $variations ) {
		$has_gallery = '' !== self::gallery_provider();
		$images      = array();
		$galleries   = array();
		$states      = array();

		foreach ( $variations as $variation ) {
			$image   = (int) $variation->get_image_id();
			$gallery = $has_gallery ? self::get_gallery( $variation ) : array();
			$states[] = array( $image, $gallery );
			if ( $image ) {
				$images[ $image ] = isset( $images[ $image ] ) ? $images[ $image ] + 1 : 1;
			}
			if ( $gallery ) {
				$k               = implode( ',', $gallery );
				$galleries[ $k ] = isset( $galleries[ $k ] ) ? $galleries[ $k ] + 1 : 1;
			}
		}

		arsort( $images );
		arsort( $galleries );
		$main    = $images ? (int) key( $images ) : 0;
		$gallery = $galleries ? self::ids( (string) key( $galleries ) ) : array();

		$same  = 0;
		$empty = 0;
		foreach ( $states as $state ) {
			if ( $state[0] === $main && $state[1] === $gallery ) {
				++$same;
			} elseif ( ! $state[0] && ! $state[1] ) {
				++$empty;
			}
		}

		return array(
			'image'     => $main,
			'gallery'   => $gallery,
			'total'     => count( $variations ),
			'same'      => $same,
			'empty'     => $empty,
			'different' => count( $variations ) - $same - $empty,
		);
	}

	/* ---------- Variation gallery (additional images) ---------- */

	/**
	 * Additional images of a variation, from the active provider.
	 *
	 * @return int[]
	 */
	public static function get_gallery( $variation ) {
		switch ( self::gallery_provider() ) {
			case 'woocommerce':
				return self::ids( $variation->get_gallery_image_ids( 'edit' ) );

			case 'wvg':
				return self::ids( get_post_meta( $variation->get_id(), self::WVG_META, true ) );

			case 'woodmart':
				$raw = get_post_meta( $variation->get_id(), self::WD_VARIATION_META, true );
				if ( '' === $raw || false === $raw ) {
					$parent = get_post_meta( $variation->get_parent_id(), self::WD_PARENT_META, true );
					if ( is_array( $parent ) && isset( $parent[ $variation->get_id() ] ) ) {
						$raw = $parent[ $variation->get_id() ];
					}
				}
				return self::ids( $raw );
		}
		return array();
	}

	/**
	 * Save the additional images of a variation in the active provider.
	 * The variation object is saved by the caller.
	 *
	 * @param int[] $ids
	 */
	private static function set_gallery( $variation, $ids ) {
		$ids = self::ids( $ids );

		switch ( self::gallery_provider() ) {
			case 'woocommerce':
				$variation->set_gallery_image_ids( $ids );
				// WoodMart may still read its legacy field if that option is on.
				if ( self::woodmart_active() ) {
					self::set_woodmart_gallery( $variation, $ids );
				}
				break;

			case 'wvg':
				if ( $ids ) {
					update_post_meta( $variation->get_id(), self::WVG_META, $ids );
				} else {
					delete_post_meta( $variation->get_id(), self::WVG_META );
				}
				break;

			case 'woodmart':
				self::set_woodmart_gallery( $variation, $ids );
				break;
		}
	}

	/**
	 * @param int[] $ids
	 */
	private static function set_woodmart_gallery( $variation, $ids ) {
		$vid = $variation->get_id();

		if ( $ids ) {
			update_post_meta( $vid, self::WD_VARIATION_META, implode( ',', $ids ) );
		} else {
			delete_post_meta( $vid, self::WD_VARIATION_META );
		}

		$parent_id = $variation->get_parent_id();
		$parent    = get_post_meta( $parent_id, self::WD_PARENT_META, true );
		if ( ! is_array( $parent ) ) {
			if ( ! $ids ) {
				return;
			}
			$parent = array();
		}
		if ( $ids ) {
			$parent[ $vid ] = implode( ',', $ids );
		} else {
			unset( $parent[ $vid ] );
		}
		if ( $parent ) {
			update_post_meta( $parent_id, self::WD_PARENT_META, $parent );
		} else {
			delete_post_meta( $parent_id, self::WD_PARENT_META );
		}
	}

	/**
	 * Clean list of image attachment IDs from an array or "1,2,3".
	 *
	 * @return int[]
	 */
	public static function ids( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$ids = array();
		foreach ( $raw as $id ) {
			$id = absint( $id );
			if ( $id && ! in_array( $id, $ids, true ) && wp_attachment_is_image( $id ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/* ---------- Writing one variation ---------- */

	/**
	 * @param int        $image   0 = no image.
	 * @param int[]|null $gallery null = do not touch the gallery.
	 * @return bool Whether something changed.
	 */
	private static function write( $variation, $image, $gallery ) {
		$changed = false;

		if ( null !== $image && (int) $variation->get_image_id() !== $image ) {
			$variation->set_image_id( $image );
			$changed = true;
		}
		if ( null !== $gallery && '' !== self::gallery_provider() && self::get_gallery( $variation ) !== $gallery ) {
			self::set_gallery( $variation, $gallery );
			$changed = true;
		}
		if ( $changed ) {
			$variation->save();
		}
		return $changed;
	}

	/* ---------- Actions ---------- */

	/**
	 * How many variations of a value already have an image or gallery that
	 * differs from the new one (and would be replaced).
	 */
	public static function conflicts( $product, $attribute, $value, $image, $gallery ) {
		$groups  = self::groups( $product, $attribute );
		$gallery = self::ids( $gallery );
		$count   = 0;
		$check_g = '' !== self::gallery_provider();

		foreach ( isset( $groups[ $value ] ) ? $groups[ $value ] : array() as $variation ) {
			$cur_image   = (int) $variation->get_image_id();
			$cur_gallery = $check_g ? self::get_gallery( $variation ) : array();
			if ( ( $cur_image && $cur_image !== $image ) || ( $cur_gallery && $cur_gallery !== $gallery ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Apply the image and gallery of one value to its variations.
	 *
	 * @param string $mode "all" = make every variation exactly like this;
	 *                     "empty" = only fill variations that have no image / no gallery.
	 * @return int Number of variations changed.
	 */
	public static function apply( $product, $attribute, $value, $image, $gallery, $mode = 'all' ) {
		$attributes = self::variation_attributes( $product );
		if ( ! isset( $attributes[ $attribute ]['values'][ $value ] ) ) {
			return 0;
		}

		$image   = absint( $image );
		$image   = $image && wp_attachment_is_image( $image ) ? $image : 0;
		$gallery = self::ids( $gallery );
		$groups  = self::groups( $product, $attribute );
		$items   = isset( $groups[ $value ] ) ? $groups[ $value ] : array();
		$has_g   = '' !== self::gallery_provider();

		// Snapshot for "Undo".
		$snapshot = array();
		foreach ( $items as $variation ) {
			$snapshot[ $variation->get_id() ] = array(
				'image'   => (int) $variation->get_image_id(),
				'gallery' => $has_g ? self::get_gallery( $variation ) : array(),
			);
		}

		$changed = array();
		foreach ( $items as $variation ) {
			if ( 'empty' === $mode ) {
				$new_image   = ( $image && ! $variation->get_image_id() ) ? $image : null;
				$new_gallery = ( $gallery && $has_g && ! self::get_gallery( $variation ) ) ? $gallery : null;
			} else {
				$new_image   = $image;
				$new_gallery = $has_g ? $gallery : null;
			}
			if ( self::write( $variation, $new_image, $new_gallery ) ) {
				$changed[] = $variation->get_id();
			}
		}

		update_post_meta( $product->get_id(), self::META, array( 'attribute' => $attribute ) );

		if ( $changed ) {
			update_post_meta(
				$product->get_id(),
				self::UNDO_META,
				array(
					'attribute' => $attribute,
					'value'     => $value,
					'label'     => $attributes[ $attribute ]['values'][ $value ],
					'items'     => array_intersect_key( $snapshot, array_flip( $changed ) ),
				)
			);
			wc_delete_product_transients( $product->get_id() );
		}

		/**
		 * Fires after images were applied to the variations of a product.
		 *
		 * @param int    $product_id
		 * @param string $attribute
		 * @param int[]  $variation_ids Changed variations.
		 */
		do_action( 'sw_evi_applied', $product->get_id(), $attribute, $changed );

		return count( $changed );
	}

	/**
	 * @return array|null Last "Apply" that can be undone.
	 */
	public static function get_undo( $product_id ) {
		$undo = get_post_meta( $product_id, self::UNDO_META, true );
		return is_array( $undo ) && ! empty( $undo['items'] ) ? $undo : null;
	}

	/**
	 * Restore images and galleries as they were before the last "Apply".
	 *
	 * @return int Number of variations restored.
	 */
	public static function undo( $product ) {
		$undo = self::get_undo( $product->get_id() );
		if ( ! $undo ) {
			return 0;
		}

		$children = array_map( 'intval', $product->get_children() );
		$count    = 0;
		foreach ( $undo['items'] as $vid => $state ) {
			$vid = (int) $vid;
			if ( ! in_array( $vid, $children, true ) ) {
				continue;
			}
			$variation = wc_get_product( $vid );
			if ( ! $variation ) {
				continue;
			}
			$image   = absint( $state['image'] );
			$image   = $image && wp_attachment_is_image( $image ) ? $image : 0;
			$gallery = '' !== self::gallery_provider() ? self::ids( $state['gallery'] ) : null;
			if ( self::write( $variation, $image, $gallery ) ) {
				++$count;
			}
		}

		delete_post_meta( $product->get_id(), self::UNDO_META );
		wc_delete_product_transients( $product->get_id() );

		return $count;
	}

	/**
	 * After new variations were created: give an image and gallery to the
	 * variations that have none, copying them from the other variations with
	 * the same value. Only in products where the panel was already used.
	 *
	 * @return int Number of variations changed.
	 */
	public static function fill_new( $product ) {
		/**
		 * Turn the automatic filling of new variations on or off.
		 *
		 * @param bool $enabled
		 * @param int  $product_id
		 */
		if ( ! apply_filters( 'sw_evi_autofill', true, $product->get_id() ) || ! self::is_used( $product->get_id() ) ) {
			return 0;
		}

		$attribute = self::current_attribute( $product );
		if ( '' === $attribute ) {
			return 0;
		}

		$has_g = '' !== self::gallery_provider();
		$count = 0;

		foreach ( self::groups( $product, $attribute ) as $variations ) {
			$state = self::summarize( $variations );
			foreach ( $variations as $variation ) {
				$new_image   = ( $state['image'] && ! $variation->get_image_id() ) ? $state['image'] : null;
				$new_gallery = ( $has_g && $state['gallery'] && ! self::get_gallery( $variation ) ) ? $state['gallery'] : null;
				if ( self::write( $variation, $new_image, $new_gallery ) ) {
					++$count;
				}
			}
		}

		if ( $count ) {
			wc_delete_product_transients( $product->get_id() );
		}
		return $count;
	}
}
