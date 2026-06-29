<?php
/**
 * Dev-only WP-CLI command: `wp ability describe-route`.
 *
 * Loaded from the plugin boot only when WP-CLI is running. It is an authoring
 * affordance — it shows what {@see wp_register_ability_from_rest_route()} would
 * derive from a route + method, so a developer can decide which seams
 * (`input_callback`/`output_callback`/`input_schema`/`output_schema`) they need
 * before registering. It registers no ability and dispatches no route, and there
 * is no HTTP/runtime introspection equivalent.
 *
 * @package AbilitiesRestAdapter
 */

declare( strict_types = 1 );

use GalatanOvidiu\AbilitiesRestAdapter\WP_REST_Ability;

// Belt-and-suspenders: the boot only requires this file under WP-CLI, but guard
// here too so a stray direct include cannot fatal on the missing WP_CLI class.
if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

WP_CLI::add_command(
	'ability describe-route',
	/**
	 * Shows what the adapter derives from a REST route + method.
	 *
	 * @param string[]              $args       Positional args: route, method.
	 * @param array<string, string> $assoc_args Associative args: `format`.
	 * @return void
	 */
	static function ( array $args, array $assoc_args ): void {
		[ $route, $method ] = $args;

		$info = WP_REST_Ability::describe( $route, $method );

		// `--format=json` is a faithful dump of the snapshot — including the
		// not-found case, where `found` is false and `error` carries the reason —
		// so a scripting consumer always gets JSON and checks the `found` field.
		// Only the human format errors out on an unresolved route.
		if ( 'json' === ( $assoc_args['format'] ?? 'human' ) ) {
			WP_CLI::print_value( $info, array( 'format' => 'json' ) );
			return;
		}

		if ( ! $info['found'] ) {
			WP_CLI::error( (string) $info['error'] );
			return; // Unreachable (WP_CLI::error exits); keeps the type-checker happy.
		}

		$type = $info['is_collection']
			? 'collection (wrapped as { items, total, total_pages })'
			: 'single item';

		WP_CLI::log( 'Route:   ' . $info['route'] );
		WP_CLI::log( 'Method:  ' . $info['method'] );
		WP_CLI::log( 'Returns: ' . $type );

		if ( $info['readonly'] ) {
			WP_CLI::log( 'Safety:  read-only (GET)' );
		} else {
			WP_CLI::log( 'Safety:  WRITE — declare meta.annotations.destructive and .idempotent when you register it; the adapter cannot derive them.' );
		}

		if ( empty( $info['captures'] ) ) {
			WP_CLI::log( 'Path params: (none)' );
		} else {
			$parts = array();
			foreach ( $info['captures'] as $name => $cast ) {
				$parts[] = $name . ' (' . $cast . ')';
			}
			WP_CLI::log( 'Path params: ' . implode( ', ', $parts ) );
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Input schema:' );
		WP_CLI::log( (string) wp_json_encode( $info['input_schema'], JSON_PRETTY_PRINT ) );

		WP_CLI::log( '' );
		WP_CLI::log( 'Output schema:' );
		$output_schema = empty( $info['output_schema'] )
			? '(none advertised — an output_callback is in play, or the route exposes no item schema)'
			: (string) wp_json_encode( $info['output_schema'], JSON_PRETTY_PRINT );
		WP_CLI::log( $output_schema );
	},
	array(
		'shortdesc' => 'Show the input/output schema, path params, and safety the adapter derives from a REST route.',
		'synopsis'  => array(
			array(
				'type'        => 'positional',
				'name'        => 'route',
				'description' => 'A registered REST route pattern, e.g. "/wp/v2/posts/(?P<id>[\\d]+)".',
			),
			array(
				'type'        => 'positional',
				'name'        => 'method',
				'description' => 'An HTTP method, e.g. GET or POST, matched against the route\'s registered methods.',
			),
			array(
				'type'        => 'assoc',
				'name'        => 'format',
				'optional'    => true,
				'default'     => 'human',
				'options'     => array( 'human', 'json' ),
				'description' => 'Render a human summary (default) or the raw snapshot as JSON.',
			),
		),
		'when'      => 'after_wp_load',
	)
);
