<?php
/**
 * Plugin Name: Memex
 * Plugin URI: https://github.com/akirk/memex
 * Description: Turn WordPress into a note-taking app: wiki-style links between notes, automatic backlinks, daily notes, tags, reminders and Markdown import.
 * Version: 0.1.0+5e8416c570a9
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Author: Alex Kirk
 * Author URI: https://alex.kirk.at/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: memex
 *
 * @package Memex
 */

namespace Memex;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MEMEX_VERSION', '0.1.0' );
define( 'MEMEX_PLUGIN_FILE', __FILE__ );
define( 'MEMEX_PLUGIN_DIR', __DIR__ );
define( 'MEMEX_PLUGIN_URL', plugins_url( '', __FILE__ ) );

require_once __DIR__ . '/vendor/autoload.php';

/*
 * Keep a single App instance for the request. Constructing the App registers
 * WpApp's router, so do that on plugins_loaded, then defer translated labels
 * and other runtime WordPress registrations until init.
 */
function memex_app(): App {
	static $app = null;

	if ( null === $app ) {
		$app = new App();
	}

	return $app;
}

add_action(
	'plugins_loaded',
	function () {
		memex_app()->register_app();
	}
);

/*
 * Register CPTs, translated menu labels, and request handlers on init:5.
 * This is before the default init:10 callbacks while still avoiding WP 6.7+
 * just-in-time textdomain notices.
 */
add_action(
	'init',
	function () {
		memex_app()->init();
	},
	5
);

register_activation_hook(
	__FILE__,
	function () {
		memex_app()->register_app();
		memex_app()->activate();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		memex_app()->deactivate();
	}
);
