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
 * So the limit is checked here instead of remembered, and the trim that keeps
 * us under it is checked for the one property that makes it acceptable:
 * NOTHING IS LOST. Entries leave readme.txt only by moving, verbatim, into
 * docs/changelog-archive.md, and readme.txt ends with a stub that points there.
 *
 * Why the archive is its own file rather than README.md: the first six review
 * rounds of this guard assumed README.md's `## Changelog` was the full history
 * and kept discovering it was not — missing versions first, then, once every
 * version was present, missing NOTES: twelve of the twenty-four trimmed entries
 * had more than a third of their wording absent from README.md, and 1.3.0 had
 * none of it. The two changelogs were written independently and say different
 * things. Reconciling them by hand is a judgement call; moving text is not.
 *
 * Three checks, all decidable from the tree:
 *   1. The Changelog's word count is under a budget set below wp.org's ceiling.
 *   2. The stub exists, is the LAST heading, and links to the archive file.
 *   3. Every stable release — every `v*` tag the repository carries — has an
 *      entry in readme.txt's Changelog OR in the archive. Tags are written by
 *      the release process, so the reference cannot drift the way a
 *      hand-kept list would, and an entry stays covered once trimmed.
 *
 * Deliberately NOT checked: what is written under a heading. That was tried;
 * a hand-written file has no edge-free definition of "content" (a footer, a
 * nested heading, a link-only line), and two rounds of patching it proved the
 * point. A heading emptied on purpose is for review to catch.
 *
 * Usage: php .github/scripts/check-readme-limits.php [readme.txt] [changelog-archive.md]
 */

const WP_ORG_CHANGELOG_WORD_LIMIT = 5000;
/** Headroom for roughly seven more releases at this plugin's typical entry size. */
const BUDGET = 4000;
const STUB_PATTERN = '/^=\s*([0-9]+(?:\.[0-9]+)+)\s+and\s+earlier\s*=\s*$/m';
const ARCHIVE_LINK = 'https://github.com/Digitizers/SiteAgent/blob/main/docs/changelog-archive.md';

$repo_root = dirname( __DIR__, 2 );
$path      = $argv[1] ?? $repo_root . '/digitizer-site-worker/readme.txt';
$archive   = $argv[2] ?? $repo_root . '/docs/changelog-archive.md';

foreach ( array( $path, $archive ) as $file ) {
	if ( ! is_readable( $file ) ) {
		fwrite( STDERR, "check-readme-limits: cannot read {$file}\n" );
		exit( 1 );
	}
}

$readme = file_get_contents( $path );

if ( ! preg_match( '/^==\s*Changelog\s*==\s*$/m', $readme, $m, PREG_OFFSET_CAPTURE ) ) {
	fwrite( STDERR, "check-readme-limits: no `== Changelog ==` section in {$path}\n" );
	exit( 1 );
}
$body = substr( $readme, $m[0][1] + strlen( $m[0][0] ) );
if ( preg_match( '/^==\s+[^=]+\s+==\s*$/m', $body, $next, PREG_OFFSET_CAPTURE ) ) {
	$body = substr( $body, 0, $next[0][1] );
}

// ── 1. The word budget ──────────────────────────────────────────────────────
$words    = count( preg_split( '/\s+/', trim( $body ), -1, PREG_SPLIT_NO_EMPTY ) );
$headroom = BUDGET - $words;
printf( "Changelog: %d words (budget %d, wp.org truncates at %d).\n", $words, BUDGET, WP_ORG_CHANGELOG_WORD_LIMIT );
if ( $words > BUDGET ) {
	fwrite(
		STDERR,
		sprintf(
			"\ncheck-readme-limits: the Changelog is %d words over budget.\n"
			. "WordPress.org truncates above %d words and reports it only in an author-only\n"
			. "warning on the plugin page, so this must be fixed before release.\n\n"
			. "Move the OLDEST entries out, not the newest: cut them from readme.txt VERBATIM\n"
			. "into docs/changelog-archive.md (newest first, directly under its header) and\n"
			. "advance the `= <version> and earlier =` stub to the newest version moved.\n",
			-$headroom,
			WP_ORG_CHANGELOG_WORD_LIMIT
		)
	);
	exit( 1 );
}
printf( "Headroom: %d words.\n", $headroom );

// ── 2. The stub: present, last, and pointing at the archive ────────────────
// Every `= … =` heading in order, numeric or not, so the stub's position is known.
preg_match_all( '/^=\s*([^=\n]+?)\s*=\s*$/m', $body, $headings );
$last_heading = end( $headings[1] );
if ( ! preg_match( STUB_PATTERN, "= {$last_heading} =", $stub ) ) {
	fwrite(
		STDERR,
		"\ncheck-readme-limits: the Changelog's last heading is `= {$last_heading} =`, not the\n"
		. "`= <version> and earlier =` stub. Without it wp.org readers reach the end of the list\n"
		. "with no path to the archived history. Add the stub as the final entry.\n"
	);
	exit( 1 );
}
$stub_version = $stub[1];
$stub_body    = substr( $body, strrpos( $body, "= {$last_heading} =" ) );
if ( false === strpos( $stub_body, ARCHIVE_LINK ) ) {
	fwrite( STDERR, "\ncheck-readme-limits: the stub does not link to " . ARCHIVE_LINK . "\n" );
	exit( 1 );
}
echo "Stub: `= {$stub_version} and earlier =` is the last entry and links to the archive.\n";

// ── 3. Every stable release has an entry somewhere ─────────────────────────
$listed = array();
foreach ( $headings[1] as $h ) {
	if ( preg_match( '/^[0-9]+(?:\.[0-9]+)+$/', $h ) ) {
		$listed[] = $h;
	}
}
preg_match_all( '/^=\s*([0-9]+(?:\.[0-9]+)+)\s*=\s*$/m', file_get_contents( $archive ), $arch );
$archived = $arch[1];

$tag_output = shell_exec( 'git -C ' . escapeshellarg( $repo_root ) . ' tag 2>/dev/null' );
$released   = array();
foreach ( array_filter( array_map( 'trim', explode( "\n", (string) $tag_output ) ) ) as $tag ) {
	if ( preg_match( '/^v?([0-9]+(?:\.[0-9]+)+)$/', $tag, $tm ) ) {
		$released[] = $tm[1]; // stable only — a `-beta.N` / `-rc.N` never reaches wp.org
	}
}
$released = array_values( array_unique( $released ) );
// A guard that cannot see the tags must say so rather than pass. In CI this
// means `actions/checkout` needs `fetch-depth: 0`; locally, `git fetch --tags`.
if ( ! $released ) {
	fwrite(
		STDERR,
		"\ncheck-readme-limits: no release tags visible, so coverage cannot be checked.\n"
		. "Fetch them (`git fetch --tags`, or `fetch-depth: 0` in CI) and re-run.\n"
	);
	exit( 1 );
}

$covered = array_unique( array_merge( $listed, $archived ) );
$missing = array_values( array_diff( $released, $covered ) );
usort( $missing, 'version_compare' );
printf( "Coverage: %d stable release tags; %d listed in readme.txt, %d archived.\n", count( $released ), count( $listed ), count( $archived ) );
if ( $missing ) {
	fwrite(
		STDERR,
		sprintf(
			"\ncheck-readme-limits: %s released but in neither readme.txt nor the archive:\n  %s\n\n"
			. "Every stable tag needs an entry in one of the two. A version trimmed from readme.txt\n"
			. "must have been MOVED into docs/changelog-archive.md, never deleted.\n",
			count( $missing ) === 1 ? '1 version was' : count( $missing ) . ' versions were',
			implode( ', ', $missing )
		)
	);
	exit( 1 );
}

// A version both listed and archived is a copy, not a move — the archive is
// for what LEFT readme.txt, and a duplicate will drift the next time either
// side is edited.
$both = array_values( array_intersect( $listed, $archived ) );
if ( $both ) {
	fwrite( STDERR, "\ncheck-readme-limits: in BOTH readme.txt and the archive (copied, not moved): " . implode( ', ', $both ) . "\n" );
	exit( 1 );
}

echo "Every stable release is covered, and nothing is in both files.\n";
