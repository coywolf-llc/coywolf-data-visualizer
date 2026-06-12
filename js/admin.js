/**
 * Admin script: the Add Chart analyze/select/save flow and the Settings
 * "Test connection" button. All DOM is built with createElement/textContent —
 * no HTML string interpolation.
 */
( function () {
	'use strict';

	var config = window.coywolfCDVAdmin || {};
	var i18n = config.i18n || {};

	/* ------------------------------------------------------------------ *
	 * Settings: test connection
	 * ------------------------------------------------------------------ */
	var testButton = document.getElementById( 'coywolf-cdv-test-key' );
	if ( testButton ) {
		testButton.addEventListener( 'click', function () {
			var result = document.getElementById( 'coywolf-cdv-test-result' );
			testButton.disabled = true;
			result.textContent = i18n.testing || '…';
			result.className = '';

			var body = new URLSearchParams();
			body.append( 'action', 'coywolf_cdv_test_api' );
			body.append( '_ajax_nonce', config.testNonce );

			fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( res ) {
					var ok = res && res.success;
					result.textContent = res && res.data && res.data.message ? res.data.message : '';
					result.className = ok ? 'coywolf-cdv-test-ok' : 'coywolf-cdv-test-fail';
				} )
				.catch( function () {
					result.textContent = i18n.requestFail || 'Request failed.';
					result.className = 'coywolf-cdv-test-fail';
				} )
				.finally( function () {
					testButton.disabled = false;
				} );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Add Chart: analyze → preview → save
	 * ------------------------------------------------------------------ */
	var form = document.getElementById( 'coywolf-cdv-analyze-form' );
	if ( ! form ) {
		return;
	}

	var fileInput = document.getElementById( 'coywolf-cdv-file' );
	var explanationInput = document.getElementById( 'coywolf-cdv-explanation' );
	var analyzeButton = document.getElementById( 'coywolf-cdv-analyze' );
	var analyzeSpinner = document.getElementById( 'coywolf-cdv-analyze-spinner' );
	var errorBox = document.getElementById( 'coywolf-cdv-error' );
	var results = document.getElementById( 'coywolf-cdv-results' );
	var suggestionsBox = document.getElementById( 'coywolf-cdv-suggestions' );
	var saveButton = document.getElementById( 'coywolf-cdv-save' );
	var saveSpinner = document.getElementById( 'coywolf-cdv-save-spinner' );

	/**
	 * Current suggestion set: [{ suggestion, card, checkbox, titleInput, captionInput, chart }]
	 */
	var cards = [];

	function showError( message ) {
		errorBox.querySelector( 'p' ).textContent = message;
		errorBox.hidden = false;
	}

	function clearError() {
		errorBox.hidden = true;
	}

	function setBusy( busy ) {
		analyzeButton.disabled = busy;
		analyzeSpinner.classList.toggle( 'is-active', busy );
	}

	function destroyCards() {
		cards.forEach( function ( card ) {
			if ( card.chart ) {
				card.chart.destroy();
			}
		} );
		cards = [];
		while ( suggestionsBox.firstChild ) {
			suggestionsBox.removeChild( suggestionsBox.firstChild );
		}
	}

	function previewConfig( suggestion, title ) {
		var cfg = JSON.parse( JSON.stringify( suggestion.config ) );
		cfg.options = cfg.options || {};
		cfg.options.plugins = cfg.options.plugins || {};
		cfg.options.plugins.title = { display: true, text: title };
		cfg.options.responsive = true;
		cfg.options.maintainAspectRatio = false;
		return cfg;
	}

	function buildCard( suggestion, index ) {
		var card = document.createElement( 'div' );
		card.className = 'coywolf-cdv-suggestion';

		var header = document.createElement( 'label' );
		header.className = 'coywolf-cdv-suggestion-select';
		var checkbox = document.createElement( 'input' );
		checkbox.type = 'checkbox';
		checkbox.checked = true;
		header.appendChild( checkbox );
		var typeBadge = document.createElement( 'code' );
		typeBadge.textContent = suggestion.type;
		header.appendChild( typeBadge );
		card.appendChild( header );

		var canvasWrap = document.createElement( 'div' );
		canvasWrap.className = 'coywolf-cdv-suggestion-canvas';
		var canvas = document.createElement( 'canvas' );
		canvasWrap.appendChild( canvas );
		card.appendChild( canvasWrap );

		var titleLabel = document.createElement( 'label' );
		titleLabel.className = 'coywolf-cdv-suggestion-field';
		titleLabel.appendChild( document.createTextNode( i18n.titleLabel || 'Title' ) );
		var titleInput = document.createElement( 'input' );
		titleInput.type = 'text';
		titleInput.className = 'regular-text';
		titleInput.value = suggestion.title;
		titleLabel.appendChild( titleInput );
		card.appendChild( titleLabel );

		var captionLabel = document.createElement( 'label' );
		captionLabel.className = 'coywolf-cdv-suggestion-field';
		captionLabel.appendChild( document.createTextNode( i18n.captionLabel || 'Caption' ) );
		var captionInput = document.createElement( 'textarea' );
		captionInput.rows = 2;
		captionInput.className = 'large-text';
		captionInput.value = suggestion.caption || '';
		captionLabel.appendChild( captionInput );
		card.appendChild( captionLabel );

		suggestionsBox.appendChild( card );

		var chart = null;
		if ( window.Chart ) {
			try {
				chart = new window.Chart( canvas, previewConfig( suggestion, suggestion.title ) );
				titleInput.addEventListener( 'input', function () {
					chart.options.plugins.title.text = titleInput.value;
					chart.update();
				} );
			} catch ( e ) {
				canvasWrap.textContent = 'Preview unavailable.';
			}
		}

		cards.push( {
			suggestion: suggestion,
			card: card,
			checkbox: checkbox,
			titleInput: titleInput,
			captionInput: captionInput,
			chart: chart,
			index: index,
		} );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		clearError();

		var file = fileInput.files && fileInput.files[ 0 ];
		if ( ! file ) {
			return;
		}
		if ( config.maxBytes && file.size > config.maxBytes ) {
			showError( i18n.tooLarge || 'File too large.' );
			return;
		}

		setBusy( true );
		results.hidden = true;
		destroyCards();

		var body = new FormData();
		body.append( 'action', 'coywolf_cdv_analyze' );
		body.append( '_ajax_nonce', config.analyzeNonce );
		body.append( 'data_file', file );
		body.append( 'explanation', explanationInput.value );
		var engineInput = form.querySelector( 'input[name="engine"]:checked' );
		if ( engineInput ) {
			body.append( 'engine', engineInput.value );
		}

		fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					showError( res && res.data && res.data.message ? res.data.message : i18n.requestFail || 'Request failed.' );
					return;
				}
				var suggestions = res.data.suggestions || [];
				suggestions.forEach( buildCard );
				var engineNote = document.getElementById( 'coywolf-cdv-engine-note' );
				if ( engineNote ) {
					engineNote.textContent = 'ai' === res.data.engine ? i18n.engineAi || '' : i18n.engineLocal || '';
				}
				results.hidden = false;
				results.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			} )
			.catch( function () {
				showError( i18n.requestFail || 'Request failed.' );
			} )
			.finally( function () {
				setBusy( false );
			} );
	} );

	saveButton.addEventListener( 'click', function () {
		clearError();
		var selected = cards
			.filter( function ( card ) {
				return card.checkbox.checked;
			} )
			.map( function ( card ) {
				return {
					title: card.titleInput.value,
					caption: card.captionInput.value,
					type: card.suggestion.type,
					config: card.suggestion.config,
				};
			} );

		if ( ! selected.length ) {
			showError( i18n.noSelection || 'Select at least one chart.' );
			return;
		}

		saveButton.disabled = true;
		saveSpinner.classList.add( 'is-active' );

		var body = new URLSearchParams();
		body.append( 'action', 'coywolf_cdv_save_charts' );
		body.append( '_ajax_nonce', config.saveNonce );
		body.append( 'charts', JSON.stringify( selected ) );

		fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( res ) {
				if ( res && res.success ) {
					window.location.href = res.data.redirect || config.listUrl;
					return;
				}
				showError( res && res.data && res.data.message ? res.data.message : i18n.requestFail || 'Request failed.' );
				saveButton.disabled = false;
				saveSpinner.classList.remove( 'is-active' );
			} )
			.catch( function () {
				showError( i18n.requestFail || 'Request failed.' );
				saveButton.disabled = false;
				saveSpinner.classList.remove( 'is-active' );
			} );
	} );
} )();
