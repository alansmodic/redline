import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import SidebarPanel from './components/sidebar-panel';
import './style.scss';

registerPlugin( 'redline', {
	render: () => (
		<>
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
