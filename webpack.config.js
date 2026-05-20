/**
 * Custom webpack config for agent-ready admin bundles.
 *
 * Extends the @wordpress/scripts default config to support multiple
 * entry points. Without this, chained `wp-scripts build` invocations
 * each clean their output directory, so the final build only retains
 * the last bundle. Switching to a single multi-entry build keeps all
 * three bundles in `build/admin/` after one invocation.
 *
 * Entry points are discovered automatically from `src/admin/<name>/index.js`.
 */

const path = require( 'path' );
const fs = require( 'fs' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const ADMIN_SRC_DIR = path.resolve( __dirname, 'src/admin' );

const entries = fs
	.readdirSync( ADMIN_SRC_DIR )
	.filter( ( name ) => {
		const indexPath = path.join( ADMIN_SRC_DIR, name, 'index.js' );
		return fs.existsSync( indexPath );
	} )
	.reduce( ( acc, name ) => {
		acc[ name ] = path.join( ADMIN_SRC_DIR, name, 'index.js' );
		return acc;
	}, {} );

module.exports = {
	...defaultConfig,
	entry: entries,
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build/admin' ),
		filename: '[name].js',
	},
};
