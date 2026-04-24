<?php
/**
 * Plugin Name: Memex
 * Description: Turns WordPress into a world-class note-taking platform — bi-directional [[wiki-links]], automatic backlinks, daily notes, tags, and one-click import from Obsidian, Notion, Evernote, and Roam Research.
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
 * Boot on `init` priority 5 — early enough that CPT registration and route
 * setup happen before WP's default init:10, late enough that the plugin
 * textdomain is loadable (WP 6.7+ warns if translations run before init).
 *
 * WpApp v1.1+ Registry::register_app detects `did_action('init')` and
 * registers rewrite rules immediately, so the older "must-use-plugins_loaded"
 * requirement no longer applies.
 */
add_action(
	'init',
	function () {
		$app = new App();
		$app->init();
	},
	5
);

register_activation_hook(
	__FILE__,
	function () {
		$app = new App();
		$app->activate();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		$app = new App();
		$app->deactivate();
	}
);
