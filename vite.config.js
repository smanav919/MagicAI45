import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import fs from 'fs';

const customFrontendPath = 'resources/css/custom-frontend.scss';

export default defineConfig( {
	plugins: [
		laravel( {
			input: [
				'resources/css/app.css',
				fs.existsSync(customFrontendPath) ? customFrontendPath : 'resources/css/frontend.scss',
				'resources/js/app.js',
			],
			refresh: true,
		} ),
	],
} );
