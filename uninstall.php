<?php
/**
 * Removes the plugin's own data. Images already assigned to variations stay.
 *
 * @package SW_Easy_Variation_Images
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_post_meta_by_key( '_sw_evi_map' );
