<?php
/**
 * The Coywolf Chart block: registration, editor assets, and the dynamic
 * front-end render.
 *
 * The block stores only the chart ID plus presentation overrides; the
 * Chart.js config lives on the chart post, so editing a chart updates every
 * post that embeds it. The config is emitted as a data attribute and
 * initialized by js/view.js — no inline scripts.
 *
 * @package CoywolfDataVisualizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coywolf_CDV_Block {

	/**
	 * @var Coywolf_CDV_Charts
	 */
	private $charts;

	public function __construct( Coywolf_CDV_Charts $charts ) {
		$this->charts = $charts;
	}

	public function init() {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front' ) );
	}

	public function register() {
		$version = Coywolf_Data_Visualizer::VERSION;

		wp_register_script( 'coywolf-cdv-chartjs', COYWOLF_CDV_URL . 'vendor/chartjs/chart.umd.js', array(), '4.5.1', true );

		wp_register_script(
			'coywolf-cdv-block',
			COYWOLF_CDV_URL . 'js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'coywolf-cdv-chartjs' ),
			$version,
			true
		);
		wp_set_script_translations( 'coywolf-cdv-block', 'coywolf-data-visualizer' );
		wp_register_style( 'coywolf-cdv-block-editor', COYWOLF_CDV_URL . 'css/block-editor.css', array(), $version );

		wp_register_script( 'coywolf-cdv-view', COYWOLF_CDV_URL . 'js/view.js', array( 'coywolf-cdv-chartjs' ), $version, true );
		wp_register_style( 'coywolf-cdv-view', COYWOLF_CDV_URL . 'css/view.css', array(), $version );

		register_block_type(
			COYWOLF_CDV_PATH,
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Load the view assets only when a singular view embeds the block.
	 */
	public function enqueue_front() {
		if ( is_singular() && has_block( Coywolf_CDV_Charts::BLOCK_NAME ) ) {
			wp_enqueue_style( 'coywolf-cdv-view' );
			wp_enqueue_script( 'coywolf-cdv-view' );
		}
	}

	/**
	 * Dynamic render: look up the chart, apply the block's presentation
	 * overrides to the config, and emit the figure + canvas.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render( $attributes ) {
		$chart_id = isset( $attributes['chartId'] ) ? (int) $attributes['chartId'] : 0;
		if ( $chart_id <= 0 ) {
			return '';
		}
		$chart = $this->charts->get_chart( $chart_id );
		if ( ! $chart ) {
			return '';
		}

		$config = $this->apply_overrides( $chart, $attributes );

		$show_caption = ! isset( $attributes['showCaption'] ) || (bool) $attributes['showCaption'];
		$caption      = isset( $attributes['captionOverride'] ) && '' !== trim( (string) $attributes['captionOverride'] )
			? (string) $attributes['captionOverride']
			: $chart['caption'];

		$max_width = isset( $attributes['maxWidth'] ) ? (int) $attributes['maxWidth'] : 0;
		$height    = isset( $attributes['height'] ) ? (int) $attributes['height'] : 0;

		$figure_style = $max_width > 0 ? 'max-width:' . $max_width . 'px;' : '';
		$wrap_style   = $height > 0 ? 'height:' . $height . 'px;' : '';

		$aria = $chart['title'];
		if ( '' !== $caption ) {
			$aria .= '. ' . $caption;
		}

		// The front-end script may not be enqueued yet when the block renders
		// outside the_content (e.g. in a query loop on an archive) — make sure
		// it loads whenever a chart is actually output.
		wp_enqueue_style( 'coywolf-cdv-view' );
		wp_enqueue_script( 'coywolf-cdv-view' );

		$wrapper = get_block_wrapper_attributes(
			array(
				'class' => 'coywolf-cdv-chart',
				'style' => $figure_style,
			)
		);

		$html  = '<figure ' . $wrapper . '>';
		$html .= '<div class="coywolf-cdv-canvas"' . ( '' !== $wrap_style ? ' style="' . esc_attr( $wrap_style ) . '"' : '' ) . '>';
		$html .= '<canvas data-coywolf-cdv-config="' . esc_attr( (string) wp_json_encode( $config ) ) . '"';
		if ( $height > 0 ) {
			$html .= ' data-coywolf-cdv-fixed-height="1"';
		}
		$html .= ' role="img" aria-label="' . esc_attr( $aria ) . '"></canvas>';
		$html .= '</div>';
		if ( $show_caption && '' !== $caption ) {
			$html .= '<figcaption class="coywolf-cdv-caption">' . esc_html( $caption ) . '</figcaption>';
		}
		$html .= '</figure>';

		return $html;
	}

	/**
	 * Merge the block's presentation settings into the stored Chart.js
	 * config. The same logic exists in js/block.js for the editor preview —
	 * keep the two in sync.
	 *
	 * @param array $chart      Chart record.
	 * @param array $attributes Block attributes.
	 * @return array
	 */
	private function apply_overrides( array $chart, array $attributes ) {
		$config = $chart['config'];
		if ( ! isset( $config['options'] ) || ! is_array( $config['options'] ) ) {
			$config['options'] = array();
		}
		if ( ! isset( $config['options']['plugins'] ) || ! is_array( $config['options']['plugins'] ) ) {
			$config['options']['plugins'] = array();
		}

		$show_title = ! isset( $attributes['showTitle'] ) || (bool) $attributes['showTitle'];
		if ( $show_title && '' !== $chart['title'] ) {
			$config['options']['plugins']['title'] = array(
				'display' => true,
				'text'    => $chart['title'],
			);
		} else {
			unset( $config['options']['plugins']['title'] );
		}

		if ( isset( $attributes['showLegend'] ) && false === (bool) $attributes['showLegend'] ) {
			if ( ! isset( $config['options']['plugins']['legend'] ) || ! is_array( $config['options']['plugins']['legend'] ) ) {
				$config['options']['plugins']['legend'] = array();
			}
			$config['options']['plugins']['legend']['display'] = false;
		} elseif ( ! empty( $attributes['legendPosition'] ) && in_array( $attributes['legendPosition'], array( 'top', 'bottom', 'left', 'right' ), true ) ) {
			if ( ! isset( $config['options']['plugins']['legend'] ) || ! is_array( $config['options']['plugins']['legend'] ) ) {
				$config['options']['plugins']['legend'] = array();
			}
			$config['options']['plugins']['legend']['position'] = (string) $attributes['legendPosition'];
		}

		$config['options']['responsive']          = true;
		$height                                   = isset( $attributes['height'] ) ? (int) $attributes['height'] : 0;
		$config['options']['maintainAspectRatio'] = $height <= 0;

		return $config;
	}
}
