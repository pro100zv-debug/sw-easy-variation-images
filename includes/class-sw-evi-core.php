<?php
/**
 * Core logic: groups variations by an attribute value and shares images
 * (main image + WoodMart additional gallery) inside each group.
 *
 * @package SW_Easy_Variation_Images
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SW_EVI_Core {

	/** Product meta with the chosen attribute and the image per value. */
	const META = '_sw_evi_map';

	/** WoodMart: per-variation gallery, comma-separated attachment IDs. */
	const WD_VARIATION_META = 'wd_additional_variation_images_data';

	/** WoodMart (older storage): array on the parent product, variation_id => "1,2,3". */
	const WD_PARENT_META = 'woodmart_variation_gallery_data';

	/** "Additional Variation Images Gallery for WooCommerce" plugin: array of IDs on the variation. */
	const WVG_META = 'woo_variation_gallery_images';

	/** Detected gallery provider, cached per request. */
	private static $provider = null;

	/** True while this plugin itself is writing variations (prevents loops). */
	private static $busy = false;

	public static function init() {
		// "Save changes" inside the Variations tab.
		add_action( 'woocommerce_ajax_save_product_variations', array( __CLASS__, 'on_variations_saved' ), 20 );
		// Main "Update" button of the product, quick/bulk edit.
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_updated' ), 20 );
	}

	/* ---------- Hooks ---------- */

	public static function on_variations_saved( $product_id ) {
		if ( current_user_can( 'edit_product', $product_id ) ) {
			self::autofill( $product_id );
		}
	}

	public static function on_product_updated( $product_id ) {
		// Only when a shop manager saves the product, never on the storefront.
		if ( ! is_admin() || ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}
		self::autofill( $product_id );
	}

	/* ---------- Environment ---------- */

	/**
	 * Is the WoodMart theme (or a child theme of it) active?
	 */
	public static function woodmart_active() {
		$active = defined( 'WOODMART_THEME_DIR' ) || 'woodmart' === get_template();

		/**
		 * Force the WoodMart gallery support on or off.
		 *
		 * @param bool $active
		 */
		return (bool) apply_filters( 'sw_evi_woodmart_gallery', $active );
	}

	/**
	 * Where the additional images (gallery) of a variation are stored:
	 * - "woodmart":    WoodMart theme;
	 * - "wvg":         "Additional Variation Images Gallery for WooCommerce" plugin;
	 * - "woocommerce": native variation gallery of WooCommerce 11.1+;
	 * - "":            no variation gallery, only the main image is shared.
	 */
	public static function gallery_provider() {
		if ( null !== self::$provider ) {
			return self::$provider;
		}

		$provider = '';
		if ( self::woodmart_active() ) {
			$provider = 'woodmart';
		} elseif ( class_exists( 'Woo_Variation_Gallery' ) || function_exists( 'woo_variation_gallery' ) ) {
			$provider = 'wvg';
		} elseif ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '11.1', '>=' ) ) {
			$provider = 'woocommerce';
		}

		/**
		 * Change or disable the variation gallery support.
		 *
		 * @param string $provider "woodmart", "wvg", "woocommerce" or "" (off).
		 */
		$provider = (string) apply_filters( 'sw_evi_gallery_provider', $provider );

		self::$provider = in_array( $provider, array( 'woodmart', 'wvg', 'woocommerce' ), true ) ? $provider : '';
		return self::$provider;
	}

	/**
	 * Forget the detected provider (used by tests and after theme switches).
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

		$map = self::get_map( $product->get_id() );
		if ( $map['attribute'] && isset( $attributes[ $map['attribute'] ] ) ) {
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

	/* ---------- Saved map ---------- */

	/**
	 * @return array( 'attribute' => string, 'values' => array( value_key => array( 'image' => int, 'gallery' => int[] ) ) )
	 */
	public static function get_map( $product_id ) {
		$map = get_post_meta( $product_id, self::META, true );
		$map = is_array( $map ) ? $map : array();

		return array(
			'attribute' => isset( $map['attribute'] ) ? (string) $map['attribute'] : '',
			'values'    => isset( $map['values'] ) && is_array( $map['values'] ) ? $map['values'] : array(),
		);
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

	/* ---------- Variation gallery (additional images) ---------- */

	/**
	 * Additional images of a variation, from the active provider.
	 *
	 * @return int[]
	 */
	public static function get_gallery( $variation ) {
		switch ( self::gallery_provider() ) {
			case 'woodmart':
				$raw = get_post_meta( $variation->get_id(), self::WD_VARIATION_META, true );
				if ( '' === $raw || false === $raw ) {
					$parent = get_post_meta( $variation->get_parent_id(), self::WD_PARENT_META, true );
					if ( is_array( $parent ) && isset( $parent[ $variation->get_id() ] ) ) {
						$raw = $parent[ $variation->get_id() ];
					}
				}
				return self::ids( $raw );

			case 'wvg':
				return self::ids( get_post_meta( $variation->get_id(), self::WVG_META, true ) );

			case 'woocommerce':
				return self::ids( $variation->get_gallery_image_ids( 'edit' ) );
		}
		return array();
	}

	/**
	 * Save the additional images of a variation in the active provider.
	 *
	 * @param int[] $ids
	 */
	public static function set_gallery( $variation, $ids ) {
		$ids = self::ids( $ids );

		switch ( self::gallery_provider() ) {
			case 'woodmart':
				self::set_woodmart_gallery( $variation, $ids );
				break;

			case 'wvg':
				if ( $ids ) {
					update_post_meta( $variation->get_id(), self::WVG_META, $ids );
				} else {
					delete_post_meta( $variation->get_id(), self::WVG_META );
				}
				break;

			case 'woocommerce':
				$variation->set_gallery_image_ids( $ids );
				$variation->save();
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

		// Also write the parent-level storage: depending on the WoodMart
		// "data storage method" option, the theme reads one or the other.
		$parent_id = $variation->get_parent_id();
		$parent    = get_post_meta( $parent_id, self::WD_PARENT_META, true );
		$parent    = is_array( $parent ) ? $parent : array();
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

	/* ---------- Actions ---------- */

	/**
	 * Apply images from the panel to all matching variations (overwrites).
	 *
	 * @param WC_Product $product
	 * @param string     $attribute
	 * @param array      $rows value_key => array( image, gallery, clear_image, clear_gallery )
	 * @return array( 'variations' => int ) Number of variations changed.
	 */
	public static function apply( $product, $attribute, $rows ) {
		$attributes = self::variation_attributes( $product );
		if ( ! isset( $attributes[ $attribute ] ) ) {
			return array( 'variations' => 0 );
		}

		$has_gallery = '' !== self::gallery_provider();
		$groups   = self::groups( $product, $attribute );
		$map      = array(
			'attribute' => $attribute,
			'values'    => array(),
		);
		$changed  = array();

		self::$busy = true;

		foreach ( $attributes[ $attribute ]['values'] as $value => $label ) {
			if ( ! isset( $rows[ $value ] ) ) {
				continue;
			}
			$row     = $rows[ $value ];
			$image   = absint( $row['image'] );
			$image   = $image && wp_attachment_is_image( $image ) ? $image : 0;
			$gallery = self::ids( $row['gallery'] );

			$map['values'][ $value ] = array(
				'image'   => $image,
				'gallery' => $gallery,
			);

			$variations = isset( $groups[ $value ] ) ? $groups[ $value ] : array();

			foreach ( $variations as $variation ) {
				$vid = $variation->get_id();

				if ( $image || ! empty( $row['clear_image'] ) ) {
					if ( (int) $variation->get_image_id() !== $image ) {
						$variation->set_image_id( $image );
						$variation->save();
						$changed[ $vid ] = true;
					}
				}

				if ( $has_gallery && ( $gallery || ! empty( $row['clear_gallery'] ) ) ) {
					$differs = self::get_gallery( $variation ) !== $gallery;
					// WoodMart keeps the gallery in two places; always rewrite both so
					// a second "Apply" repairs data saved by older versions.
					if ( $differs || 'woodmart' === self::gallery_provider() ) {
						self::set_gallery( $variation, $gallery );
					}
					if ( $differs ) {
						$changed[ $vid ] = true;
					}
				}
			}
		}

		update_post_meta( $product->get_id(), self::META, $map );

		self::$busy = false;

		if ( $changed ) {
			wc_delete_product_transients( $product->get_id() );
		}

		/**
		 * Fires after images were applied to the variations of a product.
		 *
		 * @param int    $product_id
		 * @param string $attribute
		 * @param int[]  $variation_ids Changed variations.
		 */
		do_action( 'sw_evi_applied', $product->get_id(), $attribute, array_keys( $changed ) );

		return array( 'variations' => count( $changed ) );
	}

	/**
	 * Give an image to every variation that has none, taking it from the
	 * saved map or from the first variation with the same value that has one.
	 * Variations that already have an image are never touched.
	 *
	 * @return int Number of variations changed.
	 */
	public static function autofill( $product_id ) {
		if ( self::$busy ) {
			return 0;
		}

		/**
		 * Turn automatic filling of empty variation images on or off.
		 *
		 * @param bool $enabled
		 * @param int  $product_id
		 */
		if ( ! apply_filters( 'sw_evi_autofill', true, $product_id ) ) {
			return 0;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return 0;
		}

		$attribute = self::current_attribute( $product );
		if ( '' === $attribute ) {
			return 0;
		}

		$map      = self::get_map( $product_id );
		$saved    = $map['attribute'] === $attribute ? $map['values'] : array();
		$has_gallery = '' !== self::gallery_provider();
		$changed  = array();

		self::$busy = true;

		foreach ( self::groups( $product, $attribute ) as $value => $variations ) {
			$image   = isset( $saved[ $value ]['image'] ) ? absint( $saved[ $value ]['image'] ) : 0;
			$gallery = isset( $saved[ $value ]['gallery'] ) ? self::ids( $saved[ $value ]['gallery'] ) : array();

			if ( $image && ! wp_attachment_is_image( $image ) ) {
				$image = 0;
			}

			foreach ( $variations as $variation ) {
				if ( ! $image && $variation->get_image_id() ) {
					$image = (int) $variation->get_image_id();
				}
				if ( $has_gallery && ! $gallery ) {
					$gallery = self::get_gallery( $variation );
				}
			}

			foreach ( $variations as $variation ) {
				if ( $image && ! $variation->get_image_id() ) {
					$variation->set_image_id( $image );
					$variation->save();
					$changed[ $variation->get_id() ] = true;
				}
				if ( $has_gallery && $gallery && ! self::get_gallery( $variation ) ) {
					self::set_gallery( $variation, $gallery );
					$changed[ $variation->get_id() ] = true;
				}
			}
		}

		self::$busy = false;

		if ( $changed ) {
			wc_delete_product_transients( $product_id );
		}

		return count( $changed );
	}
}
