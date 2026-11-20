/* global ppmlAdmin */
( function () {
	document.addEventListener( 'click', function ( e ) {
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
		var box = btn.closest( '.ppml-box' );
		var result = box.querySelector( '.ppml-result' );
		var body = new FormData();
		body.append( 'action', 'ppml' );
		body.append( 'do', btn.dataset.do );
		body.append( 'post', box.dataset.post );
		body.append( '_ajax_nonce', box.dataset.nonce );

		btn.disabled = true;
		result.textContent = '';
		fetch( ppmlAdmin.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				if ( ! res.success ) {
					result.textContent = res.data || ppmlAdmin.failed;
					return;
				}
				if ( res.data.link ) {
					var wrap = box.querySelector( '.ppml-link' );
					wrap.hidden = false;
					wrap.querySelector( '.ppml-copied' ).hidden = true;
					var input = wrap.querySelector( '.ppml-url' );
					input.value = res.data.link;
					if ( res.data.expires ) {
						wrap.querySelector( '.ppml-expires' ).textContent = res.data.expires;
					}
				}
				result.textContent = res.data.message || '';
			} )
			.catch( function () {
				result.textContent = ppmlAdmin.failed;
			} )
			.then( function () {
				btn.disabled = false;
			} );
	} );
}() );
