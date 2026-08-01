/* Site JavaScript shared by every Hitchwiki language wiki.
 *
 * This used to live in MediaWiki:Common.js on each wiki separately. Thirty-four
 * copies drifted into six different versions, and twenty-six of them had lost the
 * infobox map code altogether — so cs/Praha, nl/Nederland and two dozen other
 * wikis rendered the raw text "<map lat='50.07' lng='14.43' zoom='9' />" in the
 * infobox where a map belonged. It is loaded family-wide as the
 * "hitchwiki.common" ResourceLoader module; see wiki/LocalSettings.php.
 *
 * Each wiki's own MediaWiki:Common.js is still loaded by core on top of this, so
 * genuinely wiki-specific JavaScript remains possible without editing this file.
 */

/* More sensible default settings at Special:Block User
   https://github.com/Hitchwiki/hitchwiki/issues/23 */
( function () {
	function blockDefaults() {
		if ( !document.querySelector( 'body.mw-special-Block' ) ) { return; }
		var disableEmail = document.querySelector( '#mw-input-wpDisableEmail' );
		var hardBlock = document.querySelector( '#mw-input-wpHardBlock' );
		var expiry = document.querySelector( '#mw-input-wpExpiry' );
		if ( disableEmail ) { disableEmail.click(); }
		if ( hardBlock ) { hardBlock.click(); }
		if ( expiry ) { expiry.value = 'infinite'; }
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', blockDefaults );
	} else {
		blockDefaults();
	}
}() );

/* for for iframe issue */
mw.hook && mw.hook( 'wikipage.content' ).add( function () {
	document.querySelectorAll( 'iframe[data-src]' ).forEach( function ( f ) {
		if ( !f.src ) { f.src = f.dataset.src; }
	} );
} );

/* Replace map tags with image that links to hitchwiki maps
   https://github.com/Hitchwiki/hitchwiki/issues/214

   Tiles come from our own origin (/tiles/{z}/{x}/{y}.png), seeded by
   tools/seed_map_tiles.py. They were previously pulled from
   tile.openstreetmap.org on every page view of ~4,500 articles, which is against
   the OSM tile usage policy. A tile that is missing locally — a map added since
   the last seeding run — falls back to OSM once rather than leaving a hole.

   The well-rated hitchhiking spots inside the window are drawn on top as pins,
   each one a link to that spot on Hitchwiki Maps. They come from
   /spots/{z}/{startTileX}/{startTileY}.json, built by tools/build_map_spots.py
   and keyed by the very numbers the mosaic below already works out, so the page
   fetches one small file and never has to filter anything itself. */
( function () {
	var TILE_SIZE = 256;
	var LOCAL_TILES = '/tiles/{z}/{x}/{y}.png';
	var FALLBACK_TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
	// JSON, but named .js: Cloudflare fronts the site and answers a plain .json
	// request with its "Just a moment…" interstitial (403), while .js and .css are
	// treated as static assets and pass. fetch().json() parses the body whatever
	// Content-Type it carries.
	var SPOT_DATA = '/spots/{z}/{x}/{y}.js';
	var MAPS = 'https://maps.hitchwiki.org';

	function tileUrl( template, z, x, y ) {
		return template.replace( '{z}', z ).replace( '{x}', x ).replace( '{y}', y );
	}

	// Web Mercator world pixel. Shared by the mosaic and the pins so a pin can
	// never drift away from the tile it belongs on.
	function project( lat, lon, zoom ) {
		return {
			x: ( ( lon + 180 ) / 360 ) * Math.pow( 2, zoom ) * TILE_SIZE,
			y: ( ( 1 - Math.log( Math.tan( lat * Math.PI / 180 ) + 1 / Math.cos( lat * Math.PI / 180 ) ) / Math.PI ) / 2 ) * Math.pow( 2, zoom ) * TILE_SIZE
		};
	}

	/* One pin per well-rated spot, layered over the mosaic. Each is its own <a>,
	   which is why the whole map is no longer wrapped in one: an anchor inside an
	   anchor is invalid HTML and browsers recover from it unpredictably. The
	   basemap link is a sibling underneath the pins instead. */
	function addPins( container, topLeftX, topLeftY, zoom, width, height ) {
		var startTileX = Math.floor( topLeftX / TILE_SIZE );
		var startTileY = Math.floor( topLeftY / TILE_SIZE );
		var url = tileUrl( SPOT_DATA, zoom, startTileX, startTileY );

		if ( !window.fetch ) { return; }
		// Same-origin credentials (fetch's default) so the Cloudflare clearance
		// cookie the reader already holds travels with the request.
		fetch( url ).then( function ( resp ) {
			return resp.ok ? resp.json() : null;
		} ).then( function ( spots ) {
			if ( !spots || !spots.length ) { return; }
			var frag = document.createDocumentFragment();
			spots.forEach( function ( spot ) {
				var p = project( spot.lat, spot.lon, zoom );
				var left = p.x - topLeftX;
				var top = p.y - topLeftY;
				if ( left < 0 || left > width || top < 0 || top > height ) { return; }

				var pin = document.createElement( 'a' );
				pin.className = 'hw-map-pin';
				pin.href = MAPS + '/spot/' + spot.id;
				pin.style.left = left + 'px';
				pin.style.top = top + 'px';
				pin.dataset.rating = Math.round( spot.r );
				var label = 'Hitchhiking spot — rated ' + spot.r + '/5 from ' +
					spot.n + ( spot.n === 1 ? ' review' : ' reviews' );
				pin.title = label;
				pin.setAttribute( 'aria-label', label );
				frag.appendChild( pin );
			} );
			container.appendChild( frag );
		} ).catch( function () {
			// No pin data for this window (or the fetch failed). The map itself is
			// still perfectly usable, so there is nothing to report.
		} );
	}

	function initStaticMaps( $content ) {
		// $content may be a jQuery object (from mw.hook) or undefined (DOM fallback)
		var root = $content && $content.length ? $content[ 0 ] : document;
		var mapEls = root.querySelectorAll( '.infobox-map' );
		if ( !mapEls || mapEls.length === 0 ) { return; }

		mapEls.forEach( function ( mapbox ) {
			if ( !mapbox || mapbox.dataset.__staticMapDone ) { return; }
			mapbox.dataset.__staticMapDone = '1';

			function findFloatAttr( attr ) {
				var floatRegex = /-?\d+(\.\d+)?/;
				var txt = ( mapbox.innerText || '' );
				var m = txt.split( attr )[ 1 ];
				if ( !m ) { return 0; }
				var v = m.match( floatRegex );
				return v ? parseFloat( v[ 0 ] ) : 0;
			}

			var lat = findFloatAttr( 'lat' );
			var lon = findFloatAttr( 'lng' );
			var zoom = findFloatAttr( 'zoom' );
			var width = 300;
			var height = 300;

			function createStaticMap( mapContainer, lat, lon, zoom, width, height ) {
				var centre = project( lat, lon, zoom );
				var topLeftX = centre.x - width / 2;
				var topLeftY = centre.y - height / 2;
				var startTileX = Math.floor( topLeftX / TILE_SIZE );
				var startTileY = Math.floor( topLeftY / TILE_SIZE );
				var xOffset = -( topLeftX % TILE_SIZE );
				var yOffset = -( topLeftY % TILE_SIZE );
				var xTiles = Math.ceil( width / TILE_SIZE ) + 1;
				var yTiles = Math.ceil( height / TILE_SIZE ) + 1;

				mapContainer.style.width = width + 'px';
				mapContainer.style.height = height + 'px';
				mapContainer.style.position = 'relative';
				mapContainer.style.overflow = 'hidden';

				for ( var x = 0; x < xTiles; x++ ) {
					for ( var y = 0; y < yTiles; y++ ) {
						var tileX = startTileX + x;
						var tileY = startTileY + y;
						var img = document.createElement( 'img' );
						img.src = tileUrl( LOCAL_TILES, zoom, tileX, tileY );
						img.alt = '';
						img.loading = 'lazy';
						img.dataset.tile = zoom + '/' + tileX + '/' + tileY;
						img.addEventListener( 'error', function () {
							// Only ever retry once, so a tile OSM does not have
							// either (out-of-range x/y) cannot loop.
							if ( this.dataset.tileFallback ) { return; }
							this.dataset.tileFallback = '1';
							var t = this.dataset.tile.split( '/' );
							this.src = tileUrl( FALLBACK_TILES, t[ 0 ], t[ 1 ], t[ 2 ] );
						} );
						img.style.position = 'absolute';
						img.style.width = TILE_SIZE + 'px';
						img.style.height = TILE_SIZE + 'px';
						img.style.left = ( x * TILE_SIZE + xOffset ) + 'px';
						img.style.top = ( y * TILE_SIZE + yOffset ) + 'px';
						mapContainer.appendChild( img );
					}
				}
				return { x: topLeftX, y: topLeftY };
			}

			var mapContainer = document.createElement( 'div' );
			mapContainer.className = 'hw-map';
			var topLeft = createStaticMap( mapContainer, lat, lon, zoom, width, height );

			/* The basemap link sits over the tiles but under the pins, as their
			   sibling rather than their parent. The hash is "#map=zoom/lat/lon":
			   the old "#location,lat,lon,zoom" matched nothing in map.js, and a
			   hash containing a comma makes it skip setView altogether, so
			   clicking an infobox map never actually framed the article. */
			var base = document.createElement( 'a' );
			base.className = 'hw-map-base';
			base.href = MAPS + '/#map=' + zoom + '/' + lat + '/' + lon;
			base.title = 'Open this area on Hitchwiki Maps';
			base.setAttribute( 'aria-label', 'Open this area on Hitchwiki Maps' );
			mapContainer.appendChild( base );

			mapbox.innerHTML = '';
			mapbox.appendChild( mapContainer );

			addPins( mapContainer, topLeft.x, topLeft.y, zoom, width, height );
		} );
	}

	// Prefer MediaWiki hook (fires on initial load and on AJAX content load)
	if ( window.mw && mw.hook ) {
		mw.hook( 'wikipage.content' ).add( initStaticMaps );
	}

	// DOMContentLoaded fallback for pages where mw.hook isn't available yet
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () { initStaticMaps(); } );
	} else {
		// run once immediately in case content already present
		initStaticMaps();
	}
}() );

/* Banner on User: pages linking to Hitchwiki Maps profile
   https://maps.hitchwiki.org/account/<username> */
mw.loader.using( 'mediawiki.util' ).done( function () {
	if ( mw.config.get( 'wgNamespaceNumber' ) === 2 && mw.config.get( 'wgTitle' ).indexOf( '/' ) === -1 ) {
		$( function () {
			var username = mw.config.get( 'wgTitle' );
			var mapsUrl = 'https://maps.hitchwiki.org/account/' + encodeURIComponent( username );
			var $banner = $( '<div>' )
				.addClass( 'user-page-maps-notice' )
				.html(
					'See <strong>' + mw.html.escape( username ) + "'s</strong> hitchhiking rides on " +
					'<a href="' + mapsUrl + '">Hitchwiki Maps</a>'
				);
			$( '#mw-content-text' ).prepend( $banner );
		} );
	}
} );

/* Coords placeholder handling
   {{Coords|0.0000000000|0.0000000000}} is used as a marker for a section that
   still needs coordinates. When BOTH values are exactly 0.0000000000 we replace
   the rendered template with a prompt that links to editing that section so the
   placeholder can be filled in. Any other (real) coordinates are left untouched,
   so they keep rendering as the normal "Go to Map" coordinate links.
   https://github.com/Hitchwiki/hitchwiki/issues */
( function () {
	var PLACEHOLDER = '0.0000000000';

	// Read "lat,lon" out of the template's maps.hitchwiki.org link.
	function readCoords( span ) {
		var link = span.querySelector( 'a[href*="maps.hitchwiki.org/#"]' );
		if ( !link ) { return null; }
		var hash = ( link.href.split( '#' )[ 1 ] || '' ).split( '?' )[ 0 ];
		var parts = hash.split( ',' );
		if ( parts.length < 2 ) { return null; }
		return { lat: decodeURIComponent( parts[ 0 ] ), lon: decodeURIComponent( parts[ 1 ] ) };
	}

	// Find the edit URL of the section the placeholder sits in. Walks backwards
	// and up the DOM to the nearest preceding heading and reuses its edit link.
	function sectionEditUrl( node ) {
		var el = node;
		while ( el && el !== document.body ) {
			var sib = el.previousElementSibling;
			while ( sib ) {
				var isHeading = /^H[1-6]$/.test( sib.tagName ) ||
					( sib.classList && sib.classList.contains( 'mw-heading' ) );
				var edit = sib.querySelector ? sib.querySelector( '.mw-editsection a' ) : null;
				if ( edit ) { return edit.href; }
				if ( isHeading ) { el = document.body; break; } // heading without edit link: stop
				sib = sib.previousElementSibling;
			}
			el = el.parentNode;
		}
		// Fallback: edit the whole page (lead section).
		return mw.util.getUrl( mw.config.get( 'wgPageName' ), { action: 'edit' } );
	}

	function initCoords( $content ) {
		var root = ( $content && $content.length ) ? $content[ 0 ] : document;
		var spans = root.querySelectorAll( 'span.plainlinks' );
		Array.prototype.forEach.call( spans, function ( span ) {
			if ( span.dataset.coordsDone ) { return; }
			var coords = readCoords( span );
			if ( !coords ) { return; }
			span.dataset.coordsDone = '1';
			// Real coordinates: leave the normal coordinate links in place.
			if ( coords.lat !== PLACEHOLDER || coords.lon !== PLACEHOLDER ) { return; }

			var link = document.createElement( 'a' );
			link.href = sectionEditUrl( span );
			link.className = 'coords-add-link';
			link.title = 'Edit this section to add coordinates';
			link.textContent = '📍 Add coordinates to this section';
			span.innerHTML = '';
			span.appendChild( link );
		} );
	}

	if ( window.mw && mw.hook ) {
		mw.loader.using( 'mediawiki.util' ).done( function () {
			mw.hook( 'wikipage.content' ).add( initCoords );
		} );
	} else if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () { initCoords(); } );
	} else {
		initCoords();
	}
}() );

/* "Add your own experience" prompt at the end of every Personal experiences
   section. The prompt is generated from the section heading itself, so it is
   NOT stored in the wikitext: it cannot be deleted by an edit, it survives
   after someone adds their account, and it appears by itself on any page that
   grows such a section. Clicking it opens the section editor with an empty
   hw-experience block already inserted, so a contributor only fills the blanks.
   Companion styles: .hw-add-experience in MediaWiki:Common.css */
( function () {
	var HEADING_RE = /personal\s+experience/i;
	var PROMPT = '➕ Add your own experience';
	var BODY_HINT = 'Write about your experience here.';
	var SKELETON = [
		'<div class="hw-experience">',
		'<span class="hw-exp-author">Your name or [[User:YourUsername|YourUsername]]</span>' +
			' <span class="hw-exp-date">Month Year</span>',
		'<div class="hw-exp-text">',
		BODY_HINT,
		'</div>',
		'</div>'
	].join( '\n' );

	function headingLevel( el ) {
		var m = /^H([1-6])$/.exec( el.tagName );
		if ( m ) { return parseInt( m[ 1 ], 10 ); }
		m = /mw-heading([1-6])/.exec( el.className || '' );
		return m ? parseInt( m[ 1 ], 10 ) : null;
	}

	function headingText( el ) {
		var h = el.querySelector( '.mw-headline' ) ||
			( /^H[1-6]$/.test( el.tagName ) ? el : el.querySelector( 'h1,h2,h3,h4,h5,h6' ) );
		if ( !h ) { return ''; }
		var clone = h.cloneNode( true );
		var es = clone.querySelector( '.mw-editsection' );
		if ( es ) { es.parentNode.removeChild( es ); }
		return clone.textContent || '';
	}

	function editUrl( headingEl ) {
		var a = headingEl.querySelector( '.mw-editsection a' );
		if ( !a ) {
			var wrap = headingEl.parentNode;
			a = wrap && wrap.querySelector ? wrap.querySelector( '.mw-editsection a' ) : null;
		}
		if ( a && a.href ) {
			return a.href + ( a.href.indexOf( '?' ) === -1 ? '?' : '&' ) + 'hwaddexp=1';
		}
		if ( window.mw && mw.util ) {
			return mw.util.getUrl( mw.config.get( 'wgPageName' ),
				{ action: 'edit', hwaddexp: '1' } );
		}
		return null;
	}

	function buildPrompt( href ) {
		var box = document.createElement( 'div' );
		box.className = 'hw-add-experience';
		var link = document.createElement( 'a' );
		link.className = 'hw-add-experience-link';
		link.href = href;
		link.title = 'Edit this section to add your own experience';
		link.textContent = PROMPT;
		box.appendChild( link );
		return box;
	}

	function initPrompts( $content ) {
		var root = ( $content && $content.length ) ? $content[ 0 ] : document;
		var heads = root.querySelectorAll( '.mw-heading, h1, h2, h3, h4, h5, h6' );
		Array.prototype.forEach.call( heads, function ( head ) {
			// A .mw-heading wrapper also contains the bare hN; only handle the outer.
			if ( /^H[1-6]$/.test( head.tagName ) && head.parentNode &&
				head.parentNode.classList &&
				head.parentNode.classList.contains( 'mw-heading' ) ) { return; }
			if ( head.dataset.hwAddExpDone ) { return; }
			if ( !HEADING_RE.test( headingText( head ) ) ) { return; }
			var lvl = headingLevel( head );
			if ( !lvl ) { return; }
			head.dataset.hwAddExpDone = '1';

			var href = editUrl( head );
			if ( !href ) { return; }

			// Walk forward to the end of this section.
			var last = head;
			var sib = head.nextElementSibling;
			while ( sib ) {
				var sl = headingLevel( sib );
				if ( sl && sl <= lvl ) { break; }
				if ( !sib.classList || !sib.classList.contains( 'hw-add-experience' ) ) {
					last = sib;
				}
				sib = sib.nextElementSibling;
			}
			if ( last.parentNode ) {
				last.parentNode.insertBefore( buildPrompt( href ), last.nextSibling );
			}
		} );
	}

	/* On the edit form: drop the skeleton in so the contributor only fills blanks. */
	function initPrefill() {
		if ( !/[?&]hwaddexp=1/.test( location.search ) ) { return; }
		var ta = document.getElementById( 'wpTextbox1' );
		if ( !ta || ta.dataset.hwAddExpFilled ) { return; }
		ta.dataset.hwAddExpFilled = '1';

		var TRAILING =
			/^\s*(\[\[\s*[Cc]ategory\s*:|\[\[\s*[a-z][a-z-]{1,11}\s*:|\{\{|<!--|\s*$)/;
		var HEAD = /^(=+)\s*[^=]*personal\s+experience[^=]*?\s*\1\s*$/i;
		var ANYHEAD = /^(=+)\s*[^=].*?\1\s*$/;

		var lines = ta.value.replace( /\s+$/, '' ).split( '\n' );

		// Works whether the textarea holds one section or the whole page: find the
		// Personal experiences heading, then the end of that section.
		var start = -1, level = 0, i, m;
		for ( i = 0; i < lines.length; i++ ) {
			m = HEAD.exec( lines[ i ] );
			if ( m ) { start = i; level = m[ 1 ].length; break; }
		}
		var end = lines.length;
		if ( start !== -1 ) {
			for ( i = start + 1; i < lines.length; i++ ) {
				var h = ANYHEAD.exec( lines[ i ] );
				if ( h && h[ 1 ].length <= level ) { end = i; break; }
			}
		}
		// Keep the block above trailing categories, interlanguage links and
		// templates, which conventionally sit at the very end of a section.
		var at = end;
		while ( at > start + 1 && TRAILING.test( lines[ at - 1 ] ) ) { at--; }
		lines.splice( at, 0, '', SKELETON );
		ta.value = lines.join( '\n' ).replace( /\s+$/, '' ) + '\n';

		var caret = ta.value.indexOf( BODY_HINT );
		ta.focus();
		if ( caret !== -1 && ta.setSelectionRange ) {
			ta.setSelectionRange( caret, caret + BODY_HINT.length );
		}
		if ( ta.scrollIntoView ) { ta.scrollIntoView( { block: 'center' } ); }

		var summary = document.getElementById( 'wpSummary' );
		if ( summary && !summary.value ) {
			summary.value = 'Add a personal experience';
		}
	}

	function boot() {
		initPrefill();
		if ( window.mw && mw.hook ) {
			mw.loader.using( 'mediawiki.util' ).done( function () {
				mw.hook( 'wikipage.content' ).add( initPrompts );
			} );
		} else {
			initPrompts();
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
