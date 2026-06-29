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
- [The four seams](#the-four-seams)
- [Collections](#collections)
- [Writes and safety annotations](#writes-and-safety-annotations)
- [Permission errors: a known limitation](#permission-errors-a-known-limitation)
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

## The four seams

The adapter **facilitates** adaptation; it does not reshape on its own. Decisions
only you can make are made at registration through four optional args.

### `input_callback`

```php
'input_callback' => function ( array $params ) {
	// transform $params, then return them — or return a WP_Error to reject.
	return $params;
},
```

`fn( array $params ): array|WP_Error`. Transforms the request parameters before
the request is built. Use it to set `_fields`, pin a `context`, inject fixed
parameters, or reshape the input. Return a `WP_Error` to reject the call.

**It must be pure.** It can run more than once per call — once for the permission
check and once for the dispatch — so do not let it depend on call order or cause
side effects.

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
a failure.

### `input_schema` and `output_schema`

```php
'input_schema'  => array( /* standard ability input schema */ ),
'output_schema' => array( /* standard ability output schema */ ),
```

Each **replaces** the schema the adapter would otherwise derive. Pass them when
the derived schema is wrong for your ability — for example, when an
`input_callback` injects parameters the route does not advertise, or an
`output_callback` reshapes the body.

> **Note:** if you set an `output_callback` but omit `output_schema`, the adapter
> advertises **no** output schema and core skips output validation (the derived
> schema would describe the route body, not your reshaped body). Pass an
> `output_schema` to opt back into a validated output.

> **Note:** the derived output schema describes the `view` context only. If you
> pin a different context with `input_callback` (e.g. `context => 'edit'`), the
> response carries fields the derived schema does not list. Pass a matching
> `output_schema` to keep the advertised schema honest.

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

Any method other than GET is a **write**. A write must declare two annotations
under `meta.annotations`:

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

- **`readonly`** is always derived from the method — `true` for GET, `false` for a
  write — and **cannot** be set by you. A stray `readonly: true` on a write is
  overridden, so a write can never be mislabeled as safe.
- **`destructive`** and **`idempotent`** are yours to declare. They describe
  behavior only you know.

If you omit `destructive`/`idempotent` on a write, the ability **still registers**,
but the adapter triggers `_doing_it_wrong` and leaves the annotations unset
(`null`). Unset means "unknown" — a consumer treats an unknown annotation as
unsafe (ask first), never as a false "safe".

## Permission errors: a known limitation

The adapter delegates the permission decision to the route's own
`permission_callback`, run on validated and sanitized parameters. Two things to
know about how errors surface:

- **A denial** (the route returns `false`/`null`) becomes a `rest_forbidden` error
  with a 403 (logged in) or 401 (logged out) status — the same actionable error
  the endpoint returns over HTTP, not a bare `false`.
- **An input error caught during the permission phase** — a missing or invalid
  path capture, or a parameter the route rejects (which happens when a supplied
  `input_schema` is looser than the route, or an `input_callback` injects a value
  the route rejects) — surfaces **faithfully** through `check_permissions()` as the
  real `WP_Error`.

> **Note:** the permission check runs only the route's own `permission_callback`,
> not request-level filters such as `rest_request_before_callbacks`. So a
> `check_permissions()` of `true` means the route would allow the call, not that
> dispatch is guaranteed to succeed.

> **Note:** one `execute()` runs the route's `permission_callback` **twice** — once
> for the adapter's permission check (which surfaces the real denial) and once
> inside `rest_do_request()` at dispatch. A permission callback with side effects
> (rate limiting, audit logging, cached state) must tolerate running more than once
> per call, just like `input_callback`.

**The limitation:** `execute()` collapses *any* permission-phase error — a denial
or an input error — into a generic `ability_invalid_permissions` error and fires
`_doing_it_wrong`. This is core's behavior for the `WP_Ability::execute()` path,
not something the adapter can change.

To get the real reason:

- Read `check_permissions( $input )` directly — it carries the real `WP_Error`.
- Or hook the `wp_ability_permission_result` filter (WordPress 7.1+), which sees
  the real result.
- Or advertise an `input_schema` that matches the route, so an input error
  surfaces as an input-validation error before the permission phase.

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
