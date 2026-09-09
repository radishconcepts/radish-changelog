<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Jira;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;
use RuntimeException;

/**
 * Jira Cloud REST v3 search client. Depends on WordPress HTTP functions
 * (wp_safe_remote_post()/wp_safe_remote_get()), so unlike the other Cli
 * classes this one is not unit-tested; coverage is manual (see plan
 * Verification).
 *
 * Credentials are read exclusively from the environment
 * (JIRA_EMAIL/JIRA_API_TOKEN, which take precedence) or from
 * ~/.config/radish-changelog/jira.json, never from the repo, and are never
 * printed or logged: exceptions carry only the HTTP status and, for an
 * authentication failure, the `x-seraph-loginreason` header value as a
 * diagnostic (see assert_authenticated()) — never the body, any other
 * header, or the credentials themselves.
 */
final class Client {

	private const REQUEST_TIMEOUT      = 15;
	private const CONFIG_RELATIVE_PATH = '.config/radish-changelog/jira.json';
	private const KEY_PATTERN          = '/^[A-Z][A-Z0-9]+-\d+$/';

	private function __construct(
		private readonly string $base_url,
		private readonly string $email,
		private readonly string $api_token,
	) {}

	/**
	 * Builds a Client from environment variables first, then
	 * ~/.config/radish-changelog/jira.json. Returns null when neither
	 * source has usable credentials; call unavailable_reason() to explain
	 * why for a warning message.
	 */
	public static function from_environment( string $base_url ): ?self {
		$credentials = self::resolve_credentials();

		if ( null === $credentials ) {
			return null;
		}

		return new self( $base_url, $credentials['email'], $credentials['api_token'] );
	}

	/**
	 * Explains why from_environment() would return null right now (no env
	 * vars, no config file, an unreadable file, or one with too-open
	 * permissions). Returns null when credentials are in fact available.
	 * Never includes the credential values themselves, only the path
	 * consulted.
	 */
	public static function unavailable_reason(): ?string {
		if ( null !== self::resolve_credentials() ) {
			return null;
		}

		$path = self::config_path();

		if ( null !== $path && is_file( $path ) ) {
			$perms = fileperms( $path );
			if ( false !== $perms && 0 !== ( $perms & 0o077 ) ) {
				return sprintf(
					'%s is readable by group or others; refusing to use it. Fix its permissions to 0600, or set JIRA_EMAIL and JIRA_API_TOKEN in the environment.',
					$path
				);
			}
		}

		return sprintf(
			'No Jira credentials found. Set JIRA_EMAIL and JIRA_API_TOKEN in the environment, or create %s (mode 0600, with "email" and "api_token" keys).',
			$path ?? '~/.config/radish-changelog/jira.json'
		);
	}

	/**
	 * Summaries for $keys via a single Jira search request (key => summary).
	 * Keys missing from the Jira response are simply absent from the
	 * result; the caller decides what "no title" means.
	 *
	 * @param string[] $keys
	 * @return array<string, string>
	 * @throws InvalidArgumentException When a key does not look like a Jira key; it is never sent in the JQL.
	 * @throws RuntimeException On a transport failure, a non-200 response, or an invalid response body.
	 */
	public function summaries( array $keys ): array {
		$validated = [];

		foreach ( $keys as $key ) {
			if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
				throw new InvalidArgumentException( sprintf( 'Refusing to query Jira for invalid ticket key "%s".', $key ) );
			}

			$validated[] = $key;
		}

		if ( [] === $validated ) {
			return [];
		}

		$issues    = $this->search( $validated );
		$summaries = [];

		foreach ( $issues as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}

			$key     = (string) ( $issue['key'] ?? '' );
			$summary = $issue['fields']['summary'] ?? null;

			if ( '' === $key || ! is_string( $summary ) ) {
				continue;
			}

			$summaries[ $key ] = $summary;
		}

		return $summaries;
	}

	/**
	 * @param string[] $keys
	 * @return array<int, mixed>
	 * @throws RuntimeException On a transport failure, a non-200 response, or an invalid response body.
	 */
	private function search( array $keys ): array {
		$jql = 'key in (' . implode( ',', $keys ) . ')';

		$response = wp_safe_remote_post( rtrim( $this->base_url, '/' ) . '/rest/api/3/search/jql', [
			'timeout' => self::REQUEST_TIMEOUT,
			'headers' => $this->headers(),
			'body'    => wp_json_encode( [
				'jql'        => $jql,
				'fields'     => [ 'summary' ],
				'maxResults' => 100,
			] ),
		] );

		$code = self::response_code( $response );

		// The POST /search/jql endpoint is relatively new; fall back once
		// to the GET /search endpoint when it is not available.
		if ( 404 === $code ) {
			$response = wp_safe_remote_get(
				rtrim( $this->base_url, '/' ) . '/rest/api/3/search?' . http_build_query( [
					'jql'        => $jql,
					'fields'     => 'summary',
					'maxResults' => 100,
				] ),
				[
					'timeout' => self::REQUEST_TIMEOUT,
					'headers' => $this->headers(),
				]
			);
			$code = self::response_code( $response );
		}

		if ( 200 !== $code ) {
			throw new RuntimeException( sprintf( 'Jira request failed with HTTP %d', $code ) );
		}

		self::assert_authenticated( $response );

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['issues'] ) || ! is_array( $decoded['issues'] ) ) {
			throw new RuntimeException( 'Jira response was not valid JSON.' );
		}

		return $decoded['issues'];
	}

	/**
	 * @return array<string, string>
	 */
	private function headers(): array {
		return [
			'Authorization' => 'Basic ' . base64_encode( $this->email . ':' . $this->api_token ),
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		];
	}

	/**
	 * @param mixed $response
	 */
	private static function response_code( $response ): int {
		if ( is_wp_error( $response ) ) {
			return 0;
		}

		return (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Jira Cloud does not return 401 for invalid Basic Auth on the search
	 * endpoints: it silently downgrades the request to anonymous and still
	 * answers 200, with an `x-seraph-loginreason` header marking the auth
	 * failure and an empty `issues` array. Guard against that so a wrong
	 * token reads as "Jira authentication failed", not "no title found".
	 *
	 * Only the specific failure values are matched (never a loose prefix
	 * like "AUTHENTICAT"), because Jira also uses this header for
	 * non-failure reasons (e.g. "OK") that happen to share the prefix.
	 *
	 * @param mixed $response
	 * @throws RuntimeException When the header indicates the request was
	 *                          served anonymously despite credentials. The
	 *                          message carries only the HTTP status and the
	 *                          header value, never credentials.
	 */
	private static function assert_authenticated( $response ): void {
		$reason = wp_remote_retrieve_header( $response, 'x-seraph-loginreason' );

		if ( ! is_string( $reason ) || '' === $reason ) {
			return;
		}

		$failure_reasons = [
			'AUTHENTICATED_FAILED',
			'AUTHENTICATION_DENIED',
			'AUTHORISATION_FAILED',
			'AUTHORIZATION_FAILED',
		];

		if ( in_array( strtoupper( $reason ), $failure_reasons, true ) ) {
			throw new RuntimeException( sprintf(
				'Jira authentication failed (HTTP 200, x-seraph-loginreason: %s)',
				$reason
			) );
		}
	}

	/**
	 * @return array{email: string, api_token: string}|null
	 */
	private static function resolve_credentials(): ?array {
		$email = getenv( 'JIRA_EMAIL' );
		$token = getenv( 'JIRA_API_TOKEN' );

		if ( is_string( $email ) && '' !== $email && is_string( $token ) && '' !== $token ) {
			return [
				'email'     => $email,
				'api_token' => $token,
			];
		}

		return self::from_config_file();
	}

	/**
	 * @return array{email: string, api_token: string}|null
	 */
	private static function from_config_file(): ?array {
		$path = self::config_path();

		if ( null === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$perms = fileperms( $path );
		if ( false === $perms || 0 !== ( $perms & 0o077 ) ) {
			// Group/other readable: refuse rather than trust a
			// loosely-permissioned credentials file.
			return null;
		}

		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return null;
		}

		$decoded = json_decode( $contents, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$email = $decoded['email'] ?? null;
		$token = $decoded['api_token'] ?? null;

		if ( ! is_string( $email ) || '' === $email || ! is_string( $token ) || '' === $token ) {
			return null;
		}

		return [
			'email'     => $email,
			'api_token' => $token,
		];
	}

	private static function config_path(): ?string {
		$home = getenv( 'HOME' );

		if ( ! is_string( $home ) || '' === $home ) {
			return null;
		}

		return rtrim( $home, '/' ) . '/' . self::CONFIG_RELATIVE_PATH;
	}
}
