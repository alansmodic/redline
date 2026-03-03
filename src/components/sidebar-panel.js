import { useState } from '@wordpress/element';
import { Button, Spinner, Notice } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import ResultsList from './results-list';

const config = window.redlineConfig || {};

export default function SidebarPanel() {
	const [ isChecking, setIsChecking ] = useState( false );
	const [ isClearing, setIsClearing ] = useState( false );
	const [ results, setResults ] = useState( null );
	const [ error, setError ] = useState( null );

	const postId = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostId(),
		[]
	);

	const { savePost, refreshPost } = useDispatch( 'core/editor' );

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

		try {
			// Save the post first so server has current content.
			await savePost();

			const response = await apiFetch( {
				path: '/redline/v1/check',
				method: 'POST',
				data: { post_id: postId },
			} );

			setResults( response );

			// Refresh editor to display new Notes on blocks.
			if ( response.notes_created > 0 ) {
				await refreshPost();
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
			const response = await apiFetch( {
				path: '/redline/v1/clear',
				method: 'POST',
				data: { post_id: postId },
			} );

			if ( response.notes_cleared > 0 ) {
				await refreshPost();
			}

			setResults( null );
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

				{ results && results.notes_created > 0 && (
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
								{ results.notes_created > 0 &&
									`${ results.notes_created } note(s) created.` }
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
