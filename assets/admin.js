/* global ppmlAdmin */
( function () {
	function send( root, data ) {
		var body = new FormData();
		body.append( 'action', 'ppml' );
		body.append( 'post', root.dataset.post );
		body.append( '_ajax_nonce', root.dataset.nonce );
		Object.keys( data ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( ppmlAdmin.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.catch( function () {
				return { success: false, data: ppmlAdmin.failed };
			} );
	}

	// Replace the box with the redrawn one from the server.
	function redraw( root, res, fallbackTarget ) {
		if ( res.success && res.data.html ) {
			var tmp = document.createElement( 'div' );
			tmp.innerHTML = res.data.html.trim();
			root.replaceWith( tmp.firstElementChild );
			return;
		}
		var msg = root.querySelector( '.ppml-result' ) || fallbackTarget;
		if ( msg ) {
			msg.textContent = res.data || ppmlAdmin.failed;
			msg.hidden = false;
		}
	}

	document.addEventListener( 'change', function ( e ) {
		if ( ! e.target.matches( '.ppml-select' ) ) {
			return;
		}
		var root = e.target.closest( '.ppml' );
		root.classList.add( 'is-busy' );
		send( root, { do: 'assign', client: e.target.value } ).then( function ( res ) {
			root.classList.remove( 'is-busy' );
			redraw( root, res, root.querySelector( '.ppml-error' ) );
		} );
	} );

	document.addEventListener( 'click', function ( e ) {
		var add = e.target.closest( '.ppml-add' );
		if ( add ) {
			var r = add.closest( '.ppml' );
			var email = r.querySelector( '[name="ppml_new_email"]' );
			var name = r.querySelector( '[name="ppml_new_name"]' );
			add.disabled = true;
			send( r, { do: 'create', email: email.value, name: name.value } ).then( function ( res ) {
				add.disabled = false;
				redraw( r, res, r.querySelector( '.ppml-error' ) );
			} );
			return;
		}

		var copy = e.target.closest( '.ppml-copy' );
		if ( copy ) {
			var wrap = copy.closest( '.ppml-link' );
			var input = wrap.querySelector( '.ppml-url' );
			input.select();
			( navigator.clipboard ? navigator.clipboard.writeText( input.value ) : Promise.resolve( document.execCommand( 'copy' ) ) ).then( function () {
				wrap.querySelector( '.ppml-copied' ).hidden = false;
			} );
			return;
		}

		var btn = e.target.closest( '.ppml-do' );
		if ( ! btn ) {
			return;
		}
		if ( btn.dataset.confirm && ! window.confirm( btn.dataset.confirm ) ) {
			return;
		}
		var root = btn.closest( '.ppml' );
		var result = root.querySelector( '.ppml-result' );
		btn.disabled = true;
		result.textContent = '';
		send( root, { do: btn.dataset.do } ).then( function ( res ) {
			btn.disabled = false;
			if ( ! res.success ) {
				result.textContent = res.data || ppmlAdmin.failed;
				return;
			}
			if ( res.data.link ) {
				var w = root.querySelector( '.ppml-link' );
				w.querySelector( '.ppml-copied' ).hidden = true;
				w.querySelector( '.ppml-url' ).value = res.data.link;
				if ( res.data.expires ) {
					w.querySelector( '.ppml-expires-text' ).textContent = res.data.expires;
				}
			}
			result.textContent = res.data.message || '';
		} );
	} );
}() );
