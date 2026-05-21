<?php
/**
 * Plugin Name: Memex
 * Description: Turns WordPress into a note-taking app — bi-directional links, automatic backlinks, daily notes, tags, reminders, and one-click import from Obsidian, Notion, Evernote, and Roam Research.
 * Version: 0.1.0
 * Author: Alex Kirk
 * Text Domain: memex
 * Requires PHP: 7.4
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
