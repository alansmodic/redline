<?php

namespace Redline;

class Note_Creator {

	/**
	 * Prefix used to identify checker-created notes.
	 */
	private const NOTE_PREFIX = 'Redline';

	/**
	 * Create Notes on flagged blocks.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $results Merged check results.
	 * @param array $blocks  All parsed blocks from the post.
	 * @return int Number of notes created.
	 */
	public function create_notes( int $post_id, array $results, array $blocks ): int {
		$created = 0;

		foreach ( $results as $result ) {
			if ( empty( $result['issues'] ) ) {
				continue;
			}

			$message = $this->format_note_message( $result );

			$comment_id = wp_insert_comment( [
				'comment_post_ID' => $post_id,
				'comment_content' => $message,
				'comment_type'    => 'note',
				'comment_author'  => 'Redline',
				'user_id'         => get_current_user_id(),
				'comment_approved' => 1,
			] );

			if ( $comment_id ) {
				// Store the block index as comment meta for reference.
				update_comment_meta( $comment_id, '_redline_block_index', $result['block_index'] );
				update_comment_meta( $comment_id, '_redline_checker_note', true );
				$created++;
			}
		}

		// Update block markup with note metadata.
		if ( $created > 0 ) {
			$this->update_block_metadata( $post_id );
		}

		return $created;
	}

	/**
	 * Format a note message from check results.
	 */
	private function format_note_message( array $result ): string {
		$lines   = [];
		$lines[] = '**' . self::NOTE_PREFIX . '**';
		$lines[] = '';
		$lines[] = sprintf( 'Block: `%s`', $result['block_name'] );
		$lines[] = '';

		foreach ( $result['issues'] as $issue ) {
			$severity = strtoupper( $issue['severity'] ?? 'warning' );
			$section  = $issue['guideline_section'] ?? '';
			$source   = $issue['source'] === 'lint' ? 'Lint' : 'AI';
			$lines[]  = sprintf( '- [%s] [%s] %s (%s)', $severity, $source, $issue['message'], $section );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Update post block markup to attach note IDs as metadata.
	 */
	private function update_block_metadata( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		// Get all checker notes for this post.
		$notes = get_comments( [
			'post_id'    => $post_id,
			'type'       => 'note',
			'meta_key'   => '_redline_checker_note',
			'meta_value' => true,
		] );

		if ( empty( $notes ) ) {
			return;
		}

		// Build a map of block_index => note IDs.
		$note_map = [];
		foreach ( $notes as $note ) {
			$block_index = get_comment_meta( $note->comment_ID, '_redline_block_index', true );
			if ( $block_index !== '' ) {
				$note_map[ (int) $block_index ][] = $note->comment_ID;
			}
		}

		// Parse blocks, add noteId metadata, serialize back.
		$blocks   = parse_blocks( $post->post_content );
		$flat     = $this->flatten_with_paths( $blocks );
		$modified = false;

		foreach ( $note_map as $block_index => $note_ids ) {
			if ( isset( $flat[ $block_index ] ) ) {
				$path = $flat[ $block_index ];
				$ref  = &$blocks;

				foreach ( $path as $segment ) {
					if ( is_array( $segment ) ) {
						$ref = &$ref[ $segment[0] ]['innerBlocks'][ $segment[1] ];
					} else {
						$ref = &$ref[ $segment ];
					}
				}

				if ( ! isset( $ref['attrs'] ) ) {
					$ref['attrs'] = [];
				}
				if ( ! isset( $ref['attrs']['metadata'] ) ) {
					$ref['attrs']['metadata'] = [];
				}
				$ref['attrs']['metadata']['noteIds'] = $note_ids;
				$modified = true;
			}
		}

		if ( $modified ) {
			$new_content = serialize_blocks( $blocks );
			wp_update_post( [
				'ID'           => $post_id,
				'post_content' => $new_content,
			] );
		}
	}

	/**
	 * Flatten blocks with their paths for indexing back into the tree.
	 */
	private function flatten_with_paths( array $blocks, array $parent_path = [] ): array {
		$flat = [];
		foreach ( $blocks as $i => $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$current_path          = array_merge( $parent_path, [ $i ] );
			$flat[]                = $current_path;

			if ( ! empty( $block['innerBlocks'] ) ) {
				foreach ( $block['innerBlocks'] as $j => $inner ) {
					if ( empty( $inner['blockName'] ) ) {
						continue;
					}
					$inner_path = array_merge( $parent_path, [ [ $i, $j ] ] );
					$flat[]     = $inner_path;

					if ( ! empty( $inner['innerBlocks'] ) ) {
						$deeper = $this->flatten_with_paths( $inner['innerBlocks'], $inner_path );
						$flat   = array_merge( $flat, $deeper );
					}
				}
			}
		}
		return $flat;
	}

	/**
	 * Clear all checker-created notes for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int Number of notes cleared.
	 */
	public function clear_notes( int $post_id ): int {
		$notes = get_comments( [
			'post_id'    => $post_id,
			'type'       => 'note',
			'meta_key'   => '_redline_checker_note',
			'meta_value' => true,
			'number'     => 100,
		] );

		$cleared = 0;
		foreach ( $notes as $note ) {
			if ( wp_delete_comment( $note->comment_ID, true ) ) {
				$cleared++;
			}
		}

		return $cleared;
	}
}
