/**
 * Front-end chart initializer: reads each canvas's Chart.js config from its
 * data attribute and instantiates the chart. The config is pure JSON written
 * by the server-side render — nothing here evaluates strings as code.
 */
( function () {
	'use strict';

	function initCharts() {
		if ( ! window.Chart ) {
			return;
		}
		var canvases = document.querySelectorAll( 'canvas[data-coywolf-cdv-config]' );
		Array.prototype.forEach.call( canvases, function ( canvas ) {
			var raw = canvas.getAttribute( 'data-coywolf-cdv-config' );
			canvas.removeAttribute( 'data-coywolf-cdv-config' );
			var config;
			try {
				config = JSON.parse( raw );
			} catch ( e ) {
				return;
			}
			try {
				new window.Chart( canvas, config );
			} catch ( e ) {
				// Leave the empty canvas (with its aria-label) in place.
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initCharts );
	} else {
		initCharts();
	}
} )();
