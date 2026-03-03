import { useState } from '@wordpress/element';
import { Button, Spinner, Notice } from '@wordpress/components';
import { useSelect, useDispatch, select as wpSelect, dispatch as wpDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import ResultsList from './results-list';

const config = window.redlineConfig || {};

/**
 * Flatten editor blocks into a single-level array (matching server-side flatten_blocks).
 * Skips blocks without a name (freeform/empty blocks).
 */
function flattenBlocks( blocks ) {
	const flat = [];
	for ( const block of blocks ) {
		if ( ! block.name ) {
			continue;
		}
		flat.push( block );
		if ( block.innerBlocks && block.innerBlocks.length ) {
			flat.push( ...flattenBlocks( block.innerBlocks ) );
		}
	}
	return flat;
}

/**
 * Format check results into a note message (matching PHP format_note_message).
 */
function formatNoteMessage( result ) {
	const lines = [ '**Redline**', '' ];
	lines.push( `Block: \`${ result.block_name }\`` );
	lines.push( '' );

	for ( const issue of result.issues ) {
		const severity = ( issue.severity || 'warning' ).toUpperCase();
		const section = issue.guideline_section || '';
		const source = issue.source === 'lint' ? 'Lint' : 'AI';
		lines.push( `- [${ severity }] [${ source }] ${ issue.message } (${ section })` );
	}

	return lines.join( '\n' );
}

/**
 * Create notes on flagged blocks using the WP REST comments API
 * and associate them via metadata.noteId — the same way the native
 * Notes feature works.
 */
async function createNotesOnBlocks( postId, results ) {
	const allBlocks = wpSelect( 'core/block-editor' ).getBlocks();
	const flatBlocks = flattenBlocks( allBlocks );
	let notesCreated = 0;

	for ( const result of results ) {
		if ( ! result.issues || result.issues.length === 0 ) {
			continue;
		}

		const block = flatBlocks[ result.block_index ];
		if ( ! block ) {
			continue;
		}

		const message = formatNoteMessage( result );

		try {
			// Create note via REST API — same path as native editor Notes.
			const note = await apiFetch( {
				path: '/wp/v2/comments',
				method: 'POST',
				data: {
					post: postId,
					content: message,
					status: 'hold',
					type: 'note',
					parent: 0,
				},
			} );

			if ( note?.id ) {
				// Associate note with block via metadata.noteId.
				const metadata = block.attributes?.metadata || {};
				wpDispatch( 'core/block-editor' ).updateBlockAttributes(
					block.clientId,
					{
						metadata: {
							...metadata,
							noteId: note.id,
						},
					}
				);
				notesCreated++;
			}
		} catch ( err ) {
			// eslint-disable-next-line no-console
			console.error( 'Redline: Failed to create note for block', result.block_index, err );
		}
	}

	return notesCreated;
}

/**
 * Remove metadata.noteId from all blocks and delete the associated notes.
 */
async function clearNotesFromBlocks( postId ) {
	const allBlocks = wpSelect( 'core/block-editor' ).getBlocks();
	const flatBlocks = flattenBlocks( allBlocks );
	let cleared = 0;

	for ( const block of flatBlocks ) {
		const noteId = block.attributes?.metadata?.noteId;
		if ( ! noteId ) {
			continue;
		}

		// Remove noteId from block metadata.
		const { noteId: _, ...restMetadata } = block.attributes.metadata;
		wpDispatch( 'core/block-editor' ).updateBlockAttributes( block.clientId, {
			metadata: Object.keys( restMetadata ).length ? restMetadata : undefined,
		} );

		// Delete the note comment.
		try {
			await apiFetch( {
				path: `/wp/v2/comments/${ noteId }?force=true`,
				method: 'DELETE',
			} );
			cleared++;
		} catch ( err ) {
			// Note may already be deleted.
		}
	}

	// Also clear any server-side created notes that might be orphaned.
	try {
		await apiFetch( {
			path: '/redline/v1/clear',
			method: 'POST',
			data: { post_id: postId },
		} );
	} catch ( err ) {
		// Ignore — best effort.
	}

	return cleared;
}

export default function SidebarPanel() {
	const [ isChecking, setIsChecking ] = useState( false );
	const [ isClearing, setIsClearing ] = useState( false );
	const [ results, setResults ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ notesCreated, setNotesCreated ] = useState( 0 );

	const postId = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostId(),
		[]
	);

	const { savePost } = useDispatch( 'core/editor' );

	// Show dependency warnings if plugins are missing.
	const missingDeps = [];
	if ( ! config.hasContentGuidelines ) {
		missingDeps.push( 'Content Guidelines' );
	}
	if ( ! config.hasAiClient ) {
		missingDeps.push( 'WP AI Client' );
	}

	const handleCheck = async () => {
		setIsChecking( true );
		setError( null );
		setResults( null );
		setNotesCreated( 0 );

		try {
			// Save the post first so server has current content.
			await savePost();

			const response = await apiFetch( {
				path: '/redline/v1/check',
				method: 'POST',
				data: { post_id: postId },
			} );

			setResults( response );

			// Create notes on blocks from JS (matching native Notes flow).
			if ( response.results && response.results.length > 0 ) {
				const count = await createNotesOnBlocks( postId, response.results );
				setNotesCreated( count );

				// Save the post to persist the metadata.noteId on blocks.
				if ( count > 0 ) {
					await savePost();
				}
			}
		} catch ( err ) {
			setError( err.message || __( 'An error occurred during the check.', 'redline' ) );
		} finally {
			setIsChecking( false );
		}
	};

	const handleClear = async () => {
		setIsClearing( true );
		setError( null );

		try {
			await clearNotesFromBlocks( postId );
			await savePost();

			setResults( null );
			setNotesCreated( 0 );
		} catch ( err ) {
			setError( err.message || __( 'Failed to clear notes.', 'redline' ) );
		} finally {
			setIsClearing( false );
		}
	};

	const totalIssues = results?.results?.reduce(
		( sum, r ) => sum + ( r.issues?.length || 0 ),
		0
	) || 0;

	return (
		<div className="rdl-sidebar-panel">
			{ missingDeps.length > 0 && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Missing required plugins: ', 'redline' ) }
					<strong>{ missingDeps.join( ', ' ) }</strong>
					{ '. ' }
					{ __( 'Install and activate them to run checks.', 'redline' ) }
				</Notice>
			) }

			<div className="rdl-sidebar-panel__actions">
				<Button
					variant="primary"
					onClick={ handleCheck }
					disabled={ isChecking || ! postId || missingDeps.length > 0 }
					className="rdl-sidebar-panel__check-btn"
				>
					{ isChecking ? (
						<>
							<Spinner />
							{ __( 'Checking…', 'redline' ) }
						</>
					) : (
						__( 'Check Content', 'redline' )
					) }
				</Button>

				{ notesCreated > 0 && (
					<Button
						variant="secondary"
						isDestructive
						onClick={ handleClear }
						disabled={ isClearing }
						className="rdl-sidebar-panel__clear-btn"
					>
						{ isClearing
							? __( 'Clearing…', 'redline' )
							: __( 'Clear All Notes', 'redline' ) }
					</Button>
				) }
			</div>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ results && (
				<div className="rdl-sidebar-panel__summary">
					{ results.results?.length > 0 ? (
						<>
							<Notice status="warning" isDismissible={ false }>
								{ totalIssues }{ ' ' }
								{ totalIssues === 1
									? __( 'issue found', 'redline' )
									: __( 'issues found', 'redline' ) }
								{ ' across ' }
								{ results.results.length }
								{ ' ' }
								{ results.results.length === 1
									? __( 'block', 'redline' )
									: __( 'blocks', 'redline' ) }
								{ '. ' }
								{ notesCreated > 0 &&
									`${ notesCreated } note(s) created.` }
							</Notice>
							<ResultsList results={ results.results } />
						</>
					) : (
						<Notice status="success" isDismissible={ false }>
							{ results.message || __( 'No issues found!', 'redline' ) }
						</Notice>
					) }
				</div>
			) }
		</div>
	);
}
