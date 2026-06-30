<?php
/**
 * FasterFy Landing — shared bootstrap.
 *
 * Session, CSRF tokens, security headers and small helpers shared by the
 * public page (index.php) and the waitlist endpoint (api/waitlist.php).
 *
 * @package FasterFy\Landing
 */

declare( strict_types=1 );

namespace FasterFy\Landing;

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
*/
const CSRF_TOKEN_KEY    = 'fasterfy_csrf';
const FORM_RENDERED_KEY = 'fasterfy_form_ts';

// Minimum seconds a human needs before a legitimate submit (time-trap).
const MIN_FILL_SECONDS = 3;

// Rate limiting: max submissions per IP inside the window.
const RATE_LIMIT_MAX     = 5;
const RATE_LIMIT_WINDOW  = 600; // 10 minutes.

// Where waitlist signups are stored (outside the web root would be ideal in
// production; kept inside a protected /data folder here with an .htaccess deny).
const DATA_DIR = __DIR__ . '/../data';

/**
 * Start a hardened session exactly once.
 */
function boot_session(): void {
	if ( PHP_SESSION_ACTIVE === session_status() ) {
		return;
	}

	$secure = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] )
		|| ( ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) === 'https' );

	session_set_cookie_params(
		[
			'lifetime' => 0,
			'path'     => '/',
			'secure'   => $secure,
			'httponly' => true,
			'samesite' => 'Lax',
		]
	);

	session_name( 'fasterfy_sess' );
	session_start();
}

/**
 * Send a baseline set of security headers.
 *
 * The CSP intentionally avoids inline scripts; the page ships its JS from an
 * external, versioned file. Inline styles are allowed to keep critical CSS
 * fast, but no inline JS is permitted.
 */
function send_security_headers(): void {
	if ( headers_sent() ) {
		return;
	}

	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: DENY' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'Permissions-Policy: geolocation=(), microphone=(), camera=(), interest-cohort=()' );
	header( 'Cross-Origin-Opener-Policy: same-origin' );
	header(
		"Content-Security-Policy: default-src 'self'; "
		. "base-uri 'self'; "
		. "frame-ancestors 'none'; "
		. "form-action 'self'; "
		. "img-src 'self' data:; "
		. "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
		. "font-src 'self' https://fonts.gstatic.com; "
		. "script-src 'self'; "
		. "connect-src 'self'; "
		. "object-src 'none'"
	);
}

/**
 * Get (or lazily create) the CSRF token for this session.
 */
function csrf_token(): string {
	boot_session();

	if ( empty( $_SESSION[ CSRF_TOKEN_KEY ] ) || ! is_string( $_SESSION[ CSRF_TOKEN_KEY ] ) ) {
		$_SESSION[ CSRF_TOKEN_KEY ] = bin2hex( random_bytes( 32 ) );
	}

	return $_SESSION[ CSRF_TOKEN_KEY ];
}

/**
 * Constant-time validation of a submitted CSRF token.
 */
function csrf_valid( ?string $candidate ): bool {
	boot_session();

	$stored = $_SESSION[ CSRF_TOKEN_KEY ] ?? '';

	return is_string( $candidate )
		&& '' !== $stored
		&& hash_equals( $stored, $candidate );
}

/**
 * Best-effort client IP, hashed before storage to limit PII exposure.
 */
function client_ip(): string {
	$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

	// Trust a forwarded header only if present (behind a known proxy/CDN).
	$forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
	if ( '' !== $forwarded ) {
		$parts = explode( ',', $forwarded );
		$first = trim( $parts[0] );
		if ( false !== filter_var( $first, FILTER_VALIDATE_IP ) ) {
			$ip = $first;
		}
	}

	return $ip;
}

/**
 * Pseudonymised IP key for rate-limiting / dedupe.
 */
function ip_hash(): string {
	return substr( hash( 'sha256', 'fasterfy|' . client_ip() ), 0, 32 );
}

/**
 * Ensure the data directory exists and is protected from direct web access.
 */
function ensure_data_dir(): string {
	if ( ! is_dir( DATA_DIR ) ) {
		@mkdir( DATA_DIR, 0750, true );
	}

	$htaccess = DATA_DIR . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		@file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
	}

	$index = DATA_DIR . '/index.html';
	if ( ! file_exists( $index ) ) {
		@file_put_contents( $index, '' );
	}

	return DATA_DIR;
}

/**
 * Simple file-based, per-IP rate limiter.
 *
 * @return bool True when the request is allowed, false when throttled.
 */
function rate_limit_ok(): bool {
	$dir  = ensure_data_dir();
	$file = $dir . '/rate-' . ip_hash() . '.json';
	$now  = time();

	$hits = [];
	if ( is_readable( $file ) ) {
		$decoded = json_decode( (string) file_get_contents( $file ), true );
		if ( is_array( $decoded ) ) {
			$hits = array_filter(
				$decoded,
				static fn( $ts ): bool => is_int( $ts ) && ( $now - $ts ) < RATE_LIMIT_WINDOW
			);
		}
	}

	if ( count( $hits ) >= RATE_LIMIT_MAX ) {
		return false;
	}

	$hits[] = $now;
	@file_put_contents( $file, json_encode( array_values( $hits ) ), LOCK_EX );

	return true;
}
