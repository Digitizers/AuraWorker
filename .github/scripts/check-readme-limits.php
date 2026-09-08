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
 * were preserved (Codex round-1 P2).
 *
 * COMPARING `README.md` AGAINST `readme.txt` IS NOT ENOUGH, and that was the
 * round-2 finding: a version already moved behind the `= <version> and earlier =`
 * stub is no longer listed in readme.txt, so it drops out of that comparison and
 * nothing guards it any more. Deleting the freshly restored `### 2.0.2` would
 * have passed CI while the stub still promised the full history — the very loss
 * this guard exists to prevent, one release later.
 *
 * So the archive is checked against the RELEASES THEMSELVES: every stable git
 * tag must have an entry in `README.md`. Tags are written by the release
 * process, not by hand, so the reference cannot drift the way a checked-in list
 * of versions would, and an entry stays protected for ever — trimmed or not.
 * `readme.txt`'s own listed versions are still required too, which catches a
 * release being prepared before its tag exists.
 *
 * That check also catches the older mistake CLAUDE.md records — 2.14.0 shipped
 * with `readme.txt` updated and `README.md` forgotten, and the GitHub changelog
 * silently stopped at 2.13.0 until a human noticed. Enforcing it surfaced three
 * more: 1.2.0, 1.3.1 and 1.3.2 were tagged and released but had never been
 * written down anywhere. They are archived now, recovered from their own tagged
 * commits rather than invented.
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

/**
 * Every STABLE release, as the repository itself records them. A pre-release
 * (`-beta.N`, `-rc.N`) never reaches wp.org and gets no changelog entry.
 */
// Resolved from THIS SCRIPT's location, never from the file under test: the
// releases being checked are this repository's, whichever archive path is
// passed. Deriving it from `$archive` made `git -C /tmp tag` fail for an
// out-of-tree control file, and the script then reported "no release tags
// visible" instead of checking the archive it was handed — so a positive
// control had to be staged inside the repo to work at all (Codex round-3 P2).
$repo_root  = dirname( __DIR__, 2 );
$tag_output = shell_exec( 'git -C ' . escapeshellarg( $repo_root ) . ' tag 2>/dev/null' );
$tags       = array_filter( array_map( 'trim', explode( "\n", (string) $tag_output ) ) );
$released   = array();
foreach ( $tags as $tag ) {
	if ( preg_match( '/^v?([0-9]+(?:\.[0-9]+)+)$/', $tag, $tm ) ) {
		$released[] = $tm[1];
	}
}
$released = array_values( array_unique( $released ) );

// A guard that cannot see the tags must say so rather than pass. In CI this
// means `actions/checkout` needs `fetch-depth: 0`; locally, `git fetch --tags`.
if ( ! $released ) {
	fwrite(
		STDERR,
		"\ncheck-readme-limits: no release tags visible, so the archive cannot be checked.\n"
		. "Fetch them (`git fetch --tags`, or `fetch-depth: 0` in CI) and re-run.\n"
	);
	exit( 1 );
}

$must_be_archived = array_values( array_unique( array_merge( $shipped[1], $released ) ) );
$missing          = array_values( array_diff( $must_be_archived, $archived[1] ) );
usort( $missing, 'version_compare' );

printf(
	"Archive: %d versions in readme.txt, %d stable release tags, %d entries in README.md.\n",
	count( $shipped[1] ),
	count( $released ),
	count( $archived[1] )
);

if ( $missing ) {
	fwrite(
		STDERR,
		sprintf(
			"\ncheck-readme-limits: %s released or listed but missing from README.md's changelog:\n  %s\n\n"
			. "README.md is the archive the trimmed readme.txt points at, so it must hold an entry\n"
			. "for every stable release tag AND every version readme.txt still lists — otherwise a\n"
			. "release can vanish while the `and earlier` stub claims it was preserved. Add the\n"
			. "entries to README.md.\n",
			count( $missing ) === 1 ? '1 version is' : count( $missing ) . ' versions are',
			implode( ', ', $missing )
		)
	);
	exit( 1 );
}

echo "Archive complete: README.md covers every stable release and every listed version.\n";
