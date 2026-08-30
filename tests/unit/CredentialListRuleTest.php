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
				if ( self::is_comment( $line ) || false === strpos( $line, 'app_password_uuids' ) ) {
					continue;
				}
				// The ARGUMENT must be the field itself. `is_array( $marker )`
				// beside a read of a marker read() already proved is not a
				// re-derivation — that check is about the marker, and the list
				// inside it is proven by then.
				if ( ! preg_match( '/\b(is_array|isset|array_map)\s*\([^)]*app_password_uuids/', $line ) ) {
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
}
