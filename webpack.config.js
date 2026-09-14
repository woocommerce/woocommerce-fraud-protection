const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const WooCommerceDependencyExtractionWebpackPlugin = require( '@woocommerce/dependency-extraction-webpack-plugin' );

module.exports = {
	...defaultConfig,
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new WooCommerceDependencyExtractionWebpackPlugin( {
			requestToExternal: ( request ) => {
				if (
					[
						'@woocommerce/navigation',
						'@wordpress/private-apis',
						'@wordpress/theme',
					].includes( request )
				) {
					return false;
				}
			},
		} ),
	],
	entry: {
		'admin-settings': './client/admin-settings/index.tsx',
	},
	optimization: {
		...defaultConfig.optimization,
		splitChunks: {
			...defaultConfig.optimization.splitChunks,
			cacheGroups: {
				...defaultConfig.optimization.splitChunks.cacheGroups,
				style: {
					...defaultConfig.optimization.splitChunks.cacheGroups.style,
					name: 'admin-settings',
				},
			},
		},
	},
};
