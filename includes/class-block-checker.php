<?php

namespace Redline;

use WP_Error;

class Block_Checker {

	/**
	 * Block types to check for content issues.
	 */
	private const CONTENT_BLOCK_TYPES = [
		'core/paragraph',
		'core/heading',
		'core/list',
		'core/list-item',
		'core/quote',
		'core/button',
		'core/image',
	];

	/**
	 * Run content guidelines check on a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error Results array or error.
	 */
	public function check( int $post_id ): array|WP_Error {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'redline_no_post', 'Post not found.', [ 'status' => 404 ] );
		}

		// Parse blocks from post content.
		$blocks = parse_blocks( $post->post_content );
		$blocks = $this->flatten_blocks( $blocks );

		// Filter to content blocks only.
		$content_blocks = [];
		foreach ( $blocks as $index => $block ) {
			if ( in_array( $block['blockName'], self::CONTENT_BLOCK_TYPES, true ) ) {
				$content_blocks[] = [
					'index'      => $index,
					'block_name' => $block['blockName'],
					'content'    => $this->get_block_text( $block ),
					'raw_block'  => $block,
				];
			}
		}

		if ( empty( $content_blocks ) ) {
			return [
				'success'       => true,
				'results'       => [],
				'notes_created' => 0,
				'message'       => 'No content blocks found to check.',
			];
		}

		// Get content guidelines for this post.
		$guidelines = wp_get_content_guidelines_for_post( $post_id );

		if ( empty( $guidelines ) ) {
			return new WP_Error( 'redline_no_guidelines', 'No content guidelines configured for this post.', [ 'status' => 422 ] );
		}

		// Run free lint checks first.
		$lint_results = $this->run_lint_checks( $content_blocks, $post_id );

		// Build and send AI prompt.
		$ai_results = $this->run_ai_check( $content_blocks, $guidelines, $lint_results );

		if ( is_wp_error( $ai_results ) ) {
			return $ai_results;
		}

		// Merge lint and AI results.
		$merged = $this->merge_results( $content_blocks, $lint_results, $ai_results );

		// Create notes on flagged blocks.
		$note_creator  = new Note_Creator();
		$notes_created = $note_creator->create_notes( $post_id, $merged, $blocks );

		return [
			'success'       => true,
			'results'       => $merged,
			'notes_created' => $notes_created,
		];
	}

	/**
	 * Flatten nested blocks into a single-level array.
	 */
	private function flatten_blocks( array $blocks ): array {
		$flat = [];
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$flat[] = $block;
			if ( ! empty( $block['innerBlocks'] ) ) {
				$flat = array_merge( $flat, $this->flatten_blocks( $block['innerBlocks'] ) );
			}
		}
		return $flat;
	}

	/**
	 * Extract readable text from a block.
	 */
	private function get_block_text( array $block ): string {
		if ( $block['blockName'] === 'core/image' ) {
			return $block['attrs']['alt'] ?? '(no alt text)';
		}
		return wp_strip_all_tags( $block['innerHTML'] );
	}

	/**
	 * Run Lint_Checker on each content block.
	 */
	private function run_lint_checks( array $content_blocks, int $post_id ): array {
		$results = [];

		if ( ! class_exists( 'ContentGuidelines\\Lint_Checker' ) ) {
			return $results;
		}

		foreach ( $content_blocks as $cb ) {
			$lint = \ContentGuidelines\Lint_Checker::check( $cb['content'], $cb['block_name'], $post_id );

			if ( ! empty( $lint ) ) {
				$results[ $cb['index'] ] = array_map( function ( $issue ) {
					return [
						'message'           => $issue['message'] ?? $issue,
						'severity'          => 'warning',
						'guideline_section' => 'Vocabulary / Readability',
						'source'            => 'lint',
					];
				}, (array) $lint );
			}
		}

		return $results;
	}

	/**
	 * Run AI check on all content blocks.
	 */
	private function run_ai_check( array $content_blocks, array $guidelines, array $lint_results ): array|WP_Error {
		$guidelines_text = $this->format_guidelines( $guidelines );

		// Build numbered block list.
		$block_list = '';
		foreach ( $content_blocks as $i => $cb ) {
			$block_list .= sprintf(
				"[Block %d] (%s): %s\n",
				$i,
				$cb['block_name'],
				$cb['content']
			);
		}

		// Note existing lint issues so AI doesn't duplicate.
		$lint_context = '';
		if ( ! empty( $lint_results ) ) {
			$lint_context = "\n\nNote: The following lint issues have already been detected (do not duplicate these):\n";
			foreach ( $lint_results as $block_index => $issues ) {
				foreach ( $issues as $issue ) {
					$lint_context .= sprintf( "- Block index %d: %s\n", $block_index, $issue['message'] );
				}
			}
		}

		$system = 'You are an editorial reviewer for a WordPress site. Check each block of content against the provided content guidelines. Focus on tone, voice, brand alignment, and subjective quality issues. Return only valid JSON.';

		$user_message = sprintf(
			"## Content Guidelines\n\n%s\n\n## Post Blocks\n\n%s%s\n\n## Instructions\n\nReview each block against the guidelines. For blocks with issues, return them in the JSON response. Only flag genuine issues. If a block is fine, omit it.\n\nReturn a JSON array where each element has:\n- \"block_index\" (integer): the block number from above\n- \"issues\" (array): each with \"message\" (string), \"severity\" (\"error\"|\"warning\"|\"info\"), and \"guideline_section\" (string referencing which guideline was violated)",
			$guidelines_text,
			$block_list,
			$lint_context
		);

		$schema = [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'properties' => [
					'block_index' => [ 'type' => 'integer' ],
					'issues'      => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'message'           => [ 'type' => 'string' ],
								'severity'          => [ 'type' => 'string', 'enum' => [ 'error', 'warning', 'info' ] ],
								'guideline_section' => [ 'type' => 'string' ],
							],
							'required'   => [ 'message', 'severity', 'guideline_section' ],
						],
					],
				],
				'required'   => [ 'block_index', 'issues' ],
			],
		];

		try {
			$response = wp_ai_client_prompt( $user_message )
				->usingSystemInstruction( $system )
				->asJsonResponse( $schema )
				->generateText();

			$decoded = json_decode( $response, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return new WP_Error( 'redline_ai_parse_error', 'Failed to parse AI response as JSON.', [ 'status' => 500 ] );
			}

			// Key results by block_index for the content_blocks mapping.
			$keyed = [];
			foreach ( $decoded as $item ) {
				$block_index = $item['block_index'];
				// Map from the AI's sequential index back to the original block index.
				if ( isset( $content_blocks[ $block_index ] ) ) {
					$original_index = $content_blocks[ $block_index ]['index'];
					$keyed[ $original_index ] = array_map( function ( $issue ) {
						$issue['source'] = 'ai';
						return $issue;
					}, $item['issues'] );
				}
			}

			return $keyed;
		} catch ( \Exception $e ) {
			return new WP_Error( 'redline_ai_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	/**
	 * Format guidelines into readable text for the AI prompt.
	 */
	private function format_guidelines( array $guidelines ): string {
		if ( isset( $guidelines['packet_text'] ) ) {
			return $guidelines['packet_text'];
		}

		// Fallback: serialize guidelines into a readable format.
		$text = '';
		foreach ( $guidelines as $key => $value ) {
			if ( is_string( $value ) ) {
				$text .= "### {$key}\n{$value}\n\n";
			} elseif ( is_array( $value ) ) {
				$text .= "### {$key}\n" . wp_json_encode( $value, JSON_PRETTY_PRINT ) . "\n\n";
			}
		}
		return $text;
	}

	/**
	 * Merge lint and AI results into a unified results array.
	 */
	private function merge_results( array $content_blocks, array $lint_results, array $ai_results ): array {
		$merged = [];

		// Collect all block indices that have issues.
		$all_indices = array_unique( array_merge( array_keys( $lint_results ), array_keys( $ai_results ) ) );

		foreach ( $all_indices as $block_index ) {
			$issues = [];

			if ( isset( $lint_results[ $block_index ] ) ) {
				$issues = array_merge( $issues, $lint_results[ $block_index ] );
			}

			if ( isset( $ai_results[ $block_index ] ) ) {
				$issues = array_merge( $issues, $ai_results[ $block_index ] );
			}

			// Find the content block info.
			$block_info = null;
			foreach ( $content_blocks as $cb ) {
				if ( $cb['index'] === $block_index ) {
					$block_info = $cb;
					break;
				}
			}

			$merged[] = [
				'block_index' => $block_index,
				'block_name'  => $block_info['block_name'] ?? 'unknown',
				'excerpt'     => mb_substr( $block_info['content'] ?? '', 0, 120 ),
				'issues'      => $issues,
			];
		}

		return $merged;
	}
}
