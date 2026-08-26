/**
 * P2P Foundation Wiki — lazy-translation gadget
 *
 * Entry point: a prominent "NEW: Translate this page into any language"
 * button at the top right of every article, with a language panel.
 *
 * Engine: the article is split into top-level blocks CLIENT-SIDE and each
 * batch is translated as a separate request to translate.p2pfoundation.net,
 * which caches per (id, revision, lang). Blocks render progressively as they
 * return and are keyed by a content hash so identical blocks are reused
 * across readers regardless of position.
 *
 * Why per-block instead of one whole-article request: the backend model runs
 * on a serialized GPU (~20-25s per ~2.5KB block). A whole article in one
 * request exceeds Cloudflare's 100s proxy limit -> HTTP 524 and the page
 * never updates. Per-block keeps every request well under the limit and lets
 * the reader watch the translation fill in. Backend: ~/Github/p2p-translation-cache.
 *
 * Deployed to: MediaWiki:Common.js (between the marker comments) — the
 * Gadgets extension is NOT installed on this wiki, so Common.js is the real
 * delivery path. MediaWiki:Gadget-translate.js is kept in sync as a copy.
 * Styles: MediaWiki:Common.css, likewise between markers.
 * Source of truth: infra/p2pwiki/gadgets/translate.js in the wiki repo.
 */
( function () {
	'use strict';

	var ENDPOINT = 'https://translate.p2pfoundation.net/translate';
	var STORAGE_KEY = 'p2pwiki-translate-lang';
	var SOURCE = 'wiki';
	// Group top-level blocks into requests of about this many characters.
	// ~2.5KB -> ~23s on the backend's fast model, comfortably under CF's 100s.
	var BATCH_TARGET_CHARS = 2500;
	// How many block requests to keep in flight. The GPU serializes anyway,
	// so this mainly bounds connections; 2 keeps the UI responsive.
	var CONCURRENCY = 2;

	// Shown as one-click chips. Anything else goes through the free-text box,
	// which takes a language name or a BCP-47 code.
	var LANGUAGES = [
		{ code: 'fr', name: 'Français' },
		{ code: 'es', name: 'Español' },
		{ code: 'de', name: 'Deutsch' },
		{ code: 'it', name: 'Italiano' },
		{ code: 'pt', name: 'Português' },
		{ code: 'nl', name: 'Nederlands' },
		{ code: 'pl', name: 'Polski' },
		{ code: 'el', name: 'Ελληνικά' },
		{ code: 'ru', name: 'Русский' },
		{ code: 'tr', name: 'Türkçe' },
		{ code: 'ar', name: 'العربية' },
		{ code: 'hi', name: 'हिन्दी' },
		{ code: 'zh', name: '中文' },
		{ code: 'ja', name: '日本語' },
		{ code: 'ko', name: '한국어' }
	];

	var originalHtml = null;
	var busy = false;
	var button = null;
	var panel = null;

	function getContentEl() {
		// `mw-parser-output` is what MediaWiki wraps article body in.
		return document.querySelector( '#mw-content-text .mw-parser-output' )
			|| document.querySelector( '#mw-content-text' );
	}

	function articleId() {
		var id = mw.config.get( 'wgArticleId' );
		return id ? String( id ) : null;
	}

	function articleRevision() {
		var rev = mw.config.get( 'wgCurRevisionId' )
			|| mw.config.get( 'wgRevisionId' );
		return rev ? String( rev ) : '0';
	}

	function isEligiblePage() {
		// Skip edit/history/special/etc. — only translate read views.
		if ( mw.config.get( 'wgAction' ) !== 'view' ) {
			return false;
		}
		if ( mw.config.get( 'wgNamespaceNumber' ) < 0 ) {
			return false;
		}
		return !!articleId() && !!getContentEl();
	}

	/* ---------- status bar ---------------------------------------------- */

	function removeStatus() {
		var el = document.getElementById( 'p2pwiki-translate-status' );
		if ( el ) {
			el.remove();
		}
	}

	function statusBar( variant ) {
		removeStatus();
		var content = getContentEl();
		if ( !content ) {
			return null;
		}
		var bar = document.createElement( 'div' );
		bar.id = 'p2pwiki-translate-status';
		bar.className = 'p2pwiki-translate-bar p2pwiki-translate-bar--' + variant;
		content.parentNode.insertBefore( bar, content );
		return bar;
	}

	function showProgress( text ) {
		var bar = statusBar( 'busy' );
		if ( !bar ) {
			return;
		}
		var spinner = document.createElement( 'span' );
		spinner.className = 'p2pwiki-translate-spinner';
		var msg = document.createElement( 'span' );
		msg.textContent = text;
		bar.appendChild( spinner );
		bar.appendChild( msg );
	}

	function showError( text ) {
		var bar = statusBar( 'error' );
		if ( !bar ) {
			return;
		}
		var msg = document.createElement( 'span' );
		msg.textContent = text;
		var dismiss = document.createElement( 'button' );
		dismiss.className = 'p2pwiki-translate-linkbtn';
		dismiss.textContent = 'Dismiss';
		dismiss.addEventListener( 'click', removeStatus );
		bar.appendChild( msg );
		bar.appendChild( dismiss );
	}

	function showRevertControl( langName, note ) {
		var bar = statusBar( 'done' );
		if ( !bar ) {
			return;
		}
		var msg = document.createElement( 'span' );
		msg.textContent = 'Translated into ' + langName + ( note || '' ) + ' by AI. ';
		var em = document.createElement( 'em' );
		em.textContent = 'Quality varies; the English original is the source of truth.';
		msg.appendChild( em );

		var btn = document.createElement( 'button' );
		btn.className = 'p2pwiki-translate-linkbtn';
		btn.textContent = 'Show original';
		btn.addEventListener( 'click', function () {
			if ( originalHtml !== null ) {
				getContentEl().innerHTML = originalHtml;
			}
			removeStatus();
			setButtonLabel( null );
			try {
				sessionStorage.removeItem( STORAGE_KEY );
			} catch ( _ ) {}
		} );
		bar.appendChild( msg );
		bar.appendChild( btn );
	}

	/* ---------- translation engine (per-block, progressive) -------------- */

	// djb2 -> base36; stable content key so identical blocks share a cache
	// entry across pages and readers regardless of where they appear.
	function hashHtml( s ) {
		var h = 5381;
		for ( var i = 0; i < s.length; i++ ) {
			h = ( ( h << 5 ) + h + s.charCodeAt( i ) ) | 0;
		}
		return ( h >>> 0 ).toString( 36 );
	}

	// Group the article's top-level child elements into batches of roughly
	// BATCH_TARGET_CHARS. A single element larger than the target becomes its
	// own batch (never split inside an element).
	function buildBatches( contentEl ) {
		var batches = [];
		var cur = [];
		var curLen = 0;
		Array.prototype.forEach.call( contentEl.children, function ( el ) {
			if ( el.id === 'p2pwiki-translate-status' || el.classList.contains( 'p2pwiki-translate-wrap' ) ) {
				return;
			}
			var len = el.outerHTML.length;
			if ( cur.length && curLen + len > BATCH_TARGET_CHARS ) {
				batches.push( cur );
				cur = [];
				curLen = 0;
			}
			cur.push( el );
			curLen += len;
		} );
		if ( cur.length ) {
			batches.push( cur );
		}
		return batches;
	}

	// Translate one batch of adjacent nodes and swap them in place.
	function translateBatch( nodes, lang, revision ) {
		var html = nodes.map( function ( n ) { return n.outerHTML; } ).join( '' );
		var payload = {
			source: SOURCE,
			id: articleId() + ':' + hashHtml( html ),
			revision: revision,
			lang: lang,
			html: html
		};
		return fetch( ENDPOINT, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( payload )
		} )
			.then( function ( resp ) {
				if ( !resp.ok ) {
					throw new Error( 'HTTP ' + resp.status );
				}
				return resp.json();
			} )
			.then( function ( data ) {
				// Replace the batch's nodes with the translated fragment,
				// preserving document order. If parsing yields nothing, leave
				// the original text in place rather than blanking the section.
				var tmp = document.createElement( 'div' );
				tmp.innerHTML = data.translated_html;
				if ( !tmp.firstChild || !nodes.length || !nodes[ 0 ].parentNode ) {
					return true;
				}
				var parent = nodes[ 0 ].parentNode;
				var anchor = nodes[ 0 ];
				while ( tmp.firstChild ) {
					parent.insertBefore( tmp.firstChild, anchor );
				}
				nodes.forEach( function ( n ) { n.remove(); } );
				return true;
			} );
	}

	function translate( lang, langName ) {
		var contentEl = getContentEl();
		if ( !contentEl || busy ) {
			return;
		}
		if ( originalHtml === null ) {
			originalHtml = contentEl.innerHTML;
		}
		var revision = articleRevision();
		var batches = buildBatches( contentEl );
		var total = batches.length;
		if ( !total ) {
			return;
		}

		busy = true;
		setButtonLabel( langName, true );

		var idx = 0;
		var done = 0;
		var failed = 0;

		function progress() {
			showProgress( 'Translating into ' + langName + '… ' + done + '/' + total
				+ ' sections' + ( done < total ? ' — sections appear as they finish.' : '' ) );
		}
		progress();

		function worker() {
			if ( idx >= batches.length ) {
				return Promise.resolve();
			}
			var nodes = batches[ idx++ ];
			return translateBatch( nodes, lang, revision )
				.catch( function () { failed++; } )
				.then( function () {
					done++;
					progress();
					return worker();
				} );
		}

		var pool = [];
		for ( var i = 0; i < Math.min( CONCURRENCY, total ); i++ ) {
			pool.push( worker() );
		}
		Promise.all( pool ).then( function () {
			busy = false;
			if ( failed >= total ) {
				setButtonLabel( null );
				showError( 'Translation failed — the service may be busy or offline. Please try again shortly.' );
				return;
			}
			setButtonLabel( langName );
			showRevertControl( langName,
				failed ? ' (' + failed + ' section(s) left in English)' : '' );
			try {
				sessionStorage.setItem( STORAGE_KEY, lang );
			} catch ( _ ) {}
		} );
	}

	/* ---------- the button + language panel ------------------------------ */

	function setButtonLabel( langName, pending ) {
		if ( !button ) {
			return;
		}
		var badge = button.querySelector( '.p2pwiki-translate-badge' );
		var text = button.querySelector( '.p2pwiki-translate-text' );
		if ( pending ) {
			text.textContent = 'Translating…';
			return;
		}
		if ( langName ) {
			if ( badge ) {
				badge.remove();
			}
			text.textContent = 'Reading in ' + langName + ' — change language';
		} else {
			text.textContent = 'Translate this page into any language';
		}
	}

	function onDocumentClick( e ) {
		if ( panel && !panel.contains( e.target ) && !button.contains( e.target ) ) {
			closePanel();
		}
	}

	function onKeydown( e ) {
		if ( e.key === 'Escape' ) {
			closePanel();
			button.focus();
		}
	}

	function closePanel() {
		if ( panel ) {
			panel.remove();
			panel = null;
		}
		if ( button ) {
			button.setAttribute( 'aria-expanded', 'false' );
		}
		document.removeEventListener( 'click', onDocumentClick, true );
		document.removeEventListener( 'keydown', onKeydown, true );
	}

	function pick( code, name ) {
		closePanel();
		translate( code, name );
	}

	function openPanel() {
		if ( panel ) {
			closePanel();
			return;
		}
		panel = document.createElement( 'div' );
		panel.className = 'p2pwiki-translate-panel';
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-label', 'Choose a language' );

		var title = document.createElement( 'div' );
		title.className = 'p2pwiki-translate-panel-title';
		title.textContent = 'Read this page in…';
		panel.appendChild( title );

		var form = document.createElement( 'form' );
		form.className = 'p2pwiki-translate-form';
		var input = document.createElement( 'input' );
		input.type = 'text';
		input.className = 'p2pwiki-translate-input';
		input.placeholder = 'Any language — e.g. Swahili, Farsi, sv';
		input.setAttribute( 'aria-label', 'Language name or code' );
		var go = document.createElement( 'button' );
		go.type = 'submit';
		go.className = 'p2pwiki-translate-go';
		go.textContent = 'Translate';
		form.appendChild( input );
		form.appendChild( go );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var v = input.value.trim();
			if ( v ) {
				pick( v, v );
			}
		} );
		panel.appendChild( form );

		var chips = document.createElement( 'div' );
		chips.className = 'p2pwiki-translate-chips';
		LANGUAGES.forEach( function ( l ) {
			var chip = document.createElement( 'button' );
			chip.type = 'button';
			chip.className = 'p2pwiki-translate-chip';
			chip.textContent = l.name;
			chip.addEventListener( 'click', function () {
				pick( l.code, l.name );
			} );
			chips.appendChild( chip );
		} );
		panel.appendChild( chips );

		var foot = document.createElement( 'div' );
		foot.className = 'p2pwiki-translate-foot';
		foot.textContent = 'Machine translation. The English original stays one click away.';
		panel.appendChild( foot );

		button.parentNode.appendChild( panel );
		button.setAttribute( 'aria-expanded', 'true' );
		input.focus();

		document.addEventListener( 'click', onDocumentClick, true );
		document.addEventListener( 'keydown', onKeydown, true );
	}

	function buildButton() {
		var wrap = document.createElement( 'div' );
		wrap.className = 'p2pwiki-translate-wrap';

		button = document.createElement( 'button' );
		button.type = 'button';
		button.id = 'p2pwiki-translate-btn';
		button.className = 'p2pwiki-translate-btn';
		button.setAttribute( 'aria-haspopup', 'dialog' );
		button.setAttribute( 'aria-expanded', 'false' );

		var badge = document.createElement( 'span' );
		badge.className = 'p2pwiki-translate-badge';
		badge.textContent = 'NEW';
		var text = document.createElement( 'span' );
		text.className = 'p2pwiki-translate-text';
		text.textContent = 'Translate this page into any language';

		button.appendChild( badge );
		button.appendChild( text );
		button.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			openPanel();
		} );

		wrap.appendChild( button );
		return wrap;
	}

	function mount( wrap ) {
		// Before the page title, floated right: lands at the top right of the
		// article body in every skin this wiki serves, and needs no absolute
		// positioning that would break on narrow screens.
		var heading = document.getElementById( 'firstHeading' );
		if ( heading && heading.parentNode ) {
			heading.parentNode.insertBefore( wrap, heading );
			return true;
		}
		var body = document.querySelector( '.mw-body-content' ) || document.getElementById( 'content' );
		if ( body ) {
			body.insertBefore( wrap, body.firstChild );
			return true;
		}
		return false;
	}

	function maybeAutoTranslate() {
		try {
			var saved = sessionStorage.getItem( STORAGE_KEY );
			if ( !saved ) {
				return;
			}
			var match = LANGUAGES.filter( function ( l ) { return l.code === saved; } )[ 0 ];
			translate( saved, match ? match.name : saved );
		} catch ( _ ) {}
	}

	function init() {
		if ( !isEligiblePage() ) {
			return;
		}
		if ( !mount( buildButton() ) ) {
			return;
		}
		maybeAutoTranslate();
	}

	mw.loader.using( [ 'mediawiki.util' ] ).then( function () {
		$( init );
	} );
}() );
