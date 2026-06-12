<?php
/**
 * Uninstall cleanup: remove every option, transient, chart post, and
 * capability the plugin created.
 *
 * @package CoywolfDataVisualizer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Options.
delete_option( 'coywolf_cdv_api_key' );
delete_option( 'coywolf_cdv_settings' );

// Plugin transients (model list cache + usage tallies).
delete_transient( 'coywolf_cdv_models' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- wildcard transient cleanup on uninstall.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE '\_transient\_coywolf\_cdv\_%'
	OR option_name LIKE '\_transient\_timeout\_coywolf\_cdv\_%'"
);

// Self-updater caches (GitHub build only; harmless otherwise).
delete_site_transient( 'coywolf_cdv_gh_release' );
delete_site_transient( 'coywolf_cdv_gh_release_neg' );
delete_site_transient( 'coywolf_cdv_gh_release_err' );

// Chart posts.
$coywolf_cdv_chart_ids = get_posts(
	array(
		'post_type'      => 'coywolf_cdv_chart',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $coywolf_cdv_chart_ids as $coywolf_cdv_chart_id ) {
	wp_delete_post( $coywolf_cdv_chart_id, true );
}

// Capability.
$coywolf_cdv_roles = wp_roles();
foreach ( array_keys( $coywolf_cdv_roles->roles ) as $coywolf_cdv_role_name ) {
	$coywolf_cdv_role = get_role( $coywolf_cdv_role_name );
	if ( $coywolf_cdv_role && $coywolf_cdv_role->has_cap( 'coywolf_cdv_manage' ) ) {
		$coywolf_cdv_role->remove_cap( 'coywolf_cdv_manage' );
	}
}
