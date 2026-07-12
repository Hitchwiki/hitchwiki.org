/* Relocate the article Table of Contents into the left sidebar, giving a
   persistent Wikipedia-style sidebar TOC while keeping the legacy Vector skin.
   Pages no longer need __TOC__ for a usable contents list.
   See https://hitchwiki.org/en/MediaWiki:Vector.js */
mw.hook( 'wikipage.content' ).add( function () {
	var panel = document.getElementById( 'mw-panel' );
	var toc = document.getElementById( 'toc' );
	// Nothing to do without a sidebar or TOC, or if we already relocated it.
	if ( !panel || !toc || document.getElementById( 'p-toc' ) ) {
		return;
	}

	// Localised "Contents" label taken from the TOC's own heading.
	var titleEl = toc.querySelector( '.toctitle h2, .toctitle' );
	var label = titleEl ? titleEl.textContent.trim() : 'Contents';

	// Drop the inline collapse widget and heading; the sidebar portal has its own.
	[ '.toctogglecheckbox', '.toctitle' ].forEach( function ( sel ) {
		var el = toc.querySelector( sel );
		if ( el ) {
			el.parentNode.removeChild( el );
		}
	} );

	// Build a standard Vector sidebar portal and move the TOC list into it.
	var portal = document.createElement( 'div' );
	portal.className = 'portal';
	portal.id = 'p-toc';
	portal.setAttribute( 'role', 'navigation' );

	var heading = document.createElement( 'h3' );
	heading.textContent = label;
	portal.appendChild( heading );

	var body = document.createElement( 'div' );
	body.className = 'body';
	toc.parentNode.removeChild( toc );
	body.appendChild( toc );
	portal.appendChild( body );

	// Insert as the first portal, directly under the logo.
	var firstPortal = panel.querySelector( '.portal' );
	panel.insertBefore( portal, firstPortal );
} );
