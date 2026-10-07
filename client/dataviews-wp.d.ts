// Plugins built with @wordpress/scripts must import DataViews from the
// `@wordpress/dataviews/wp` runtime entry point. TypeScript's classic module
// resolution ("node") does not read the package's `exports` map, so it cannot
// resolve the types for that subpath on its own. Its public surface is identical
// to the package root, so re-export those types here.
declare module '@wordpress/dataviews/wp' {
	export * from '@wordpress/dataviews';
}
