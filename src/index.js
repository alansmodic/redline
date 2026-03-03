import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { useCommand } from '@wordpress/commands';
import { useDispatch } from '@wordpress/data';
import SidebarPanel from './components/sidebar-panel';
import './style.scss';

function RedlineCommands() {
	const { openGeneralSidebar } = useDispatch( 'core/edit-post' );

	useCommand( {
		name: 'redline/check-content',
		label: 'Redline: Check Content Against Guidelines',
		icon: 'yes-alt',
		callback: ( { close } ) => {
			close();
			openGeneralSidebar( 'redline/redline' );
		},
	} );

	useCommand( {
		name: 'redline/clear-notes',
		label: 'Redline: Clear All Notes',
		icon: 'dismiss',
		callback: ( { close } ) => {
			close();
			openGeneralSidebar( 'redline/redline' );
		},
	} );

	return null;
}

registerPlugin( 'redline', {
	render: () => (
		<>
			<RedlineCommands />
			<PluginSidebarMoreMenuItem target="redline">
				Redline
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name="redline"
				title="Redline"
				icon="yes-alt"
			>
				<SidebarPanel />
			</PluginSidebar>
		</>
	),
} );
