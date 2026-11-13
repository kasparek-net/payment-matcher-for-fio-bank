/* Payment Matcher for Fio Bank – live payment status on the thank-you page. */
( function () {
	var cfg = window.pmfioStatus;
	var box = document.querySelector( '.pmfio-status' );
	if ( ! cfg || ! box || ! window.fetch ) {
		return;
	}
	var started = Date.now();
	var timer;

	function done() {
		box.querySelector( '.pmfio-status__text' ).textContent = cfg.paid;
		box.querySelector( '.pmfio-status__dot' ).style.background = '#00a32a';
		box.classList.add( 'is-paid' );
		var qr = document.querySelector( '.pmfio-qr' );
		if ( qr ) {
			qr.style.display = 'none';
		}
	}

	function check() {
		if ( Date.now() - started > cfg.maxTime ) {
			return;
		}
		fetch( cfg.url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { order: cfg.order, key: cfg.key } ),
			credentials: 'same-origin'
		} )
			.then( function ( r ) {
				return r.ok ? r.json() : null;
			} )
			.then( function ( data ) {
				if ( data && data.paid ) {
					done();
				} else {
					timer = setTimeout( check, cfg.interval );
				}
			} )
			.catch( function () {
				timer = setTimeout( check, cfg.interval * 2 );
			} );
	}

	timer = setTimeout( check, cfg.interval );
} )();
