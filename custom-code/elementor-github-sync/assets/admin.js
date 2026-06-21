/* Elementor GitHub Sync - admin scripts */
( function ( $ ) {
	'use strict';

	var EGS_DATA = window.EGS || {};

	function escapeHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function setCard( cardId, state, value ) {
		if ( ! cardId ) {
			return;
		}
		var $card = $( '#' + cardId );
		if ( ! $card.length ) {
			return;
		}
		$card.removeClass( 'egs-state-good egs-state-bad egs-state-unknown' )
			.addClass( 'egs-state-' + state );
		if ( value ) {
			$card.find( '.egs-card-value' ).text( value );
		}
	}

	function showResult( ok, message, output ) {
		var html = '<p class="' + ( ok ? 'egs-ok' : 'egs-fail' ) + '"><strong>' +
			( ok ? '\u2713 ' : '\u2717 ' ) + escapeHtml( message ) + '</strong></p>';
		if ( output ) {
			html += '<pre>' + escapeHtml( output ) + '</pre>';
		}
		$( '#egs-result' ).html( html );
	}

	function ajax( action, data ) {
		return $.post( EGS_DATA.ajaxUrl, $.extend( {
			action: action,
			nonce: EGS_DATA.nonce
		}, data || {} ) );
	}

	/* Test / action buttons */
	$( document ).on( 'click', '[data-egs-action]', function () {
		var $btn = $( this );
		var action = $btn.data( 'egsAction' );
		var target = $btn.data( 'target' );
		var original = $btn.text();

		$btn.addClass( 'egs-spin' ).text( EGS_DATA.i18n.running );
		if ( target ) {
			setCard( target, 'unknown', EGS_DATA.i18n.running );
		}
		$( '#egs-result' ).html( '' );

		ajax( action )
			.done( function ( res ) {
				var data = res && res.data ? res.data : {};
				var ok = res && res.success;
				showResult( ok, data.message || ( ok ? 'OK' : 'Failed' ), data.output || '' );
				if ( target ) {
					setCard( target, ok ? 'good' : 'bad', ok ? 'OK' : 'Failed' );
				}
			} )
			.fail( function ( xhr ) {
				var msg = 'Request failed (HTTP ' + ( xhr.status || 0 ) + ').';
				if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
					msg = xhr.responseJSON.data.message;
				}
				showResult( false, msg, '' );
				if ( target ) {
					setCard( target, 'bad', 'Failed' );
				}
			} )
			.always( function () {
				$btn.removeClass( 'egs-spin' ).text( original );
			} );
	} );

	/* Refresh logs */
	$( '#egs-refresh-logs' ).on( 'click', function () {
		var $btn = $( this ).addClass( 'egs-spin' );
		ajax( 'egs_get_logs' ).done( function ( res ) {
			if ( res && res.success && res.data.html ) {
				$( '#egs-logs-body' ).html( res.data.html );
			}
		} ).always( function () {
			$btn.removeClass( 'egs-spin' );
		} );
	} );

	/* Clear logs */
	$( '#egs-clear-logs' ).on( 'click', function () {
		if ( ! window.confirm( EGS_DATA.i18n.confirmClr ) ) {
			return;
		}
		var $btn = $( this ).addClass( 'egs-spin' );
		ajax( 'egs_clear_logs' ).done( function () {
			$( '#egs-logs-body' ).html(
				'<tr><td colspan="3">' + escapeHtml( 'No log entries yet.' ) + '</td></tr>'
			);
		} ).always( function () {
			$btn.removeClass( 'egs-spin' );
		} );
	} );

	/* Toggle method-specific sections */
	function toggleMethod() {
		var method = $( 'input[name="connection_method"]:checked' ).val();
		if ( method === 'local' ) {
			$( '.egs-method-api' ).addClass( 'egs-hidden' );
			$( '.egs-method-local' ).removeClass( 'egs-hidden' );
		} else {
			$( '.egs-method-local' ).addClass( 'egs-hidden' );
			$( '.egs-method-api' ).removeClass( 'egs-hidden' );
		}
	}

	$( document ).on( 'change', 'input[name="connection_method"]', toggleMethod );
	toggleMethod();

	/* Clear the masked token field on focus so a real token can be typed */
	$( '#github_token' ).on( 'focus', function () {
		if ( this.value.indexOf( '*' ) !== -1 ) {
			this.value = '';
		}
	} );

} )( jQuery );
