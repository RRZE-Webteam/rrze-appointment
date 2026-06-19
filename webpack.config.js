const path = require('path');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');

const config = Array.isArray(defaultConfig) ? defaultConfig[0] : defaultConfig;
const entry = typeof config.entry === 'function' ? config.entry() : config.entry;

module.exports = {
	...config,
	entry: {
		...entry,
		'rrze-appointment-guided-tour': path.resolve(
			__dirname,
			'src/js/rrze-appointment-guided-tour.js'
		),
	},
};
