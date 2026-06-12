/**
 * Edit Chart screen: live preview that re-applies the chart type, color
 * scheme, and display toggles exactly as the server render will.
 */
( function () {
	'use strict';

	var data = window.coywolfCDVEdit;
	var transforms = window.coywolfCDVTransforms;
	if ( ! data || ! transforms || ! window.Chart ) {
		return;
	}

	var canvas = document.getElementById( 'coywolf-cdv-edit-canvas' );
	var form = document.querySelector( '.coywolf-cdv-edit-form' );
	if ( ! canvas || ! form ) {
		return;
	}

	var titleInput = document.getElementById( 'coywolf-cdv-edit-title' );
	var captionInput = document.getElementById( 'coywolf-cdv-edit-caption' );
	var captionPreview = document.getElementById( 'coywolf-cdv-edit-caption-preview' );
	var typeSelect = document.getElementById( 'coywolf-cdv-edit-type' );
	var schemeSelect = document.getElementById( 'coywolf-cdv-edit-scheme' );
	var chart = null;

	function checked( id ) {
		var box = document.getElementById( id );
		return !! ( box && box.checked );
	}

	function currentDisplay() {
		return {
			scheme: schemeSelect ? schemeSelect.value : '',
			horizontal: checked( 'coywolf-cdv-edit-horizontal' ),
			stacked: checked( 'coywolf-cdv-edit-stacked' ),
			begin_at_zero: checked( 'coywolf-cdv-edit-zero' ),
			hide_grid: checked( 'coywolf-cdv-edit-grid' ),
		};
	}

	function render() {
		var config = JSON.parse( JSON.stringify( data.config ) );
		if ( typeSelect && typeSelect.value ) {
			config.type = typeSelect.value;
		}
		config = transforms.apply( config, currentDisplay(), data.palettes );

		config.options = config.options || {};
		config.options.plugins = config.options.plugins || {};
		if ( titleInput && titleInput.value ) {
			config.options.plugins.title = { display: true, text: titleInput.value };
		}
		config.options.responsive = true;
		config.options.maintainAspectRatio = false;

		if ( chart ) {
			chart.destroy();
		}
		try {
			chart = new window.Chart( canvas, config );
		} catch ( e ) {
			chart = null;
		}

		if ( captionPreview ) {
			captionPreview.textContent = captionInput ? captionInput.value : '';
		}
	}

	Array.prototype.forEach.call( form.querySelectorAll( 'input, select, textarea' ), function ( field ) {
		field.addEventListener( 'change', render );
		field.addEventListener( 'input', render );
	} );

	if ( schemeSelect ) {
		var mount = schemeSelect.parentNode.querySelector( '.coywolf-cdv-scheme-swatches' );
		if ( mount ) {
			transforms.swatches( schemeSelect, mount, data.palettes );
		}
	}

	render();
} )();
