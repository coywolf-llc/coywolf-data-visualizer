<?php
/**
 * Plugin Name:       Coywolf Data Visualizer
 * Plugin URI:        https://coywolf.com/notes/coywolf-data-visualizer/
 * Description:       Turn CSV, Excel, and JSON data into Chart.js charts — a built-in analyzer designs them instantly, or connect Claude (optional) for the best results — and embed them anywhere with a block.
 * Version:           1.2.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Coywolf
 * Author URI:        https://coywolf.com/jon-henshaw/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       coywolf-data-visualizer
 * Update URI:        https://github.com/coywolf-llc/coywolf-data-visualizer
 *
 * @package CoywolfDataVisualizer
 *
 * Coywolf Data Visualizer
 * Copyright (C) 2026 Coywolf LLC
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, version 2, as published
 * by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, see https://www.gnu.org/licenses/gpl-2.0.html.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COYWOLF_CDV_FILE', __FILE__ );
define( 'COYWOLF_CDV_PATH', plugin_dir_path( __FILE__ ) );
define( 'COYWOLF_CDV_URL', plugin_dir_url( __FILE__ ) );

/* wporg-strip:start — GitHub self-updater (removed from the WordPress.org build) */
require_once __DIR__ . '/includes/class-github-updater.php';
// Flags this as the GitHub distribution (stripped from the WordPress.org build).
define( 'COYWOLF_CDV_GITHUB_BUILD', true );
/* wporg-strip:end */
require_once __DIR__ . '/includes/class-cdv-schemes.php';
require_once __DIR__ . '/includes/class-cdv-charts.php';
require_once __DIR__ . '/includes/class-cdv-parser.php';
require_once __DIR__ . '/includes/class-cdv-heuristics.php';
require_once __DIR__ . '/includes/class-cdv-ai.php';
require_once __DIR__ . '/includes/class-cdv-settings.php';
require_once __DIR__ . '/includes/class-cdv-rest.php';
require_once __DIR__ . '/includes/class-cdv-block.php';
require_once __DIR__ . '/includes/class-cdv-docs.php';
require_once __DIR__ . '/includes/class-cdv-list-table.php';
require_once __DIR__ . '/includes/class-cdv-admin.php';
require_once __DIR__ . '/includes/class-coywolf-data-visualizer.php';

register_activation_hook( __FILE__, array( 'Coywolf_Data_Visualizer', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'Coywolf_Data_Visualizer', 'on_deactivate' ) );

Coywolf_Data_Visualizer::instance();

/* wporg-strip:start — GitHub self-updater (removed from the WordPress.org build) */
// Wire in the GitHub self-updater so releases show up on Dashboard → Updates.
( new Coywolf_CDV_GitHub_Updater( __FILE__, Coywolf_Data_Visualizer::VERSION ) )->init();
/* wporg-strip:end */
