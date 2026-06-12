<?php
/**
 * Admin screens: menu, All Charts, Add Chart, and the AJAX endpoints behind
 * the Analyze / Save flow.
 *
 * @package CoywolfDataVisualizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coywolf_CDV_Admin {

	const PAGE      = 'coywolf-data-visualizer';
	const PAGE_ADD  = 'coywolf-data-visualizer-add';
	const PAGE_EDIT = 'coywolf-data-visualizer-edit';
	const PAGE_DOCS = 'coywolf-data-visualizer-docs';

	/**
	 * Edit Chart screen URL for a chart.
	 *
	 * @param int $chart_id Chart post ID.
	 * @return string
	 */
	public static function edit_url( $chart_id ) {
		return add_query_arg(
			array(
				'page'  => self::PAGE_EDIT,
				'chart' => (int) $chart_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @var Coywolf_CDV_Charts
	 */
	private $charts;

	/**
	 * @var Coywolf_CDV_AI
	 */
	private $ai;

	/**
	 * @var Coywolf_CDV_Settings
	 */
	private $settings;

	/**
	 * Menu page hook suffixes, keyed by screen.
	 *
	 * @var array<string,string>
	 */
	private $hooks = array();

	/**
	 * @var Coywolf_CDV_List_Table|null
	 */
	private $list_table = null;

	public function __construct( Coywolf_CDV_Charts $charts, Coywolf_CDV_AI $ai, Coywolf_CDV_Settings $settings ) {
		$this->charts   = $charts;
		$this->ai       = $ai;
		$this->settings = $settings;
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_coywolf_cdv_analyze', array( $this, 'ajax_analyze' ) );
		add_action( 'wp_ajax_coywolf_cdv_save_charts', array( $this, 'ajax_save_charts' ) );
	}

	public function register_menu() {
		$cap = Coywolf_Data_Visualizer::CAPABILITY;

		$this->hooks['list']     = add_menu_page(
			__( 'Coywolf Data Visualizer', 'coywolf-data-visualizer' ),
			__( 'Charts', 'coywolf-data-visualizer' ),
			$cap,
			self::PAGE,
			array( $this, 'render_charts_page' ),
			'dashicons-chart-bar',
			27
		);
		$this->hooks['list_sub'] = add_submenu_page(
			self::PAGE,
			__( 'All Charts', 'coywolf-data-visualizer' ),
			__( 'All Charts', 'coywolf-data-visualizer' ),
			$cap,
			self::PAGE,
			array( $this, 'render_charts_page' )
		);
		$this->hooks['add']      = add_submenu_page(
			self::PAGE,
			__( 'Add Chart', 'coywolf-data-visualizer' ),
			__( 'Add Chart', 'coywolf-data-visualizer' ),
			$cap,
			self::PAGE_ADD,
			array( $this, 'render_add_page' )
		);
		$this->hooks['settings'] = add_submenu_page(
			self::PAGE,
			__( 'Settings', 'coywolf-data-visualizer' ),
			__( 'Settings', 'coywolf-data-visualizer' ),
			$cap,
			Coywolf_CDV_Settings::PAGE,
			array( $this->settings, 'render_page' )
		);
		$this->hooks['docs']     = add_submenu_page(
			self::PAGE,
			__( 'Documentation', 'coywolf-data-visualizer' ),
			__( 'Documentation', 'coywolf-data-visualizer' ),
			$cap,
			self::PAGE_DOCS,
			array( 'Coywolf_CDV_Docs', 'render_page' )
		);
		// Edit Chart: registered under Charts so WordPress's routing,
		// capability check, and menu highlighting all resolve natively. The
		// menu item itself is removed at admin_head — which runs after
		// admin.php has routed and permission-checked the request, so
		// (unlike calling remove_submenu_page() here at registration time,
		// which breaks parent resolution with "Sorry, you are not allowed
		// to access this page") the removal only affects display. On the
		// Edit Chart screen the item is kept and shows as current.
		$this->hooks['edit'] = add_submenu_page(
			self::PAGE,
			__( 'Edit Chart', 'coywolf-data-visualizer' ),
			__( 'Edit Chart', 'coywolf-data-visualizer' ),
			$cap,
			self::PAGE_EDIT,
			array( $this, 'render_edit_page' )
		);
		add_action( 'admin_head', array( $this, 'hide_edit_menu_item' ) );

		add_action( 'load-' . $this->hooks['list'], array( $this, 'handle_chart_actions' ) );
		add_action( 'load-' . $this->hooks['edit'], array( $this, 'handle_edit_save' ) );
	}

	/**
	 * Drop the Edit Chart menu item just before the menu renders, on every
	 * screen except the Edit Chart screen itself.
	 */
	public function hide_edit_menu_item() {
		global $plugin_page;
		if ( self::PAGE_EDIT !== $plugin_page ) {
			remove_submenu_page( self::PAGE, self::PAGE_EDIT );
		}
	}

	/**
	 * Process the Edit Chart form before output, then redirect back.
	 */
	public function handle_edit_save() {
		if ( ! current_user_can( Coywolf_Data_Visualizer::CAPABILITY ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence check; nonce verified below.
		if ( empty( $_POST['coywolf_cdv_edit_chart'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified here.
		$chart_id = isset( $_POST['chart_id'] ) ? absint( $_POST['chart_id'] ) : 0;
		check_admin_referer( 'coywolf_cdv_edit_chart_' . $chart_id );

		$chart = $this->charts->get_chart_raw( $chart_id );
		if ( ! $chart ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$title   = isset( $_POST['chart_title'] ) ? sanitize_text_field( wp_unslash( $_POST['chart_title'] ) ) : '';
		$caption = isset( $_POST['chart_caption'] ) ? sanitize_textarea_field( wp_unslash( $_POST['chart_caption'] ) ) : '';
		$type    = isset( $_POST['chart_type'] ) ? sanitize_key( wp_unslash( $_POST['chart_type'] ) ) : '';
		$display = array(
			'scheme'        => isset( $_POST['display_scheme'] ) ? sanitize_key( wp_unslash( $_POST['display_scheme'] ) ) : '',
			'horizontal'    => ! empty( $_POST['display_horizontal'] ),
			'stacked'       => ! empty( $_POST['display_stacked'] ),
			'begin_at_zero' => ! empty( $_POST['display_begin_at_zero'] ),
			'hide_grid'     => ! empty( $_POST['display_hide_grid'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Chart type may switch within its family (bar↔line, pie↔doughnut↔polarArea).
		$config = $chart['config'];
		if ( '' !== $type && $type !== $chart['config']['type'] && in_array( $type, Coywolf_CDV_Schemes::type_family( (string) $chart['config']['type'] ), true ) ) {
			$config['type'] = $type;
		}

		$result = $this->charts->update_chart( $chart_id, $config, $title, $caption, $display );

		wp_safe_redirect(
			add_query_arg(
				'cdv-updated',
				is_wp_error( $result ) ? '0' : '1',
				self::edit_url( $chart_id )
			)
		);
		exit;
	}

	/**
	 * Edit Chart screen: live preview, name, caption, chart type, color
	 * scheme, and display toggles.
	 */
	public function render_edit_page() {
		if ( ! current_user_can( Coywolf_Data_Visualizer::CAPABILITY ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen lookup.
		$chart_id = isset( $_GET['chart'] ) ? absint( $_GET['chart'] ) : 0;
		$chart    = $this->charts->get_chart_raw( $chart_id );
		if ( ! $chart ) {
			?>
			<div class="wrap coywolf-cdv-edit">
				<h1><?php esc_html_e( 'Edit Chart', 'coywolf-data-visualizer' ); ?></h1>
				<p><?php esc_html_e( 'That chart no longer exists.', 'coywolf-data-visualizer' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Back to All Charts', 'coywolf-data-visualizer' ); ?></a></p>
			</div>
			<?php
			return;
		}

		$display   = $chart['display'];
		$type      = (string) $chart['config']['type'];
		$family    = Coywolf_CDV_Schemes::type_family( $type );
		$is_axis   = in_array( $type, Coywolf_CDV_Schemes::AXIS_TYPES, true ) || in_array( 'bar', $family, true );
		$multi_set = isset( $chart['config']['data']['datasets'] ) && count( $chart['config']['data']['datasets'] ) > 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our redirect.
		$updated = isset( $_GET['cdv-updated'] ) ? sanitize_key( wp_unslash( $_GET['cdv-updated'] ) ) : '';
		?>
		<div class="wrap coywolf-cdv-edit">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Edit Chart', 'coywolf-data-visualizer' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>" class="page-title-action"><?php esc_html_e( 'All Charts', 'coywolf-data-visualizer' ); ?></a>
			<hr class="wp-header-end" />

			<?php if ( '1' === $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Chart updated. Every post and page embedding it shows the change immediately.', 'coywolf-data-visualizer' ); ?></p></div>
			<?php elseif ( '0' === $updated ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The chart could not be updated.', 'coywolf-data-visualizer' ); ?></p></div>
			<?php endif; ?>

			<div class="coywolf-cdv-edit-layout">
				<div class="coywolf-cdv-edit-preview">
					<div class="coywolf-cdv-edit-canvas"><canvas id="coywolf-cdv-edit-canvas"></canvas></div>
					<p class="description" id="coywolf-cdv-edit-caption-preview"></p>
				</div>

				<form method="post" class="coywolf-cdv-edit-form">
					<input type="hidden" name="coywolf_cdv_edit_chart" value="1" />
					<input type="hidden" name="chart_id" value="<?php echo (int) $chart['id']; ?>" />
					<?php wp_nonce_field( 'coywolf_cdv_edit_chart_' . (int) $chart['id'] ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="coywolf-cdv-edit-title"><?php esc_html_e( 'Name', 'coywolf-data-visualizer' ); ?></label></th>
							<td><input type="text" id="coywolf-cdv-edit-title" name="chart_title" class="regular-text" value="<?php echo esc_attr( $chart['title'] ); ?>" required /></td>
						</tr>
						<tr>
							<th scope="row"><label for="coywolf-cdv-edit-caption"><?php esc_html_e( 'Caption', 'coywolf-data-visualizer' ); ?></label></th>
							<td><textarea id="coywolf-cdv-edit-caption" name="chart_caption" rows="3" class="large-text"><?php echo esc_textarea( $chart['caption'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="coywolf-cdv-edit-type"><?php esc_html_e( 'Chart type', 'coywolf-data-visualizer' ); ?></label></th>
							<td>
								<select id="coywolf-cdv-edit-type" name="chart_type" <?php disabled( count( $family ) < 2 ); ?>>
									<?php foreach ( $family as $family_type ) : ?>
										<option value="<?php echo esc_attr( $family_type ); ?>" <?php selected( $type, $family_type ); ?>><?php echo esc_html( $family_type ); ?></option>
									<?php endforeach; ?>
								</select>
								<?php if ( count( $family ) < 2 ) : ?>
									<p class="description"><?php esc_html_e( 'This chart type has no compatible alternatives.', 'coywolf-data-visualizer' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="coywolf-cdv-edit-scheme"><?php esc_html_e( 'Color scheme', 'coywolf-data-visualizer' ); ?></label></th>
							<td>
								<select id="coywolf-cdv-edit-scheme" name="display_scheme" class="coywolf-cdv-scheme-select">
									<option value="" <?php selected( $display['scheme'], '' ); ?>><?php esc_html_e( 'Original colors', 'coywolf-data-visualizer' ); ?></option>
									<?php foreach ( Coywolf_CDV_Schemes::choices() as $scheme_key => $scheme_label ) : ?>
										<option value="<?php echo esc_attr( $scheme_key ); ?>" <?php selected( $display['scheme'], $scheme_key ); ?>><?php echo esc_html( $scheme_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<span class="coywolf-cdv-scheme-swatches"></span>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Display options', 'coywolf-data-visualizer' ); ?></th>
							<td>
								<fieldset>
									<?php if ( in_array( 'bar', $family, true ) ) : ?>
										<label><input type="checkbox" name="display_horizontal" id="coywolf-cdv-edit-horizontal" value="1" <?php checked( $display['horizontal'] ); ?> /> <?php esc_html_e( 'Horizontal bars (swap the axes)', 'coywolf-data-visualizer' ); ?></label><br />
									<?php endif; ?>
									<?php if ( $is_axis && $multi_set ) : ?>
										<label><input type="checkbox" name="display_stacked" id="coywolf-cdv-edit-stacked" value="1" <?php checked( $display['stacked'] ); ?> /> <?php esc_html_e( 'Stack the datasets', 'coywolf-data-visualizer' ); ?></label><br />
									<?php endif; ?>
									<?php if ( $is_axis ) : ?>
										<label><input type="checkbox" name="display_begin_at_zero" id="coywolf-cdv-edit-zero" value="1" <?php checked( $display['begin_at_zero'] ); ?> /> <?php esc_html_e( 'Begin the value axis at zero', 'coywolf-data-visualizer' ); ?></label><br />
									<?php endif; ?>
									<?php if ( $is_axis || in_array( $type, array( 'radar', 'polarArea' ), true ) ) : ?>
										<label><input type="checkbox" name="display_hide_grid" id="coywolf-cdv-edit-grid" value="1" <?php checked( $display['hide_grid'] ); ?> /> <?php esc_html_e( 'Hide grid lines', 'coywolf-data-visualizer' ); ?></label>
									<?php endif; ?>
								</fieldset>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Update Chart', 'coywolf-data-visualizer' ) ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Process delete actions on the All Charts screen before output, then
	 * redirect back to a clean URL.
	 */
	public function handle_chart_actions() {
		if ( ! current_user_can( Coywolf_Data_Visualizer::CAPABILITY ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified per action below.
		$action = '';
		if ( isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
		} elseif ( isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
		}
		if ( 'delete' !== $action ) {
			return;
		}

		$deleted = 0;
		$single  = isset( $_REQUEST['chart'] ) && ! is_array( $_REQUEST['chart'] ) ? absint( $_REQUEST['chart'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $single > 0 ) {
			check_admin_referer( 'coywolf_cdv_delete_chart_' . $single );
			if ( $this->charts->delete_chart( $single ) ) {
				++$deleted;
			}
		} else {
			check_admin_referer( 'bulk-charts' );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
			$ids = isset( $_REQUEST['chart'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['chart'] ) ) : array();
			foreach ( $ids as $id ) {
				if ( $id > 0 && $this->charts->delete_chart( $id ) ) {
					++$deleted;
				}
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE,
					'cdv-deleted' => $deleted,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * All Charts screen.
	 */
	public function render_charts_page() {
		if ( ! current_user_can( Coywolf_Data_Visualizer::CAPABILITY ) ) {
			return;
		}
		$this->list_table = new Coywolf_CDV_List_Table( $this->charts );
		$this->list_table->prepare_items();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our redirect.
		$deleted = isset( $_GET['cdv-deleted'] ) ? absint( $_GET['cdv-deleted'] ) : 0;
		?>
		<div class="wrap coywolf-cdv-list">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'All Charts', 'coywolf-data-visualizer' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_ADD ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add Chart', 'coywolf-data-visualizer' ); ?></a>
			<hr class="wp-header-end" />

			<?php if ( $deleted > 0 ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					printf(
						/* translators: %d: number of deleted charts */
						esc_html( _n( '%d chart deleted. Its block was removed from the posts and pages that used it.', '%d charts deleted. Their blocks were removed from the posts and pages that used them.', $deleted, 'coywolf-data-visualizer' ) ),
						(int) $deleted
					);
					?>
				</p></div>
			<?php endif; ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<?php
				$this->list_table->search_box( __( 'Search charts', 'coywolf-data-visualizer' ), 'coywolf-cdv-search' );
				$this->list_table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Add Chart screen.
	 */
	public function render_add_page() {
		if ( ! current_user_can( Coywolf_Data_Visualizer::CAPABILITY ) ) {
			return;
		}
		?>
		<div class="wrap coywolf-cdv-add">
			<h1><?php esc_html_e( 'Add Chart', 'coywolf-data-visualizer' ); ?></h1>

			<?php $has_key = $this->ai->is_configured(); ?>

			<p><?php esc_html_e( 'Upload a data file, describe what the data represents, and click Analyze for a set of suggested charts. Pick the ones you want to keep.', 'coywolf-data-visualizer' ); ?></p>

			<?php if ( ! $has_key ) : ?>
				<div class="notice notice-info inline"><p>
					<?php
					printf(
						/* translators: %s: Settings screen URL */
						wp_kses_post( __( 'Charts are designed by the built-in analyzer. For richer, AI-designed charts and captions, add a Claude API key on the <a href="%s">Settings page</a> — it&#8217;s optional.', 'coywolf-data-visualizer' ) ),
						esc_url( admin_url( 'admin.php?page=' . Coywolf_CDV_Settings::PAGE ) )
					);
					?>
				</p></div>
			<?php endif; ?>

			<form id="coywolf-cdv-analyze-form">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="coywolf-cdv-file"><?php esc_html_e( 'Data file', 'coywolf-data-visualizer' ); ?></label>
						</th>
						<td>
							<input type="file" id="coywolf-cdv-file" name="data_file" accept=".csv,.tsv,.txt,.json,.xlsx" required />
							<p class="description"><?php esc_html_e( 'CSV, TSV, JSON, or Excel (.xlsx). 10 MB maximum.', 'coywolf-data-visualizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="coywolf-cdv-explanation"><?php esc_html_e( 'What does this data represent?', 'coywolf-data-visualizer' ); ?></label>
						</th>
						<td>
							<textarea id="coywolf-cdv-explanation" name="explanation" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Monthly organic traffic and conversions for our three product pages, January through December 2025.', 'coywolf-data-visualizer' ); ?>"></textarea>
							<p class="description">
								<?php
								echo $has_key
									? esc_html__( 'Required for the Claude engine — context helps it pick the most relevant charts. The data and this explanation are sent to the Claude API.', 'coywolf-data-visualizer' )
									: esc_html__( 'Optional for the built-in analyzer, which works from the column names and values alone.', 'coywolf-data-visualizer' );
								?>
							</p>
						</td>
					</tr>
					<?php if ( $has_key ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Analysis engine', 'coywolf-data-visualizer' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Analysis engine', 'coywolf-data-visualizer' ); ?></legend>
									<label>
										<input type="radio" name="engine" value="ai" checked="checked" />
										<?php esc_html_e( 'Claude (recommended) — AI-designed charts and insight captions via your API key', 'coywolf-data-visualizer' ); ?>
									</label><br />
									<label>
										<input type="radio" name="engine" value="builtin" />
										<?php esc_html_e( 'Built-in analyzer — instant and free, designed from the column types', 'coywolf-data-visualizer' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>
					<?php endif; ?>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary" id="coywolf-cdv-analyze"><?php esc_html_e( 'Analyze', 'coywolf-data-visualizer' ); ?></button>
					<span class="spinner" id="coywolf-cdv-analyze-spinner"></span>
				</p>
			</form>

			<div class="notice notice-error" id="coywolf-cdv-error" hidden><p></p></div>

			<div id="coywolf-cdv-results" hidden>
				<h2><?php esc_html_e( 'Suggested charts', 'coywolf-data-visualizer' ); ?></h2>
				<p id="coywolf-cdv-engine-note" class="description"></p>
				<p><?php esc_html_e( 'Select the charts to save. You can edit each title and caption before saving.', 'coywolf-data-visualizer' ); ?></p>
				<div id="coywolf-cdv-suggestions" class="coywolf-cdv-suggestions"></div>
				<p class="submit">
					<button type="button" class="button button-primary" id="coywolf-cdv-save"><?php esc_html_e( 'Save selected charts', 'coywolf-data-visualizer' ); ?></button>
					<span class="spinner" id="coywolf-cdv-save-spinner"></span>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue admin assets on the plugin's screens.
	 *
	 * @param string $hook Current screen hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, $this->hooks, true ) ) {
			return;
		}
		$version = Coywolf_Data_Visualizer::VERSION;
		wp_enqueue_style( 'coywolf-cdv-admin', COYWOLF_CDV_URL . 'css/admin.css', array(), $version );

		$needs_js = array( $this->hooks['add'], $this->hooks['settings'], $this->hooks['edit'] );
		if ( ! in_array( $hook, $needs_js, true ) ) {
			return;
		}

		wp_register_script( 'coywolf-cdv-chartjs', COYWOLF_CDV_URL . 'vendor/chartjs/chart.umd.js', array(), '4.5.1', true );
		wp_register_script( 'coywolf-cdv-transforms', COYWOLF_CDV_URL . 'js/transforms.js', array(), $version, true );

		if ( $hook === $this->hooks['edit'] ) {
			$this->enqueue_edit_assets( $version );
			return;
		}

		wp_enqueue_script(
			'coywolf-cdv-admin',
			COYWOLF_CDV_URL . 'js/admin.js',
			$hook === $this->hooks['add'] ? array( 'coywolf-cdv-chartjs', 'coywolf-cdv-transforms' ) : array(),
			$version,
			true
		);

		if ( $hook === $this->hooks['settings'] ) {
			wp_enqueue_style( 'coywolf-cdv-jscolorpicker', COYWOLF_CDV_URL . 'vendor/jscolorpicker/colorpicker.min.css', array(), '1.1.0' );
			wp_enqueue_script( 'coywolf-cdv-jscolorpicker', COYWOLF_CDV_URL . 'vendor/jscolorpicker/colorpicker.iife.min.js', array(), '1.1.0', true );
			wp_enqueue_script( 'coywolf-cdv-settings', COYWOLF_CDV_URL . 'js/settings.js', array( 'coywolf-cdv-jscolorpicker', 'coywolf-cdv-transforms' ), $version, true );
			wp_localize_script(
				'coywolf-cdv-settings',
				'coywolfCDVSettings',
				array( 'palettes' => Coywolf_CDV_Schemes::palettes() )
			);
		}
		wp_localize_script(
			'coywolf-cdv-admin',
			'coywolfCDVAdmin',
			array(
				'palettes'      => Coywolf_CDV_Schemes::palettes(),
				'schemes'       => Coywolf_CDV_Schemes::choices(),
				'defaultScheme' => $this->settings->default_scheme(),
				'typeFamilies'  => Coywolf_CDV_Schemes::TYPE_FAMILIES,
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'analyzeNonce'  => wp_create_nonce( 'coywolf_cdv_analyze' ),
				'saveNonce'     => wp_create_nonce( 'coywolf_cdv_save_charts' ),
				'testNonce'     => wp_create_nonce( 'coywolf_cdv_test_api' ),
				'listUrl'       => admin_url( 'admin.php?page=' . self::PAGE ),
				'maxBytes'      => Coywolf_CDV_Parser::MAX_FILE_BYTES,
				'i18n'          => array(
					'analyzing'    => __( 'Analyzing your data — this can take a minute…', 'coywolf-data-visualizer' ),
					'tooLarge'     => __( 'That file is larger than the 10 MB limit.', 'coywolf-data-visualizer' ),
					'noSelection'  => __( 'Select at least one chart to save.', 'coywolf-data-visualizer' ),
					'requestFail'  => __( 'The request failed. Check your connection and try again.', 'coywolf-data-visualizer' ),
					'testing'      => __( 'Testing…', 'coywolf-data-visualizer' ),
					'saving'       => __( 'Saving…', 'coywolf-data-visualizer' ),
					'titleLabel'   => __( 'Title', 'coywolf-data-visualizer' ),
					'captionLabel' => __( 'Caption', 'coywolf-data-visualizer' ),
					'engineAi'     => __( 'Designed by Claude from your data and explanation.', 'coywolf-data-visualizer' ),
					'engineLocal'  => __( 'Designed by the built-in analyzer from the column types — add a Claude API key in Settings for richer suggestions.', 'coywolf-data-visualizer' ),
					'schemeLabel'  => __( 'Color scheme', 'coywolf-data-visualizer' ),
					'typeLabel'    => __( 'Chart type', 'coywolf-data-visualizer' ),
					'horizontal'   => __( 'Horizontal bars', 'coywolf-data-visualizer' ),
					'stacked'      => __( 'Stacked', 'coywolf-data-visualizer' ),
					'beginAtZero'  => __( 'Begin axis at zero', 'coywolf-data-visualizer' ),
					'hideGrid'     => __( 'Hide grid lines', 'coywolf-data-visualizer' ),
				),
			)
		);
	}

	/**
	 * Assets + data for the Edit Chart screen: Chart.js, the shared
	 * transforms, and the chart's raw config so the preview can re-apply
	 * options live exactly as the server will.
	 *
	 * @param string $version Asset version.
	 */
	private function enqueue_edit_assets( $version ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen lookup.
		$chart_id = isset( $_GET['chart'] ) ? absint( $_GET['chart'] ) : 0;
		$chart    = $this->charts->get_chart_raw( $chart_id );
		if ( ! $chart ) {
			return;
		}
		wp_enqueue_script(
			'coywolf-cdv-edit',
			COYWOLF_CDV_URL . 'js/edit.js',
			array( 'coywolf-cdv-chartjs', 'coywolf-cdv-transforms' ),
			$version,
			true
		);
		wp_localize_script(
			'coywolf-cdv-edit',
			'coywolfCDVEdit',
			array(
				'config'   => $chart['config'],
				'display'  => $chart['display'],
				'palettes' => Coywolf_CDV_Schemes::palettes(),
			)
		);
	}

	/**
	 * AJAX: parse the upload and return chart suggestions from the chosen
	 * engine — the built-in analyzer by default, Claude when a key is saved
	 * and the user picked it.
	 */
	public function ajax_analyze() {
		check_ajax_referer( 'coywolf_cdv_analyze' );
		if ( ! current_user_can( Coywolf_Data_Visualizer::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'coywolf-data-visualizer' ) ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$explanation = isset( $_POST['explanation'] ) ? sanitize_textarea_field( wp_unslash( $_POST['explanation'] ) ) : '';
		$engine      = isset( $_POST['engine'] ) ? sanitize_key( wp_unslash( $_POST['engine'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $engine, array( 'ai', 'builtin' ), true ) ) {
			$engine = $this->ai->is_configured() ? 'ai' : 'builtin';
		}
		if ( 'ai' === $engine && ! $this->ai->is_configured() ) {
			$engine = 'builtin';
		}
		if ( 'ai' === $engine && '' === trim( $explanation ) ) {
			wp_send_json_error( array( 'message' => __( 'Describe what the data represents before analyzing with Claude.', 'coywolf-data-visualizer' ) ) );
		}

		$parser = new Coywolf_CDV_Parser();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- nonce checked above; the parser validates the upload itself.
		$table = $parser->parse_upload( isset( $_FILES['data_file'] ) ? $_FILES['data_file'] : null );
		if ( is_wp_error( $table ) ) {
			wp_send_json_error( array( 'message' => $table->get_error_message() ) );
		}

		if ( 'ai' === $engine ) {
			$suggestions = $this->ai->analyze(
				$table['csv'],
				$explanation,
				array(
					'total_rows' => $table['total_rows'],
					'truncated'  => $table['truncated'],
				)
			);
		} else {
			$heuristics  = new Coywolf_CDV_Heuristics();
			$suggestions = $heuristics->analyze( $table['rows'] );
		}
		if ( is_wp_error( $suggestions ) ) {
			wp_send_json_error( array( 'message' => $suggestions->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'suggestions' => $suggestions,
				'engine'      => $engine,
			)
		);
	}

	/**
	 * AJAX: persist the user's selected suggestions as charts.
	 */
	public function ajax_save_charts() {
		check_ajax_referer( 'coywolf_cdv_save_charts' );
		if ( ! current_user_can( Coywolf_Data_Visualizer::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'coywolf-data-visualizer' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above; the JSON payload is validated piecewise below.
		$raw    = isset( $_POST['charts'] ) ? wp_unslash( $_POST['charts'] ) : '';
		$charts = json_decode( (string) $raw, true );
		if ( ! is_array( $charts ) || empty( $charts ) ) {
			wp_send_json_error( array( 'message' => __( 'No charts were selected.', 'coywolf-data-visualizer' ) ) );
		}

		$saved  = 0;
		$errors = array();
		foreach ( array_slice( $charts, 0, 8 ) as $chart ) {
			$clean = $this->ai->sanitize_suggestion( $chart );
			if ( null === $clean ) {
				$errors[] = __( 'One suggestion was skipped because its configuration was invalid.', 'coywolf-data-visualizer' );
				continue;
			}
			// Per-chart display options chosen on the suggestion card. The
			// scheme falls back to the site default for new charts.
			$display = isset( $chart['display'] ) && is_array( $chart['display'] ) ? $chart['display'] : array();
			if ( ! isset( $display['scheme'] ) ) {
				$display['scheme'] = $this->settings->default_scheme();
			}
			$display = Coywolf_CDV_Schemes::sanitize_display( $display, $clean['config'] );

			$result = $this->charts->save_chart( $clean['title'], $clean['caption'], $clean['config'], $display );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
				continue;
			}
			++$saved;
		}

		if ( 0 === $saved ) {
			wp_send_json_error(
				array( 'message' => $errors ? implode( ' ', array_unique( $errors ) ) : __( 'The charts could not be saved.', 'coywolf-data-visualizer' ) )
			);
		}
		wp_send_json_success(
			array(
				'saved'    => $saved,
				'redirect' => admin_url( 'admin.php?page=' . self::PAGE ),
			)
		);
	}
}
