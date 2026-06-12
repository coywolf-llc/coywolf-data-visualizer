/**
 * Client-side mirror of Coywolf_CDV_Schemes::apply() — recolors a Chart.js
 * config with a named scheme and applies the chart-level display toggles.
 * Used by the Add Chart suggestion cards and the Edit Chart live preview so
 * what you see matches the server render exactly. Keep in sync with the PHP.
 */
( function () {
	'use strict';

	var PIE_TYPES = [ 'pie', 'doughnut', 'polarArea' ];
	var AXIS_TYPES = [ 'bar', 'line', 'scatter', 'bubble' ];

	function clone( value ) {
		return JSON.parse( JSON.stringify( value ) );
	}

	function setScale( options, axis, key, value ) {
		options.scales = options.scales || {};
		options.scales[ axis ] = options.scales[ axis ] || {};
		if ( value && 'object' === typeof value && options.scales[ axis ][ key ] && 'object' === typeof options.scales[ axis ][ key ] ) {
			Object.assign( options.scales[ axis ][ key ], value );
		} else {
			options.scales[ axis ][ key ] = value;
		}
	}

	function recolor( config, colors ) {
		if ( ! colors || ! colors.length || ! config.data || ! config.data.datasets ) {
			return;
		}
		var type = config.type;
		config.data.datasets.forEach( function ( dataset, i ) {
			if ( -1 !== PIE_TYPES.indexOf( type ) ) {
				var slices = dataset.data ? dataset.data.length : colors.length;
				var bg = [];
				for ( var s = 0; s < slices; s++ ) {
					bg.push( colors[ s % colors.length ] );
				}
				dataset.backgroundColor = bg;
				delete dataset.borderColor;
				return;
			}
			var color = colors[ i % colors.length ];
			if ( 'line' === type || 'radar' === type ) {
				dataset.borderColor = color;
				dataset.backgroundColor = color + '33';
			} else {
				dataset.backgroundColor = color;
				if ( dataset.borderColor ) {
					dataset.borderColor = color;
				}
			}
		} );
	}

	/**
	 * @param {Object} config   Chart.js config (not mutated).
	 * @param {Object} display  { scheme, horizontal, stacked, begin_at_zero, hide_grid }
	 * @param {Object} palettes scheme key => color array.
	 * @return {Object} Transformed copy.
	 */
	function apply( config, display, palettes ) {
		config = clone( config );
		display = display || {};
		var type = config.type;

		if ( display.scheme && palettes && palettes[ display.scheme ] ) {
			recolor( config, palettes[ display.scheme ] );
		}

		config.options = config.options || {};

		if ( 'bar' === type ) {
			config.options.indexAxis = display.horizontal ? 'y' : 'x';
		}

		if ( -1 !== AXIS_TYPES.indexOf( type ) ) {
			if ( display.stacked ) {
				setScale( config.options, 'x', 'stacked', true );
				setScale( config.options, 'y', 'stacked', true );
			}
			if ( display.begin_at_zero ) {
				setScale( config.options, 'bar' === type && display.horizontal ? 'x' : 'y', 'beginAtZero', true );
			}
			if ( display.hide_grid ) {
				setScale( config.options, 'x', 'grid', { display: false } );
				setScale( config.options, 'y', 'grid', { display: false } );
			}
		} else if ( ( 'radar' === type || 'polarArea' === type ) && display.hide_grid ) {
			setScale( config.options, 'r', 'grid', { display: false } );
		}

		return config;
	}

	/**
	 * Render palette swatches next to a scheme <select> and keep them in
	 * sync as the selection changes.
	 *
	 * @param {HTMLSelectElement} select   Scheme select.
	 * @param {HTMLElement}       mount    Swatch container.
	 * @param {Object}            palettes scheme key => color array.
	 */
	function swatches( select, mount, palettes ) {
		function render() {
			while ( mount.firstChild ) {
				mount.removeChild( mount.firstChild );
			}
			var colors = palettes && palettes[ select.value ] ? palettes[ select.value ] : [];
			colors.forEach( function ( color ) {
				var dot = document.createElement( 'span' );
				dot.className = 'coywolf-cdv-swatch';
				dot.style.backgroundColor = color;
				mount.appendChild( dot );
			} );
		}
		select.addEventListener( 'change', render );
		render();
	}

	window.coywolfCDVTransforms = { apply: apply, swatches: swatches };
} )();
