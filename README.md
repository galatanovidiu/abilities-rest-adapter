# Abilities REST Adapter

[![Packagist Version](https://img.shields.io/packagist/v/galatanovidiu/abilities-rest-adapter)](https://packagist.org/packages/galatanovidiu/abilities-rest-adapter)
[![Packagist Downloads](https://img.shields.io/packagist/dt/galatanovidiu/abilities-rest-adapter)](https://packagist.org/packages/galatanovidiu/abilities-rest-adapter)

Define a WordPress [Abilities API](https://github.com/WordPress/abilities-api) ability by **reusing an existing REST API route** — its schema, validation, permission check, and handler — instead of re-implementing them. The adapter dispatches the real route via `rest_do_request()`, so the ability stays behaviorally equivalent to the endpoint.

```php
wp_register_ability_from_rest_route( 'my-plugin/list-posts', array(
	'route'       => '/wp/v2/posts',
	'method'      => 'GET',
	'label'       => 'List Posts',
	'description' => 'List published posts.',
	'category'    => 'content', // must already be registered
) );
```

The signature mirrors core's `wp_register_ability( $name, $args )`. `route`, `method`, `label`, `description`, and `category` are required.

**See [`docs/usage.md`](docs/usage.md)** for the full guide: the five seams (`input_callback` / `output_callback` / `input_schema` / `output_schema` / `require_permission`), collections, the write-safety rule, the permission-error limitation, and the `wp ability describe-route` command.

- **Reuse, don't re-implement.** Permission is the route's real check; output is the route's real body. A collection synthesizes `{ items, total, total_pages }` from the `X-WP-Total` headers.
- **Facilitate, don't force.** The adapter gives the developer seams instead of baking in opinions:
  - `input_callback( array $params ): array|WP_Error` — transform the request before dispatch (set `_fields`, pin `context`, inject fixed params, reshape, or reject).
  - `output_callback( $data, array $input, WP_REST_Response $response ): mixed|WP_Error` — reshape a successful response (or reject it).
  - `input_schema` / `output_schema` — replace the schemas the adapter derives from the route.
  - `require_permission( mixed $input ): bool|WP_Error` — an opt-in floor checked before the route's own permission; it can only tighten access, never widen it.
- **Safety annotations (writes).** A write (any non-GET) must declare `destructive` and `idempotent` under `meta.annotations`; `readonly` is always derived from the method. Omitting them still registers but warns and leaves them unset (unknown, never a false "safe").
- **Standalone now, core later.** Built as a feature plugin; designed so it can move into core.
- **Status:** behavioral-equivalence + regression suite green on PHP 7.4–8.5.

## Requirements

- WordPress 6.9+ (the Abilities API ships in core 6.9). Faithful permission-error surfacing uses the 7.1 `wp_ability_permission_result` filter.
- PHP 7.4+ (matches WordPress core's supported floor). CI tests every major version through PHP 8.5.

The dev environment (`.wp-env.json`) tracks current WordPress trunk (7.1-alpha) so the 7.1 filter is available.

## Installation

### As a Composer dependency (plugin developers)

Plugin developers can install the adapter as a Composer dependency and use
`wp_register_ability_from_rest_route()` from their own plugin. The package is
published on [Packagist](https://packagist.org/packages/galatanovidiu/abilities-rest-adapter):

```bash
composer require galatanovidiu/abilities-rest-adapter
```

#### Using Jetpack Autoloader (recommended)

When multiple plugins bundle this adapter, use the
[Jetpack Autoloader](https://github.com/Automattic/jetpack-autoloader) so only
the latest shared version loads:

```bash
composer require automattic/jetpack-autoloader
```

Load it in your main plugin file instead of the standard Composer autoloader:

```php
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload_packages.php';
```

#### Using the adapter in your plugin

Check availability and register abilities on `wp_abilities_api_init`:

```php
add_action( 'plugins_loaded', function () {
	if ( ! function_exists( 'wp_register_ability_from_rest_route' ) ) {
		return;
	}

	add_action( 'wp_abilities_api_init', function () {
		wp_register_ability_from_rest_route( 'my-plugin/get-post', array(
			'route'       => '/wp/v2/posts/(?P<id>[\d]+)',
			'method'      => 'GET',
			'label'       => 'Get a post',
			'description' => 'Fetch a single published post by ID.',
			'category'    => 'content',
		) );
	} );
} );
```

See [`docs/usage.md`](docs/usage.md) for the full registration guide.

### As a WordPress plugin (recommended for site-wide use)

Install and activate the adapter once; any plugin on the site can call
`wp_register_ability_from_rest_route()`.

Download the latest stable release from the
[GitHub Releases page](https://github.com/galatanovidiu/abilities-rest-adapter/releases/latest)
and install it like any other WordPress plugin. Release ZIPs include the
bundled `vendor/` directory.

#### With WP-CLI

```bash
wp plugin install https://github.com/galatanovidiu/abilities-rest-adapter/releases/latest/download/abilities-rest-adapter.zip --activate
```

#### From a git checkout

```bash
composer install --no-dev
wp plugin activate abilities-rest-adapter
```

## Development

```sh
npm install
composer install
npm run wp-env          # start the dev environment
npm run lint:php        # PHPCS
npm run lint:php:stan   # PHPStan (level 8)
npm run test:php:setup  # install deps in the test env (first run)
npm run test:php        # PHPUnit
```

## License

MIT
