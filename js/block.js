/**
 * Coywolf Chart block editor script.
 *
 * Plain-JS block (no build step): a placeholder + searchable picker modal to
 * choose a saved chart, a live Chart.js preview, and inspector controls for
 * the block's presentation overrides.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var BlockControls = wp.blockEditor.BlockControls;
	var components = wp.components;
	var PanelBody = components.PanelBody;
	var ToggleControl = components.ToggleControl;
	var SelectControl = components.SelectControl;
	var RangeControl = components.RangeControl;
	var TextareaControl = components.TextareaControl;
	var TextControl = components.TextControl;
	var ToolbarGroup = components.ToolbarGroup;
	var ToolbarButton = components.ToolbarButton;
	var Button = components.Button;
	var Modal = components.Modal;
	var Spinner = components.Spinner;
	var Placeholder = components.Placeholder;
	var __ = wp.i18n.__;
	var apiFetch = wp.apiFetch;

	/**
	 * Mirror of Coywolf_CDV_Block::apply_overrides() — keep in sync.
	 */
	function applyOverrides( chart, attributes ) {
		var config = JSON.parse( JSON.stringify( chart.config ) );
		config.options = config.options || {};
		config.options.plugins = config.options.plugins || {};

		if ( attributes.showTitle && chart.title ) {
			config.options.plugins.title = { display: true, text: chart.title };
		} else {
			delete config.options.plugins.title;
		}

		if ( false === attributes.showLegend ) {
			config.options.plugins.legend = config.options.plugins.legend || {};
			config.options.plugins.legend.display = false;
		} else if ( attributes.legendPosition ) {
			config.options.plugins.legend = config.options.plugins.legend || {};
			config.options.plugins.legend.position = attributes.legendPosition;
		}

		config.options.responsive = true;
		config.options.maintainAspectRatio = ! ( attributes.height > 0 );
		return config;
	}

	/**
	 * Live Chart.js preview of the selected chart with overrides applied.
	 */
	function ChartPreview( props ) {
		var canvasRef = useRef( null );
		var instanceRef = useRef( null );
		var attrs = props.attributes;

		useEffect(
			function () {
				if ( ! canvasRef.current || ! window.Chart || ! props.chart ) {
					return undefined;
				}
				if ( instanceRef.current ) {
					instanceRef.current.destroy();
					instanceRef.current = null;
				}
				try {
					instanceRef.current = new window.Chart( canvasRef.current, applyOverrides( props.chart, attrs ) );
				} catch ( e ) {
					// An unrenderable config shouldn't crash the editor.
				}
				return function () {
					if ( instanceRef.current ) {
						instanceRef.current.destroy();
						instanceRef.current = null;
					}
				};
			},
			[ props.chart, attrs.showTitle, attrs.showLegend, attrs.legendPosition, attrs.height ]
		);

		var wrapStyle = attrs.height > 0 ? { height: attrs.height + 'px' } : {};
		return el( 'div', { className: 'coywolf-cdv-canvas', style: wrapStyle }, el( 'canvas', { ref: canvasRef } ) );
	}

	/**
	 * Searchable chart picker modal (same pattern as the Coywolf Video
	 * Manager selector): type to filter, click to choose.
	 */
	function Picker( props ) {
		var queryState = useState( '' );
		var query = queryState[ 0 ];
		var setQuery = queryState[ 1 ];
		var listState = useState( null );
		var items = listState[ 0 ];
		var setItems = listState[ 1 ];
		var loadingState = useState( false );
		var loading = loadingState[ 0 ];
		var setLoading = loadingState[ 1 ];

		function search( term ) {
			setLoading( true );
			apiFetch( { path: '/coywolf-cdv/v1/charts?s=' + encodeURIComponent( term ) } )
				.then( function ( res ) {
					setItems( res && res.charts ? res.charts : [] );
					setLoading( false );
				} )
				.catch( function () {
					setItems( [] );
					setLoading( false );
				} );
		}

		// Filter as the user types (debounced); also runs on open (query '').
		useEffect(
			function () {
				var timer = setTimeout( function () {
					search( query );
				}, 300 );
				return function () {
					clearTimeout( timer );
				};
			},
			[ query ]
		);

		var list = null;
		if ( loading || null === items ) {
			list = el( Spinner, {} );
		} else if ( items.length ) {
			list = el(
				'div',
				{ className: 'coywolf-cdv-picker-list' },
				items.map( function ( chart ) {
					return el(
						'button',
						{
							key: chart.id,
							type: 'button',
							className: 'coywolf-cdv-picker-item',
							onClick: function () {
								props.onSelect( chart );
							},
						},
						el( 'span', { className: 'coywolf-cdv-picker-type' }, chart.type || 'chart' ),
						el( 'span', { className: 'coywolf-cdv-picker-name' }, chart.title || '#' + chart.id ),
						chart.caption ? el( 'span', { className: 'coywolf-cdv-picker-caption' }, chart.caption ) : null
					);
				} )
			);
		} else {
			list = el( 'p', {}, __( 'No charts found. Create charts under Charts → Add Chart.', 'coywolf-data-visualizer' ) );
		}

		return el(
			Modal,
			{
				title: __( 'Select a chart', 'coywolf-data-visualizer' ),
				onRequestClose: props.onClose,
				className: 'coywolf-cdv-picker',
			},
			el(
				'div',
				{ className: 'coywolf-cdv-picker-search' },
				el( TextControl, {
					label: __( 'Search charts', 'coywolf-data-visualizer' ),
					hideLabelFromVision: true,
					value: query,
					placeholder: __( 'Search charts…', 'coywolf-data-visualizer' ),
					onChange: setQuery,
					__nextHasNoMarginBottom: true,
				} )
			),
			list
		);
	}

	function Edit( props ) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var pickerState = useState( false );
		var pickerOpen = pickerState[ 0 ];
		var setPickerOpen = pickerState[ 1 ];
		var chartState = useState( null );
		var chart = chartState[ 0 ];
		var setChart = chartState[ 1 ];
		var missingState = useState( false );
		var missing = missingState[ 0 ];
		var setMissing = missingState[ 1 ];

		useEffect(
			function () {
				if ( ! attributes.chartId ) {
					setChart( null );
					setMissing( false );
					return;
				}
				setMissing( false );
				apiFetch( { path: '/coywolf-cdv/v1/charts/' + attributes.chartId } )
					.then( function ( res ) {
						setChart( res );
						if ( res && res.title !== attributes.chartTitle ) {
							setAttributes( { chartTitle: res.title } );
						}
					} )
					.catch( function () {
						setChart( null );
						setMissing( true );
					} );
			},
			[ attributes.chartId ]
		);

		function onSelect( selected ) {
			setAttributes( { chartId: selected.id, chartTitle: selected.title } );
			setPickerOpen( false );
		}

		var blockProps = useBlockProps( {
			className: 'coywolf-cdv-chart coywolf-cdv-editor',
			style: attributes.maxWidth > 0 ? { maxWidth: attributes.maxWidth + 'px' } : {},
		} );

		var inspector = el(
			InspectorControls,
			{},
			el(
				PanelBody,
				{ title: __( 'Chart', 'coywolf-data-visualizer' ), initialOpen: true },
				el(
					'p',
					{},
					attributes.chartId
						? attributes.chartTitle || '#' + attributes.chartId
						: __( 'No chart selected.', 'coywolf-data-visualizer' )
				),
				el(
					Button,
					{
						variant: 'secondary',
						onClick: function () {
							setPickerOpen( true );
						},
					},
					attributes.chartId ? __( 'Replace chart', 'coywolf-data-visualizer' ) : __( 'Select chart', 'coywolf-data-visualizer' )
				)
			),
			el(
				PanelBody,
				{ title: __( 'Display settings', 'coywolf-data-visualizer' ), initialOpen: true },
				el( ToggleControl, {
					label: __( 'Show title', 'coywolf-data-visualizer' ),
					checked: attributes.showTitle,
					onChange: function ( value ) {
						setAttributes( { showTitle: value } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( ToggleControl, {
					label: __( 'Show caption', 'coywolf-data-visualizer' ),
					checked: attributes.showCaption,
					onChange: function ( value ) {
						setAttributes( { showCaption: value } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				attributes.showCaption
					? el( TextareaControl, {
							label: __( 'Caption override', 'coywolf-data-visualizer' ),
							help: __( 'Leave empty to use the chart’s saved caption.', 'coywolf-data-visualizer' ),
							value: attributes.captionOverride,
							onChange: function ( value ) {
								setAttributes( { captionOverride: value } );
							},
							__nextHasNoMarginBottom: true,
					  } )
					: null,
				el( ToggleControl, {
					label: __( 'Show legend', 'coywolf-data-visualizer' ),
					checked: attributes.showLegend,
					onChange: function ( value ) {
						setAttributes( { showLegend: value } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				attributes.showLegend
					? el( SelectControl, {
							label: __( 'Legend position', 'coywolf-data-visualizer' ),
							value: attributes.legendPosition,
							options: [
								{ value: '', label: __( 'Default', 'coywolf-data-visualizer' ) },
								{ value: 'top', label: __( 'Top', 'coywolf-data-visualizer' ) },
								{ value: 'bottom', label: __( 'Bottom', 'coywolf-data-visualizer' ) },
								{ value: 'left', label: __( 'Left', 'coywolf-data-visualizer' ) },
								{ value: 'right', label: __( 'Right', 'coywolf-data-visualizer' ) },
							],
							onChange: function ( value ) {
								setAttributes( { legendPosition: value } );
							},
							__nextHasNoMarginBottom: true,
					  } )
					: null,
				el( RangeControl, {
					label: __( 'Max width (px, 0 = full width)', 'coywolf-data-visualizer' ),
					value: attributes.maxWidth,
					min: 0,
					max: 1600,
					step: 10,
					onChange: function ( value ) {
						setAttributes( { maxWidth: value || 0 } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( RangeControl, {
					label: __( 'Height (px, 0 = automatic)', 'coywolf-data-visualizer' ),
					value: attributes.height,
					min: 0,
					max: 900,
					step: 10,
					onChange: function ( value ) {
						setAttributes( { height: value || 0 } );
					},
					__nextHasNoMarginBottom: true,
				} )
			)
		);

		var toolbar = el(
			BlockControls,
			{},
			el(
				ToolbarGroup,
				{},
				el( ToolbarButton, {
					icon: 'update',
					label: __( 'Replace chart', 'coywolf-data-visualizer' ),
					onClick: function () {
						setPickerOpen( true );
					},
				} )
			)
		);

		var body;
		if ( ! attributes.chartId ) {
			body = el(
				Placeholder,
				{
					icon: 'chart-bar',
					label: __( 'Coywolf Chart', 'coywolf-data-visualizer' ),
					instructions: __( 'Pick one of your saved charts to embed it here.', 'coywolf-data-visualizer' ),
				},
				el(
					Button,
					{
						variant: 'primary',
						onClick: function () {
							setPickerOpen( true );
						},
					},
					__( 'Select chart', 'coywolf-data-visualizer' )
				)
			);
		} else if ( missing ) {
			body = el(
				Placeholder,
				{
					icon: 'chart-bar',
					label: __( 'Coywolf Chart', 'coywolf-data-visualizer' ),
					instructions: __( 'This chart no longer exists. Select a different chart.', 'coywolf-data-visualizer' ),
				},
				el(
					Button,
					{
						variant: 'primary',
						onClick: function () {
							setPickerOpen( true );
						},
					},
					__( 'Select chart', 'coywolf-data-visualizer' )
				)
			);
		} else if ( ! chart ) {
			body = el( 'div', { className: 'coywolf-cdv-loading' }, el( Spinner, {} ) );
		} else {
			var caption = attributes.captionOverride || chart.caption;
			body = el(
				Fragment,
				{},
				el( ChartPreview, { chart: chart, attributes: attributes } ),
				attributes.showCaption && caption ? el( 'figcaption', { className: 'coywolf-cdv-caption' }, caption ) : null
			);
		}

		return el(
			'figure',
			blockProps,
			inspector,
			toolbar,
			body,
			pickerOpen
				? el( Picker, {
						onSelect: onSelect,
						onClose: function () {
							setPickerOpen( false );
						},
				  } )
				: null
		);
	}

	registerBlockType( 'coywolf/chart', {
		edit: Edit,
		save: function () {
			return null;
		},
	} );
} )( window.wp );
