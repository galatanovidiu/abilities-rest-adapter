=== Abilities REST Adapter ===
Contributors:      galatanovidiu
Tags:              abilities-api, rest-api, ai, wordpress
Requires at least: 6.9
Requires PHP:      7.4
Stable tag:        0.1.0
License:           MIT
License URI:       https://opensource.org/licenses/MIT

Define Abilities API abilities by reusing existing REST API routes.

== Description ==

The Abilities REST Adapter turns one REST API route and one HTTP method into one [Abilities API](https://github.com/WordPress/abilities-api) ability. The ability reuses the route's schema, validation, permission check, and handler — it dispatches the real route through `rest_do_request()` instead of reimplementing it.

== Installation ==

The primary installation method for plugin developers is via Composer:

`composer require galatanovidiu/abilities-rest-adapter`

The adapter can also be installed as a standard WordPress plugin from a GitHub release or a Git clone. See the [README](https://github.com/galatanovidiu/abilities-rest-adapter#installation) for detailed instructions.

== Changelog ==

For the full changelog, see the [releases on GitHub](https://github.com/galatanovidiu/abilities-rest-adapter/releases).