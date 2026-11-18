/* SW Easy Variation Images — "Images by attribute" panel in the Variations tab. */
( function ( $ ) {
	'use strict';

	var data = window.swEviData || {};
	var i18n = data.i18n || {};

	function panel() {
		return $( '.sw-evi-panel' ).first();
	}

	function replacePanel( html ) {
		var $panel = panel();
		if ( ! $panel.length || 'string' !== typeof html ) {
			return;
		}
		$panel.find( '.sw-evi-body' ).html( html );
		$panel.attr( 'data-loaded', '1' );
	}

	function openPanel() {
		var $panel = panel();
		$panel.removeClass( 'is-collapsed' );
		$panel.find( '.sw-evi-toggle' ).attr( 'aria-expanded', 'true' );
	}

	// The panel content is loaded only when it is open, never on page load.
	function loadPanel() {
		var $panel = panel();
		if ( ! $panel.length || $panel.hasClass( 'is-collapsed' ) || $panel.attr( 'data-loading' ) ) {
			return;
		}
		$panel.attr( 'data-loading', '1' );
		$panel.find( '.sw-evi-body' ).html( '<span class="spinner is-active sw-evi-loading"></span>' );
		request( 'sw_evi_panel' )
			.done( function ( res ) {
				if ( res && res.success ) {
					replacePanel( res.data.panel );
				} else {
					$panel.find( '.sw-evi-body' ).html( $( '<p class="sw-evi-error">' ).text( i18n.error ) );
				}
			} )
			.fail( function () {
				$panel.find( '.sw-evi-body' ).html( $( '<p class="sw-evi-error">' ).text( i18n.error ) );
			} )
			.always( function () {
				panel().removeAttr( 'data-loading' );
			} );
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
					product_id: panel().attr( 'data-product-id' ),
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

	function markChanged( $row ) {
		$row.addClass( 'is-changed' );
		$row.find( '.sw-evi-state' ).text( i18n.changed );
	}

	function rowBusy( $row, on ) {
		$row.find( '.spinner' ).toggleClass( 'is-active', !! on );
		$row.find( 'button' ).prop( 'disabled', !! on );
	}

	function showError( $row ) {
		rowBusy( $row, false );
		$row.find( '.sw-evi-state' ).text( i18n.error ).addClass( 'is-error' );
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
	} );

	$( document ).on( 'click', '.sw-evi-toggle', function () {
		var $panel = panel();
		var collapsed = ! $panel.hasClass( 'is-collapsed' );
		$panel.toggleClass( 'is-collapsed', collapsed );
		$( this ).attr( 'aria-expanded', collapsed ? 'false' : 'true' );
		if ( ! collapsed && '1' !== $panel.attr( 'data-loaded' ) ) {
			loadPanel();
		}
	} );

	// Products where the panel was used: open it once the variations tab is ready.
	function autoload() {
		var $panel = panel();
		if ( $panel.hasClass( 'is-autoload' ) && '1' !== $panel.attr( 'data-loaded' ) ) {
			openPanel();
			loadPanel();
		}
	}
	$( autoload );
	// WooCommerce re-renders the Variations tab after saving attributes.
	$( document ).on( 'woocommerce_variations_loaded', autoload );

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
			$cell.find( '.sw-evi-remove' ).prop( 'hidden', ! ids.length );
			renderThumbs( $cell, attachments );
			markChanged( $cell.closest( 'tr' ) );
		} );

		frame.open();
	} );

	$( document ).on( 'click', '.sw-evi-remove', function ( e ) {
		e.preventDefault();
		var $cell = $( this ).closest( '.sw-evi-cell' );
		$cell.find( '.sw-evi-ids' ).val( '' );
		$cell.find( '.sw-evi-thumbs' ).empty();
		$( this ).prop( 'hidden', true );
		markChanged( $cell.closest( 'tr' ) );
	} );

	/* ---------- Apply one row ---------- */

	function applyRow( $row, mode ) {
		if ( hasUnsavedVariations() ) {
			window.alert( i18n.unsaved );
			return;
		}

		var $cells = $row.find( '.sw-evi-cell' );
		var $gallery = $cells.filter( '[data-multiple="1"]' );

		rowBusy( $row, true );
		$row.find( '.sw-evi-state' ).text( i18n.working ).removeClass( 'is-error' );

		request( 'sw_evi_apply', {
			attribute: $row.closest( '.sw-evi-rows' ).attr( 'data-attribute' ),
			value: $row.attr( 'data-value' ),
			image: $cells.filter( '[data-multiple="0"]' ).find( '.sw-evi-ids' ).val() || 0,
			gallery: $gallery.length ? $gallery.find( '.sw-evi-ids' ).val() || '' : '',
			mode: mode || '',
		} )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					showError( $row );
					return;
				}
				if ( res.data.confirm ) {
					rowBusy( $row, false );
					$row.find( '.sw-evi-apply' ).prop( 'hidden', true );
					$row.find( '.sw-evi-confirm-text' ).text( res.data.message );
					$row.find( '.sw-evi-confirm' ).prop( 'hidden', false );
					return;
				}
				replacePanel( res.data.panel );
				if ( res.data.changed ) {
					reloadVariations();
				}
			} )
			.fail( function () {
				showError( $row );
			} );
	}

	$( document ).on( 'click', '.sw-evi-apply', function ( e ) {
		e.preventDefault();
		applyRow( $( this ).closest( 'tr' ), '' );
	} );

	$( document ).on( 'click', '.sw-evi-apply-all', function ( e ) {
		e.preventDefault();
		applyRow( $( this ).closest( 'tr' ), 'all' );
	} );

	$( document ).on( 'click', '.sw-evi-apply-empty', function ( e ) {
		e.preventDefault();
		applyRow( $( this ).closest( 'tr' ), 'empty' );
	} );

	$( document ).on( 'click', '.sw-evi-cancel', function ( e ) {
		e.preventDefault();
		var $row = $( this ).closest( 'tr' );
		$row.find( '.sw-evi-confirm' ).prop( 'hidden', true );
		$row.find( '.sw-evi-apply' ).prop( 'hidden', false );
	} );

	/* ---------- Undo ---------- */

	$( document ).on( 'click', '.sw-evi-undo', function ( e ) {
		e.preventDefault();
		if ( hasUnsavedVariations() ) {
			window.alert( i18n.unsaved );
			return;
		}
		var $btn = $( this ).prop( 'disabled', true );
		request( 'sw_evi_undo' )
			.done( function ( res ) {
				if ( res && res.success ) {
					replacePanel( res.data.panel );
					reloadVariations();
				} else {
					$btn.prop( 'disabled', false );
				}
			} )
			.fail( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	/* ---------- New variations ---------- */

	// After "Generate variations" or "Add manually". The server only fills
	// variations without images, and only in products where the panel was used.
	$( document ).on( 'woocommerce_variations_added', '#variable_product_options', function () {
		if ( ! panel().length || panel().find( 'tr.is-changed' ).length ) {
			return;
		}
		// Products where the panel was never used are skipped by the server.
		request( 'sw_evi_fill_new' ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				return;
			}
			if ( '1' === panel().attr( 'data-loaded' ) && ! panel().find( 'tr.is-changed' ).length ) {
				replacePanel( res.data.panel );
			}
			if ( res.data.changed ) {
				reloadVariations();
			}
		} );
	} );

	// After saving variations by hand, refresh the counters in the panel.
	$( document ).on( 'woocommerce_variations_saved', '#woocommerce-product-data', function () {
		if ( ! panel().length || '1' !== panel().attr( 'data-loaded' ) || panel().find( 'tr.is-changed' ).length ) {
			return;
		}
		request( 'sw_evi_panel' ).done( function ( res ) {
			if ( res && res.success && ! panel().find( 'tr.is-changed' ).length ) {
				replacePanel( res.data.panel );
			}
		} );
	} );
} )( jQuery );
