<?php
/**
 * Plugin Name:       Abilities REST Adapter
 * Plugin URI:        https://github.com/galatanovidiu/abilities-rest-adapter
 * Description:       Defines an Abilities API ability by reusing an existing REST API route — its schema, validation, permission check, and handler — instead of re-implementing them. Dispatches the real route via rest_do_request().
 * Version:           0.1.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Ovidiu Galatan
 * Author URI:        https://github.com/galatanovidiu
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       abilities-rest-adapter
 *
 * @package AbilitiesRestAdapter
 */

declare( strict_types=1 );

namespace GalatanOvidiu\AbilitiesRestAdapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define the plugin constants.
 */
function constants(): void {
	define( 'ABILITIES_REST_ADAPTER_VERSION', '0.1.1' );
	define( 'ABILITIES_REST_ADAPTER_FILE', __FILE__ );
	define( 'ABILITIES_REST_ADAPTER_DIR', plugin_dir_path( __FILE__ ) );
}

constants();

// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Bootstrap file path is a plugin constant.
require_once ABILITIES_REST_ADAPTER_DIR . 'includes/class-autoloader.php';

// If autoloader failed, we cannot proceed.
if ( ! Autoloader::autoload() ) {
	return;
}

// Load the plugin.
if ( class_exists( Plugin::class ) ) {
	Plugin::instance();
}
