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
 * The second check is why the first one is SAFE. Trimming readme.txt only stops
 * losing history if the entries being removed are already archived somewhere,
 * and the first version of this change assumed `README.md` was that archive
 * without verifying it — it was missing 2.0.2, 2.0.1 and 1.0.0, so the trim
 * would have deleted three releases outright while the stub told readers they
 * were preserved (Codex round-1 P2). Rather than copy three entries and move
 * on, the invariant is enforced: `README.md`'s changelog must be a SUPERSET of
 * `readme.txt`'s, so every entry is archived BEFORE it can ever be trimmed.
 *
 * That check also catches the older mistake CLAUDE.md records — 2.14.0 shipped
 * with `readme.txt` updated and `README.md` forgotten, and the GitHub changelog
 * silently stopped at 2.13.0 until a human noticed.
 *
 * Usage: php .github/scripts/check-readme-limits.php [path/to/readme.txt] [path/to/README.md]
 */

const WP_ORG_CHANGELOG_WORD_LIMIT = 5000;
/** Headroom for roughly seven more releases at this plugin's typical entry size. */
const BUDGET = 4000;

$path    = $argv[1] ?? __DIR__ . '/../../digitizer-site-worker/readme.txt';
$archive = $argv[2] ?? __DIR__ . '/../../README.md';

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

/**
 * Every version readme.txt lists must also be in README.md's changelog, so the
 * archive is always ahead of the trim. README.md may hold MORE (it keeps
 * entries readme.txt has already dropped, which is the whole point).
 */
if ( ! is_readable( $archive ) ) {
	fwrite( STDERR, "check-readme-limits: cannot read {$archive}\n" );
	exit( 1 );
}

preg_match_all( '/^=\s*([0-9]+(?:\.[0-9]+)+)\s*=\s*$/m', $body, $shipped );

$archive_md = file_get_contents( $archive );
if ( ! preg_match( '/^##\s*Changelog\s*$/m', $archive_md, $am, PREG_OFFSET_CAPTURE ) ) {
	fwrite( STDERR, "check-readme-limits: no `## Changelog` section in {$archive}\n" );
	exit( 1 );
}
preg_match_all(
	'/^###\s*([0-9]+(?:\.[0-9]+)+)/m',
	substr( $archive_md, $am[0][1] ),
	$archived
);

$missing = array_values( array_diff( $shipped[1], $archived[1] ) );

printf(
	"Archive: %d versions in readme.txt, %d in README.md.\n",
	count( $shipped[1] ),
	count( $archived[1] )
);

if ( $missing ) {
	fwrite(
		STDERR,
		sprintf(
			"\ncheck-readme-limits: %s in readme.txt's Changelog but not in README.md's:\n  %s\n\n"
			. "README.md is the archive the trimmed readme.txt points at, so it must hold every\n"
			. "version readme.txt lists — otherwise trimming an entry deletes it outright while\n"
			. "the `and earlier` stub claims it was preserved. Add the entries to README.md.\n",
			count( $missing ) === 1 ? '1 version is' : count( $missing ) . ' versions are',
			implode( ', ', $missing )
		)
	);
	exit( 1 );
}

echo "Archive complete: README.md covers every version readme.txt lists.\n";
