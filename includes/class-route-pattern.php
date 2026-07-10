<?php
/**
 * REST route-pattern parsing and substitution.
 *
 * @package AbilitiesRestAdapter
 */

declare( strict_types = 1 );

namespace GalatanOvidiu\AbilitiesRestAdapter;

use WP_Error;

/**
 * Parses named captures and safely builds a concrete path from a REST route regex.
 *
 * @since 0.1.2
 */
final class Route_Pattern {

	/** @var string */
	private string $route;

	/**
	 * @param string $route The registered REST route regex.
	 */
	public function __construct( string $route ) {
		$this->route = $route;
	}

	/**
	 * Returns every named capture with its regex and derived ability type.
	 *
	 * @return array<string, array{subpattern: string, type: string}>
	 */
	public function captures(): array {
		$captures = array();
		foreach ( $this->iterate_captures() as $capture ) {
			$captures[ $capture['name'] ] = array(
				'subpattern' => $capture['subpattern'],
				'type'       => $this->is_numeric_subpattern( $capture['subpattern'] ) ? 'integer' : 'string',
			);
		}

		return $captures;
	}

	/**
	 * Substitutes named captures with scalar input values that satisfy each regex.
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return array{0: string, 1: array<string, string>}|\WP_Error The concrete path and consumed captures, or an error.
	 */
	public function substitute( array $input ) {
		$result   = '';
		$offset   = 0;
		$consumed = array();
		$missing  = array();
		$invalid  = array();
		$no_match = array();

		foreach ( $this->iterate_captures() as $capture ) {
			$name        = $capture['name'];
			$group_start = $capture['group_start'];
			$group_end   = $capture['group_end'];

			$result .= substr( $this->route, $offset, $group_start - $offset );

			if ( 0 === $capture['depth'] && array_key_exists( $name, $input ) && is_scalar( $input[ $name ] ) ) {
				$value             = (string) $input[ $name ];
				$matches_pattern   = $this->capture_matches( $value, $capture['subpattern'] );
				$encoded           = $this->encode_capture( $value, $capture['subpattern'] );
				$result           .= $encoded;
				$consumed[ $name ] = $encoded;

				if ( ! $matches_pattern ) {
					$no_match[] = $name;
				}
			} elseif ( 0 === $capture['depth'] && array_key_exists( $name, $input ) ) {
				$invalid[] = $name;
				$result   .= substr( $this->route, $group_start, $group_end - $group_start );
			} else {
				$missing[] = $name;
				$result   .= substr( $this->route, $group_start, $group_end - $group_start );
			}

			$offset = $group_end;
		}

		$result .= substr( $this->route, $offset );

		if ( ! empty( $invalid ) ) {
			return new WP_Error(
				'rest_ability_invalid_route_param',
				sprintf(
					/* translators: %s: comma-separated list of parameter names. */
					__( 'Path parameter(s) must be a scalar value: %s', 'abilities-rest-adapter' ),
					implode( ', ', $invalid )
				),
				array( 'status' => 400 )
			);
		}

		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'rest_ability_missing_route_param',
				sprintf(
					/* translators: %s: comma-separated list of parameter names. */
					__( 'Missing required path parameter(s): %s', 'abilities-rest-adapter' ),
					implode( ', ', $missing )
				),
				array( 'status' => 400 )
			);
		}

		if ( ! empty( $no_match ) ) {
			return new WP_Error(
				'rest_no_route',
				sprintf(
					/* translators: %s: path parameter name. */
					__( 'The "%s" path parameter does not fit the route pattern; no REST route matches it.', 'abilities-rest-adapter' ),
					$no_match[0]
				),
				array( 'status' => 404 )
			);
		}

		return array( $result, $consumed );
	}

	/**
	 * Iterates named captures in source order.
	 *
	 * @return iterable<array{name: string, group_start: int, group_end: int, subpattern: string, depth: int}>
	 */
	private function iterate_captures(): iterable {
		$offset = 0;
		$length = strlen( $this->route );
		$opener = '/\(\?(?:P<([A-Za-z_][A-Za-z0-9_]*)>|<([A-Za-z_][A-Za-z0-9_]*)>|\'([A-Za-z_][A-Za-z0-9_]*)\')/';

		while ( preg_match( $opener, $this->route, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset ) ) {
			$matched_opener = $matches[0][0];
			if ( ! is_string( $matched_opener ) ) {
				break;
			}
			$group_start      = (int) $matches[0][1];
			$subpattern_start = $group_start + strlen( $matched_opener );
			$name             = '';
			foreach ( array( 1, 2, 3 ) as $name_index ) {
				if ( null === $matches[ $name_index ][0] ) {
					continue;
				}
				$name = $matches[ $name_index ][0];
				break;
			}

			[ $group_end, $depth ] = $this->find_capture_end( $subpattern_start, $length );

			yield array(
				'name'        => $name,
				'group_start' => $group_start,
				'group_end'   => $group_end,
				'subpattern'  => substr( $this->route, $subpattern_start, $group_end - 1 - $subpattern_start ),
				'depth'       => $depth,
			);

			$offset = $group_end;
		}
	}

	/**
	 * Finds the balanced end of a named capture, including nested groups/classes.
	 *
	 * @return array{0: int, 1: int} End offset and residual nesting depth.
	 */
	private function find_capture_end( int $subpattern_start, int $length ): array {
		$depth = 1;
		$pos   = $subpattern_start;
		while ( $pos < $length && $depth > 0 ) {
			$char = $this->route[ $pos ];
			if ( '\\' === $char ) {
				$pos += 2;
				continue;
			}
			if ( '[' === $char ) {
				++$pos;
				if ( $pos < $length && '^' === $this->route[ $pos ] ) {
					++$pos;
				}
				if ( $pos < $length && '\\' === $this->route[ $pos ] ) {
					++$pos;
				}
				++$pos;
				while ( $pos < $length && ']' !== $this->route[ $pos ] ) {
					if ( '\\' === $this->route[ $pos ] ) {
						++$pos;
					}
					++$pos;
				}
			} elseif ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				--$depth;
			}
			++$pos;
		}

		return array( $pos, $depth );
	}

	private function encode_capture( string $value, string $subpattern ): string {
		return $this->capture_matches( $value, $subpattern ) ? $value : rawurlencode( $value );
	}

	private function capture_matches( string $value, string $subpattern ): bool {
		return 1 === @preg_match( '@^(?:' . $subpattern . ')$@i', $value ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Forbidden, WordPress.PHP.NoSilencedErrors.Discouraged -- Fail closed on an uncompilable registered sub-pattern.
	}

	private function is_numeric_subpattern( string $subpattern ): bool {
		return (bool) preg_match( '/^(?:\[\\\\d\]|\\\\d|\[0-9\])(?:[+*]|\{\d+(?:,\d*)?\})?$/', $subpattern );
	}
}
