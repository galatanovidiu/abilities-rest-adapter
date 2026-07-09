<?php
/**
 * Public API for the Abilities REST Adapter.
 *
 * This is a global (unnamespaced) function — `wp_register_ability_from_rest_route()`
 * mirrors core's `wp_register_ability( $name, $args )`. The `Rest_Route_Ability`
 * engine class is namespaced and autoloaded on demand.
 *
 * @package AbilitiesRestAdapter
 */

declare( strict_types = 1 );

use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;

if ( ! function_exists( 'wp_register_ability_from_rest_route' ) ) {
	/**
	 * Registers an ability derived from an existing REST API route and method.
	 *
	 * Mirrors core's `wp_register_ability( $name, $args )`: one route + one HTTP
	 * method becomes one ability. The route's schema, validation, permission
	 * check, and handler are reused — the ability dispatches the real route via
	 * `rest_do_request()` rather than reimplementing it. Resolution is deferred to
	 * first use, so the route need not be registered yet at call time.
	 *
	 * The standard ability properties (`label`, `description`, `category`) are
	 * required, exactly as for `wp_register_ability()`; the adapter does not invent
	 * them, and there is no default category — register your category first.
	 *
	 * Writes (any method other than GET) must declare `destructive` and
	 * `idempotent` annotations under `meta.annotations`; omitting them still
	 * registers the ability but triggers `_doing_it_wrong` and leaves them unset,
	 * since the adapter will not guess a safety hint that could be wrong.
	 *
	 * The adapter facilitates; it does not reshape on its own. Use the callbacks
	 * and schema args to adapt the route to your ability. An opt-in
	 * `require_permission` guard can add a stricter permission floor on top of the
	 * route's own check (it can only tighten, never widen, access).
	 *
	 * @since 0.1.0
	 *
	 * @param string               $name Ability name, `namespace/slug` (e.g. `my-plugin/get-post`).
	 * @param array<string, mixed> $args {
	 *     Registration args.
	 *
	 *     @type string   $route           Required. A registered REST route pattern (e.g. `/wp/v2/users/me`
	 *                                     or `/wp/v2/posts/(?P<id>[\d]+)`).
	 *     @type string   $method          Required. One HTTP method: `GET`, `POST`, `PUT`, `PATCH`, or `DELETE`.
	 *     @type string   $label           Required. Human-readable label.
	 *     @type string   $description     Required. Human-readable description.
	 *     @type string   $category        Required. An already-registered ability category slug.
	 *     @type callable $input_callback  Optional. `fn( array $params ): array|WP_Error`. Transforms the request
	 *                                     params before dispatch — set `_fields`, pin `context`, inject fixed
	 *                                     params, reshape — or return a `WP_Error` to reject. Runs after the
	 *                                     ability validates input against its schema, so to inject a *required*
	 *                                     path capture (e.g. `id`) also pass an `input_schema` that does not mark
	 *                                     it required, or validation rejects the call before this runs. Runs once
	 *                                     per `execute()`, at dispatch.
	 *     @type callable $output_callback Optional. `fn( $data, array $input, WP_REST_Response $response ): mixed|WP_Error`.
	 *                                     Reshapes a successful response (runs last, over the body or the
	 *                                     `{ items, total, total_pages }` envelope); not called on an error.
	 *                                     `$input` is the original ability input, before any `input_callback`.
	 *     @type array    $input_schema    Optional. Replaces the derived input schema (standard ability schema).
	 *     @type array    $output_schema   Optional. Replaces the derived output schema (standard ability schema).
	 *                                     When an `output_callback` is set and this is omitted, no output schema
	 *                                     is advertised and output validation is skipped.
	 *     @type callable $require_permission Optional. `fn( mixed $input ): bool|WP_Error`. An additive permission
	 *                                     floor, enforced in the ability's permission phase — the ONLY permission
	 *                                     check that runs there. The route's own permission check runs later, at
	 *                                     dispatch (inside `rest_do_request()`), so the guard fires once per
	 *                                     `execute()` and the route check fires once. The guard can only tighten
	 *                                     access, never widen it: a truthy verdict defers to the route's own check,
	 *                                     which stays the authority and can still deny at dispatch. A falsey verdict is
	 *                                     denied as `rest_forbidden`; a `WP_Error` surfaces from `check_permissions()`
	 *                                     unchanged. Bare `execute()` collapses the guard's denial — like any
	 *                                     permission error — to a generic `ability_invalid_permissions`; the route's
	 *                                     own denial, by contrast, surfaces through `execute()` as the real REST
	 *                                     error. `$input` is the RAW ability input, before any `input_callback` —
	 *                                     best for a coarse floor (a capability, or "logged in"), not an object-level
	 *                                     check on transformed data. Runs as the current user; a standalone
	 *                                     `check_permissions()` does not pre-validate input.
	 *     @type array    $meta            Optional. Ability meta, including `annotations`; required for write methods.
	 * }
	 * @return \WP_Ability|null The registered ability, or `null` on failure.
	 */
	function wp_register_ability_from_rest_route( string $name, array $args ): ?WP_Ability { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Public API deliberately mirrors core's wp_register_ability() for a clean path to core.
		// A light early guard: the name must be a non-empty lowercase string. The full
		// `namespace/slug` format (and the standard ability properties) are enforced by
		// core's `wp_register_ability()`; the adapter only owns its route/method keys.
		if ( ! $name || strtolower( $name ) !== $name ) {
			_doing_it_wrong(
				__FUNCTION__,
				esc_html__( 'Ability name must be a non-empty lowercase string; the full "namespace/slug" format is enforced by wp_register_ability().', 'abilities-rest-adapter' ),
				'0.1.0'
			);
			return null;
		}

		if ( empty( $args['route'] ) || ! is_string( $args['route'] ) ) {
			_doing_it_wrong(
				__FUNCTION__,
				esc_html__( 'A REST `route` string is required, e.g. "/wp/v2/posts/(?P<id>[\\d]+)".', 'abilities-rest-adapter' ),
				'0.1.0'
			);
			return null;
		}

		if ( empty( $args['method'] ) || ! is_string( $args['method'] ) ) {
			_doing_it_wrong(
				__FUNCTION__,
				esc_html__( 'An HTTP `method` string is required, e.g. "GET".', 'abilities-rest-adapter' ),
				'0.1.0'
			);
			return null;
		}

		return wp_register_ability( $name, Rest_Route_Ability::build_args( $name, $args ) );
	}
}
