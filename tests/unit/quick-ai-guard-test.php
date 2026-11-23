<?php
/**
 * Quick Add AI photo guardrails: image size, per-minute and per-day limits.
 *
 * @package Xophz_Compass_Bazaar
 */

require_once dirname( __DIR__ ) . '/support/harness.php';
$bazaar_root = dirname( dirname( __DIR__ ) );
require_once $bazaar_root . '/admin/class-xophz-compass-bazaar-admin-quick-add.php';

$QA = 'Xophz_Compass_Bazaar_Admin_Quick_Add';

// Decoded size from base64 text, without decoding it.
check( 'ai guard: size of empty', $QA::ai_decoded_size( '' ), 0 );
check( 'ai guard: size of 4 chars', $QA::ai_decoded_size( 'QUJD' ), 3 );
check( 'ai guard: size with one pad', $QA::ai_decoded_size( 'QUI=' ), 2 );
check( 'ai guard: size with two pads', $QA::ai_decoded_size( 'QQ==' ), 1 );

// Verdicts.
check( 'ai guard: normal photo passes', $QA::ai_guard_refusal( 500000, 0, 0 ), null );
check( 'ai guard: exactly at the size cap passes', $QA::ai_guard_refusal( $QA::AI_IMAGE_MAX_BYTES, 0, 0 ), null );
$big = $QA::ai_guard_refusal( $QA::AI_IMAGE_MAX_BYTES + 1, 0, 0 );
check( 'ai guard: one byte over the cap is refused', $big['code'] ?? '', 'image_too_large' );

check( 'ai guard: last request of the minute passes', $QA::ai_guard_refusal( 1000, $QA::AI_PER_MINUTE - 1, 0 ), null );
$fast = $QA::ai_guard_refusal( 1000, $QA::AI_PER_MINUTE, 0 );
check( 'ai guard: minute limit reached is refused', $fast['code'] ?? '', 'ai_slow_down' );

check( 'ai guard: last request of the day passes', $QA::ai_guard_refusal( 1000, 0, $QA::AI_PER_DAY - 1 ), null );
$day = $QA::ai_guard_refusal( 1000, 0, $QA::AI_PER_DAY );
check( 'ai guard: day limit reached is refused', $day['code'] ?? '', 'ai_daily_limit' );

// The day limit outranks the minute limit, so the cashier is told the real reason.
$both = $QA::ai_guard_refusal( 1000, $QA::AI_PER_MINUTE, $QA::AI_PER_DAY );
check( 'ai guard: day limit wins over minute limit', $both['code'] ?? '', 'ai_daily_limit' );

// Limits can be raised or lowered by a filter.
$tight = $QA::ai_guard_refusal( 1000, 2, 0, [ 'per_minute' => 2 ] );
check( 'ai guard: filtered minute limit applies', $tight['code'] ?? '', 'ai_slow_down' );
check( 'ai guard: filtered size cap applies', ( $QA::ai_guard_refusal( 600, 0, 0, [ 'max_bytes' => 500 ] )['code'] ?? '' ), 'image_too_large' );

// A refusal always carries a message the cashier can read.
check( 'ai guard: refusal has a message', is_string( $fast['message'] ?? null ) && '' !== $fast['message'], true );
