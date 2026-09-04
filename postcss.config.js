const postcssPlugins = require( '@wordpress/postcss-plugins-preset' );
const cssnano = require( 'cssnano' );
const designTokenFallbacks =
	require( '@wordpress/theme/postcss-plugins/postcss-ds-token-fallbacks' ).default;

// wp-scripts does not add WPDS token fallbacks. Reassess this custom pipeline
// when wp-scripts is upgraded.
module.exports = ( { env } ) => {
	const plugins = [ ...postcssPlugins, designTokenFallbacks ];

	if ( env === 'production' ) {
		plugins.push(
			cssnano( {
				preset: [
					'default',
					{
						discardComments: {
							removeAll: true,
						},
					},
				],
			} )
		);
	}

	return { plugins };
};
