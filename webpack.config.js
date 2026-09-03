const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
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
