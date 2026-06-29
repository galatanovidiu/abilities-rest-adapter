<?php
/**
 * Plugin Name:       Abilities REST Adapter
 * Plugin URI:        https://github.com/galatanovidiu/abilities-rest-adapter
 * Description:       Defines an Abilities API ability by reusing an existing REST API route — its schema, validation, permission check, and handler — instead of re-implementing them. Dispatches the real route via rest_do_request().
 * Version:           0.1.0
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

declare( strict_types = 1 );

namespace GalatanOvidiu\AbilitiesRestAdapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ABILITIES_REST_ADAPTER_VERSION', '0.1.0' );
define( 'ABILITIES_REST_ADAPTER_FILE', __FILE__ );
define( 'ABILITIES_REST_ADAPTER_DIR', plugin_dir_path( __FILE__ ) );

/**
 * No-build autoloader for the `GalatanOvidiu\AbilitiesRestAdapter\` namespace.
 *
 * Maps a class name to the WordPress class-file convention under `includes/` —
 * lowercased, underscores to hyphens, with a `class-` prefix (so
 * `Rest_Route_Ability` loads from `includes/class-rest-route-ability.php`). The
 * namespace is flat (no sub-namespaces), so the class name maps straight to a file.
 * Registered before the bootstrap so adapter classes load on demand.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		$path     = ABILITIES_REST_ADAPTER_DIR . 'includes/' . $file;

		if ( ! is_readable( $path ) ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Path built from a plugin constant and an internal class name, not user input.
		require_once $path;
	}
);

// Boot. Loads the public `wp_register_ability_from_rest_route()` helper. The
// `Rest_Route_Ability` engine class is autoloaded on demand. The Abilities API ships
// with WordPress 6.9; without it there is nothing to register. The adapter does
// not register a category — each ability declares an already-registered one.
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		require_once ABILITIES_REST_ADAPTER_DIR . 'includes/api.php';

		// The dev-only `wp ability describe-route` command; never loaded outside WP-CLI.
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		require_once ABILITIES_REST_ADAPTER_DIR . 'includes/cli.php';
	}
);
