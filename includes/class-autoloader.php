<?php
/**
 * Composer autoloader bootstrap for the plugin.
 *
 * Ensures that autoloaders are present, and logs an admin notice if not.
 *
 * Can be bypassed by defining the ABILITIES_REST_ADAPTER_AUTOLOAD constant to false.
 *
 * @package AbilitiesRestAdapter
 */

declare( strict_types=1 );

namespace GalatanOvidiu\AbilitiesRestAdapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the plugin's Composer autoloader.
 */
final class Autoloader {

	/**
	 * Whether the autoloader has been loaded.
	 *
	 * @var bool
	 */
	protected static bool $is_loaded = false;

	/**
	 * Attempt to autoload the Composer dependencies.
	 *
	 * @return bool True when autoloading succeeded or was intentionally skipped.
	 */
	public static function autoload(): bool {
		if ( defined( 'ABILITIES_REST_ADAPTER_AUTOLOAD' ) && false === ABILITIES_REST_ADAPTER_AUTOLOAD ) {
			return true;
		}

		if ( self::$is_loaded ) {
			return self::$is_loaded;
		}

		$autoloader      = ABILITIES_REST_ADAPTER_DIR . 'vendor/autoload.php';
		self::$is_loaded = self::require_autoloader( $autoloader );

		return self::$is_loaded;
	}

	/**
	 * Attempts to load the autoloader file, if it exists.
	 *
	 * @param string $autoloader_file The path to the autoloader file.
	 * @return bool
	 */
	private static function require_autoloader( string $autoloader_file ): bool {
		if ( ! is_readable( $autoloader_file ) ) {
			self::missing_autoloader_notice();

			return false;
		}

		return (bool) require_once $autoloader_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Autoloader is a Composer file.
	}

	/**
	 * Displays a notice if the autoloader is missing.
	 */
	private static function missing_autoloader_notice(): void {
		$hooks = array(
			'admin_notices',
			'network_admin_notices',
		);

		foreach ( $hooks as $hook ) {
			add_action(
				$hook,
				static function (): void {
					$error_message = __( 'Abilities REST Adapter: The Composer autoloader was not found. If you installed the plugin from the GitHub source code, make sure to run `composer install`.', 'abilities-rest-adapter' );

					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( esc_html( $error_message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- This is a development notice.
					}
					?>
					<div class="error notice">
						<p>
							<?php echo esc_html( $error_message ); ?>
						</p>
					</div>
					<?php
				}
			);
		}
	}
}