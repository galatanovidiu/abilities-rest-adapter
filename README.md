# Abilities REST Adapter

Define a WordPress [Abilities API](https://make.wordpress.org/core/) ability by **reusing an existing REST API route** — its schema, validation, permission check, and handler — instead of re-implementing them. The adapter dispatches the real route via `rest_do_request()`, so the ability stays behaviorally equivalent to the endpoint.

```php
wp_register_ability_from_rest_route(
	'my-plugin/list-posts',
	'/wp/v2/posts',
	'GET'
);
```

- **Reuse, don't reshape.** Permission is the route's real check; output is the route's real body. Collections synthesize `{items, total, total_pages}` from the `X-WP-Total` headers. No field renaming or transforming.
- **Standalone now, core later.** Built as a feature plugin; designed so it can move into core.
- **Status:** early. Design is complete (`.hyper/specs/abilities-rest-adapter/`); the build is in progress.

## Requirements

- WordPress 6.9+ (the Abilities API ships in core 6.9). Faithful permission-error surfacing uses the 7.1 `wp_ability_permission_result` filter.
- PHP 7.4+ (matches WordPress core's supported floor). CI tests every major version through PHP 8.5.

The dev environment (`.wp-env.json`) tracks current WordPress trunk (7.1-alpha) so the 7.1 filter is available.

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
