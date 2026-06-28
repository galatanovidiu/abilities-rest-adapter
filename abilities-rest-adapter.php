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

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesRestAdapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ABILITIES_REST_ADAPTER_VERSION', '0.1.0' );
define( 'ABILITIES_REST_ADAPTER_FILE', __FILE__ );
define( 'ABILITIES_REST_ADAPTER_DIR', plugin_dir_path( __FILE__ ) );

/**
 * No-build PSR-4 autoloader for the `GalatanOvidiu\AbilitiesRestAdapter\` namespace.
 *
 * Maps the namespace root to the `includes/` directory (no Composer step for
 * runtime). Registered before the bootstrap so adapter classes load on demand.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = ABILITIES_REST_ADAPTER_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( ! is_readable( $path ) ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- PSR-4 path built from a plugin constant and an internal class name, not user input.
		require_once $path;
	}
);

// Boot. Loads the public `wp_register_ability_from_rest_route()` helper. The
// `WP_REST_Ability` engine class is autoloaded on demand. The Abilities API ships
// with WordPress 6.9; without it there is nothing to register. The adapter does
// not register a category — each ability declares an already-registered one.
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		require_once ABILITIES_REST_ADAPTER_DIR . 'includes/api.php';
	}
);
