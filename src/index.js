import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { store as commandsStore } from '@wordpress/commands';
import { useDispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import SidebarPanel from './components/sidebar-panel';
import './style.scss';

function RedlineCommands() {
	const { openGeneralSidebar } = useDispatch( 'core/edit-post' );
	const { registerCommand, unregisterCommand } = useDispatch( commandsStore );

	useEffect( () => {
		registerCommand( {
			name: 'redline/check-content',
			label: 'Redline: Check Content Against Guidelines',
			icon: 'yes-alt',
			callback: ( { close } ) => {
				close();
				openGeneralSidebar( 'redline/redline' );
			},
		} );

		registerCommand( {
			name: 'redline/clear-notes',
			label: 'Redline: Clear All Notes',
			icon: 'dismiss',
			callback: ( { close } ) => {
				close();
				openGeneralSidebar( 'redline/redline' );
			},
		} );

		return () => {
			unregisterCommand( 'redline/check-content' );
			unregisterCommand( 'redline/clear-notes' );
		};
	}, [ registerCommand, unregisterCommand, openGeneralSidebar ] );

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
