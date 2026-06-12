<?php
/**
 * Plugin bootstrap singleton.
 *
 * Wires the chart store, Claude client, settings, REST routes, block, and
 * admin screens together, and owns the activation/deactivation hooks.
 *
 * @package CoywolfDataVisualizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coywolf_Data_Visualizer {

	const VERSION = '1.2.0';

	/**
	 * Capability gating every admin screen and AJAX endpoint.
	 */
	const CAPABILITY = 'coywolf_cdv_manage';

	/**
	 * @var Coywolf_Data_Visualizer|null
	 */
	private static $instance = null;

	/**
	 * @var Coywolf_CDV_Charts
	 */
	public $charts;

	/**
	 * @var Coywolf_CDV_AI
	 */
	public $ai;

	/**
	 * @var Coywolf_CDV_Settings
	 */
	public $settings;

	/**
	 * @var Coywolf_CDV_Rest
	 */
	public $rest;

	/**
	 * @var Coywolf_CDV_Block
	 */
	public $block;

	/**
	 * @var Coywolf_CDV_Admin|null
	 */
	public $admin = null;

	/**
	 * @return Coywolf_Data_Visualizer
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->charts   = new Coywolf_CDV_Charts();
		$this->ai       = new Coywolf_CDV_AI();
		$this->settings = new Coywolf_CDV_Settings( $this->ai );
		$this->rest     = new Coywolf_CDV_Rest( $this->charts, $this->settings );
		$this->block    = new Coywolf_CDV_Block( $this->charts, $this->settings );

		$this->charts->init();
		$this->settings->init();
		$this->rest->init();
		$this->block->init();

		if ( is_admin() ) {
			$this->admin = new Coywolf_CDV_Admin( $this->charts, $this->ai, $this->settings );
			$this->admin->init();
		}
	}

	/**
	 * Activation: grant the management capability to administrators.
	 */
	public static function on_activate() {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAPABILITY ) ) {
			$role->add_cap( self::CAPABILITY );
		}
	}

	/**
	 * Deactivation: nothing to tear down (capability removal happens on
	 * uninstall so a temporary deactivation doesn't lock anyone out).
	 */
	public static function on_deactivate() {}
}
