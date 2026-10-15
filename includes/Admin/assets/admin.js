( function () {
	'use strict';

	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );
			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}
			document.body.removeChild( area );
			return ok ? resolve() : reject( new Error( 'copy failed' ) );
		} );
	}

	function onCopyClick( button ) {
		var target = document.getElementById( button.getAttribute( 'data-ttm-copy' ) );
		if ( ! target ) {
			return;
		}
		var original = button.textContent;
		copyText( target.textContent ).then( function () {
			button.textContent = button.getAttribute( 'data-ttm-copied' ) || original;
			window.setTimeout( function () {
				button.textContent = original;
			}, 1500 );
		} );
	}

	function applyPreset( list ) {
		var wanted = list.split( ',' );
		document.querySelectorAll( 'input[name="scopes[]"]' ).forEach( function ( box ) {
			box.checked = wanted.indexOf( box.value ) !== -1;
		} );
	}

	// Tabs and panels may carry data-ttm-group so several tab sets can share a page.
	function showTab( key, group ) {
		group = group || '';
		document.querySelectorAll( '[data-ttm-panel]' ).forEach( function ( panel ) {
			if ( ( panel.getAttribute( 'data-ttm-group' ) || '' ) === group ) {
				panel.classList.toggle( 'ttm-hidden', panel.getAttribute( 'data-ttm-panel' ) !== key );
			}
		} );
		document.querySelectorAll( '[data-ttm-tab]' ).forEach( function ( button ) {
			if ( ( button.getAttribute( 'data-ttm-group' ) || '' ) === group ) {
				button.classList.toggle( 'button-primary', button.getAttribute( 'data-ttm-tab' ) === key );
			}
		} );
	}

	function syncExpiry() {
		var select = document.getElementById( 'ttm-expiry' );
		var date = document.getElementById( 'ttm-expiry-date' );
		if ( ! select || ! date ) {
			return;
		}
		var custom = select.value === 'custom';
		date.classList.toggle( 'ttm-hidden', ! custom );
		date.required = custom;
	}

	document.addEventListener( 'click', function ( event ) {
		var el = event.target.closest ? event.target.closest( '[data-ttm-copy], [data-ttm-preset], [data-ttm-tab], [data-ttm-confirm]' ) : null;
		if ( ! el ) {
			return;
		}
		if ( el.hasAttribute( 'data-ttm-confirm' ) && ! window.confirm( el.getAttribute( 'data-ttm-confirm' ) ) ) {
			event.preventDefault();
			return;
		}
		if ( el.hasAttribute( 'data-ttm-copy' ) ) {
			onCopyClick( el );
		} else if ( el.hasAttribute( 'data-ttm-preset' ) ) {
			applyPreset( el.getAttribute( 'data-ttm-preset' ) );
		} else if ( el.hasAttribute( 'data-ttm-tab' ) ) {
			showTab( el.getAttribute( 'data-ttm-tab' ), el.getAttribute( 'data-ttm-group' ) );
		}
	} );

	document.addEventListener( 'change', function ( event ) {
		if ( event.target && event.target.id === 'ttm-expiry' ) {
			syncExpiry();
		}
	} );

	syncExpiry();
}() );
