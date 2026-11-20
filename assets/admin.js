/* global cplinkAdmin */
( function () {
	document.addEventListener( 'click', function ( e ) {
		var copy = e.target.closest( '.cplink-copy' );
		if ( copy ) {
			var wrap = copy.closest( '.cplink-link' );
			var input = wrap.querySelector( '.cplink-url' );
			input.select();
			( navigator.clipboard ? navigator.clipboard.writeText( input.value ) : Promise.resolve( document.execCommand( 'copy' ) ) ).then( function () {
				wrap.querySelector( '.cplink-copied' ).hidden = false;
			} );
			return;
		}

		var btn = e.target.closest( '.cplink-do' );
		if ( ! btn ) {
			return;
		}
		if ( btn.dataset.confirm && ! window.confirm( btn.dataset.confirm ) ) {
			return;
		}
		var box = btn.closest( '.cplink-box' );
		var result = box.querySelector( '.cplink-result' );
		var body = new FormData();
		body.append( 'action', 'cplink' );
		body.append( 'do', btn.dataset.do );
		body.append( 'post', box.dataset.post );
		body.append( '_ajax_nonce', box.dataset.nonce );

		btn.disabled = true;
		result.textContent = '';
		fetch( cplinkAdmin.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				if ( ! res.success ) {
					result.textContent = res.data || cplinkAdmin.failed;
					return;
				}
				if ( res.data.link ) {
					var wrap = box.querySelector( '.cplink-link' );
					wrap.hidden = false;
					wrap.querySelector( '.cplink-copied' ).hidden = true;
					var input = wrap.querySelector( '.cplink-url' );
					input.value = res.data.link;
					input.focus();
				} else {
					box.querySelector( '.cplink-link' ).hidden = true;
				}
				result.textContent = res.data.message || '';
			} )
			.catch( function () {
				result.textContent = cplinkAdmin.failed;
			} )
			.then( function () {
				btn.disabled = false;
			} );
	} );
}() );
