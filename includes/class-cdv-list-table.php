<?php
/**
 * All Charts list table.
 *
 * Standard WP_List_Table over the chart post type with search, a chart-type
 * filter, pagination, bulk delete, and Posts/Pages usage columns that link
 * through to a filtered edit.php.
 *
 * @package CoywolfDataVisualizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class Coywolf_CDV_List_Table extends WP_List_Table {

	const PER_PAGE = 20;

	/**
	 * @var Coywolf_CDV_Charts
	 */
	private $charts;

	public function __construct( Coywolf_CDV_Charts $charts ) {
		parent::__construct(
			array(
				'singular' => 'chart',
				'plural'   => 'charts',
				'ajax'     => false,
			)
		);
		$this->charts = $charts;
	}

	/**
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'      => '<input type="checkbox" />',
			'title'   => __( 'Name', 'coywolf-data-visualizer' ),
			'type'    => __( 'Type', 'coywolf-data-visualizer' ),
			'posts'   => __( 'Posts', 'coywolf-data-visualizer' ),
			'pages'   => __( 'Pages', 'coywolf-data-visualizer' ),
			'created' => __( 'Created', 'coywolf-data-visualizer' ),
		);
	}

	/**
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'title'   => array( 'title', false ),
			'created' => array( 'date', true ),
		);
	}

	/**
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'coywolf-data-visualizer' ) );
	}

	/**
	 * Chart-type filter dropdown.
	 *
	 * @param string $which Table nav position.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$current = isset( $_REQUEST['cdv_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['cdv_type'] ) ) : '';
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="coywolf-cdv-type-filter">' . esc_html__( 'Filter by chart type', 'coywolf-data-visualizer' ) . '</label>';
		echo '<select name="cdv_type" id="coywolf-cdv-type-filter">';
		echo '<option value="">' . esc_html__( 'All types', 'coywolf-data-visualizer' ) . '</option>';
		foreach ( Coywolf_CDV_Charts::ALLOWED_TYPES as $type ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $type ), selected( $current, $type, false ), esc_html( $type ) );
		}
		echo '</select>';
		submit_button( __( 'Filter', 'coywolf-data-visualizer' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Query charts for the current page.
	 */
	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list params.
		$paged   = max( 1, $this->get_pagenum() );
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$type    = isset( $_REQUEST['cdv_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['cdv_type'] ) ) : '';
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'date';
		$order   = isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) ? 'ASC' : 'DESC';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$args = array(
			'post_type'      => Coywolf_CDV_Charts::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'orderby'        => in_array( $orderby, array( 'title', 'date' ), true ) ? $orderby : 'date',
			'order'          => $order,
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( in_array( $type, Coywolf_CDV_Charts::ALLOWED_TYPES, true ) ) {
			$args['meta_key']   = Coywolf_CDV_Charts::META_TYPE; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['meta_value'] = $type; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}

		$query       = new WP_Query( $args );
		$this->items = $query->posts;

		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) $query->max_num_pages,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
	}

	/**
	 * @param WP_Post $item Chart post.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="chart[]" value="%d" />', (int) $item->ID );
	}

	/**
	 * @param WP_Post $item Chart post.
	 * @return string
	 */
	public function column_title( $item ) {
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => 'coywolf-data-visualizer',
					'action' => 'delete',
					'chart'  => (int) $item->ID,
				),
				admin_url( 'admin.php' )
			),
			'coywolf_cdv_delete_chart_' . (int) $item->ID
		);
		$edit_url   = Coywolf_CDV_Admin::edit_url( (int) $item->ID );
		$actions    = array(
			'edit'   => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Edit', 'coywolf-data-visualizer' )
			),
			'id'     => sprintf( /* translators: %d: chart ID */ esc_html__( 'ID: %d', 'coywolf-data-visualizer' ), (int) $item->ID ),
			'delete' => sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(%s);">%s</a>',
				esc_url( $delete_url ),
				esc_attr( wp_json_encode( __( 'Delete this chart? Its block will also be removed from every post and page that uses it.', 'coywolf-data-visualizer' ) ) ),
				esc_html__( 'Delete', 'coywolf-data-visualizer' )
			),
		);
		$title      = $item->post_title ? $item->post_title : __( '(no title)', 'coywolf-data-visualizer' );
		return sprintf(
			'<strong><a class="row-title" href="%s" aria-label="%s">%s</a></strong>%s',
			esc_url( $edit_url ),
			/* translators: %s: chart name */
			esc_attr( sprintf( __( 'Edit "%s"', 'coywolf-data-visualizer' ), $title ) ),
			esc_html( $title ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * @param WP_Post $item Chart post.
	 * @return string
	 */
	public function column_type( $item ) {
		$type = (string) get_post_meta( $item->ID, Coywolf_CDV_Charts::META_TYPE, true );
		return '' !== $type ? '<code>' . esc_html( $type ) . '</code>' : '&#8212;';
	}

	/**
	 * @param WP_Post $item Chart post.
	 * @return string
	 */
	public function column_posts( $item ) {
		return $this->render_usage_cell( (int) $item->ID, 'post' );
	}

	/**
	 * @param WP_Post $item Chart post.
	 * @return string
	 */
	public function column_pages( $item ) {
		return $this->render_usage_cell( (int) $item->ID, 'page' );
	}

	/**
	 * @param WP_Post $item Chart post.
	 * @return string
	 */
	public function column_created( $item ) {
		return esc_html( get_the_date( '', $item ) );
	}

	/**
	 * Usage count, linking through to edit.php filtered to the posts that
	 * embed the chart.
	 *
	 * @param int    $chart_id  Chart post ID.
	 * @param string $post_type 'post' or 'page'.
	 * @return string
	 */
	private function render_usage_cell( $chart_id, $post_type ) {
		$count = $this->charts->count_posts_using( $chart_id, $post_type );
		if ( 0 === $count ) {
			return '0';
		}
		$url = add_query_arg(
			array(
				'post_type'         => $post_type,
				'coywolf_cdv_chart' => $chart_id,
			),
			admin_url( 'edit.php' )
		);
		return sprintf(
			'<a href="%1$s" title="%2$s">%3$s</a>',
			esc_url( $url ),
			esc_attr(
				sprintf(
					/* translators: 1: count, 2: post type name */
					_n( 'View the %1$d %2$s using this chart', 'View the %1$d %2$ss using this chart', $count, 'coywolf-data-visualizer' ),
					$count,
					$post_type
				)
			),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * Empty-state message.
	 */
	public function no_items() {
		printf(
			/* translators: %s: Add Chart screen URL */
			wp_kses_post( __( 'No charts yet. <a href="%s">Add your first chart</a> by uploading a data file.', 'coywolf-data-visualizer' ) ),
			esc_url( admin_url( 'admin.php?page=coywolf-data-visualizer-add' ) )
		);
	}
}
