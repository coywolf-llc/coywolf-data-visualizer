<?php
/**
 * Settings screen: Claude API key and model.
 *
 * The key is stored in its own option, displayed only as a masked
 * placeholder, preserved when the field is submitted empty, and removable
 * via a dedicated nonce-protected action.
 *
 * @package CoywolfDataVisualizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coywolf_CDV_Settings {

	const GROUP      = 'coywolf_cdv_settings_group';
	const PAGE       = 'coywolf-data-visualizer-settings';
	const OPTION     = 'coywolf_cdv_settings';
	const KEY_OPTION = 'coywolf_cdv_api_key';

	/**
	 * @var Coywolf_CDV_AI
	 */
	private $ai;

	public function __construct( Coywolf_CDV_AI $ai ) {
		$this->ai = $ai;
	}

	public function init() {
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_post_coywolf_cdv_remove_api_key', array( $this, 'handle_remove_key' ) );
		add_action( 'wp_ajax_coywolf_cdv_test_api', array( $this, 'ajax_test_api' ) );
	}

	/**
	 * Current settings merged over defaults.
	 *
	 * @return array
	 */
	public function get_settings() {
		$stored = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * @return array
	 */
	public static function defaults() {
		return array(
			'model'          => Coywolf_CDV_AI::DEFAULT_MODEL,
			'chart_bg'       => '#ffffff',
			'chart_radius'   => 0,
			'default_scheme' => Coywolf_CDV_Schemes::DEFAULT_SCHEME,
		);
	}

	public function register() {
		register_setting(
			self::GROUP,
			self::KEY_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_api_key' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * An empty submission keeps the stored key (the field is rendered blank
	 * so the key never round-trips to the browser).
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_api_key( $value ) {
		$value = preg_replace( '/\s+/', '', (string) $value );
		if ( '' === $value ) {
			return (string) get_option( self::KEY_OPTION, '' );
		}
		$this->ai->flush_models_cache();
		return $value;
	}

	/**
	 * @param mixed $value Submitted settings array.
	 * @return array
	 */
	public function sanitize_settings( $value ) {
		$out   = self::defaults();
		$value = is_array( $value ) ? $value : array();
		if ( ! empty( $value['model'] ) && preg_match( '/^[a-z0-9._-]+$/i', (string) $value['model'] ) ) {
			$out['model'] = (string) $value['model'];
		}
		// An empty color is intentional (cleared via the picker's clear
		// button) and means a transparent chart background.
		if ( isset( $value['chart_bg'] ) ) {
			$hex             = sanitize_hex_color( (string) $value['chart_bg'] );
			$out['chart_bg'] = $hex ? $hex : '';
		}
		if ( isset( $value['chart_radius'] ) ) {
			$out['chart_radius'] = max( 0, min( 48, (int) $value['chart_radius'] ) );
		}
		if ( isset( $value['default_scheme'] ) && array_key_exists( (string) $value['default_scheme'], Coywolf_CDV_Schemes::SCHEMES ) ) {
			$out['default_scheme'] = (string) $value['default_scheme'];
		}
		return $out;
	}

	/**
	 * Scheme applied to newly created charts.
	 *
	 * @return string
	 */
	public function default_scheme() {
		$settings = $this->get_settings();
		return array_key_exists( (string) $settings['default_scheme'], Coywolf_CDV_Schemes::SCHEMES )
			? (string) $settings['default_scheme']
			: Coywolf_CDV_Schemes::DEFAULT_SCHEME;
	}

	/**
	 * Appearance values the chart render and the editor preview share.
	 *
	 * @return array { bg: string, radius: int }
	 */
	public function appearance() {
		$settings = $this->get_settings();
		return array(
			'bg'     => (string) $settings['chart_bg'],
			'radius' => (int) $settings['chart_radius'],
		);
	}

	/**
	 * Nonce-protected key removal (admin-post).
	 */
	public function handle_remove_key() {
		if ( ! current_user_can( Coywolf_Data_Visualizer::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'coywolf-data-visualizer' ) );
		}
		check_admin_referer( 'coywolf_cdv_remove_api_key' );
		delete_option( self::KEY_OPTION );
		$this->ai->flush_models_cache();
		wp_safe_redirect( add_query_arg( 'cdv-key-removed', '1', admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * AJAX: test the stored key against the Claude API.
	 */
	public function ajax_test_api() {
		check_ajax_referer( 'coywolf_cdv_test_api' );
		if ( ! current_user_can( Coywolf_Data_Visualizer::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'coywolf-data-visualizer' ) ), 403 );
		}
		$result = $this->ai->test_connection();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Connected — your Claude API key works.', 'coywolf-data-visualizer' ) ) );
	}

	/**
	 * Render the Settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( Coywolf_Data_Visualizer::CAPABILITY ) ) {
			return;
		}
		$has_key  = $this->ai->is_configured();
		$settings = $this->get_settings();
		$models   = $has_key ? $this->ai->models() : array();

		// Make sure the saved/default model is always selectable even when
		// the live model list is unavailable.
		$model_ids = wp_list_pluck( $models, 'id' );
		foreach ( array_unique( array( $settings['model'], Coywolf_CDV_AI::DEFAULT_MODEL ) ) as $must_have ) {
			if ( ! in_array( $must_have, $model_ids, true ) ) {
				$models[] = array(
					'id'   => $must_have,
					'name' => $must_have,
				);
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our redirect.
		$key_removed = isset( $_GET['cdv-key-removed'] );
		?>
		<div class="wrap coywolf-cdv-settings">
			<h1><?php esc_html_e( 'Coywolf Data Visualizer Settings', 'coywolf-data-visualizer' ); ?></h1>

			<?php if ( $key_removed ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'API key removed.', 'coywolf-data-visualizer' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'Claude API (optional)', 'coywolf-data-visualizer' ); ?></h2>
				<p><?php esc_html_e( 'The plugin works without a key — the built-in analyzer designs charts from your data\'s column types. Add a Claude API key (from the Anthropic Console) to have Claude design the charts and write insight captions; it gives the best results.', 'coywolf-data-visualizer' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="coywolf-cdv-api-key"><?php esc_html_e( 'API key', 'coywolf-data-visualizer' ); ?></label>
						</th>
						<td>
							<input type="password" class="regular-text" id="coywolf-cdv-api-key"
								name="<?php echo esc_attr( self::KEY_OPTION ); ?>" value="" autocomplete="off"
								placeholder="<?php echo esc_attr( $has_key ? __( 'Saved — enter a new key to replace it', 'coywolf-data-visualizer' ) : 'sk-ant-…' ); ?>" />
							<?php if ( $has_key ) : ?>
								<a class="button-link button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=coywolf_cdv_remove_api_key' ), 'coywolf_cdv_remove_api_key' ) ); ?>"><?php esc_html_e( 'Remove', 'coywolf-data-visualizer' ); ?></a>
								<button type="button" class="button" id="coywolf-cdv-test-key"><?php esc_html_e( 'Test connection', 'coywolf-data-visualizer' ); ?></button>
								<span id="coywolf-cdv-test-result" role="status"></span>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'The key is stored in your WordPress database and is only sent to api.anthropic.com.', 'coywolf-data-visualizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="coywolf-cdv-model"><?php esc_html_e( 'Model', 'coywolf-data-visualizer' ); ?></label>
						</th>
						<td>
							<select id="coywolf-cdv-model" name="<?php echo esc_attr( self::OPTION ); ?>[model]">
								<?php foreach ( $models as $model ) : ?>
									<option value="<?php echo esc_attr( $model['id'] ); ?>" <?php selected( $settings['model'], $model['id'] ); ?>><?php echo esc_html( $model['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The Claude model used to analyze your data and design charts.', 'coywolf-data-visualizer' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Chart appearance', 'coywolf-data-visualizer' ); ?></h2>
				<p><?php esc_html_e( 'Applied to every chart on your site.', 'coywolf-data-visualizer' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Background color', 'coywolf-data-visualizer' ); ?></th>
						<td>
							<p class="coywolf-cdv-color-field" data-key="chart_bg">
								<input type="hidden" class="coywolf-cdv-color-value" name="<?php echo esc_attr( self::OPTION ); ?>[chart_bg]" value="<?php echo esc_attr( $settings['chart_bg'] ); ?>" />
								<span class="coywolf-cdv-color-mount"></span>
							</p>
							<p class="description"><?php esc_html_e( 'Drawn behind every chart — keeps them readable on dark themes. Clear the color for a transparent background.', 'coywolf-data-visualizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="coywolf-cdv-chart-radius"><?php esc_html_e( 'Corner radius', 'coywolf-data-visualizer' ); ?></label>
						</th>
						<td>
							<input type="number" min="0" max="48" step="1" id="coywolf-cdv-chart-radius" name="<?php echo esc_attr( self::OPTION ); ?>[chart_radius]" value="<?php echo (int) $settings['chart_radius']; ?>" class="small-text" /> px
							<p class="description"><?php esc_html_e( 'Rounds the corners of the chart background.', 'coywolf-data-visualizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="coywolf-cdv-default-scheme"><?php esc_html_e( 'Color scheme', 'coywolf-data-visualizer' ); ?></label>
						</th>
						<td>
							<select id="coywolf-cdv-default-scheme" name="<?php echo esc_attr( self::OPTION ); ?>[default_scheme]" class="coywolf-cdv-scheme-select">
								<?php foreach ( Coywolf_CDV_Schemes::choices() as $scheme_key => $scheme_label ) : ?>
									<option value="<?php echo esc_attr( $scheme_key ); ?>" <?php selected( $settings['default_scheme'], $scheme_key ); ?>><?php echo esc_html( $scheme_label ); ?></option>
								<?php endforeach; ?>
							</select>
							<span class="coywolf-cdv-scheme-swatches"></span>
							<p class="description"><?php esc_html_e( 'The palette applied to newly created charts. Each chart can switch to a different scheme on its Edit Chart screen. Palettes include Tableau 10, the Okabe–Ito color-blind-safe set, ColorBrewer, and D3 Category 10.', 'coywolf-data-visualizer' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
