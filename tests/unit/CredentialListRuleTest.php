<?php
/**
 * THE RULE HAS ONE DEFINITION (#434, after six review rounds).
 *
 * `app_password_uuids` is the only field on the site whose EMPTINESS is a
 * security claim: cleanup() reads it to decide the credentials are settled and
 * then deletes the site token, the mutation boundaries read it to recognise a
 * live Application Password, and uninstall.php reads it to decide whether the
 * marker may be swept away. Six rounds of review found the same inference —
 * "a list I could not read is a list that holds nothing" — in five different
 * readers, because each one decided for itself what an unreadable list meant.
 *
 * The rule lives in includes/unbind-credential-list.php now. This file is the
 * guard that keeps it there: a reader that re-derives it is caught here rather
 * than in the next review round, or on a customer's site.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class CredentialListRuleTest extends TestCase {

	private const RULE = 'aura_worker_credential_list';

	/** Production sources, computed — never a list somebody maintains. */
	private static function sources(): array {
		$out = array();
		$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( SA_PLUGIN_DIR ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$out[] = $file->getPathname();
			}
		}
		sort( $out );
		return $out;
	}

	/** Strip comment-only lines, so prose about the rule is not code re-deriving it. */
	private static function is_comment( string $line ): bool {
		$t = ltrim( $line );
		return '' === $t || 0 === strpos( $t, '//' ) || 0 === strpos( $t, '*' ) || 0 === strpos( $t, '/*' );
	}

	/**
	 * THE GUARD. A line that mentions the field AND decides its shape —
	 * `is_array()`, `array_map()`, an `isset()` gate — is a reader making the
	 * judgement the rule exists to make. Only the rule's own file may.
	 */
	public function test_no_reader_decides_the_shape_of_the_credential_list_for_itself(): void {
		$offenders = array();
		foreach ( self::sources() as $path ) {
			if ( 'unbind-credential-list.php' === basename( $path ) ) {
				continue; // the rule itself
			}
			foreach ( explode( "\n", (string) file_get_contents( $path ) ) as $n => $line ) {
				if ( self::is_comment( $line ) || false === strpos( $line, 'app_password_' ) ) {
					continue;
				}
				// Two shapes, and only these two.
				//
				// (a) Deciding the shape of the uuid LIST, with the field
				//     itself as the argument. `is_array( $marker )` beside a
				//     read of a list read() already proved is not a
				//     re-derivation — that check is about the marker.
				//     Deciding the shape of the owners MAP is not flagged
				//     either: an unreadable map means every owner is unknown,
				//     which fails CLOSED, where an unreadable LIST read as
				//     empty fails open. Only one of them is a security claim.
				//
				// (b) Casting an owner. `(int) "42junk"` is 42 in PHP, and a
				//     confident wrong owner sends the revocation to the wrong
				//     user's list, which answers "not there".
				$shape = preg_match( '/\b(is_array|isset|array_map)\s*\([^)]*app_password_uuids/', $line );
				$cast  = preg_match( '/\(int\)[^\n]*app_password_users/', $line );
				if ( ! $shape && ! $cast ) {
					continue;
				}
				if ( false !== strpos( $line, self::RULE ) ) {
					continue; // asking the rule, not restating it
				}
				$offenders[] = basename( $path ) . ':' . ( $n + 1 ) . ' ' . trim( $line );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"these lines decide for themselves what an unreadable credential list means; call " . self::RULE . "() instead"
		);
	}

	/**
	 * The rule is LOADED by both worlds. uninstall.php deliberately loads no
	 * plugin code — that is why it grew its own copy of this reasoning in the
	 * first place — so the one file it may load is pinned here.
	 */
	public function test_both_worlds_load_the_one_rule(): void {
		foreach ( array( 'digitizer-site-worker.php', 'uninstall.php' ) as $entry ) {
			$this->assertStringContainsString(
				'unbind-credential-list.php',
				(string) file_get_contents( SA_PLUGIN_DIR . '/' . $entry ),
				"{$entry} does not load the credential-list rule, so it is free to invent its own"
			);
		}
	}

	/**
	 * THE OWNER HALF (Codex round-7 P1). A line-level scan cannot see a read
	 * through a local alias — uninstall.php copied the owners map into a
	 * variable and then cast it — so this asks the question at file level,
	 * where it holds: nothing may index that map without asking the rule what
	 * the value names. A `(int)` cast reads "42junk" as user 42, and a
	 * confident wrong owner is worse than an unknown one.
	 */
	public function test_no_file_reads_a_credential_owner_without_asking_the_rule(): void {
		$offenders = array();
		foreach ( self::sources() as $path ) {
			if ( 'unbind-credential-list.php' === basename( $path ) ) {
				continue;
			}
			$src = (string) file_get_contents( $path );
			// A file that holds the owners map AND revokes a credential is
			// acting on an attribution, whether it reads it directly or
			// through a local alias. A file that only carries the map forward
			// — copying it into a marker it is about to write — interprets
			// nothing and is not asked to.
			if ( false === strpos( $src, 'app_password_users' ) ) {
				continue;
			}
			if ( false === strpos( $src, 'delete_application_password(' ) && false === strpos( $src, 'password_gone(' ) ) {
				continue;
			}
			if ( false === strpos( $src, 'aura_worker_credential_owner(' ) ) {
				$offenders[] = basename( $path );
			}
		}

		$this->assertSame( array(), $offenders, 'these files read a credential owner without asking the rule what it names' );
	}

	// --- the rule's own behaviour ------------------------------------------

	public function test_a_readable_list_is_returned_normalised_and_deduped(): void {
		$this->assertSame( array( 'u-1', '7' ), aura_worker_credential_list( array( 'u-1', 7, 'u-1' ) ) );
	}

	/** The strong answer: read, and it says this binding holds no credentials. */
	public function test_an_empty_list_is_a_real_answer(): void {
		$this->assertSame( array(), aura_worker_credential_list( array() ) );
	}

	/**
	 * @dataProvider unreadable
	 *
	 * @param mixed $raw What the row holds.
	 */
	public function test_nothing_readable_proves_nothing( $raw ): void {
		$this->assertNull( aura_worker_credential_list( $raw ) );
	}

	/** @return array<string,array{0:mixed}> */
	public static function unreadable(): array {
		return array(
			'null'            => array( null ),
			'a string'        => array( 'u-1' ),
			'an int'          => array( 7 ),
			'false'           => array( false ),
			'an object'       => array( new stdClass() ),
			'a nested entry'  => array( array( 'u-1', array( 'u-2' ) ) ),
			'an object entry' => array( array( 'u-1', new stdClass() ) ),
			'a null entry'    => array( array( 'u-1', null ) ),
			'a bool entry'    => array( array( 'u-1', true ) ),
			'an empty entry'  => array( array( 'u-1', '' ) ),
		);
	}

	// --- the owner rule ----------------------------------------------------

	public function test_a_positive_int_owner_is_the_user(): void {
		$this->assertSame( 42, aura_worker_credential_owner( 42 ) );
		$this->assertSame( 42, aura_worker_credential_owner( '42' ) );
	}

	/**
	 * @dataProvider unnamed_owners
	 *
	 * @param mixed $raw What the row holds.
	 */
	public function test_anything_that_names_nobody_is_null( $raw ): void {
		$this->assertNull( aura_worker_credential_owner( $raw ) );
	}

	/** @return array<string,array{0:mixed}> */
	public static function unnamed_owners(): array {
		return array(
			'null'              => array( null ),
			'zero'              => array( 0 ),
			'zero as a string'  => array( '0' ),
			'negative'          => array( -3 ),
			'an empty string'   => array( '' ),
			'digits with junk'  => array( '42junk' ),
			'junk with digits'  => array( 'junk42' ),
			'a float'           => array( 42.5 ),
			'a float string'    => array( '4.2' ),
			'true'              => array( true ),
			'an array'          => array( array( 42 ) ),
			'an object'         => array( new stdClass() ),
		);
	}
}
