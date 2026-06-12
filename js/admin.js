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

	var AXIS_TYPES = [ 'bar', 'line', 'scatter', 'bubble' ];

	function typeFamily( type ) {
		var families = config.typeFamilies || [];
		for ( var i = 0; i < families.length; i++ ) {
			if ( -1 !== families[ i ].indexOf( type ) ) {
				return families[ i ];
			}
		}
		return [ type ];
	}

	function previewConfig( suggestion, title, type, display ) {
		var cfg = JSON.parse( JSON.stringify( suggestion.config ) );
		cfg.type = type || cfg.type;
		if ( window.coywolfCDVTransforms ) {
			cfg = window.coywolfCDVTransforms.apply( cfg, display || {}, config.palettes || {} );
		}
		cfg.options = cfg.options || {};
		cfg.options.plugins = cfg.options.plugins || {};
		cfg.options.plugins.title = { display: true, text: title };
		cfg.options.responsive = true;
		cfg.options.maintainAspectRatio = false;
		return cfg;
	}

	function makeCheckbox( label, checked ) {
		var wrap = document.createElement( 'label' );
		wrap.className = 'coywolf-cdv-suggestion-toggle';
		var box = document.createElement( 'input' );
		box.type = 'checkbox';
		box.checked = !! checked;
		wrap.appendChild( box );
		wrap.appendChild( document.createTextNode( ' ' + label ) );
		return { wrap: wrap, box: box };
	}

	function makeSelect( label, options, value ) {
		var wrap = document.createElement( 'label' );
		wrap.className = 'coywolf-cdv-suggestion-control';
		wrap.appendChild( document.createTextNode( label ) );
		var select = document.createElement( 'select' );
		Object.keys( options ).forEach( function ( key ) {
			var option = document.createElement( 'option' );
			option.value = key;
			option.textContent = options[ key ];
			if ( key === value ) {
				option.selected = true;
			}
			select.appendChild( option );
		} );
		wrap.appendChild( select );
		return { wrap: wrap, select: select };
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

		// Per-chart options: color scheme, compatible type switch, toggles.
		var controls = document.createElement( 'div' );
		controls.className = 'coywolf-cdv-suggestion-options';

		var schemeField = makeSelect( i18n.schemeLabel || 'Color scheme', config.schemes || {}, config.defaultScheme || '' );
		controls.appendChild( schemeField.wrap );

		var family = typeFamily( suggestion.type );
		var typeField = null;
		if ( family.length > 1 ) {
			var typeOptions = {};
			family.forEach( function ( familyType ) {
				typeOptions[ familyType ] = familyType;
			} );
			typeField = makeSelect( i18n.typeLabel || 'Chart type', typeOptions, suggestion.type );
			controls.appendChild( typeField.wrap );
		}

		var initialConfig = suggestion.config || {};
		var isAxisFamily = -1 !== AXIS_TYPES.indexOf( suggestion.type ) || -1 !== family.indexOf( 'bar' );
		var multiDataset = initialConfig.data && initialConfig.data.datasets && initialConfig.data.datasets.length > 1;
		var startsHorizontal = !! ( initialConfig.options && 'y' === initialConfig.options.indexAxis );

		var toggles = {};
		if ( -1 !== family.indexOf( 'bar' ) ) {
			toggles.horizontal = makeCheckbox( i18n.horizontal || 'Horizontal bars', startsHorizontal );
			controls.appendChild( toggles.horizontal.wrap );
		}
		if ( isAxisFamily && multiDataset ) {
			toggles.stacked = makeCheckbox( i18n.stacked || 'Stacked', false );
			controls.appendChild( toggles.stacked.wrap );
		}
		if ( isAxisFamily ) {
			toggles.beginAtZero = makeCheckbox( i18n.beginAtZero || 'Begin axis at zero', false );
			controls.appendChild( toggles.beginAtZero.wrap );
		}
		if ( isAxisFamily || 'radar' === suggestion.type || 'polarArea' === suggestion.type ) {
			toggles.hideGrid = makeCheckbox( i18n.hideGrid || 'Hide grid lines', false );
			controls.appendChild( toggles.hideGrid.wrap );
		}
		card.appendChild( controls );

		suggestionsBox.appendChild( card );

		function currentType() {
			return typeField ? typeField.select.value : suggestion.type;
		}

		function currentDisplay() {
			return {
				scheme: schemeField.select.value,
				horizontal: !! ( toggles.horizontal && toggles.horizontal.box.checked ),
				stacked: !! ( toggles.stacked && toggles.stacked.box.checked ),
				begin_at_zero: !! ( toggles.beginAtZero && toggles.beginAtZero.box.checked ),
				hide_grid: !! ( toggles.hideGrid && toggles.hideGrid.box.checked ),
			};
		}

		var entry = {
			suggestion: suggestion,
			card: card,
			checkbox: checkbox,
			titleInput: titleInput,
			captionInput: captionInput,
			currentType: currentType,
			currentDisplay: currentDisplay,
			chart: null,
			index: index,
		};

		function renderPreview() {
			if ( ! window.Chart ) {
				return;
			}
			if ( entry.chart ) {
				entry.chart.destroy();
				entry.chart = null;
			}
			try {
				entry.chart = new window.Chart( canvas, previewConfig( suggestion, titleInput.value, currentType(), currentDisplay() ) );
			} catch ( e ) {
				canvasWrap.textContent = 'Preview unavailable.';
			}
		}

		Array.prototype.forEach.call( controls.querySelectorAll( 'select, input' ), function ( field ) {
			field.addEventListener( 'change', renderPreview );
		} );
		titleInput.addEventListener( 'input', function () {
			if ( entry.chart ) {
				entry.chart.options.plugins.title.text = titleInput.value;
				entry.chart.update();
			}
		} );

		renderPreview();
		cards.push( entry );
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
				var chartConfig = JSON.parse( JSON.stringify( card.suggestion.config ) );
				chartConfig.type = card.currentType();
				return {
					title: card.titleInput.value,
					caption: card.captionInput.value,
					type: chartConfig.type,
					config: chartConfig,
					display: card.currentDisplay(),
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
