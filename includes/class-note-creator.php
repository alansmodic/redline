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
	 * @return array Array with 'count' and 'note_map' (block_index => comment_id).
	 */
	public function create_notes( int $post_id, array $results, array $blocks ): array {
		$created  = 0;
		$note_map = [];

		foreach ( $results as $result ) {
			if ( empty( $result['issues'] ) ) {
				continue;
			}

			$message = $this->format_note_message( $result );

			$comment_id = \wp_insert_comment( [
				'comment_post_ID'  => $post_id,
				'comment_content'  => $message,
				'comment_type'     => 'note',
				'comment_author'   => 'Redline',
				'user_id'          => \get_current_user_id(),
				'comment_approved' => 1,
			] );

			if ( $comment_id ) {
				\update_comment_meta( $comment_id, '_redline_block_index', $result['block_index'] );
				\update_comment_meta( $comment_id, '_redline_checker_note', true );
				$note_map[ $result['block_index'] ] = $comment_id;
				$created++;
			}
		}

		return [
			'count'    => $created,
			'note_map' => $note_map,
		];
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
	 * Clear all checker-created notes for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int Number of notes cleared.
	 */
	public function clear_notes( int $post_id ): int {
		$notes = \get_comments( [
			'post_id'    => $post_id,
			'type'       => 'note',
			'meta_key'   => '_redline_checker_note',
			'meta_value' => true,
			'number'     => 100,
		] );

		$cleared = 0;
		foreach ( $notes as $note ) {
			if ( \wp_delete_comment( $note->comment_ID, true ) ) {
				$cleared++;
			}
		}

		return $cleared;
	}
}
