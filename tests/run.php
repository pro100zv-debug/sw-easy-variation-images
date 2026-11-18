<?php
// Integration-style tests: real SQL (SQLite) for posts/postmeta, fake WooCommerce objects on top.
define( 'ABSPATH', '/tmp/' );
define( 'SW_EVI_FILE', '/tmp/plugin.php' );
define( 'SW_EVI_VERSION', 'test' );
define( 'WC_VERSION', '11.4.0' );

$PDO = new PDO( 'sqlite::memory:' );
$PDO->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$PDO->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_parent INT, post_type TEXT, post_status TEXT, menu_order INT, post_mime_type TEXT)' );
$PDO->exec( 'CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY, post_id INT, meta_key TEXT, meta_value TEXT)' );

$LOADS    = 0; // full product object loads
$QUERIES  = 0;
$FILTERS  = array();
$TEMPLATE = 'woodmart';

class FakeWpdb {
	public $posts = 'wp_posts', $postmeta = 'wp_postmeta';
	function prepare( $q, ...$a ) { foreach ( $a as $v ) { $q = preg_replace( '/%d/', (string) (int) $v, $q, 1 ); } return $q; }
	function get_col( $q ) { global $PDO, $QUERIES; $QUERIES++; return $PDO->query( $q )->fetchAll( PDO::FETCH_COLUMN ); }
	function get_results( $q ) { global $PDO, $QUERIES; $QUERIES++; return $PDO->query( $q )->fetchAll( PDO::FETCH_OBJ ); }
}
$wpdb = new FakeWpdb();

function esc_sql( $s ) { return str_replace( "'", "''", $s ); }
function maybe_unserialize( $v ) { $u = @unserialize( $v ); return false !== $u || 'b:0;' === $v ? $u : $v; }
function maybe_serialize( $v ) { return is_array( $v ) ? serialize( $v ) : $v; }
function get_post_meta( $id, $key, $single ) {
	global $PDO; $st = $PDO->prepare( 'SELECT meta_value FROM wp_postmeta WHERE post_id=? AND meta_key=?' ); $st->execute( array( $id, $key ) );
	$v = $st->fetchColumn(); return false === $v ? '' : maybe_unserialize( $v );
}
function update_post_meta( $id, $key, $v ) {
	global $PDO; delete_post_meta( $id, $key );
	$PDO->prepare( 'INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES (?,?,?)' )->execute( array( $id, $key, maybe_serialize( $v ) ) ); return true;
}
function delete_post_meta( $id, $key ) { global $PDO; $PDO->prepare( 'DELETE FROM wp_postmeta WHERE post_id=? AND meta_key=?' )->execute( array( $id, $key ) ); return true; }
function sanitize_title( $s ) { return strtolower( preg_replace( '/[^A-Za-z0-9_\-%]+/', '-', trim( (string) $s ) ) ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_attachment_is_image( $id ) { return $id > 0 && $id < 1000; }
function apply_filters( $tag, $v ) { global $FILTERS; return array_key_exists( $tag, $FILTERS ) ? $FILTERS[ $tag ] : $v; }
function do_action() {}
function add_action() {}
function get_template() { global $TEMPLATE; return $TEMPLATE; }
function wc_attribute_label( $n ) { return ucfirst( str_replace( 'pa_', '', $n ) ); }
function wc_delete_product_transients() {}
function wc_get_product_terms( $pid, $tax ) {
	$t = array( 'pa_color' => array( 'azul-marino' => 'Azul marino', 'blanco' => 'Blanco' ), 'pa_talla' => array( 'm' => 'M', 'l' => 'L', 'xl' => 'XL', '3xl-yl' => '3XL/YL' ) );
	$o = array(); foreach ( $t[ $tax ] as $s => $n ) { $o[] = (object) array( 'slug' => $s, 'name' => $n ); } return $o;
}
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return $s; }
function esc_html_e( $s ) { echo esc_html( $s ); }
function esc_html__( $s ) { return $s; }
function __( $s ) { return $s; }
function _n( $a, $b, $n ) { return 1 === $n ? $a : $b; }
function selected( $a, $b ) { echo $a === $b ? 'selected' : ''; }
function wp_get_attachment_image_url( $id ) { return "https://x/img-$id.jpg"; }

class Attr {
	public $name;
	function __construct( $n ) { $this->name = $n; }
	function get_variation() { return true; }
	function get_name() { return $this->name; }
	function is_taxonomy() { return true; }
	function get_options() { return array(); }
}
class Prod {
	public $id;
	function __construct( $id ) { $this->id = $id; }
	function get_id() { return $this->id; }
	function is_type( $t ) { return 'variable' === $t; }
	function get_attributes() { return array( new Attr( 'pa_talla' ), new Attr( 'pa_color' ) ); }
}
class Var_ {
	public $id, $image, $gallery;
	function __construct( $id ) { $this->id = $id; $this->image = (int) get_post_meta( $id, '_thumbnail_id', true ); $g = get_post_meta( $id, '_product_image_gallery', true ); $this->gallery = $g ? array_map( 'intval', explode( ',', $g ) ) : array(); }
	function get_id() { return $this->id; }
	function is_type( $t ) { return 'variation' === $t; }
	function set_image_id( $i ) { $this->image = (int) $i; }
	function set_gallery_image_ids( $ids ) { $this->gallery = $ids; }
	function save() {
		$this->image ? update_post_meta( $this->id, '_thumbnail_id', $this->image ) : delete_post_meta( $this->id, '_thumbnail_id' );
		$this->gallery ? update_post_meta( $this->id, '_product_image_gallery', implode( ',', $this->gallery ) ) : delete_post_meta( $this->id, '_product_image_gallery' );
	}
}
function wc_get_product( $id ) {
	global $LOADS, $PDO;
	if ( 100 === (int) $id ) { return new Prod( 100 ); }
	$t = $PDO->query( 'SELECT post_type FROM wp_posts WHERE ID=' . (int) $id )->fetchColumn();
	if ( 'product_variation' !== $t ) { return false; }
	$LOADS++;
	return new Var_( (int) $id );
}
function img( $vid ) { return (int) get_post_meta( $vid, '_thumbnail_id', true ); }
function gal( $vid ) { return (string) get_post_meta( $vid, '_product_image_gallery', true ); }

require __DIR__ . '/../includes/class-sw-evi-core.php';
require __DIR__ . '/../includes/class-sw-evi-admin.php';

$fails = 0;
function check( $name, $cond ) { global $fails; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $name . "\n"; if ( ! $cond ) { $fails++; } }

// 2 colors x 4 sizes = 8 variations, plus one "any color", plus a trashed one.
$PDO->exec( "INSERT INTO wp_posts VALUES (100,0,'product','publish',0,'')" );
$id = 200; $order = 0;
foreach ( array( 'azul-marino', 'blanco' ) as $c ) {
	foreach ( array( 'm', 'l', 'xl', '3xl-yl' ) as $s ) {
		$id++;
		$PDO->exec( "INSERT INTO wp_posts VALUES ($id,100,'product_variation','publish'," . ( $order++ ) . ",'')" );
		update_post_meta( $id, 'attribute_pa_color', $c );
		update_post_meta( $id, 'attribute_pa_talla', $s );
	}
}
$PDO->exec( "INSERT INTO wp_posts VALUES (300,100,'product_variation','publish',99,'')" );
update_post_meta( 300, 'attribute_pa_color', '' );
update_post_meta( 300, 'attribute_pa_talla', 'm' );
$PDO->exec( "INSERT INTO wp_posts VALUES (301,100,'product_variation','trash',100,'')" );
update_post_meta( 301, 'attribute_pa_color', 'blanco' );
update_post_meta( 201, '_thumbnail_id', 11 );
update_post_meta( 202, '_thumbnail_id', 11 );

$p = new Prod( 100 );
check( 'provider native', 'woocommerce' === SW_EVI_Core::gallery_provider() );

// Reading.
$QUERIES = 0; $LOADS = 0;
$v = SW_EVI_Core::read_variations( 100 );
check( 'read: 9 variations, trash excluded, menu order', array( 201, 202, 203, 204, 205, 206, 207, 208, 300 ) === array_keys( $v ) );
check( 'read: 2 queries, 0 object loads', 2 === $QUERIES && 0 === $LOADS );
check( 'read: attrs and image', 'azul-marino' === $v[201]['attrs']['pa_color'] && 11 === $v[201]['image'] && 0 === $v[203]['image'] );

// Panel render: no full loads at all.
$QUERIES = 0; $LOADS = 0;
$html = SW_EVI_Admin::panel_html( $p );
check( 'panel: 0 variation object loads', 0 === $LOADS );
check( 'panel: few queries', $QUERIES <= 3 );
check( 'panel: state text', false !== strpos( $html, '2 without images' ) || false !== strpos( $html, 'without images' ) );
check( 'panel: any note', false !== strpos( $html, 'uses “Any”' ) );

// Placeholder on page load: zero queries via wpdb, nothing heavy.
$QUERIES = 0; $LOADS = 0;
$GLOBALS['post'] = (object) array( 'ID' => 100, 'post_type' => 'product' );
ob_start(); SW_EVI_Admin::print_panel(); $ph = ob_get_clean();
check( 'page load: 0 queries, 0 loads, collapsed placeholder', 0 === $QUERIES && 0 === $LOADS && false !== strpos( $ph, 'is-collapsed' ) );

// Apply blanco: loads only the changed variations.
$LOADS = 0;
$n = SW_EVI_Core::apply( $p, 'pa_color', 'blanco', 21, array( 22, 23 ), 'all' );
check( 'apply blanco: 4 changed, 4 loads', 4 === $n && 4 === $LOADS );
check( 'apply blanco: data in DB', 21 === img( 205 ) && '22,23' === gal( 208 ) );
check( 'apply: no hidden WoodMart copy with WC 11.1+', '' === get_post_meta( 205, 'wd_additional_variation_images_data', true ) && '' === get_post_meta( 100, 'woodmart_variation_gallery_data', true ) );
check( 'apply: azul untouched', 11 === img( 201 ) && 0 === img( 203 ) && '' === gal( 201 ) );
check( 'apply: trashed + any untouched', 0 === img( 301 ) && 0 === img( 300 ) );
check( 'used flag', SW_EVI_Core::is_used( 100 ) );

// Re-apply same: no loads.
$LOADS = 0;
check( 're-apply: 0 changes, 0 loads', 0 === SW_EVI_Core::apply( $p, 'pa_color', 'blanco', 21, array( 22, 23 ), 'all' ) && 0 === $LOADS );

// Conflicts + empty mode on azul.
check( 'conflicts azul for new image 12', 2 === SW_EVI_Core::conflicts( 100, 'pa_color', 'azul-marino', 12, array() ) );
$n = SW_EVI_Core::apply( $p, 'pa_color', 'azul-marino', 12, array( 13 ), 'empty' );
check( 'empty mode: 4 changed (2 get image+gallery, 2 only gallery)', 4 === $n && 11 === img( 201 ) && 12 === img( 203 ) && '13' === gal( 201 ) );

// Undo.
$n = SW_EVI_Core::undo( $p );
check( 'undo restores', 4 === $n && 0 === img( 203 ) && '' === gal( 201 ) && 11 === img( 201 ) );


// New variation + fill_new.
$PDO->exec( "INSERT INTO wp_posts VALUES (209,100,'product_variation','publish',50,'')" );
update_post_meta( 209, 'attribute_pa_color', 'blanco' );
update_post_meta( 209, 'attribute_pa_talla', 'xs' );
$LOADS = 0;
$n = SW_EVI_Core::fill_new( $p );
check( 'fill_new: new blanco gets images, azul empties too', 21 === img( 209 ) && '22,23' === gal( 209 ) );
check( 'fill_new: manual azul kept', 11 === img( 201 ) );

// Product where panel never used: fill_new no-op.
delete_post_meta( 100, SW_EVI_Core::META );
update_post_meta( 209, '_thumbnail_id', 0 ); delete_post_meta( 209, '_thumbnail_id' );
check( 'fill_new ignored when unused', 0 === SW_EVI_Core::fill_new( $p ) && 0 === img( 209 ) );

// Undo bar label with value.
SW_EVI_Core::apply( $p, 'pa_color', 'blanco', 21, array( 22, 23 ), 'all' );
$html = SW_EVI_Admin::panel_html( $p );
check( 'undo bar shown', false !== strpos( $html, 'sw-evi-undo' ) && false !== strpos( $html, 'Blanco' ) );

// Old WooCommerce + WoodMart: legacy WoodMart fields are the storage.
$FILTERS['sw_evi_gallery_provider'] = 'woodmart';
SW_EVI_Core::reset_provider();
SW_EVI_Core::apply( $p, 'pa_color', 'azul-marino', 11, array( 31, 32 ), 'all' );
$parent_meta = get_post_meta( 100, 'woodmart_variation_gallery_data', true );
check( 'legacy WoodMart storage when WC < 11.1', '31,32' === get_post_meta( 201, 'wd_additional_variation_images_data', true ) && is_array( $parent_meta ) && '31,32' === $parent_meta[201] );
unset( $FILTERS['sw_evi_gallery_provider'] );
SW_EVI_Core::reset_provider();

// Big product timing: 2 colors x 300 sizes.
$PDO->exec( 'BEGIN' );
for ( $i = 1000; $i < 1600; $i++ ) {
	$PDO->exec( "INSERT INTO wp_posts VALUES ($i,100,'product_variation','publish',$i,'')" );
	update_post_meta( $i, 'attribute_pa_color', $i % 2 ? 'blanco' : 'azul-marino' );
	update_post_meta( $i, 'attribute_pa_talla', 'm' );
}
$PDO->exec( 'COMMIT' );
$LOADS = 0; $QUERIES = 0;
$t = microtime( true );
SW_EVI_Admin::panel_html( $p );
$ms = ( microtime( true ) - $t ) * 1000;
check( sprintf( 'panel with 609 variations: %.1f ms, %d queries, 0 loads', $ms, $QUERIES ), $ms < 500 && 0 === $LOADS && $QUERIES <= 3 );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
