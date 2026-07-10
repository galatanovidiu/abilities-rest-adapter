# Usage guide

The Abilities REST Adapter turns one REST API route + one HTTP method into one
[Abilities API](https://github.com/WordPress/abilities-api) ability. The ability reuses the
route's schema, validation, permission check, and handler — it dispatches the real
route through `rest_do_request()` instead of re-implementing it, so it stays
behaviorally equivalent to the endpoint.

This guide covers the registration call, what the adapter derives for you, the
seams you use to adapt a route, the write-safety rule, a known permission-error
limitation, and the `wp ability describe-route` command.

## Contents

- [Registering an ability](#registering-an-ability)
- [What the adapter derives](#what-the-adapter-derives)
- [The five seams](#the-five-seams)
- [Collections](#collections)
- [Writes and safety annotations](#writes-and-safety-annotations)
- [How permission errors surface](#how-permission-errors-surface)
- [Extending with filters](#extending-with-filters)
- [Inspecting a route: `wp ability describe-route`](#inspecting-a-route-wp-ability-describe-route)

## Registering an ability

Call `wp_register_ability_from_rest_route( string $name, array $args )` inside the
`wp_abilities_api_init` action. The signature mirrors core's
`wp_register_ability( $name, $args )`.

```php
add_action( 'wp_abilities_api_init', function () {
	wp_register_ability_from_rest_route( 'my-plugin/get-post', array(
		'route'       => '/wp/v2/posts/(?P<id>[\d]+)',
		'method'      => 'GET',
		'label'       => 'Get a post',
		'description' => 'Fetch a single published post by ID.',
		'category'    => 'content',
	) );
} );
```

### Required keys

| Key           | Type   | Notes |
|---------------|--------|-------|
| `route`       | string | A **registered** REST route pattern, e.g. `/wp/v2/users/me` or `/wp/v2/posts/(?P<id>[\d]+)`. Must match the registered pattern exactly. |
| `method`      | string | One HTTP method: `GET`, `POST`, `PUT`, `PATCH`, or `DELETE`. |
| `label`       | string | Human-readable label. Passed straight to core. |
| `description` | string | Human-readable description. Passed straight to core. |
| `category`    | string | An **already-registered** ability category slug. |

There is **no default category**. The adapter does not invent one — register your
category first (on `wp_abilities_api_categories_init`), then pass its slug:

```php
add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'content', array(
		'label'       => 'Content',
		'description' => 'Abilities that read or write content.',
	) );
} );
```

`label`, `description`, and `category` are required exactly as for
`wp_register_ability()`; if you omit one, core reports the missing property.

## What the adapter derives

When the ability is first used (the lookup is deferred, so the route need not be
registered yet at call time), the adapter derives:

- **Input schema** — the route's per-method arguments, plus any path captures
  (e.g. the `id` in `/posts/(?P<id>[\d]+)`) as required properties. A numeric
  capture becomes `integer`, anything else `string`. The schema is closed
  (`additionalProperties: false`).
- **Output schema** — the route's item schema at the default `view` context. A
  collection is wrapped in `{ items, total, total_pages }`. If the route exposes
  no item schema, no output schema is advertised (an empty schema).
- **Permission** — the route's real `permission_callback`, run on validated,
  sanitized parameters.
- **Execution** — `rest_do_request()` against the real route.

Use [`wp ability describe-route`](#inspecting-a-route-wp-ability-describe-route)
to see exactly what a route produces before you register it.

## The five seams

The adapter **facilitates** adaptation; it does not reshape on its own. Decisions
only you can make are made at registration through five optional args — four that
adapt the route (`input_callback`, `output_callback`, `input_schema`,
`output_schema`) and one permission floor (`require_permission`).

### `input_callback`

```php
'input_callback' => function ( array $params ) {
	// transform $params, then return them — or return a WP_Error to reject.
	return $params;
},
```

`fn( array $params ): array|WP_Error`. Transforms the request parameters before
the request is built. Use it to set `_fields`, pin a `context`, inject fixed
parameters, or reshape the input. Return a `WP_Error` to reject the call; a return
that is neither an array nor a `WP_Error` fails closed as `rest_invalid_input_callback`
(500).

It runs **once** per `execute()`, at dispatch — the permission phase runs only the
`require_permission` guard and builds no request.

> **Note:** the callback runs *after* the ability validates the input against its
> schema. It can inject an **optional** parameter (e.g. `per_page`, `context`),
> but it cannot supply a value the schema requires: a required path capture like
> `id` must be present before the callback runs, or validation rejects the call.
> To pin a fixed required capture, also pass an `input_schema` that does not mark
> it required.

### `output_callback`

```php
'output_callback' => function ( $data, array $input, $response ) {
	// reshape the successful $data, or return a WP_Error.
	return $data;
},
```

`fn( $data, array $input, WP_REST_Response $response ): mixed|WP_Error`. Reshapes
a **successful** response. It runs last, over the route body or the collection
envelope. It is **not** called on an error. `$input` is the original ability
input, before any `input_callback` ran. Return a `WP_Error` to turn a success into
a failure. A callable `output_callback` requires a non-empty `output_schema`.

### `input_schema` and `output_schema`

```php
'input_schema'  => array( /* standard ability input schema */ ),
'output_schema' => array( /* standard ability output schema */ ),
```

Each **replaces** the schema the adapter would otherwise derive. Pass them when
the derived schema is wrong for your ability — for example, when an
`input_callback` injects parameters the route does not advertise, or an
`output_callback` reshapes the body.

> **Note:** if you set an `output_callback` but omit a non-empty `output_schema`,
> registration fails. The declared schema is the validated contract for the
> reshaped result.

> **Note:** the derived output schema describes the `view` context only. If you
> pin a different context with `input_callback` (e.g. `context => 'edit'`), the
> response carries fields the derived schema does not list. Pass a matching
> `output_schema` to keep the advertised schema honest.

### `require_permission`

```php
'require_permission' => function ( $input ) {
	// return true to allow, false/null to deny, or a WP_Error.
	return current_user_can( 'edit_posts' );
},
```

`fn( mixed $input ): bool|WP_Error`. An **opt-in permission floor** on top of the
route's own check. The adapter is faithful to the route's permission by default,
which is usually right — but a route can be more permissive than your ability
should be (e.g. `GET /wp/v2/comments/<id>` lets anyone read an approved comment).
This guard lets you re-impose a coarse floor without giving up the route's schema,
validation, and dispatch.

It can only **tighten** access, never widen it. The guard runs first, in the
permission phase; if it passes, the route's own check still runs and remains the
authority. So a guard that returns `true` can never grant access the route would
deny — the two are an AND-gate.

- Return `true` (or any truthy non-`WP_Error`) → allowed; the route decides next.
- Return any falsey value (`false`, `null`, `0`, `''`, `array()`) → denied as
  `rest_forbidden` (401/403).
- Return a `WP_Error` → that error is the denial.

`$input` is the **raw** ability input, before any `input_callback`. That suits a
coarse floor — a capability, or "must be logged in" — not an object-level check on
transformed data.

> **Note:** the guard runs **once**, in the permission phase — the only permission
> check that runs there. The route's own `permission_callback` runs once later, at
> dispatch. The guard runs as the current user, and a standalone
> `check_permissions()` does not pre-validate input, so keep the guard robust.

> **Note:** a `WP_Error` from the guard surfaces unchanged through
> `check_permissions()`. Bare `execute()` collapses the guard's denial to the generic
> `ability_invalid_permissions` (the one case where the reason is hidden). The route's
> own denial, by contrast, surfaces through `execute()` as the real REST error — see
> [How permission errors surface](#how-permission-errors-surface).

## Collections

A GET route that returns a list is detected as a collection when its handler
callback is a controller's `get_items` method — the reliable signal that the route
returns a list. A closure-based list route that is not a `get_items` controller is
treated as a single item; shape it as a collection with `output_schema` /
`output_callback`. A collection's result is wrapped:

```json
{ "items": [ /* the route body */ ], "total": 42, "total_pages": 5 }
```

`total` and `total_pages` come from the `X-WP-Total` and `X-WP-TotalPages`
response headers that a plain dispatch would otherwise drop; when those headers
are absent, `total` falls back to the item count and `total_pages` to `1`. A
single-item route returns its body unchanged.

## Writes and safety annotations

Every non-readonly operation must declare two boolean annotations under
`meta.annotations`:

```php
wp_register_ability_from_rest_route( 'my-plugin/trash-post', array(
	'route'       => '/wp/v2/posts/(?P<id>[\d]+)',
	'method'      => 'DELETE',
	'label'       => 'Trash a post',
	'description' => 'Move a post to the trash.',
	'category'    => 'content',
	'meta'        => array(
		'annotations' => array(
			'destructive' => true, // deleting is a destructive update
			'idempotent'  => true, // trashing an already-trashed post is a no-op
		),
	),
) );
```

- **`readonly`** is derived from the method — `true` for GET, `false` for a write.
  A write is **always** `false`: a stray `readonly: true` on a write is overridden,
  so a write can never be mislabeled as safe. A GET defaults to `true`, but if you
  know the GET has side effects (an oEmbed proxy, a view counter, a cache regen) you
  may pass `readonly: false` to flag it as not free to call. You can only make a GET
  *more* conservative this way — you can never mark a write safe.
- **`destructive`** and **`idempotent`** are yours to declare for every
  non-readonly operation. They describe behavior only you know.

Missing or non-boolean `destructive`/`idempotent` values fail registration. A
readonly GET may omit them because the adapter supplies `destructive => false`
and `idempotent => true`; contradictory read hints also fail registration.

## How permission errors surface

Permission is checked in two places, at two times:

1. **The ability's permission phase** runs only your optional
   [`require_permission`](#require_permission) guard. With no guard it always passes.
2. **The route's own `permission_callback`** runs at dispatch, inside
   `rest_do_request()`, when you call `execute()` — exactly as it would over HTTP.

This split is what makes errors useful. `WP_Ability::execute()` collapses *any*
non-`true` permission-phase result into a generic `ability_invalid_permissions` error
and fires `_doing_it_wrong`. Because the adapter keeps the permission phase to the
guard alone, the route's real decision flows through dispatch untouched, and
`execute()` returns the **real** REST error the caller can recover from:

- **A route denial** → `rest_forbidden` (403/401), or a controller's own
  `rest_cannot_*` error.
- **A missing object** → `rest_post_invalid_id` and friends (404).
- **A parameter the route rejects** → `rest_invalid_param` (400) — e.g. when a
  supplied `input_schema` is looser than the route, or an `input_callback` injects a
  value the route rejects.
- **A missing or non-fitting path capture** → `ability_invalid_input` (caught by the
  ability's own schema validation), or, with a loose `input_schema`,
  `rest_ability_missing_route_param` / `rest_no_route` from dispatch.

An MCP/agent consumer gets an actionable code and status to recover from, instead of
one opaque error.

**The one thing still collapsed:** a `require_permission` **guard** denial. The guard
is the adapter's own floor, run only in the permission phase, so its denial is a
genuine "not allowed" — `execute()` returns the generic `ability_invalid_permissions`,
hiding the reason, which is the legitimate case for an authorization denial. The
guard's real `WP_Error` still surfaces through a standalone `check_permissions()` and
the `wp_ability_permission_result` filter (WordPress 7.1+).

> **Note:** because the route's permission check runs only at dispatch, a
> standalone `check_permissions()` reflects the guard alone — it does not pre-run the
> route's check, so an ability may report "allowed" yet still be denied by the route at
> `execute()` time. A callback with side effects (rate limiting, audit logging) runs
> once per `execute()`.

## Extending with filters

Two filters let a consumer adapt an ability it did **not** register — for example a
site-wide policy layer wrapping every REST-backed ability. They complement the
per-registration seams above, and reach state the registration args cannot.

### `abilities_rest_adapter_input_schema`

```php
add_filter( 'abilities_rest_adapter_input_schema', function ( array $schema, string $name, array $rest_args ) {
	// Add a property the route itself does not declare.
	return $schema;
}, 10, 3 );
```

Filters the derived input schema. The schema is derived lazily and is not present in
the registration args, so a `wp_register_ability_args` filter cannot reach it — this
is the seam that can. Use it, for example, in a multisite policy layer that injects
an optional `blog_id` the route does not advertise.

### `abilities_rest_adapter_dispatch_wrapper`

```php
add_filter( 'abilities_rest_adapter_dispatch_wrapper', function ( $wrapper, string $name, $input ) {
	return function ( callable $proceed, $input ) {
		// Run dispatch inside a context, and/or adjust $input first.
		return $proceed( $input );
	};
}, 10, 3 );
```

Wraps dispatch. The wrapper is `fn( callable $proceed, mixed $input ): mixed`, where
`$proceed( $input )` performs the normal dispatch; return `null` (the default) to
dispatch unwrapped. Because the adapter's real permission check runs *at* dispatch
(inside `rest_do_request()`), wrapping dispatch runs both the route's permission check
and its handler inside your context — the two never split. A multisite policy layer,
for instance, can open a balanced `switch_to_blog()` around `$proceed()` and strip its
own `blog_id` from `$input` first.

## Inspecting a route: `wp ability describe-route`

`wp ability describe-route <route> <method>` shows what the adapter derives from a
route, so you can decide which seams you need **before** you register anything. It
registers no ability and dispatches no route. The command exists only when WP-CLI
is running; there is no HTTP or runtime equivalent.

```sh
wp ability describe-route '/wp/v2/posts/(?P<id>[\d]+)' GET
```

```
Route:   /wp/v2/posts/(?P<id>[\d]+)
Method:  GET
Returns: single item
Safety:  read-only (GET)
Path params: id (integer)

Input schema:
{ ... }

Output schema:
{ ... }
```

For a write, the safety line tells you which annotations you must declare:

```
Safety:  WRITE — declare meta.annotations.destructive and .idempotent when you register it; the adapter cannot derive them.
```

Pass `--format=json` to get the whole snapshot (route, method, found, error,
is_collection, readonly, captures, input_schema, output_schema) as one JSON object
for scripting:

```sh
wp ability describe-route '/wp/v2/posts' GET --format=json
```

`--format=json` always emits the snapshot — including an unresolved route, where
`found` is `false` and `error` carries the reason — so a script checks the `found`
field. The default human format instead reports a clear "no registered REST route
handler" error and exits non-zero on an unresolved route.

The `<route>` must match the registered route pattern exactly. Copy the exact
pattern from `wp rest` route listings or the route registry when a route has a
complex capture (such as the FSE template `id`).

> **Known limitation:** the adapter resolves the exact registered route pattern.
> For overlapping third-party routes with permissive captures — where a
> substituted path could also match a different, earlier-registered route — the
> permission check and the dispatch resolve the handler independently. Core routes
> do not overlap this way; wrap third-party routes with permissive captures with
> care.
