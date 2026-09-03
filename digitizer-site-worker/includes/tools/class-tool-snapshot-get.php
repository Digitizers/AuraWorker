<?php
/**
 * MCP tool: read a stored snapshot envelope and its payload.
 *
 * A read-only mirror of what `GET /aura/v2/snapshots` lists and
 * `POST /aura/v2/snapshot/restore` consumes: the record
 * `Aura_Worker_Snapshots::get()` loads from disk, plus the raw payload bytes
 * (base64) when they are small enough to inline. A `posts`/`creation` record
 * (door_kind page|component|design_system|creation|creation_restore) is what
 * Aura mirrors as `DoorSnapshot.contentJson` — this tool is how it gets read
 * back. Never returns `payload_path`: that is a local filesystem path, not
 * something a caller off-site has any use for, and leaking it would name
 * exactly where on disk the plugin keeps its rollback envelopes. And never
 * returns the PAYLOAD of an envelope that is not a door capture — see
 * execute().
 *
 * @package Aura_Worker
 * @since 2.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Snapshot_Get extends Aura_Tool_Base {

	/**
	 * Payloads at or under this size are inlined as base64; anything larger
	 * comes back as `payload: null, truncated: true` instead of ballooning an
	 * MCP response with a multi-megabyte string.
	 */
	const MAX_INLINE_BYTES = 2097152; // 2 MiB.

	/**
	 * Tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'snapshot_get';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description() {
		return 'Read a stored snapshot envelope by id — the record captured before a power write or a door-governed Elementor change. The raw payload (base64, up to 2 MiB) is returned only for door captures; any other envelope comes back as metadata with withheld: true. Never returns the on-disk payload path. Read-only; makes no changes.';
	}

	/**
	 * Parameter schema.
	 *
	 * @return array
	 */
	public function get_parameters() {
		return array(
			'id' => array(
				'type'        => 'string',
				'description' => 'The snapshot id to read (e.g. snap_20260902_101500_abcdef012345).',
				'required'    => true,
			),
		);
	}

	/**
	 * Return shape.
	 *
	 * @return array
	 */
	public function get_returns() {
		return array(
			'found'     => 'bool — whether a snapshot with this id exists on this site',
			'record'    => 'object|null — the stored envelope (kind, target/targets, door metadata, …), never including payload_path; null when not found',
			'payload'   => 'string|null — the raw payload, base64-encoded, when it is at most 2 MiB; null when the snapshot has no payload, could not be read, or was withheld',
			'truncated' => 'bool — present and true only when the payload exceeds 2 MiB and was withheld',
			'withheld'  => 'bool — present and true only when this envelope is not a door capture (its door_kind is not one of page|component|design_system|creation|creation_restore), in which case the record is described but its payload is never returned',
		);
	}

	/**
	 * Read-only: never mutates the site.
	 *
	 * @return array
	 */
	public function get_annotations() {
		return array(
			'read_only'         => true,
			'destructive'       => false,
			'requires_approval' => false,
			'supports_preview'  => false,
		);
	}

	/**
	 * @param array $params Params.
	 * @return array
	 */
	public function execute( $params ) {
		$id = (string) ( isset( $params['id'] ) ? $params['id'] : '' );

		$record = ( new Aura_Worker_Snapshots() )->get( $id );
		if ( ! is_array( $record ) ) {
			return array(
				'found'   => false,
				'record'  => null,
				'payload' => null,
			);
		}

		$payload_path = isset( $record['payload_path'] ) ? (string) $record['payload_path'] : '';
		unset( $record['payload_path'] );

		// The payload of a NON-DOOR envelope is never returned. This tool
		// exists to read back what the Elementor door captured before a
		// governed write — what Aura mirrors as `DoorSnapshot.contentJson` —
		// and it is read-only, so it is reachable by a session with no write
		// scope at all. The snapshot ROUTE does not jail option names, so such
		// a session can `snapshot_option( '<anything>' )` and would then read
		// the stored value straight back out of here: a read-scoped session
		// with a general options-table read primitive. Gated on `door_kind`
		// (Aura_Worker_Snapshots::DOOR_KINDS), the same field retention is
		// keyed on, never on `kind` — a door capture of a page IS a `posts`
		// record. The metadata still comes back: what was captured and when is
		// not the secret; the bytes are.
		$door_kind = isset( $record['door_kind'] ) ? (string) $record['door_kind'] : '';
		if ( ! in_array( $door_kind, Aura_Worker_Snapshots::DOOR_KINDS, true ) ) {
			return array(
				'found'    => true,
				'record'   => $record,
				'payload'  => null,
				'withheld' => true,
			);
		}

		if ( '' === $payload_path || ! file_exists( $payload_path ) ) {
			return array(
				'found'   => true,
				'record'  => $record,
				'payload' => null,
			);
		}

		if ( (int) filesize( $payload_path ) > self::MAX_INLINE_BYTES ) {
			return array(
				'found'     => true,
				'record'    => $record,
				'payload'   => null,
				'truncated' => true,
			);
		}

		$raw = file_get_contents( $payload_path );
		return array(
			'found'   => true,
			'record'  => $record,
			'payload' => false === $raw ? null : base64_encode( $raw ),
		);
	}
}
