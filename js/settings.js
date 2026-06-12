/**
 * Settings → Chart appearance: jscolorpicker init (same picker and wiring as
 * Coywolf Video Manager). Each color field is a hidden value input plus a
 * mount the picker renders into.
 */
( function () {
	'use strict';

	function init() {
		if ( 'undefined' === typeof ColorPicker ) {
			return;
		}
		var fields = document.querySelectorAll( '.coywolf-cdv-color-field' );
		Array.prototype.forEach.call( fields, function ( field ) {
			var hidden = field.querySelector( '.coywolf-cdv-color-value' );
			var mount = field.querySelector( '.coywolf-cdv-color-mount' );
			if ( ! hidden || ! mount ) {
				return;
			}
			var picker = new ColorPicker( mount, {
				color: hidden.value || null,
				submitMode: 'instant',
				enableAlpha: false,
				formats: [ 'hex' ],
				defaultFormat: 'hex',
				showClearButton: true,
			} );
			picker.on( 'pick', function ( color ) {
				var hex = '';
				if ( color ) {
					hex = color.string( 'hex' );
					if ( hex && '#' !== hex.charAt( 0 ) ) {
						hex = '#' + hex;
					}
					if ( hex.length > 7 ) {
						hex = hex.substring( 0, 7 ); // drop any alpha.
					}
				}
				hidden.value = hex;
			} );
		} );
	}

	function initSwatches() {
		var data = window.coywolfCDVSettings || {};
		if ( ! window.coywolfCDVTransforms || ! data.palettes ) {
			return;
		}
		var selects = document.querySelectorAll( '.coywolf-cdv-scheme-select' );
		Array.prototype.forEach.call( selects, function ( select ) {
			var mount = select.parentNode.querySelector( '.coywolf-cdv-scheme-swatches' );
			if ( mount ) {
				window.coywolfCDVTransforms.swatches( select, mount, data.palettes );
			}
		} );
	}

	function boot() {
		init();
		initSwatches();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
