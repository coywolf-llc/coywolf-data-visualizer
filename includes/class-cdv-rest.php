<?php
/**
 * REST routes for the block editor: the chart picker list and the full chart
 * config the editor preview renders.
 *
 * @package CoywolfDataVisualizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coywolf_CDV_Rest {

	const ROUTE_NAMESPACE = 'coywolf-cdv/v1';

	/**
	 * @var Coywolf_CDV_Charts
	 */
	private $charts;

	/**
	 * @var Coywolf_CDV_Settings
	 */
	private $settings;

	public function __construct( Coywolf_CDV_Charts $charts, Coywolf_CDV_Settings $settings ) {
		$this->charts   = $charts;
		$this->settings = $settings;
	}

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/charts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_charts' ),
				'permission_callback' => array( $this, 'can_use_picker' ),
				'args'                => array(
					's' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/charts/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_chart' ),
				'permission_callback' => array( $this, 'can_use_picker' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Anyone who can edit posts can browse charts in the block picker.
	 *
	 * @return bool
	 */
	public function can_use_picker() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_charts( $request ) {
		$charts = $this->charts->list_charts( (string) $request['s'] );
		return rest_ensure_response( array( 'charts' => $charts ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_chart( $request ) {
		$chart = $this->charts->get_chart( (int) $request['id'] );
		if ( ! $chart ) {
			return new WP_Error(
				'coywolf_cdv_not_found',
				__( 'Chart not found.', 'coywolf-data-visualizer' ),
				array( 'status' => 404 )
			);
		}
		// Site-wide appearance settings, so the editor preview matches the
		// front-end render.
		$chart['appearance'] = $this->settings->appearance();
		return rest_ensure_response( $chart );
	}
}
