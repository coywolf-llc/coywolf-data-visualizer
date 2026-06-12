<?php
/**
 * Color schemes and chart-level display transforms.
 *
 * Schemes are applied at render time (never baked into the stored config),
 * so switching a chart's scheme — or the site default for new charts — is
 * always non-destructive. The palette sources are widely used free/open
 * palettes: Tableau 10, the Okabe–Ito color-blind-safe palette, ColorBrewer
 * (Apache-2.0), and D3's Category 10, plus Coywolf's own.
 *
 * The same transforms exist in js/transforms.js for the live previews on the
 * Add Chart and Edit Chart screens — keep the two in sync.
 *
 * @package CoywolfDataVisualizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coywolf_CDV_Schemes {

	/**
	 * Scheme key => [ label, colors ].
	 *
	 * @var array
	 */
	const SCHEMES = array(
		'coywolf'    => array(
			'label'  => 'Coywolf (default)',
			'colors' => array( '#2563eb', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4', '#f97316', '#84cc16' ),
		),
		'tableau10'  => array(
			'label'  => 'Tableau 10',
			'colors' => array( '#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f', '#edc948', '#b07aa1', '#ff9da7', '#9c755f', '#bab0ac' ),
		),
		'okabe_ito'  => array(
			'label'  => 'Okabe–Ito (color-blind safe)',
			'colors' => array( '#0072b2', '#e69f00', '#009e73', '#d55e00', '#cc79a7', '#56b4e9', '#f0e442', '#999999' ),
		),
		'category10' => array(
			'label'  => 'D3 Category 10',
			'colors' => array( '#1f77b4', '#ff7f0e', '#2ca02c', '#d62728', '#9467bd', '#8c564b', '#e377c2', '#7f7f7f', '#bcbd22', '#17becf' ),
		),
		'set2'       => array(
			'label'  => 'ColorBrewer Set2',
			'colors' => array( '#66c2a5', '#fc8d62', '#8da0cb', '#e78ac3', '#a6d854', '#ffd92f', '#e5c494', '#b3b3b3' ),
		),
		'dark2'      => array(
			'label'  => 'ColorBrewer Dark2',
			'colors' => array( '#1b9e77', '#d95f02', '#7570b3', '#e7298a', '#66a61e', '#e6ab02', '#a6761d', '#666666' ),
		),
		'pastel'     => array(
			'label'  => 'ColorBrewer Pastel',
			'colors' => array( '#fbb4ae', '#b3cde3', '#ccebc5', '#decbe4', '#fed9a6', '#ffffcc', '#e5d8bd', '#fddaec' ),
		),
		'ocean'      => array(
			'label'  => 'Ocean',
			'colors' => array( '#0c4a6e', '#0369a1', '#0284c7', '#0ea5e9', '#38bdf8', '#7dd3fc', '#0d9488', '#14b8a6' ),
		),
		'sunset'     => array(
			'label'  => 'Sunset',
			'colors' => array( '#7c2d12', '#c2410c', '#ea580c', '#f97316', '#fb923c', '#fdba74', '#dc2626', '#f59e0b' ),
		),
	);

	const DEFAULT_SCHEME = 'coywolf';

	/**
	 * Option storing user-uploaded schemes: key => { label, colors }.
	 */
	const CUSTOM_OPTION = 'coywolf_cdv_custom_schemes';

	const CUSTOM_PREFIX     = 'custom-';
	const MIN_CUSTOM_COLORS = 2;
	const MAX_CUSTOM_COLORS = 24;

	/**
	 * Chart types drawn on x/y axes (the toggles only make sense there).
	 *
	 * @var string[]
	 */
	const AXIS_TYPES = array( 'bar', 'line', 'scatter', 'bubble' );

	/**
	 * Interchangeable chart-type families (same data shape).
	 *
	 * @var array
	 */
	const TYPE_FAMILIES = array(
		array( 'bar', 'line' ),
		array( 'pie', 'doughnut', 'polarArea' ),
	);

	/**
	 * Built-in schemes merged with user-uploaded ones.
	 *
	 * @return array<string,array{label:string,colors:string[]}>
	 */
	public static function all() {
		$all    = self::SCHEMES;
		$custom = get_option( self::CUSTOM_OPTION, array() );
		if ( is_array( $custom ) ) {
			foreach ( $custom as $key => $scheme ) {
				if ( is_string( $key ) && is_array( $scheme ) && ! empty( $scheme['colors'] ) && ! isset( $all[ $key ] ) ) {
					$all[ $key ] = array(
						'label'  => isset( $scheme['label'] ) ? (string) $scheme['label'] : $key,
						'colors' => array_values( array_map( 'strval', (array) $scheme['colors'] ) ),
					);
				}
			}
		}
		return $all;
	}

	/**
	 * User-uploaded schemes only.
	 *
	 * @return array<string,array{label:string,colors:string[]}>
	 */
	public static function custom_schemes() {
		$custom = get_option( self::CUSTOM_OPTION, array() );
		return is_array( $custom ) ? $custom : array();
	}

	/**
	 * Whether a scheme key exists (built-in or custom).
	 *
	 * @param string $key Scheme key.
	 * @return bool
	 */
	public static function exists( $key ) {
		$all = self::all();
		return isset( $all[ (string) $key ] );
	}

	/**
	 * Validate an uploaded scheme payload into a storable entry.
	 *
	 * @param mixed $name   Scheme name.
	 * @param mixed $colors Color list.
	 * @return array|WP_Error { key, label, colors }
	 */
	public static function sanitize_custom_scheme( $name, $colors ) {
		$label = sanitize_text_field( (string) $name );
		if ( '' === $label ) {
			return new WP_Error( 'coywolf_cdv_scheme', __( 'The scheme file needs a "name".', 'coywolf-data-visualizer' ) );
		}
		$clean = array();
		foreach ( (array) $colors as $color ) {
			$hex = sanitize_hex_color( trim( (string) $color ) );
			if ( $hex ) {
				$clean[] = strtolower( $hex );
			}
		}
		if ( count( $clean ) < self::MIN_CUSTOM_COLORS || count( $clean ) > self::MAX_CUSTOM_COLORS ) {
			return new WP_Error(
				'coywolf_cdv_scheme',
				sprintf(
					/* translators: 1: minimum colors, 2: maximum colors */
					__( 'The scheme needs between %1$d and %2$d valid hex colors.', 'coywolf-data-visualizer' ),
					self::MIN_CUSTOM_COLORS,
					self::MAX_CUSTOM_COLORS
				)
			);
		}
		$key = self::CUSTOM_PREFIX . sanitize_key( str_replace( ' ', '-', $label ) );
		if ( isset( self::SCHEMES[ $key ] ) || self::CUSTOM_PREFIX === $key ) {
			return new WP_Error( 'coywolf_cdv_scheme', __( 'That scheme name is reserved — pick another.', 'coywolf-data-visualizer' ) );
		}
		return array(
			'key'    => $key,
			'label'  => $label,
			'colors' => $clean,
		);
	}

	/**
	 * key => label for selects.
	 *
	 * @return array<string,string>
	 */
	public static function choices() {
		$out = array();
		foreach ( self::all() as $key => $scheme ) {
			$out[ $key ] = $scheme['label'];
		}
		return $out;
	}

	/**
	 * A scheme's palette ('' or unknown keys return an empty array).
	 *
	 * @param string $key Scheme key.
	 * @return string[]
	 */
	public static function colors( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ]['colors'] : array();
	}

	/**
	 * key => colors map for the admin JS previews.
	 *
	 * @return array<string,string[]>
	 */
	public static function palettes() {
		$out = array();
		foreach ( self::all() as $key => $scheme ) {
			$out[ $key ] = $scheme['colors'];
		}
		return $out;
	}

	/**
	 * Chart types a stored type may be switched to (itself first).
	 *
	 * @param string $type Current chart type.
	 * @return string[]
	 */
	public static function type_family( $type ) {
		foreach ( self::TYPE_FAMILIES as $family ) {
			if ( in_array( $type, $family, true ) ) {
				return $family;
			}
		}
		return array( $type );
	}

	/**
	 * Default per-chart display options, derived from a config so charts that
	 * were generated horizontal (top-10 rankings) round-trip correctly.
	 *
	 * @param array  $config Chart.js config.
	 * @param string $scheme Scheme key for new charts.
	 * @return array
	 */
	public static function default_display( array $config, $scheme = '' ) {
		$index_axis = isset( $config['options']['indexAxis'] ) ? (string) $config['options']['indexAxis'] : 'x';
		return array(
			'scheme'        => self::exists( $scheme ) ? $scheme : '',
			'horizontal'    => 'y' === $index_axis,
			'stacked'       => false,
			'begin_at_zero' => false,
			'hide_grid'     => false,
		);
	}

	/**
	 * Sanitize a display-options payload (from the Edit screen or the Add
	 * Chart save) against a config.
	 *
	 * @param mixed $raw    Submitted options.
	 * @param array $config Chart.js config the options apply to.
	 * @return array
	 */
	public static function sanitize_display( $raw, array $config ) {
		$raw = is_array( $raw ) ? $raw : array();
		$out = self::default_display( $config );
		if ( isset( $raw['scheme'] ) && self::exists( (string) $raw['scheme'] ) ) {
			$out['scheme'] = (string) $raw['scheme'];
		} elseif ( isset( $raw['scheme'] ) && '' === (string) $raw['scheme'] ) {
			$out['scheme'] = '';
		}
		foreach ( array( 'horizontal', 'stacked', 'begin_at_zero', 'hide_grid' ) as $flag ) {
			if ( isset( $raw[ $flag ] ) ) {
				$out[ $flag ] = (bool) $raw[ $flag ];
			}
		}
		// Horizontal only applies to bar charts.
		$type = isset( $config['type'] ) ? (string) $config['type'] : '';
		if ( 'bar' !== $type ) {
			$out['horizontal'] = false;
		}
		return $out;
	}

	/**
	 * Apply a chart's display options (scheme recolor + axis toggles) to its
	 * config. Render-time only — the stored config is never mutated.
	 *
	 * @param array $config  Chart.js config.
	 * @param array $display Display options (default_display() shape).
	 * @return array
	 */
	public static function apply( array $config, array $display ) {
		$type = isset( $config['type'] ) ? (string) $config['type'] : '';

		if ( ! empty( $display['scheme'] ) ) {
			$config = self::recolor( $config, (string) $display['scheme'] );
		}

		if ( ! isset( $config['options'] ) || ! is_array( $config['options'] ) ) {
			$config['options'] = array();
		}

		if ( 'bar' === $type ) {
			$config['options']['indexAxis'] = ! empty( $display['horizontal'] ) ? 'y' : 'x';
		}

		if ( in_array( $type, self::AXIS_TYPES, true ) ) {
			if ( ! empty( $display['stacked'] ) ) {
				$config = self::set_scale( $config, 'x', 'stacked', true );
				$config = self::set_scale( $config, 'y', 'stacked', true );
			}
			if ( ! empty( $display['begin_at_zero'] ) ) {
				$value_axis = ( 'bar' === $type && ! empty( $display['horizontal'] ) ) ? 'x' : 'y';
				$config     = self::set_scale( $config, $value_axis, 'beginAtZero', true );
			}
			if ( ! empty( $display['hide_grid'] ) ) {
				$config = self::set_scale( $config, 'x', 'grid', array( 'display' => false ) );
				$config = self::set_scale( $config, 'y', 'grid', array( 'display' => false ) );
			}
		} elseif ( in_array( $type, array( 'radar', 'polarArea' ), true ) && ! empty( $display['hide_grid'] ) ) {
			$config = self::set_scale( $config, 'r', 'grid', array( 'display' => false ) );
		}

		return $config;
	}

	/**
	 * Recolor a config's datasets with a scheme palette.
	 *
	 * @param array  $config Chart.js config.
	 * @param string $scheme Scheme key.
	 * @return array
	 */
	private static function recolor( array $config, $scheme ) {
		$colors = self::colors( $scheme );
		if ( empty( $colors ) || empty( $config['data']['datasets'] ) || ! is_array( $config['data']['datasets'] ) ) {
			return $config;
		}
		$type  = isset( $config['type'] ) ? (string) $config['type'] : '';
		$count = count( $colors );

		foreach ( $config['data']['datasets'] as $i => $dataset ) {
			if ( ! is_array( $dataset ) ) {
				continue;
			}
			if ( in_array( $type, array( 'pie', 'doughnut', 'polarArea' ), true ) ) {
				$slices = isset( $dataset['data'] ) && is_array( $dataset['data'] ) ? count( $dataset['data'] ) : $count;
				$bg     = array();
				for ( $s = 0; $s < $slices; $s++ ) {
					$bg[] = $colors[ $s % $count ];
				}
				$dataset['backgroundColor'] = $bg;
				unset( $dataset['borderColor'] );
			} else {
				$color = $colors[ $i % $count ];
				if ( in_array( $type, array( 'line', 'radar' ), true ) ) {
					$dataset['borderColor']     = $color;
					$dataset['backgroundColor'] = $color . '33';
				} else {
					$dataset['backgroundColor'] = $color;
					if ( isset( $dataset['borderColor'] ) ) {
						$dataset['borderColor'] = $color;
					}
				}
			}
			$config['data']['datasets'][ $i ] = $dataset;
		}
		return $config;
	}

	/**
	 * Set one key on a scale, creating the scale array as needed.
	 *
	 * @param array  $config Chart.js config.
	 * @param string $axis   Scale id (x, y, r).
	 * @param string $key    Option key.
	 * @param mixed  $value  Option value (arrays are merged over existing).
	 * @return array
	 */
	private static function set_scale( array $config, $axis, $key, $value ) {
		if ( ! isset( $config['options']['scales'] ) || ! is_array( $config['options']['scales'] ) ) {
			$config['options']['scales'] = array();
		}
		if ( ! isset( $config['options']['scales'][ $axis ] ) || ! is_array( $config['options']['scales'][ $axis ] ) ) {
			$config['options']['scales'][ $axis ] = array();
		}
		if ( is_array( $value ) && isset( $config['options']['scales'][ $axis ][ $key ] ) && is_array( $config['options']['scales'][ $axis ][ $key ] ) ) {
			$config['options']['scales'][ $axis ][ $key ] = array_merge( $config['options']['scales'][ $axis ][ $key ], $value );
		} else {
			$config['options']['scales'][ $axis ][ $key ] = $value;
		}
		return $config;
	}
}
