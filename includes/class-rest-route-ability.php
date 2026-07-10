<?php
/**
 * The REST-backed ability class.
 *
 * @package AbilitiesRestAdapter
 */

declare( strict_types = 1 );

namespace GalatanOvidiu\AbilitiesRestAdapter;

use Closure;
use WP_Ability;
use WP_Error;
use WP_REST_Request;
use stdClass;

/**
 * An ability whose schema, permission, and handler are derived from an existing
 * REST API route and method.
 *
 * Registration is thin: {@see build_args()} stashes the route, method, and the
 * developer's args and assigns this class via the `ability_class` seam.
 * Resolution is deferred; the route is looked up and the schemas derived on
 * first access to the input/output schema, the permission check, or execution.
 * This removes the ordering dependency between `rest_api_init` and
 * `wp_abilities_api_init`, which both fire lazily with no guaranteed order.
 *
 * The class overrides only four seams; each resolves once, then hands back to
 * the parent so core's filters (`wp_ability_permission_result`,
 * `wp_ability_execute_result`) and validation run unchanged:
 *
 * - {@see get_input_schema()}  — derived input schema (route args + path captures).
 * - {@see get_output_schema()} — derived output schema (item schema at the view context).
 * - {@see check_permissions()} — the ability-level floor only (the optional `require_permission` guard).
 * - {@see do_execute()}        — dispatches the real route via `rest_do_request()`.
 *
 * Permission model — two checks, run at two different times:
 *
 * 1. The ability's permission phase ({@see check_permissions()}) enforces ONLY the
 *    optional `require_permission` guard. With no guard it returns `true`.
 * 2. The wrapped route's OWN `permission_callback` runs later — once, inside
 *    `rest_do_request()` during {@see do_execute()} — exactly as it would over HTTP.
 *
 * The split is deliberate, and it is the whole reason callers get useful errors.
 * `WP_Ability::execute()` collapses ANY non-`true` permission result into a generic
 * `ability_invalid_permissions` (and fires `_doing_it_wrong`). If this adapter ran the
 * route's permission check in phase 1, every route denial — and every 404, 400, or
 * validation error the route raises — would be flattened to that one opaque code and
 * its real reason lost. By keeping phase 1 to the guard alone and returning `true`
 * otherwise, the route's real decision flows through phase 2 untouched: the caller (an
 * MCP/agent consumer especially) gets the actionable REST error — `rest_forbidden`
 * (403), `rest_post_invalid_id` (404), `rest_invalid_param` (400) — instead of the
 * collapse. The guard is the one check `rest_do_request()` does NOT repeat (it is the
 * adapter's, not the route's), so it is the one thing phase 1 must enforce; a guard
 * denial is itself collapsed by `execute()`, the one case where hiding the reason is
 * legitimate (a real authorization denial).
 *
 * The adapter facilitates adaptation; it does not resolve every problem centrally.
 * Decisions only the developer can make are made at registration via five optional
 * args: `input_callback`, `output_callback`, `input_schema`, `output_schema`, and the
 * opt-in `require_permission` guard. See {@see wp_register_ability_from_rest_route()}
 * for what each does.
 *
 * The remaining behavioral caveats — an optional `require_permission` guard can only
 * tighten (never widen) permission and runs once against the raw ability input, an
 * `input_callback` runs after input validation, and resolution matches the exact route
 * pattern — are flagged on the method that enforces each.
 *
 * @since 0.1.0
 */
class Rest_Route_Ability extends WP_Ability {

	/**
	 * The REST route pattern this ability wraps (e.g. `/wp/v2/posts/(?P<id>[\d]+)`).
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected string $rest_route = '';

	/**
	 * The single HTTP method this ability wraps, uppercased.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected string $rest_method = 'GET';

	/**
	 * The developer's registration args that drive resolution, dispatch, and the
	 * optional permission floor (`input_schema`, `output_schema`, `input_callback`,
	 * `output_callback`, `require_permission`).
	 *
	 * @since 0.1.0
	 * @var array<string, mixed>
	 */
	protected array $rest_args = array();

	/**
	 * Whether resolution has run (memoization guard).
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	protected bool $resolved = false;

	/**
	 * A resolution failure (route/handler not found), surfaced faithfully to callers.
	 *
	 * @since 0.1.0
	 * @var \WP_Error|null
	 */
	protected ?WP_Error $resolve_error = null;

	/**
	 * The matched route handler array, after resolution.
	 *
	 * @since 0.1.0
	 * @var array<string, mixed>|null
	 */
	protected ?array $rest_handler = null;

	/**
	 * The `get_routes()` key actually matched (the registered route regex).
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected string $resolved_route_key = '';

	/**
	 * Parsed representation of the resolved route regex.
	 *
	 * @since 0.1.2
	 * @var \GalatanOvidiu\AbilitiesRestAdapter\Route_Pattern|null
	 */
	protected ?Route_Pattern $route_pattern = null;

	/**
	 * Whether the wrapped route is a paginated collection (drives the output envelope).
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	protected bool $is_collection = false;

	/**
	 * Builds the `wp_register_ability()` args for a REST-backed ability.
	 *
	 * Everything here is eager (no route lookup): the standard ability properties
	 * (`label`, `description`, `category`, `meta`) are passed through unchanged, and
	 * the safety annotations are derived from the HTTP method. The schema,
	 * permission, and execution are deferred to {@see resolve()}.
	 *
	 * Safety annotations follow the fail-safe rule: a GET is marked
	 * `readonly` unless the developer explicitly passes `readonly => false` (a GET
	 * with side effects), and a write is always marked not-readonly. A read-only GET
	 * is also filled with `destructive => false` and `idempotent => true` (a read is
	 * both by definition), so its annotations are complete. Every non-readonly
	 * operation must declare boolean `destructive` and `idempotent` values; missing,
	 * non-boolean, or contradictory safety metadata fails registration.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $name The ability name (`namespace/slug`).
	 * @param array<string, mixed> $args The developer's registration args (see
	 *                                   {@see wp_register_ability_from_rest_route()}).
	 * @return array<string, mixed> Args ready for `wp_register_ability()`, or an empty array when registration metadata is invalid.
	 */
	public static function build_args( string $name, array $args ): array {
		$route   = isset( $args['route'] ) && is_string( $args['route'] ) ? $args['route'] : '';
		$method  = isset( $args['method'] ) && is_string( $args['method'] ) ? strtoupper( trim( $args['method'] ) ) : 'GET';
		$is_read = ( 'GET' === $method );

		if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			_doing_it_wrong(
				'wp_register_ability_from_rest_route',
				sprintf(
					/* translators: 1: HTTP method, 2: ability name. */
					esc_html__( 'The HTTP method "%1$s" for ability "%2$s" is unsupported; use GET, POST, PUT, PATCH, or DELETE.', 'abilities-rest-adapter' ),
					esc_html( $method ),
					esc_html( $name )
				),
				'0.1.0'
			);
			return array();
		}

		if ( isset( $args['meta'] ) && ! is_array( $args['meta'] ) ) {
			_doing_it_wrong(
				'wp_register_ability_from_rest_route',
				sprintf(
					/* translators: %s: ability name. */
					esc_html__( 'The `meta` value for ability "%s" must be an array.', 'abilities-rest-adapter' ),
					esc_html( $name )
				),
				'0.1.0'
			);
			return array();
		}

		if ( isset( $args['meta']['annotations'] ) && ! is_array( $args['meta']['annotations'] ) ) {
			_doing_it_wrong(
				'wp_register_ability_from_rest_route',
				sprintf(
					/* translators: %s: ability name. */
					esc_html__( 'The `meta.annotations` value for ability "%s" must be an array.', 'abilities-rest-adapter' ),
					esc_html( $name )
				),
				'0.1.0'
			);
			return array();
		}

		$annotations = array();
		if ( isset( $args['meta']['annotations'] ) && is_array( $args['meta']['annotations'] ) ) {
			$annotations = $args['meta']['annotations'];
		}

		if ( array_key_exists( 'readonly', $annotations ) && ! is_bool( $annotations['readonly'] ) ) {
			_doing_it_wrong(
				'wp_register_ability_from_rest_route',
				sprintf(
					/* translators: %s: ability name. */
					esc_html__( 'The `readonly` annotation for ability "%s" must be a boolean.', 'abilities-rest-adapter' ),
					esc_html( $name )
				),
				'0.1.0'
			);
			return array();
		}

		$has_write_annotations = array_key_exists( 'destructive', $annotations )
			&& is_bool( $annotations['destructive'] )
			&& array_key_exists( 'idempotent', $annotations )
			&& is_bool( $annotations['idempotent'] );
		$is_readonly           = $is_read
			&& ! ( array_key_exists( 'readonly', $annotations ) && false === $annotations['readonly'] );

		if ( ! $is_readonly && ! $has_write_annotations ) {
			_doing_it_wrong(
				'wp_register_ability_from_rest_route',
				sprintf(
					/* translators: 1: HTTP method, 2: ability name. */
					esc_html__( 'The non-readonly %1$s ability "%2$s" must declare both `destructive` and `idempotent` as boolean annotations under meta.annotations.', 'abilities-rest-adapter' ),
					esc_html( $method ),
					esc_html( $name )
				),
				'0.1.0'
			);
			return array();
		}

		$has_invalid_read_annotations = $is_readonly
			&& (
				( array_key_exists( 'destructive', $annotations ) && false !== $annotations['destructive'] )
				|| ( array_key_exists( 'idempotent', $annotations ) && true !== $annotations['idempotent'] )
			);
		if ( $has_invalid_read_annotations ) {
			_doing_it_wrong(
				'wp_register_ability_from_rest_route',
				sprintf(
					/* translators: %s: ability name. */
					esc_html__( 'The readonly ability "%s" must use `destructive => false` and `idempotent => true` annotations.', 'abilities-rest-adapter' ),
					esc_html( $name )
				),
				'0.1.0'
			);
			return array();
		}

		$has_output_callback = isset( $args['output_callback'] ) && is_callable( $args['output_callback'] );
		$has_output_schema   = isset( $args['output_schema'] ) && is_array( $args['output_schema'] ) && ! empty( $args['output_schema'] );
		if ( $has_output_callback && ! $has_output_schema ) {
			_doing_it_wrong(
				'wp_register_ability_from_rest_route',
				sprintf(
					/* translators: %s: ability name. */
					esc_html__( 'Ability "%s" must declare a non-empty `output_schema` when using an `output_callback`.', 'abilities-rest-adapter' ),
					esc_html( $name )
				),
				'0.1.0'
			);
			return array();
		}

		// A supplied callback must be callable and a supplied schema must be an array;
		// warn and ignore a wrong-typed value so a typo fails loudly at registration
		// instead of silently no-op'ing or falling back to the derived schema unnoticed.
		self::warn_unless_valid(
			$name,
			$args,
			array( 'input_callback', 'output_callback', 'require_permission' ),
			'is_callable',
			/* translators: 1: callback arg name, 2: ability name. */
			__( 'The `%1$s` for ability "%2$s" must be callable; the supplied value was ignored.', 'abilities-rest-adapter' )
		);
		self::warn_unless_valid(
			$name,
			$args,
			array( 'input_schema', 'output_schema' ),
			'is_array',
			/* translators: 1: schema arg name, 2: ability name. */
			__( 'The `%1$s` for ability "%2$s" must be an array; the supplied value was ignored.', 'abilities-rest-adapter' )
		);

		// `readonly` is derived from the method, and a developer may only make it MORE
		// conservative, never less. A write is never read-only: force `false`, overwriting
		// any stray developer `readonly => true` that would mislabel a write as safe. A GET
		// is read-only by default, but a developer who knows the GET has side effects (an
		// oembed proxy, a view counter, a cache regen) may pass `readonly => false` to flag
		// it as not-free-to-call; honor that explicit opt-out, otherwise force `true`. Only
		// `destructive`/`idempotent` are otherwise the developer's to declare.
		$annotations['readonly'] = $is_readonly;

		// A genuine read is non-destructive and idempotent by definition, so fill both
		// when the ability ends up read-only. This keeps the registered annotations
		// complete: without it a GET carries only `readonly`, and core's annotation
		// normalization leaves `destructive`/`idempotent` as `null` ("unknown"), which a
		// consumer treats as ask-first — wrong for a plain read. A GET flagged
		// `readonly => false` (known side effects) is treated like a write: these stay
		// the developer's to declare. Existing developer values are preserved.
		if ( true === $annotations['readonly'] ) {
			if ( ! array_key_exists( 'destructive', $annotations ) ) {
				$annotations['destructive'] = false;
			}
			if ( ! array_key_exists( 'idempotent', $annotations ) ) {
				$annotations['idempotent'] = true;
			}
		}

		$meta                = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		$meta['annotations'] = $annotations;

		// `label`, `description`, and `category` are required standard ability
		// properties; the adapter passes them through and lets core enforce them. When
		// absent they are left null so core reports its clear "must contain a …"
		// requirement — and a null `category` is treated as unset by the registry, so
		// it avoids core's misleading "category \"\" is not registered" message.
		return array(
			'label'         => isset( $args['label'] ) && is_string( $args['label'] ) ? $args['label'] : null,
			'description'   => isset( $args['description'] ) && is_string( $args['description'] ) ? $args['description'] : null,
			'category'      => isset( $args['category'] ) && is_string( $args['category'] ) ? $args['category'] : null,
			'meta'          => $meta,
			'ability_class' => self::class,
			'rest_route'    => $route,
			'rest_method'   => $method,
			'rest_args'     => $args,
		);
	}

	/**
	 * Warns via `_doing_it_wrong` for each arg in `$keys` that is set but fails
	 * `$predicate`, catching a wrong-typed registration value at registration time.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $name      The ability name, for the message.
	 * @param array<string, mixed> $args      The developer's registration args.
	 * @param array<int, string>   $keys      The arg keys to validate.
	 * @param callable             $predicate Returns true when a value is the right type.
	 * @param string               $message   A sprintf format with `%1$s` (arg key) and `%2$s` (ability name).
	 * @return void
	 */
	private static function warn_unless_valid( string $name, array $args, array $keys, callable $predicate, string $message ): void {
		foreach ( $keys as $key ) {
			if ( ! isset( $args[ $key ] ) || $predicate( $args[ $key ] ) ) {
				continue;
			}
			_doing_it_wrong(
				'wp_register_ability_from_rest_route',
				esc_html( sprintf( $message, $key, $name ) ),
				'0.1.0'
			);
		}
	}

	/**
	 * Describes what the adapter derives from a route + method, without registering.
	 *
	 * A developer authoring affordance (the `wp ability describe-route` WP-CLI
	 * command renders this): it constructs a throwaway instance, resolves it once,
	 * and returns a plain snapshot — the derived input/output schemas, the path
	 * captures and their types, whether the route is a collection, and whether it
	 * is a write. It registers no ability and dispatches no route, and it needs no
	 * current user; its only side effect is booting the REST server for route
	 * discovery (`rest_get_server()`), which is idempotent.
	 *
	 * When `found` is false the route did not resolve: `error` carries the reason,
	 * and `is_collection`/`readonly`/`captures`/`input_schema`/`output_schema` are
	 * placeholders, not derived values. `output_schema` is an empty array for a
	 * not-found route or a route that exposes no item schema.
	 *
	 * @since 0.1.0
	 *
	 * @param string $route  A REST route pattern (e.g. `/wp/v2/posts/(?P<id>[\d]+)`).
	 * @param string $method An HTTP method (e.g. `GET`).
	 * @return array<string, mixed> {
	 *     The route snapshot.
	 *
	 *     @type string                $route         The REST route pattern.
	 *     @type string                $method        The HTTP method, trimmed and uppercased.
	 *     @type bool                  $found         Whether the route resolved.
	 *     @type string|null           $error         The resolution error message, or null when found.
	 *     @type bool                  $is_collection Whether the route is a collection (list) GET.
	 *     @type bool                  $readonly      Whether the method is read-only (a GET).
	 *     @type array<string, string> $captures      Map of path-capture name to type (`integer` or `string`).
	 *     @type array<string, mixed>  $input_schema  The derived input schema.
	 *     @type array<string, mixed>  $output_schema The derived output schema, or empty when none is advertised.
	 * }
	 *
	 * @phpstan-return array{route: string, method: string, found: bool, error: string|null, is_collection: bool, readonly: bool, captures: array<string, string>, input_schema: array<string, mixed>, output_schema: array<string, mixed>}
	 */
	public static function describe( string $route, string $method ): array {
		$http_method = strtoupper( trim( $method ) );

		// A throwaway instance: placeholder label/description/category satisfy core's
		// constructor (a subclass needs no execute/permission callback), and the
		// rest_* keys drive resolution. Nothing is registered or dispatched, and the
		// annotation/callback warnings of build_args() are deliberately bypassed —
		// describing a route is not registering one.
		$ability = new self(
			'abilities-rest-adapter/describe-route',
			array(
				'label'       => 'describe-route',
				'description' => 'describe-route',
				'category'    => 'describe-route',
				'rest_route'  => $route,
				'rest_method' => $http_method,
				'rest_args'   => array(),
			)
		);

		$ability->resolve();

		$resolve_error = $ability->resolve_error;
		$found         = ( null === $resolve_error );
		$error         = null !== $resolve_error ? $resolve_error->get_error_message() : null;

		$captures = array();
		if ( $found && null !== $ability->route_pattern ) {
			foreach ( $ability->route_pattern->captures() as $name => $capture ) {
				$captures[ $name ] = $capture['type'];
			}
		}

		return array(
			'route'         => $route,
			'method'        => $http_method,
			'found'         => $found,
			'error'         => $error,
			'is_collection' => $ability->is_collection,
			'readonly'      => ( 'GET' === $http_method ),
			'captures'      => $captures,
			'input_schema'  => $ability->input_schema,
			'output_schema' => $ability->output_schema,
		);
	}

	/**
	 * Retrieves the derived input schema (route args + required path captures).
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The input schema.
	 */
	public function get_input_schema(): array {
		$this->resolve();

		/**
		 * Filters the derived input schema for a REST-route ability.
		 *
		 * The adapter derives the schema from the route's args and path captures; this
		 * lets a consumer add properties the route itself does not declare — e.g. a
		 * multisite policy layer injecting an optional `blog_id`. The schema is derived
		 * lazily and is not present in the registration args, so a `wp_register_ability_args`
		 * filter cannot reach it; this is the seam that can.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,mixed> $schema    The derived input schema.
		 * @param string              $name      The ability name.
		 * @param array<string,mixed> $rest_args The developer's registration args.
		 */
		return apply_filters( 'abilities_rest_adapter_input_schema', $this->input_schema, $this->get_name(), $this->rest_args );
	}

	/**
	 * Retrieves the derived output schema (item schema at the view context).
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The output schema.
	 */
	public function get_output_schema(): array {
		$this->resolve();
		return $this->output_schema;
	}

	/**
	 * Enforces the ability-level permission floor — the optional `require_permission`
	 * guard — and only that. The wrapped route's own permission check is NOT run here;
	 * it runs at dispatch, inside {@see do_execute()}. See the class docblock's
	 * "Permission model" for why this split exists: it is what lets the route's real
	 * error reach the caller instead of being collapsed by `execute()`.
	 *
	 * Resolves, then hands to the parent so the `wp_ability_permission_result` filter
	 * (WordPress 7.1+) fires around the guard verdict. Returns `true` when there is no
	 * guard or the guard passes, deferring the real authorization decision to the route
	 * at dispatch time.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input Optional. The input data for the permission check. Default `null`.
	 * @return bool|\WP_Error `true` when the guard passes or is absent; a `WP_Error` when the guard denies.
	 */
	public function check_permissions( $input = null ) {
		$this->resolve();
		return parent::check_permissions( $input );
	}

	/**
	 * Executes the ability by dispatching the wrapped route via `rest_do_request()`.
	 *
	 * This is where the wrapped route's OWN `permission_callback` runs (inside
	 * `rest_do_request()`), so a route-level denial — or a 404/validation error —
	 * surfaces here as the real REST error, not as the permission phase's generic
	 * collapse. See the class docblock's "Permission model".
	 *
	 * Resolves, then hands to the parent so the `wp_ability_execute_result`
	 * filter fires around the dispatch.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input Optional. The input data for the ability. Default `null`.
	 * @return mixed|\WP_Error The dispatched response data, or a `WP_Error`.
	 */
	protected function do_execute( $input = null ) {
		$this->resolve();

		/**
		 * Filters a wrapper around dispatch for a REST-route ability.
		 *
		 * A consumer can run dispatch inside a context and/or adjust the input before
		 * it reaches the route — e.g. a multisite policy layer that opens a balanced
		 * `switch_to_blog()` and strips its own `blog_id` from the input. Because the
		 * adapter is guard-only (its real permission runs at dispatch, inside
		 * `rest_do_request()`), wrapping dispatch alone runs BOTH the route's permission
		 * check and the handler in the consumer's context — no split-brain. The wrapper
		 * is `fn( callable $proceed, mixed $input ): mixed`; `$proceed( $input )` performs
		 * the normal dispatch. Return `null` (the default) to dispatch unwrapped.
		 *
		 * @since 0.1.0
		 *
		 * @param callable|null $wrapper The dispatch wrapper, or null for none.
		 * @param string        $name    The ability name.
		 * @param mixed         $input   The validated ability input.
		 */
		$wrapper = apply_filters( 'abilities_rest_adapter_dispatch_wrapper', null, $this->get_name(), $input );
		if ( is_callable( $wrapper ) ) {
			$proceed = function ( $in = null ) {
				return $this->dispatch_internally( $in );
			};
			return $wrapper( $proceed, $input );
		}

		return parent::do_execute( $input );
	}

	/**
	 * Performs the normal dispatch (the parent's `do_execute`).
	 *
	 * Extracted so an `abilities_rest_adapter_dispatch_wrapper` can call it as the
	 * `$proceed` step from a closure (where `parent::` is not available).
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input Optional. The input data for the ability. Default `null`.
	 * @return mixed|\WP_Error The dispatched response data, or a `WP_Error`.
	 */
	private function dispatch_internally( $input = null ) {
		return parent::do_execute( $input );
	}

	/**
	 * Resolves the wrapped route once and derives everything from it.
	 *
	 * Wires the permission and execute callbacks unconditionally (even on
	 * failure, so the parent machinery can run and surface a faithful error),
	 * then looks up the handler and derives the input/output schemas.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	protected function resolve(): void {
		if ( $this->resolved ) {
			return;
		}

		// Wire the callbacks (idempotent across retries) so the parent machinery runs.
		// The permission callback runs only the optional `require_permission` guard; the
		// route's own permission check fires later, at dispatch inside the execute
		// callback. See the class docblock's "Permission model" for why they are split.
		$this->permission_callback = function ( $input = null ) {
			return $this->run_permission_check( $input );
		};
		$this->execute_callback    = function ( $input = null ) {
			return $this->run_rest_dispatch( $input );
		};

		$handler = $this->find_handler();
		if ( null === $handler ) {
			// The route may simply not be registered yet — resolution can be triggered
			// mid-boot, before rest_api_init finishes. Surface a fail-closed error for
			// this call but do NOT memoize, so a later call (post-boot) can resolve once
			// the route exists. The input schema stays open so validate_input() passes
			// and the real route-not-found error surfaces from the permission/execute path.
			$this->resolve_error = new WP_Error(
				'rest_ability_route_not_found',
				sprintf(
					/* translators: 1: HTTP method, 2: REST route pattern. */
					__( 'No registered REST route handler was found for "%1$s %2$s".', 'abilities-rest-adapter' ),
					$this->rest_method,
					$this->rest_route
				),
				array( 'status' => 500 )
			);
			$this->input_schema  = array(
				'type'                 => 'object',
				'additionalProperties' => true,
			);
			$this->output_schema = array();
			return;
		}

		$this->resolve_error                               = null;
		[ $this->resolved_route_key, $this->rest_handler ] = $handler;
		$this->route_pattern                               = new Route_Pattern( $this->resolved_route_key );
		$this->is_collection                               = $this->detect_collection( $this->rest_handler );

		// Input schema: a developer-supplied schema wins, else derive from the route.
		$input_override     = $this->arg_schema( 'input_schema' );
		$this->input_schema = null !== $input_override ? $input_override : $this->derive_input_schema( $this->rest_handler );

		// Output schema: a developer-supplied schema wins; otherwise derive from the
		// route. Registration requires an explicit non-empty schema for every callable
		// output callback, so a reshaped result can never reach this fallback.
		$output_override     = $this->arg_schema( 'output_schema' );
		$this->output_schema = null !== $output_override ? $output_override : $this->derive_output_schema( $this->rest_handler );

		$this->resolved = true;
	}

	/**
	 * Finds the route handler matching the configured route and method.
	 *
	 * Calls `rest_get_server()`, which boots the REST server (firing
	 * `rest_api_init`) if it has not booted yet, then matches the route pattern
	 * against the `get_routes()` keys and picks the handler whose `methods`
	 * include the configured method.
	 *
	 * This resolves the exact registered route pattern. After capture substitution,
	 * {@see check_route_identity()} separately confirms that WordPress's ordered
	 * matcher still selects this route before any permission callback is invoked.
	 *
	 * @since 0.1.0
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null The `[route_key, handler]`, or `null` if not found.
	 */
	protected function find_handler(): ?array {
		$routes = rest_get_server()->get_routes();
		$route  = $this->rest_route;

		if ( empty( $routes[ $route ] ) ) {
			return null;
		}

		foreach ( $routes[ $route ] as $handler ) {
			// `register_rest_route()` always normalizes `methods` to a verb-keyed array
			// (`rest-api.php`: `$handler['methods'][ $method ] = true`), so a verb-keyed
			// lookup is the only reachable case; `empty()` is null-safe for a route that
			// somehow declares none.
			if ( ! empty( $handler['methods'][ $this->rest_method ] ) ) {
				return array( $route, $handler );
			}
		}

		return null;
	}

	/**
	 * Determines whether the wrapped route is a collection (list) GET.
	 *
	 * A GET handler is a collection only when its callback is a controller's
	 * `get_items` method — the reliable signal that the route returns a list,
	 * including controllers that override `get_collection_params()` (e.g.
	 * `WP_REST_Themes_Controller`). A `per_page`/`page` argument is deliberately
	 * NOT treated as a collection signal: a route can paginate without returning a
	 * list, and a false positive would advertise the `{ items, total, total_pages }`
	 * envelope for a body that dispatch returns unwrapped. A closure-based list
	 * route that is not a `get_items` controller should declare its shape with
	 * `output_schema`/`output_callback`. This distinguishes `/wp/v2/posts` and
	 * `/wp/v2/themes` (collections) from `/wp/v2/posts/(?P<id>[\d]+)` and
	 * `/wp/v2/users/me` (single items).
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $handler The matched route handler.
	 * @return bool True if the route is a collection GET.
	 */
	protected function detect_collection( array $handler ): bool {
		if ( 'GET' !== $this->rest_method ) {
			return false;
		}

		return isset( $handler['callback'] )
			&& is_array( $handler['callback'] )
			&& isset( $handler['callback'][1] )
			&& 'get_items' === $handler['callback'][1];
	}

	/**
	 * Derives the input schema: the route's per-method args plus its path captures.
	 *
	 * Path captures become required properties (typed `integer` for numeric
	 * sub-patterns, else `string`). Non-portable keys (`sanitize_callback`,
	 * `validate_callback`, `arg_options`, `context`, `readonly`) and closures are
	 * stripped — the route re-applies them at dispatch. The schema is closed
	 * (`additionalProperties: false`) and normalized (empty `properties` and
	 * `required` keywords are omitted).
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $handler The matched route handler.
	 * @return array<string, mixed> The input schema.
	 */
	protected function derive_input_schema( array $handler ): array {
		$args       = isset( $handler['args'] ) && is_array( $handler['args'] ) ? $handler['args'] : array();
		$properties = array();
		$required   = array();

		foreach ( $args as $name => $arg ) {
			$clean = $this->clean_schema_node( $arg, true );
			if ( empty( $clean ) ) {
				continue;
			}
			$properties[ $name ] = $clean;
			if ( ! isset( $arg['required'] ) || true !== $arg['required'] ) {
				continue;
			}

			$required[] = $name;
		}

		$route_captures = null !== $this->route_pattern ? $this->route_pattern->captures() : array();
		foreach ( $route_captures as $name => $capture ) {
			if ( ! isset( $properties[ $name ] ) ) {
				$properties[ $name ] = array(
					'type'        => $capture['type'],
					'description' => sprintf(
						/* translators: %s: path parameter name. */
						__( 'The "%s" path parameter.', 'abilities-rest-adapter' ),
						$name
					),
				);
			}
			$required[] = $name;
		}

		$schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
		);
		if ( ! empty( $properties ) ) {
			$schema['properties'] = $properties;
		}

		$required = array_values( array_unique( $required ) );
		if ( ! empty( $required ) ) {
			$schema['required'] = $required;
			return $schema;
		}

		// No required input: the route accepts an empty call (a no-arg or all-optional
		// route, e.g. a collection GET). `WP_Ability::execute()` invoked with no
		// argument normalizes the input to the schema's top-level `default` and then
		// validates it; without one, the input stays `null` and fails this schema's
		// `type: object`, surfacing `ability_invalid_input` ("input is not of type
		// object"). Default to an empty object so an empty call normalizes to `{}` and
		// passes. A stdClass keeps it `{}` (not `[]`) for strict JSON Schema validators.
		$schema['default'] = new stdClass();

		return $schema;
	}

	/**
	 * Derives the output schema from the route's item schema at the view context.
	 *
	 * Acquires the item schema via the standard fallback chain (handler `schema`
	 * callable → handler `schema` array → `callback[0]->get_item_schema()`), keeps
	 * only the properties visible in the default `view` context, and wraps a
	 * collection in `{ items, total, total_pages }`. `additionalProperties` stays
	 * open so capability-gated and `register_rest_field`/`meta` extras still
	 * validate. A developer who pins a different `context` (via `input_callback`)
	 * or reshapes the body should pass an `output_schema` to keep this honest.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $handler The matched route handler.
	 * @return array<string, mixed> The output schema, or an empty array if no item schema is available.
	 */
	protected function derive_output_schema( array $handler ): array {
		$item_props = $this->item_schema_properties( $handler );
		if ( empty( $item_props ) ) {
			return array();
		}

		// The REST default context; deviations are the developer's to declare via an
		// `input_callback` (to set `context`) plus a matching `output_schema`.
		$context = 'view';

		$kept = array();
		foreach ( $item_props as $name => $prop ) {
			$prop = $prop instanceof stdClass ? (array) $prop : $prop;
			if ( ! is_array( $prop ) ) {
				continue;
			}
			if ( isset( $prop['context'] ) && is_array( $prop['context'] ) && ! in_array( $context, $prop['context'], true ) ) {
				continue;
			}

			$clean = $this->clean_schema_node( $prop, false, $context );
			if ( empty( $clean ) ) {
				continue;
			}

			$kept[ $name ] = $clean;
		}

		$item_schema = array(
			'type'                 => 'object',
			'additionalProperties' => true,
		);
		if ( ! empty( $kept ) ) {
			$item_schema['properties'] = $kept;
		}

		if ( $this->is_collection ) {
			return array(
				'type'                 => 'object',
				'properties'           => array(
					'items'       => array(
						'type'  => 'array',
						'items' => $item_schema,
					),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
				),
				'additionalProperties' => true,
			);
		}

		return $item_schema;
	}

	/**
	 * Acquires the route's item-schema properties via the standard fallback chain.
	 *
	 * Core controllers usually do not set the handler `schema` key, so the
	 * controller's `get_item_schema()` (reached through `callback[0]`) is the
	 * common path.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $handler The matched route handler.
	 * @return array<string, mixed> The item schema's `properties`, or empty array.
	 */
	protected function item_schema_properties( array $handler ): array {
		if ( isset( $handler['schema'] ) ) {
			$schema = is_callable( $handler['schema'] )
				? call_user_func( $handler['schema'] )
				: $handler['schema'];
			$schema = $schema instanceof stdClass ? (array) $schema : $schema;
			if ( is_array( $schema ) && isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
				return $schema['properties'];
			}
		}

		if ( isset( $handler['callback'] ) && is_array( $handler['callback'] ) && isset( $handler['callback'][0] ) ) {
			$controller = $handler['callback'][0];
			if ( is_object( $controller ) && method_exists( $controller, 'get_item_schema' ) ) {
				$schema = $controller->get_item_schema();
				$schema = $schema instanceof stdClass ? (array) $schema : $schema;
				if ( is_array( $schema ) && isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
					return $schema['properties'];
				}
			}
		}

		return array();
	}

	/**
	 * Recursively strips non-portable keys and closures from a schema node.
	 *
	 * Always removes REST-internal keys (`sanitize_callback`, `validate_callback`,
	 * `arg_options`, `context`) and any closure/non-array-object values so the
	 * schema is JSON-serializable. For input nodes, also removes `readonly` and
	 * prunes read-only nested properties (at every depth). Empty schema-map
	 * keywords are omitted: this serializes portably and keeps WordPress's PHP
	 * validator from receiving a non-array `stdClass` schema map.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed       $node           The schema node (array, object, or scalar).
	 * @param bool        $strip_readonly Whether to drop `readonly` and read-only nested properties (input only).
	 * @param string|null $output_context Optional output context used to prune nested properties.
	 * @return array<string, mixed> The cleaned node, or an empty array if the node is not a usable schema.
	 */
	protected function clean_schema_node( $node, bool $strip_readonly, ?string $output_context = null ): array {
		if ( $node instanceof stdClass ) {
			$node = (array) $node;
		}
		if ( ! is_array( $node ) ) {
			return array();
		}

		// Prune child properties from the RAW node first: recursion below strips the
		// `readonly` and `context` markers these decisions depend on.
		$raw_properties = $node['properties'] ?? null;
		if ( $raw_properties instanceof stdClass ) {
			$raw_properties = (array) $raw_properties;
		}
		if ( is_array( $raw_properties ) && ( $strip_readonly || null !== $output_context ) ) {
			$removed_properties = array();
			foreach ( $raw_properties as $prop_name => $prop_schema ) {
				$prop_array = $prop_schema instanceof stdClass ? (array) $prop_schema : $prop_schema;
				if ( ! is_array( $prop_array ) ) {
					continue;
				}

				$remove_readonly = $strip_readonly && ! empty( $prop_array['readonly'] );
				$remove_context  = null !== $output_context
					&& isset( $prop_array['context'] )
					&& is_array( $prop_array['context'] )
					&& ! in_array( $output_context, $prop_array['context'], true );
				if ( ! $remove_readonly && ! $remove_context ) {
					continue;
				}

				unset( $raw_properties[ $prop_name ] );
				$removed_properties[] = $prop_name;
			}

			$node['properties'] = $raw_properties;
			if ( ! empty( $removed_properties ) && isset( $node['required'] ) && is_array( $node['required'] ) ) {
				$node['required'] = array_values( array_diff( $node['required'], $removed_properties ) );
				if ( empty( $node['required'] ) ) {
					unset( $node['required'] );
				}
			}
		}

		$strip = array( 'sanitize_callback', 'validate_callback', 'arg_options', 'context' );
		if ( $strip_readonly ) {
			$strip[] = 'readonly';
		}

		$clean = array();
		foreach ( $node as $key => $value ) {
			if ( in_array( $key, $strip, true ) ) {
				continue;
			}

			// A schema map is not itself a schema node: its keys are caller-defined
			// names and must never be compared with the schema-keyword strip list.
			if ( in_array( $key, array( 'properties', 'patternProperties', 'definitions', '$defs', 'dependentSchemas' ), true ) ) {
				if ( $value instanceof stdClass ) {
					$value = (array) $value;
				}
				if ( ! is_array( $value ) ) {
					continue;
				}

				$property_schemas = array();
				foreach ( $value as $property_name => $property_schema ) {
					$property_schema = $this->clean_schema_node( $property_schema, $strip_readonly, $output_context );
					if ( empty( $property_schema ) ) {
						continue;
					}
					$property_schemas[ $property_name ] = $property_schema;
				}

				if ( ! empty( $property_schemas ) ) {
					$clean[ $key ] = $property_schemas;
				}
				continue;
			}

			if ( in_array( $key, array( 'oneOf', 'anyOf', 'allOf', 'prefixItems' ), true ) ) {
				if ( $value instanceof stdClass ) {
					$value = (array) $value;
				}
				if ( ! is_array( $value ) ) {
					continue;
				}

				$schema_members = array();
				foreach ( $value as $member ) {
					$member = $this->clean_schema_node( $member, $strip_readonly, $output_context );
					if ( empty( $member ) ) {
						continue;
					}

					$schema_members[] = $member;
				}

				if ( ! empty( $schema_members ) ) {
					$clean[ $key ] = $schema_members;
				}
				continue;
			}

			if ( in_array( $key, array( 'items', 'additionalItems', 'additionalProperties', 'unevaluatedProperties', 'contains', 'propertyNames', 'not', 'if', 'then', 'else' ), true ) ) {
				if ( is_bool( $value ) ) {
					$clean[ $key ] = $value;
					continue;
				}

				$value = $this->clean_schema_node( $value, $strip_readonly, $output_context );
				if ( ! empty( $value ) ) {
					$clean[ $key ] = $value;
				}
				continue;
			}

			if ( is_array( $value ) || $value instanceof stdClass ) {
				$value = $this->clean_schema_data_value( $value );
			} elseif ( $value instanceof Closure || is_object( $value ) ) {
				continue;
			}
			$clean[ $key ] = $value;
		}

		// Omitting an empty schema-map keyword is equivalent to an empty `{}` schema for
		// these positions, while remaining valid for WordPress's array-based validator.
		foreach ( array( 'properties', 'patternProperties', 'additionalProperties', 'items' ) as $object_keyword ) {
			if ( ! array_key_exists( $object_keyword, $clean ) || ! is_array( $clean[ $object_keyword ] ) || ! empty( $clean[ $object_keyword ] ) ) {
				continue;
			}

			unset( $clean[ $object_keyword ] );
		}

		// Drop `oneOf`/`anyOf`/`allOf` members that cleaned out to empty: an empty `{}`
		// schema matches everything, which would silently defeat the combinator. If a
		// combinator loses all its members, drop it entirely.
		foreach ( array( 'oneOf', 'anyOf', 'allOf' ) as $list_keyword ) {
			if ( ! isset( $clean[ $list_keyword ] ) || ! is_array( $clean[ $list_keyword ] ) ) {
				continue;
			}

			$members = array();
			foreach ( $clean[ $list_keyword ] as $member ) {
				if ( is_array( $member ) && empty( $member ) ) {
					continue;
				}

				$members[] = $member;
			}

			if ( empty( $members ) ) {
				unset( $clean[ $list_keyword ] );
				continue;
			}

			$clean[ $list_keyword ] = $members;
		}

		return $clean;
	}

	/**
	 * Removes non-serializable values from array-valued schema data without
	 * interpreting caller-defined keys as schema keywords.
	 *
	 * @since 0.1.0
	 *
	 * @param array<array-key, mixed>|\stdClass $value The data value to clean.
	 * @return array<array-key, mixed>|\stdClass The JSON-serializable data value.
	 */
	protected function clean_schema_data_value( $value ) {
		$is_object = $value instanceof stdClass;
		$items     = $is_object ? (array) $value : $value;
		$clean     = array();
		foreach ( $items as $key => $item ) {
			if ( is_array( $item ) || $item instanceof stdClass ) {
				$item = $this->clean_schema_data_value( $item );
			} elseif ( $item instanceof Closure || is_object( $item ) ) {
				continue;
			}

			$clean[ $key ] = $item;
		}

		return $is_object ? (object) $clean : $clean;
	}

	/**
	 * Runs the ability-level permission floor: the optional `require_permission`
	 * guard, and nothing else. The wrapped route's own `permission_callback` runs
	 * later, at dispatch — see the class docblock's "Permission model" for why the two
	 * are split (it is what lets the route's real error reach the caller).
	 *
	 * The guard can only DENY: a truthy verdict returns `true` and hands authority to
	 * the route's dispatch-time check; any falsey verdict (`false`, `null`, `0`, `''`,
	 * `array()`) becomes `rest_forbidden`; a `WP_Error` surfaces unchanged. It sees the
	 * RAW ability input, before any `input_callback`, which suits a coarse floor (a
	 * capability, or "logged in") rather than an object-level check on transformed data.
	 * A non-callable guard was warned about and dropped at registration (see {@see build_args()}).
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input Optional. The raw ability input. Default `null`.
	 * @return true|\WP_Error `true` to defer to the route's dispatch-time check, or a `WP_Error` if the guard denies.
	 */
	protected function run_permission_check( $input = null ) {
		$guard = $this->rest_args['require_permission'] ?? null;
		if ( ! is_callable( $guard ) ) {
			return true;
		}

		$verdict = $guard( $input );
		if ( is_wp_error( $verdict ) ) {
			return $verdict;
		}

		// Any falsey verdict (false, null, 0, '', array()) denies, normalizing to the
		// same `rest_forbidden` (401/403) a route denial returns at dispatch, so a
		// consumer reading check_permissions() sees an actionable code and status, not a
		// bare false. A WP_Error was already returned above; only a truthy verdict allows.
		if ( ! $verdict ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to do that.', 'abilities-rest-adapter' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Dispatches the wrapped route in-process and shapes the result.
	 *
	 * Errors map faithfully via `WP_REST_Response::as_error()` and never reach the
	 * output callback. A collection is wrapped as `{ items, total, total_pages }`,
	 * reading the totals from the `X-WP-Total`/`X-WP-TotalPages` headers that
	 * dispatch otherwise drops; a single item is the body unchanged. A
	 * developer-supplied `output_callback` then gets the last word on that
	 * successful value (`$data`, the original `$input`, and the `$response`) and may
	 * reshape it or return a `WP_Error`.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input Optional. The ability input. Default `null`.
	 * @return mixed|\WP_Error The shaped response data, or a `WP_Error`.
	 */
	protected function run_rest_dispatch( $input = null ) {
		if ( null !== $this->resolve_error ) {
			return $this->resolve_error;
		}

		$request = $this->build_request( $input );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$route_identity = $this->check_route_identity( $request );
		if ( is_wp_error( $route_identity ) ) {
			return $route_identity;
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$data = $response->get_data();

		if ( $this->is_collection && $this->is_list( $data ) ) {
			$headers     = $response->get_headers();
			$total       = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $data );
			$total_pages = isset( $headers['X-WP-TotalPages'] ) ? (int) $headers['X-WP-TotalPages'] : 1;
			$data        = array(
				'items'       => $data,
				'total'       => $total,
				'total_pages' => $total_pages,
			);
		}

		// The developer's output callback gets the last word on a successful body
		// (the single item or the collection envelope), with the original input and
		// the response for context. It may reshape the data or return a WP_Error.
		$callback = $this->rest_args['output_callback'] ?? null;
		if ( is_callable( $callback ) ) {
			return $callback( $data, is_array( $input ) ? $input : array(), $response );
		}

		return $data;
	}

	/**
	 * Confirms that WordPress will dispatch the concrete path to the configured route.
	 *
	 * A concrete path can match more than one registered regex. The REST server uses
	 * registration order, so dispatching without this check could run a sibling route's
	 * permission callback and handler instead of the route the ability was built from.
	 * This mirrors the server's route/method selection without invoking any callbacks.
	 *
	 * @since 0.1.2
	 *
	 * @param \WP_REST_Request $request The prepared concrete request.
	 * @return true|\WP_Error True when the configured route owns the path, or an error on a conflict.
	 */
	protected function check_route_identity( WP_REST_Request $request ) {
		$method = $request->get_method();
		$path   = $request->get_route();
		$server = rest_get_server();

		$with_namespace = array();
		$path_prefix    = trailingslashit( ltrim( $path, '/' ) );
		foreach ( $server->get_namespaces() as $namespace ) {
			if ( 0 !== strpos( $path_prefix, $namespace ) ) {
				continue;
			}

			$with_namespace[] = $server->get_routes( $namespace );
		}

		$routes = ! empty( $with_namespace )
			? array_merge( ...$with_namespace )
			: $server->get_routes();

		foreach ( $routes as $route => $handlers ) {
			if ( 1 !== preg_match( '@^' . $route . '$@i', $path ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				$checked_method = $method;
				if ( 'HEAD' === $method && empty( $handler['methods']['HEAD'] ) ) {
					$checked_method = 'GET';
				}
				if ( empty( $handler['methods'][ $checked_method ] ) ) {
					continue;
				}

				if ( $this->resolved_route_key === $route ) {
					return true;
				}

				return new WP_Error(
					'rest_ability_route_mismatch',
					__( 'The request path resolves to a different REST route than the route configured for this ability.', 'abilities-rest-adapter' ),
					array( 'status' => 409 )
				);
			}
		}

		// Let the REST server return its canonical rest_no_route error when no route matches.
		return true;
	}

	/**
	 * Whether a value is a zero-indexed sequential array (a JSON list).
	 *
	 * Only a list is wrapped in the collection envelope, so a non-array or
	 * associative response body is returned unchanged.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The value to test.
	 * @return bool True if the value is a list array.
	 */
	protected function is_list( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( array() === $value ) {
			return true;
		}
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Builds the REST request: the concrete path plus the remaining params.
	 *
	 * Applies the developer's `input_callback` (if any) to the params first, then
	 * substitutes path captures through {@see Route_Pattern}; the keys consumed as captures are set as URL params
	 * (so dispatch's permission callback and handler read them) and removed from the
	 * body/query params. Exactly the supplied keys are forwarded — no schema defaults
	 * are injected, so an explicit empty string stays an empty string.
	 *
	 * Caveat: `WP_Ability::execute()` validates the input against the ability's
	 * schema before this runs, so an `input_callback` cannot supply a value the
	 * schema already requires (e.g. a required path capture). To inject a fixed
	 * required value, also pass an `input_schema` that does not mark it required.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input The ability input.
	 * @return \WP_REST_Request|\WP_Error The prepared request, or a `WP_Error` if the input callback rejects it or returns a non-array value, or route-pattern substitution rejects a path capture (missing, non-scalar, or not fitting the route pattern).
	 */
	protected function build_request( $input ) {
		$input = is_array( $input ) ? $input : array();

		// The developer's input callback transforms the params before the request is
		// built — set `_fields`, pin `context`, inject fixed params, reshape, or return
		// a WP_Error to reject. A return that is neither an array nor a WP_Error fails
		// closed with `rest_invalid_input_callback` (500). It runs once per execute(), at
		// dispatch (the permission phase is guard-only and builds no request).
		$input_callback = $this->rest_args['input_callback'] ?? null;
		if ( is_callable( $input_callback ) ) {
			$transformed = $input_callback( $input );
			if ( is_wp_error( $transformed ) ) {
				return $transformed;
			}
			if ( ! is_array( $transformed ) ) {
				return new WP_Error(
					'rest_invalid_input_callback',
					__( 'The input callback returned an invalid value; it must return an array or a WP_Error.', 'abilities-rest-adapter' ),
					array( 'status' => 500 )
				);
			}
			$input = $transformed;
		}

		if ( null === $this->route_pattern ) {
			return new WP_Error(
				'rest_ability_route_not_resolved',
				__( 'The REST route pattern is not available for this ability.', 'abilities-rest-adapter' ),
				array( 'status' => 500 )
			);
		}

		$path = $this->route_pattern->substitute( $input );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		[ $route, $consumed ] = $path;

		// The consumed captures become url_params — dispatch's permission callback and
		// handler read them from the path, mirroring HTTP — and drop out of the body/query.
		$url_params = array();
		$params     = $input;
		foreach ( $consumed as $name => $encoded ) {
			$url_params[ $name ] = $encoded;
			unset( $params[ $name ] );
		}

		$request = new WP_REST_Request( $this->rest_method, $route );
		$request->set_url_params( $url_params );

		if ( 'GET' === $this->rest_method || 'DELETE' === $this->rest_method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		return $request;
	}

	/**
	 * Returns a developer-supplied schema arg, or null if none is set.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Either `input_schema` or `output_schema`.
	 * @return array<string, mixed>|null The supplied schema, or null when unset.
	 */
	protected function arg_schema( string $key ): ?array {
		return isset( $this->rest_args[ $key ] ) && is_array( $this->rest_args[ $key ] )
			? $this->rest_args[ $key ]
			: null;
	}
}
