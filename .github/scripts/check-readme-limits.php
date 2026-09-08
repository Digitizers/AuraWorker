#!/usr/bin/env php
<?php
/**
 * WordPress.org truncates a readme.txt `== Changelog ==` section longer than
 * 5,000 words, and says so only in a warning on the plugin page that is
 * "visible only to the plugin authors & committers" — nothing in this
 * repository, in CI, or in the release output mentions it. We shipped past the
 * limit at 2.16.2 (5,195 words across 36 entries) and found out from that
 * banner, by which point wp.org had already published a Changelog with the
 * older half of the history silently missing.
 *
 * So the limit is checked here instead of remembered. The budget is
 * deliberately below wp.org's own ceiling: a release that lands exactly at
 * 5,000 words is one release away from truncation, and the person who writes
 * the next entry is not the person who reads this file.
 *
 * The fix when this fails is never to compress the newest entries — it is to
 * move the OLDEST ones out. `README.md#changelog` holds the full history, and
 * readme.txt keeps a `= <version> and earlier =` stub pointing at it.
 *
 * Usage: php .github/scripts/check-readme-limits.php [path/to/readme.txt]
 */

const WP_ORG_CHANGELOG_WORD_LIMIT = 5000;
/** Headroom for roughly seven more releases at this plugin's typical entry size. */
const BUDGET = 4000;

$path = $argv[1] ?? __DIR__ . '/../../digitizer-site-worker/readme.txt';

if ( ! is_readable( $path ) ) {
	fwrite( STDERR, "check-readme-limits: cannot read {$path}\n" );
	exit( 1 );
}

$readme = file_get_contents( $path );

// The section runs from its own heading to the next `== … ==` heading, or to
// end of file when Changelog is last.
if ( ! preg_match( '/^==\s*Changelog\s*==\s*$/m', $readme, $m, PREG_OFFSET_CAPTURE ) ) {
	fwrite( STDERR, "check-readme-limits: no `== Changelog ==` section in {$path}\n" );
	exit( 1 );
}

$body = substr( $readme, $m[0][1] + strlen( $m[0][0] ) );
if ( preg_match( '/^==\s+[^=]+\s+==\s*$/m', $body, $next, PREG_OFFSET_CAPTURE ) ) {
	$body = substr( $body, 0, $next[0][1] );
}

$words    = count( preg_split( '/\s+/', trim( $body ), -1, PREG_SPLIT_NO_EMPTY ) );
$entries  = preg_match_all( '/^=\s*[^=]+?\s*=\s*$/m', $body );
$headroom = BUDGET - $words;

printf(
	"Changelog: %d words in %d entries (budget %d, wp.org truncates at %d).\n",
	$words,
	$entries,
	BUDGET,
	WP_ORG_CHANGELOG_WORD_LIMIT
);

if ( $words > BUDGET ) {
	fwrite(
		STDERR,
		sprintf(
			"\ncheck-readme-limits: the Changelog is %d words over budget.\n"
			. "WordPress.org truncates above %d words and reports it only in an author-only\n"
			. "warning on the plugin page, so this must be fixed before release.\n\n"
			. "Move the OLDEST entries out, not the newest: replace them with a\n"
			. "`= <version> and earlier =` stub pointing at README.md#changelog, which keeps\n"
			. "the full history.\n",
			-$headroom,
			WP_ORG_CHANGELOG_WORD_LIMIT
		)
	);
	exit( 1 );
}

printf( "Headroom: %d words.\n", $headroom );
