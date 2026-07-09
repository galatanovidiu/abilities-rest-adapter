<?php
/**
 * Main plugin bootstrap.
 *
 * If this evolves from a standalone plugin into WordPress core, this file would be left behind.
 *
 * @package AbilitiesRestAdapter
 */

declare( strict_types=1 );

namespace GalatanOvidiu\AbilitiesRestAdapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the Abilities REST Adapter when installed as a standalone plugin.
 */
final class Plugin {

	/**
	 * The one true plugin instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Gets the singleton instance of the plugin.
	 *
	 * @return self The plugin instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->setup();

			/**
			 * Fires after the main plugin class has been initialized.
			 *
			 * @since 0.1.0
			 *
			 * @param self $instance The main plugin class instance.
			 */
			do_action( 'abilities_rest_adapter_init', self::$instance );
		}

		return self::$instance;
	}

	/**
	 * Sets up the plugin.
	 */
	private function setup(): void {
		if ( ! $this->has_dependencies() ) {
			return;
		}

		// The dev-only `wp ability describe-route` command; never loaded outside WP-CLI.
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Path built from a plugin constant, not user input.
		require_once ABILITIES_REST_ADAPTER_DIR . 'includes/cli.php';
	}

	/**
	 * Checks if all required dependencies are available.
	 *
	 * @return bool True if all dependencies are met, false otherwise.
	 */
	private function has_dependencies(): bool {
		if ( function_exists( 'wp_register_ability' ) ) {
			return true;
		}

		add_action(
			'admin_notices',
			static function (): void {
				wp_admin_notice(
					__( 'Abilities REST Adapter: Abilities API not available (wp_register_ability function not found).', 'abilities-rest-adapter' ),
					array(
						'type'    => 'error',
						'dismiss' => false,
					)
				);
			}
		);

		return false;
	}

	/**
	 * Prevents the class from being cloned.
	 */
	public function __clone() {
		_doing_it_wrong(
			__FUNCTION__,
			sprintf(
				// translators: %s: Class name.
				esc_html__( 'The %s class should not be cloned.', 'abilities-rest-adapter' ),
				esc_html( self::class )
			),
			'0.1.0'
		);
	}

	/**
	 * Prevents the class from being deserialized.
	 */
	public function __wakeup() {
		_doing_it_wrong(
			__FUNCTION__,
			sprintf(
				// translators: %s: Class name.
				esc_html__( 'De-serializing instances of %s is not allowed.', 'abilities-rest-adapter' ),
				esc_html( self::class )
			),
			'0.1.0'
		);
	}
}