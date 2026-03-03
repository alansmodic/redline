import { PanelBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const SEVERITY_CLASSES = {
	error: 'rdl-severity--error',
	warning: 'rdl-severity--warning',
	info: 'rdl-severity--info',
};

function blockTypeLabel( blockName ) {
	const map = {
		'core/paragraph': 'Paragraph',
		'core/heading': 'Heading',
		'core/list': 'List',
		'core/list-item': 'List Item',
		'core/quote': 'Quote',
		'core/button': 'Button',
		'core/image': 'Image',
	};
	return map[ blockName ] || blockName;
}

export default function ResultsList( { results } ) {
	if ( ! results || results.length === 0 ) {
		return null;
	}

	return (
		<div className="rdl-results-list">
			{ results.map( ( result, index ) => (
				<PanelBody
					key={ index }
					title={
						`${ blockTypeLabel( result.block_name ) } — ${ result.issues.length } issue(s)`
					}
					initialOpen={ index === 0 }
					className="rdl-results-list__block"
				>
					{ result.excerpt && (
						<p className="rdl-results-list__excerpt">
							{ result.excerpt }
						</p>
					) }
					<ul className="rdl-results-list__issues">
						{ result.issues.map( ( issue, issueIndex ) => (
							<li
								key={ issueIndex }
								className={ `rdl-results-list__issue ${ SEVERITY_CLASSES[ issue.severity ] || '' }` }
							>
								<span className="rdl-results-list__severity">
									{ issue.severity.toUpperCase() }
								</span>
								<span className="rdl-results-list__message">
									{ issue.message }
								</span>
								{ issue.guideline_section && (
									<span className="rdl-results-list__section">
										{ issue.guideline_section }
									</span>
								) }
								{ issue.source && (
									<span className="rdl-results-list__source">
										{ issue.source === 'lint'
											? __( 'Lint', 'redline' )
											: __( 'AI', 'redline' ) }
									</span>
								) }
							</li>
						) ) }
					</ul>
				</PanelBody>
			) ) }
		</div>
	);
}
