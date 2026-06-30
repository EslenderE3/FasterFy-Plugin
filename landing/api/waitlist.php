<?php
/**
 * FasterFy — Waitlist submission endpoint.
 *
 * Security layers applied here:
 *   1. POST-only + same-origin form-action (also enforced via CSP).
 *   2. CSRF token (session-bound, constant-time compare).
 *   3. Honeypot field ("company_website") — must stay empty.
 *   4. Time-trap — reject submissions faster than a human can fill.
 *   5. Per-IP rate limiting (file-based sliding window).
 *   6. Strict server-side sanitization + validation of every field.
 *   7. Output is JSON only; no user input is ever reflected as HTML.
 *
 * @package FasterFy\Landing
 */

declare( strict_types=1 );

namespace FasterFy\Landing;

require __DIR__ . '/../includes/bootstrap.php';

send_security_headers();
header( 'Content-Type: application/json; charset=utf-8' );
header( 'Cache-Control: no-store' );

/**
 * Emit a JSON response and stop.
 *
 * @param int                  $status  HTTP status code.
 * @param array<string, mixed> $payload Response body.
 */
function respond( int $status, array $payload ): void {
	http_response_code( $status );
	echo json_encode( $payload, JSON_UNESCAPED_UNICODE );
	exit;
}

/**
 * Localised messages (mirror of the client dictionary, server-authoritative).
 */
function msg( string $key, string $lang ): string {
	$messages = [
		'en' => [
			'method'    => 'Method not allowed.',
			'csrf'      => 'Your session expired. Please reload the page and try again.',
			'spam'      => 'Submission rejected.',
			'email'     => 'Please enter a valid email address.',
			'consent'   => 'Please accept to continue.',
			'throttled' => 'Too many attempts. Please try again later.',
			'server'    => 'Something went wrong. Please try again.',
			'success'   => "You're on the list! We'll email you the moment early access opens.",
		],
		'es' => [
			'method'    => 'Método no permitido.',
			'csrf'      => 'Tu sesión expiró. Recarga la página e inténtalo de nuevo.',
			'spam'      => 'Envío rechazado.',
			'email'     => 'Introduce una dirección de correo válida.',
			'consent'   => 'Acepta para continuar.',
			'throttled' => 'Demasiados intentos. Inténtalo más tarde.',
			'server'    => 'Algo salió mal. Inténtalo de nuevo.',
			'success'   => '¡Estás en la lista! Te avisaremos por correo en cuanto abra el acceso anticipado.',
		],
	];

	$table = $messages[ $lang ] ?? $messages['en'];
	return $table[ $key ] ?? $messages['en'][ $key ] ?? '';
}

/* --------------------------------------------------------------------- */

boot_session();

// Determine the response language up front (whitelist).
$lang = ( ( $_POST['lang'] ?? 'en' ) === 'es' ) ? 'es' : 'en';

// 1) POST only.
if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	respond( 405, [ 'success' => false, 'message' => msg( 'method', $lang ) ] );
}

// 2) CSRF.
$token = isset( $_POST['csrf_token'] ) && is_string( $_POST['csrf_token'] ) ? $_POST['csrf_token'] : null;
if ( ! csrf_valid( $token ) ) {
	respond( 403, [ 'success' => false, 'message' => msg( 'csrf', $lang ) ] );
}

// 3) Honeypot — bots fill hidden fields; humans never see them.
$honeypot = trim( (string) ( $_POST['company_website'] ?? '' ) );
if ( '' !== $honeypot ) {
	// Pretend success so bots don't learn they were caught.
	respond( 200, [ 'success' => true, 'message' => msg( 'success', $lang ) ] );
}

// 4) Time-trap — too fast to be human.
$rendered_at = (int) ( $_SESSION[ FORM_RENDERED_KEY ] ?? 0 );
if ( 0 === $rendered_at || ( time() - $rendered_at ) < MIN_FILL_SECONDS ) {
	respond( 422, [ 'success' => false, 'message' => msg( 'spam', $lang ) ] );
}

// 5) Rate limit.
if ( ! rate_limit_ok() ) {
	respond( 429, [ 'success' => false, 'message' => msg( 'throttled', $lang ) ] );
}

// 6) Validate + sanitize.
$email_raw = (string) ( $_POST['email'] ?? '' );
$email     = trim( $email_raw );
// Normalise + strip control characters.
$email = preg_replace( '/[\x00-\x1F\x7F]/u', '', $email ) ?? '';
$email = mb_substr( $email, 0, 254 );

if ( '' === $email || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
	respond( 422, [ 'success' => false, 'message' => msg( 'email', $lang ) ] );
}
$email = mb_strtolower( $email );

// Consent must be explicitly given.
$consent = (string) ( $_POST['consent'] ?? '' );
if ( '1' !== $consent && 'on' !== $consent ) {
	respond( 422, [ 'success' => false, 'message' => msg( 'consent', $lang ) ] );
}

// 7) Persist (append-only JSON Lines). Never echoes user input back as HTML.
$dir  = ensure_data_dir();
$file = $dir . '/waitlist.jsonl';

$record = [
	'email'      => $email,
	'lang'       => $lang,
	'ip_hash'    => ip_hash(),
	'user_agent' => mb_substr( preg_replace( '/[\x00-\x1F\x7F]/u', '', (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) ?? '', 0, 255 ),
	'created_at' => gmdate( 'c' ),
];

$line   = json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
$ok     = false;
$handle = @fopen( $file, 'ab' );
if ( $handle ) {
	if ( flock( $handle, LOCK_EX ) ) {
		$ok = ( false !== fwrite( $handle, $line ) );
		fflush( $handle );
		flock( $handle, LOCK_UN );
	}
	fclose( $handle );
}

if ( ! $ok ) {
	respond( 500, [ 'success' => false, 'message' => msg( 'server', $lang ) ] );
}

// Rotate the CSRF token + form timestamp after a successful submit.
$_SESSION[ CSRF_TOKEN_KEY ]    = bin2hex( random_bytes( 32 ) );
$_SESSION[ FORM_RENDERED_KEY ] = time();

respond( 200, [ 'success' => true, 'message' => msg( 'success', $lang ) ] );
