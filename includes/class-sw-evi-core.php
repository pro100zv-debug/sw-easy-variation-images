<?php
/**
 * Core logic.
 *
 * Rules:
 * - Nothing runs on its own: products change only from an explicit action in
 *   the admin panel ("Apply", "Undo") or right after new variations are
 *   created in a product where the panel was already used.
 * - Reading is cheap: the state of all variations is read with two SQL
 *   queries, without loading WooCommerce product objects.
 * - Only variations that really change are loaded and saved.
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

	/** WooCommerce 11.1+ native variation gallery (same key as product galleries). */
	const WC_GALLERY_META = '_product_image_gallery';

	/** WoodMart legacy (deprecated in WoodMart 8.6): per-variation gallery, "1,2,3". */
	const WD_VARIATION_META = 'wd_additional_variation_images_data';

	/** WoodMart older legacy storage: array on the parent product, variation_id => "1,2,3". */
	const WD_PARENT_META = 'woodmart_variation_gallery_data';

	/** "Additional Variation Images Gallery for WooCommerce" plugin: array of IDs on the variation. */
	const WVG_META = 'woo_variation_gallery_images';

	/** Detected gallery provider, cached per request. */
	private static $provider = null;

	public static function init() {
		// Intentionally no hooks: the plugin never runs on product save,
		// on the storefront or on page load.
	}

	/* ---------- Environment ---------- */

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

	public static function value_key( $value ) {
		return sanitize_title( (string) $value );
	}

	/**
	 * Was the panel already used in this product? One cached meta read.
	 */
	public static function is_used( $product_id ) {
		$map = get_post_meta( $product_id, self::META, true );
		return is_array( $map ) && ! empty( $map['attribute'] );
	}

	/**
	 * Attribute used to share images: the saved one, otherwise the first one
	 * that looks like a color, otherwise the first variation attribute.
	 */
	public static function current_attribute( $product, $attributes ) {
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

	/* ---------- Reading (cheap, bulk) ---------- */

	/**
	 * State of all variations of a product, read with two queries.
	 *
	 * @return array variation_id => array( 'attrs' => array( key => value_key ), 'image' => int, 'gallery' => int[] )
	 */
	public static function read_variations( $product_id ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation' AND post_status IN ('publish','private') ORDER BY menu_order ASC, ID ASC",
				$product_id
			)
		);
		$ids = array_map( 'intval', (array) $ids );
		if ( ! $ids ) {
			return array();
		}

		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = array(
				'attrs'   => array(),
				'image'   => 0,
				'gallery' => array(),
			);
		}

		$provider = self::gallery_provider();
		$keys     = array( '_thumbnail_id' );
		if ( 'woocommerce' === $provider ) {
			$keys[] = self::WC_GALLERY_META;
		} elseif ( 'wvg' === $provider ) {
			$keys[] = self::WVG_META;
		} elseif ( 'woodmart' === $provider ) {
			$keys[] = self::WD_VARIATION_META;
		}

		$in_ids  = implode( ',', $ids );
		$in_keys = "'" . implode( "','", array_map( 'esc_sql', $keys ) ) . "'";
		// IDs are integers and keys are constants: safe to inline.
		$rows = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$in_ids}) AND ( meta_key IN ({$in_keys}) OR meta_key LIKE 'attribute%' )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( (array) $rows as $row ) {
			$id = (int) $row->post_id;
			if ( ! isset( $out[ $id ] ) ) {
				continue;
			}
			$key = $row->meta_key;
			if ( 0 === strpos( $key, 'attribute_' ) ) {
				$out[ $id ]['attrs'][ substr( $key, 10 ) ] = self::value_key( $row->meta_value );
			} elseif ( '_thumbnail_id' === $key ) {
				$out[ $id ]['image'] = absint( $row->meta_value );
			} else {
				$out[ $id ]['gallery'] = self::parse_ids( maybe_unserialize( $row->meta_value ) );
			}
		}

		if ( 'woodmart' === $provider ) {
			$parent = get_post_meta( $product_id, self::WD_PARENT_META, true );
			if ( is_array( $parent ) ) {
				foreach ( $out as $id => $state ) {
					if ( ! $state['gallery'] && isset( $parent[ $id ] ) ) {
						$out[ $id ]['gallery'] = self::parse_ids( $parent[ $id ] );
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Variation IDs grouped by attribute value ("any" excluded).
	 *
	 * @return array value_key => int[]
	 */
	public static function group_ids( $variations, $attribute ) {
		$groups = array();
		foreach ( $variations as $id => $state ) {
			$value = isset( $state['attrs'][ $attribute ] ) ? $state['attrs'][ $attribute ] : '';
			if ( '' !== $value ) {
				$groups[ $value ][] = $id;
			}
		}
		return $groups;
	}

	/**
	 * Number of variations with "any" for this attribute.
	 */
	public static function count_any( $variations, $attribute ) {
		$count = 0;
		foreach ( $variations as $state ) {
			if ( empty( $state['attrs'][ $attribute ] ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Most common image and gallery of a group and how many variations match.
	 *
	 * @param array $states Variation states (from read_variations).
	 */
	public static function summarize( $states ) {
		$images    = array();
		$galleries = array();

		foreach ( $states as $state ) {
			if ( $state['image'] ) {
				$images[ $state['image'] ] = isset( $images[ $state['image'] ] ) ? $images[ $state['image'] ] + 1 : 1;
			}
			if ( $state['gallery'] ) {
				$k               = implode( ',', $state['gallery'] );
				$galleries[ $k ] = isset( $galleries[ $k ] ) ? $galleries[ $k ] + 1 : 1;
			}
		}

		arsort( $images );
		arsort( $galleries );
		$main    = $images ? (int) key( $images ) : 0;
		$gallery = $galleries ? self::parse_ids( (string) key( $galleries ) ) : array();

		$same  = 0;
		$empty = 0;
		foreach ( $states as $state ) {
			if ( $state['image'] === $main && $state['gallery'] === $gallery ) {
				++$same;
			} elseif ( ! $state['image'] && ! $state['gallery'] ) {
				++$empty;
			}
		}

		return array(
			'image'     => $main,
			'gallery'   => $gallery,
			'total'     => count( $states ),
			'same'      => $same,
			'empty'     => $empty,
			'different' => count( $states ) - $same - $empty,
		);
	}

	/**
	 * List of positive unique integers from an array or "1,2,3". No DB access.
	 *
	 * @return int[]
	 */
	public static function parse_ids( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$ids = array();
		foreach ( $raw as $id ) {
			$id = absint( $id );
			if ( $id && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Same as parse_ids(), keeping only existing images. Used for input only.
	 *
	 * @return int[]
	 */
	public static function valid_images( $raw ) {
		return array_values( array_filter( self::parse_ids( $raw ), 'wp_attachment_is_image' ) );
	}

	/* ---------- Writing ---------- */

	/**
	 * Write one variation, only if something changes.
	 *
	 * @param int        $id
	 * @param array      $state   Current state (from read_variations).
	 * @param int|null   $image   New main image, 0 = none, null = keep.
	 * @param int[]|null $gallery New gallery, array() = none, null = keep.
	 * @param int        $parent_id
	 * @return bool
	 */
	private static function write( $id, $state, $image, $gallery, $parent_id ) {
		$provider    = self::gallery_provider();
		$set_image   = null !== $image && $state['image'] !== $image;
		$set_gallery = null !== $gallery && '' !== $provider && $state['gallery'] !== $gallery;

		if ( ! $set_image && ! $set_gallery ) {
			return false;
		}

		$variation = wc_get_product( $id );
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			return false;
		}

		if ( $set_image ) {
			$variation->set_image_id( $image );
		}
		if ( $set_gallery ) {
			if ( 'woocommerce' === $provider ) {
				$variation->set_gallery_image_ids( $gallery );
			} elseif ( 'wvg' === $provider ) {
				if ( $gallery ) {
					update_post_meta( $id, self::WVG_META, $gallery );
				} else {
					delete_post_meta( $id, self::WVG_META );
				}
			}
			// WoodMart legacy fields: the only storage with old WooCommerce,
			// and a mirror for WoodMart's deprecated option with WooCommerce 11.1+.
			if ( 'woodmart' === $provider || ( 'woocommerce' === $provider && self::woodmart_active() ) ) {
				self::write_woodmart_legacy( $id, $gallery, $parent_id );
			}
		}

		$variation->save();
		return true;
	}

	private static function write_woodmart_legacy( $id, $gallery, $parent_id ) {
		if ( $gallery ) {
			update_post_meta( $id, self::WD_VARIATION_META, implode( ',', $gallery ) );
		} else {
			delete_post_meta( $id, self::WD_VARIATION_META );
		}

		$parent = get_post_meta( $parent_id, self::WD_PARENT_META, true );
		if ( ! is_array( $parent ) ) {
			if ( ! $gallery ) {
				return;
			}
			$parent = array();
		}
		if ( $gallery ) {
			$parent[ $id ] = implode( ',', $gallery );
		} else {
			unset( $parent[ $id ] );
		}
		if ( $parent ) {
			update_post_meta( $parent_id, self::WD_PARENT_META, $parent );
		} else {
			delete_post_meta( $parent_id, self::WD_PARENT_META );
		}
	}

	/* ---------- Actions ---------- */

	/**
	 * How many variations of a value already have other images and would be replaced.
	 */
	public static function conflicts( $product_id, $attribute, $value, $image, $gallery ) {
		$variations = self::read_variations( $product_id );
		$groups     = self::group_ids( $variations, $attribute );
		$check_g    = '' !== self::gallery_provider();
		$count      = 0;

		foreach ( isset( $groups[ $value ] ) ? $groups[ $value ] : array() as $id ) {
			$state = $variations[ $id ];
			if ( ( $state['image'] && $state['image'] !== $image ) || ( $check_g && $state['gallery'] && $state['gallery'] !== $gallery ) ) {
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

		$product_id = $product->get_id();
		$has_g      = '' !== self::gallery_provider();
		$variations = self::read_variations( $product_id );
		$groups     = self::group_ids( $variations, $attribute );
		$ids        = isset( $groups[ $value ] ) ? $groups[ $value ] : array();

		$snapshot = array();
		$changed  = array();

		foreach ( $ids as $id ) {
			$state = $variations[ $id ];
			if ( 'empty' === $mode ) {
				$new_image   = ( $image && ! $state['image'] ) ? $image : null;
				$new_gallery = ( $has_g && $gallery && ! $state['gallery'] ) ? $gallery : null;
			} else {
				$new_image   = $image;
				$new_gallery = $has_g ? $gallery : null;
			}
			if ( self::write( $id, $state, $new_image, $new_gallery, $product_id ) ) {
				$changed[]       = $id;
				$snapshot[ $id ] = array(
					'image'   => $state['image'],
					'gallery' => $state['gallery'],
				);
			}
		}

		update_post_meta( $product_id, self::META, array( 'attribute' => $attribute ) );

		if ( $changed ) {
			update_post_meta(
				$product_id,
				self::UNDO_META,
				array(
					'value' => $value,
					'label' => $attributes[ $attribute ]['values'][ $value ],
					'items' => $snapshot,
				)
			);
			wc_delete_product_transients( $product_id );
		}

		/**
		 * Fires after images were applied to the variations of a product.
		 *
		 * @param int    $product_id
		 * @param string $attribute
		 * @param int[]  $variation_ids Changed variations.
		 */
		do_action( 'sw_evi_applied', $product_id, $attribute, $changed );

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
	 */
	public static function undo( $product ) {
		$product_id = $product->get_id();
		$undo       = self::get_undo( $product_id );
		if ( ! $undo ) {
			return 0;
		}

		$has_g      = '' !== self::gallery_provider();
		$variations = self::read_variations( $product_id );
		$count      = 0;

		foreach ( $undo['items'] as $id => $old ) {
			$id = (int) $id;
			if ( ! isset( $variations[ $id ] ) ) {
				continue;
			}
			$image   = absint( $old['image'] );
			$gallery = $has_g ? self::parse_ids( $old['gallery'] ) : null;
			if ( self::write( $id, $variations[ $id ], $image, $gallery, $product_id ) ) {
				++$count;
			}
		}

		delete_post_meta( $product_id, self::UNDO_META );
		wc_delete_product_transients( $product_id );

		return $count;
	}

	/**
	 * After new variations were created: variations without image / gallery
	 * get the most common ones of their value. Only in products where the
	 * panel was already used.
	 */
	public static function fill_new( $product ) {
		$product_id = $product->get_id();

		/**
		 * Turn the filling of new variations on or off.
		 *
		 * @param bool $enabled
		 * @param int  $product_id
		 */
		if ( ! apply_filters( 'sw_evi_autofill', true, $product_id ) || ! self::is_used( $product_id ) ) {
			return 0;
		}

		$attribute = self::current_attribute( $product, self::variation_attributes( $product ) );
		if ( '' === $attribute ) {
			return 0;
		}

		$has_g      = '' !== self::gallery_provider();
		$variations = self::read_variations( $product_id );
		$count      = 0;

		foreach ( self::group_ids( $variations, $attribute ) as $ids ) {
			$sum = self::summarize( array_intersect_key( $variations, array_flip( $ids ) ) );
			foreach ( $ids as $id ) {
				$state       = $variations[ $id ];
				$new_image   = ( $sum['image'] && ! $state['image'] ) ? $sum['image'] : null;
				$new_gallery = ( $has_g && $sum['gallery'] && ! $state['gallery'] ) ? $sum['gallery'] : null;
				if ( self::write( $id, $state, $new_image, $new_gallery, $product_id ) ) {
					++$count;
				}
			}
		}

		if ( $count ) {
			wc_delete_product_transients( $product_id );
		}
		return $count;
	}
}
