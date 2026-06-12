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
	const PAGE_DOCS = 'coywolf-data-visualizer-docs';

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

		add_action( 'load-' . $this->hooks['list'], array( $this, 'handle_chart_actions' ) );
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

			<?php if ( ! $this->ai->is_configured() ) : ?>
				<div class="notice notice-warning"><p>
					<?php
					printf(
						/* translators: %s: Settings screen URL */
						wp_kses_post( __( 'Add your Claude API key on the <a href="%s">Settings page</a> before generating charts.', 'coywolf-data-visualizer' ) ),
						esc_url( admin_url( 'admin.php?page=' . Coywolf_CDV_Settings::PAGE ) )
					);
					?>
				</p></div>
				</div>
				<?php
				return;
			endif;
			?>

			<p><?php esc_html_e( 'Upload a data file, describe what the data represents, and Claude will design a set of charts. Pick the ones you want to keep.', 'coywolf-data-visualizer' ); ?></p>

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
							<textarea id="coywolf-cdv-explanation" name="explanation" rows="4" class="large-text" required placeholder="<?php esc_attr_e( 'e.g. Monthly organic traffic and conversions for our three product pages, January through December 2025.', 'coywolf-data-visualizer' ); ?>"></textarea>
							<p class="description"><?php esc_html_e( 'A sentence or two of context helps Claude pick the most relevant charts. The data and this explanation are sent to the Claude API.', 'coywolf-data-visualizer' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary" id="coywolf-cdv-analyze"><?php esc_html_e( 'Analyze', 'coywolf-data-visualizer' ); ?></button>
					<span class="spinner" id="coywolf-cdv-analyze-spinner"></span>
				</p>
			</form>

			<div class="notice notice-error" id="coywolf-cdv-error" hidden><p></p></div>

			<div id="coywolf-cdv-results" hidden>
				<h2><?php esc_html_e( 'Suggested charts', 'coywolf-data-visualizer' ); ?></h2>
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

		$needs_js = array( $this->hooks['add'], $this->hooks['settings'] );
		if ( ! in_array( $hook, $needs_js, true ) ) {
			return;
		}

		wp_register_script( 'coywolf-cdv-chartjs', COYWOLF_CDV_URL . 'vendor/chartjs/chart.umd.js', array(), '4.5.1', true );
		wp_enqueue_script(
			'coywolf-cdv-admin',
			COYWOLF_CDV_URL . 'js/admin.js',
			$hook === $this->hooks['add'] ? array( 'coywolf-cdv-chartjs' ) : array(),
			$version,
			true
		);
		wp_localize_script(
			'coywolf-cdv-admin',
			'coywolfCDVAdmin',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'analyzeNonce' => wp_create_nonce( 'coywolf_cdv_analyze' ),
				'saveNonce'    => wp_create_nonce( 'coywolf_cdv_save_charts' ),
				'testNonce'    => wp_create_nonce( 'coywolf_cdv_test_api' ),
				'listUrl'      => admin_url( 'admin.php?page=' . self::PAGE ),
				'maxBytes'     => Coywolf_CDV_Parser::MAX_FILE_BYTES,
				'i18n'         => array(
					'analyzing'    => __( 'Analyzing your data — this can take a minute…', 'coywolf-data-visualizer' ),
					'tooLarge'     => __( 'That file is larger than the 10 MB limit.', 'coywolf-data-visualizer' ),
					'noSelection'  => __( 'Select at least one chart to save.', 'coywolf-data-visualizer' ),
					'requestFail'  => __( 'The request failed. Check your connection and try again.', 'coywolf-data-visualizer' ),
					'testing'      => __( 'Testing…', 'coywolf-data-visualizer' ),
					'saving'       => __( 'Saving…', 'coywolf-data-visualizer' ),
					'titleLabel'   => __( 'Title', 'coywolf-data-visualizer' ),
					'captionLabel' => __( 'Caption', 'coywolf-data-visualizer' ),
				),
			)
		);
	}

	/**
	 * AJAX: parse the upload, send it to Claude, return chart suggestions.
	 */
	public function ajax_analyze() {
		check_ajax_referer( 'coywolf_cdv_analyze' );
		if ( ! current_user_can( Coywolf_Data_Visualizer::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'coywolf-data-visualizer' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$explanation = isset( $_POST['explanation'] ) ? sanitize_textarea_field( wp_unslash( $_POST['explanation'] ) ) : '';
		if ( '' === trim( $explanation ) ) {
			wp_send_json_error( array( 'message' => __( 'Describe what the data represents before analyzing.', 'coywolf-data-visualizer' ) ) );
		}

		$parser = new Coywolf_CDV_Parser();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- nonce checked above; the parser validates the upload itself.
		$table = $parser->parse_upload( isset( $_FILES['data_file'] ) ? $_FILES['data_file'] : null );
		if ( is_wp_error( $table ) ) {
			wp_send_json_error( array( 'message' => $table->get_error_message() ) );
		}

		$suggestions = $this->ai->analyze(
			$table['csv'],
			$explanation,
			array(
				'total_rows' => $table['total_rows'],
				'truncated'  => $table['truncated'],
			)
		);
		if ( is_wp_error( $suggestions ) ) {
			wp_send_json_error( array( 'message' => $suggestions->get_error_message() ) );
		}

		wp_send_json_success( array( 'suggestions' => $suggestions ) );
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
			$result = $this->charts->save_chart( $clean['title'], $clean['caption'], $clean['config'] );
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
