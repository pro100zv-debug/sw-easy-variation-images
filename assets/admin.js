/* SW Easy Variation Images — "Images by attribute" panel in the Variations tab. */
( function ( $ ) {
	'use strict';

	var data = window.swEviData || {};
	var i18n = data.i18n || {};

	function panel() {
		return $( '.sw-evi-panel' ).first();
	}

	function setStatus( text, isError ) {
		panel()
			.find( '.sw-evi-status' )
			.text( text || '' )
			.toggleClass( 'is-error', !! isError );
	}

	function setDirty( dirty ) {
		var $panel = panel();
		$panel.toggleClass( 'is-dirty', !! dirty );
		setStatus( dirty ? i18n.unsavedPanel : '' );
	}

	function busy( on ) {
		var $panel = panel();
		$panel.find( '.spinner' ).toggleClass( 'is-active', !! on );
		$panel.find( '.sw-evi-apply' ).prop( 'disabled', !! on );
	}

	function replacePanel( html ) {
		var $old = panel();
		if ( ! $old.length || ! html ) {
			return;
		}
		var collapsed = $old.hasClass( 'is-collapsed' );
		var $new = $( html );
		$new.toggleClass( 'is-collapsed', collapsed );
		$new.find( '.sw-evi-toggle' ).attr( 'aria-expanded', collapsed ? 'false' : 'true' );
		$old.replaceWith( $new );
	}

	function reloadVariations() {
		// WooCommerce reloads the variations list on this event.
		$( '#variable_product_options' ).trigger( 'reload' );
	}

	function request( action, extra ) {
		return $.post(
			data.ajaxUrl,
			$.extend(
				{
					action: action,
					nonce: data.nonce,
					product_id: panel().data( 'product-id' ),
				},
				extra || {}
			)
		);
	}

	function hasUnsavedVariations() {
		return (
			$( '#variable_product_options .woocommerce_variations .variation-needs-update' ).length > 0
		);
	}

	function renderThumbs( $cell, attachments ) {
		var $thumbs = $cell.find( '.sw-evi-thumbs' ).empty();
		$.each( attachments, function ( i, att ) {
			var src =
				att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
			$( '<img>', { src: src, alt: '', width: 40, height: 40 } ).appendTo( $thumbs );
		} );
	}

	/* ---------- Attribute selector and collapse ---------- */

	$( document ).on( 'change', '.sw-evi-attribute', function () {
		var value = $( this ).val();
		panel()
			.find( '.sw-evi-rows' )
			.each( function () {
				var $rows = $( this );
				$rows.prop( 'hidden', $rows.attr( 'data-attribute' ) !== value );
			} );
		setDirty( true );
	} );

	$( document ).on( 'click', '.sw-evi-toggle', function () {
		var $panel = panel();
		var collapsed = ! $panel.hasClass( 'is-collapsed' );
		$panel.toggleClass( 'is-collapsed', collapsed );
		$( this ).attr( 'aria-expanded', collapsed ? 'false' : 'true' );
	} );

	/* ---------- Media library ---------- */

	$( document ).on( 'click', '.sw-evi-choose', function ( e ) {
		e.preventDefault();
		if ( ! window.wp || ! wp.media ) {
			return;
		}

		var $cell = $( this ).closest( '.sw-evi-cell' );
		var multiple = $cell.attr( 'data-multiple' ) === '1';
		var current = String( $cell.find( '.sw-evi-ids' ).val() || '' )
			.split( ',' )
			.filter( Boolean );

		var frame = wp.media( {
			title: multiple ? i18n.chooseImages : i18n.chooseImage,
			button: { text: multiple ? i18n.useImages : i18n.useImage },
			library: { type: 'image' },
			multiple: multiple ? 'add' : false,
		} );

		frame.on( 'open', function () {
			var selection = frame.state().get( 'selection' );
			$.each( current, function ( i, id ) {
				var attachment = wp.media.attachment( id );
				attachment.fetch();
				selection.add( attachment );
			} );
		} );

		frame.on( 'select', function () {
			var attachments = frame.state().get( 'selection' ).toJSON();
			if ( ! multiple ) {
				attachments = attachments.slice( 0, 1 );
			}
			var ids = $.map( attachments, function ( att ) {
				return att.id;
			} );
			$cell.find( '.sw-evi-ids' ).val( ids.join( ',' ) );
			$cell.attr( 'data-cleared', ids.length ? '0' : '1' );
			$cell.find( '.sw-evi-remove' ).prop( 'hidden', ! ids.length );
			renderThumbs( $cell, attachments );
			setDirty( true );
		} );

		frame.open();
	} );

	$( document ).on( 'click', '.sw-evi-remove', function ( e ) {
		e.preventDefault();
		var $cell = $( this ).closest( '.sw-evi-cell' );
		$cell.find( '.sw-evi-ids' ).val( '' );
		$cell.find( '.sw-evi-thumbs' ).empty();
		$cell.attr( 'data-cleared', '1' );
		$( this ).prop( 'hidden', true );
		setDirty( true );
	} );

	/* ---------- Apply ---------- */

	$( document ).on( 'click', '.sw-evi-apply', function ( e ) {
		e.preventDefault();

		if ( hasUnsavedVariations() ) {
			window.alert( i18n.unsaved );
			return;
		}

		var $panel = panel();
		var attribute = $panel.find( '.sw-evi-attribute' ).val();
		var rows = [];

		$panel
			.find( '.sw-evi-rows' )
			.filter( function () {
				return $( this ).attr( 'data-attribute' ) === attribute;
			} )
			.find( 'tbody tr' )
			.each( function () {
				var $row = $( this );
				var $cells = $row.find( '.sw-evi-cell' );
				var $image = $cells.filter( '[data-multiple="0"]' );
				var $gallery = $cells.filter( '[data-multiple="1"]' );

				rows.push( {
					value: String( $row.attr( 'data-value' ) ),
					image: $image.find( '.sw-evi-ids' ).val() || 0,
					clear_image: $image.attr( 'data-cleared' ) === '1' ? 1 : 0,
					gallery: $gallery.length ? $gallery.find( '.sw-evi-ids' ).val() || '' : '',
					clear_gallery: $gallery.attr( 'data-cleared' ) === '1' ? 1 : 0,
				} );
			} );

		busy( true );
		setStatus( i18n.working );

		request( 'sw_evi_apply', {
			attribute: attribute,
			rows: JSON.stringify( rows ),
		} )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					busy( false );
					setStatus( i18n.error, true );
					return;
				}
				replacePanel( res.data.panel );
				setStatus( res.data.message );
				reloadVariations();
			} )
			.fail( function () {
				busy( false );
				setStatus( i18n.error, true );
			} );
	} );

	/* ---------- Keep new variations and the panel in sync ---------- */

	// After "Generate variations" or "Add manually": give images to the new ones.
	$( document ).on( 'woocommerce_variations_added', '#variable_product_options', function () {
		if ( ! panel().length ) {
			return;
		}
		request( 'sw_evi_autofill' ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				return;
			}
			if ( ! panel().hasClass( 'is-dirty' ) ) {
				replacePanel( res.data.panel );
			}
			if ( res.data.changed ) {
				reloadVariations();
			}
		} );
	} );

	// After saving variations the counts and images may have changed.
	$( document ).on( 'woocommerce_variations_saved', '#woocommerce-product-data', function () {
		if ( ! panel().length || panel().hasClass( 'is-dirty' ) ) {
			return;
		}
		request( 'sw_evi_panel' ).done( function ( res ) {
			if ( res && res.success && ! panel().hasClass( 'is-dirty' ) ) {
				replacePanel( res.data.panel );
			}
		} );
	} );
} )( jQuery );
