'use strict';
const { buildEngine } = require('ember-engines/lib/engine-addon');
const { name } = require('./package');

module.exports = buildEngine({
    name,

    lazyLoading: {
        // The engine ships lazily, but a lazy bundle is not reachable from the dummy application's
        // test bundle, so addon modules cannot be imported by unit tests. Building eagerly under
        // `ember test` keeps the shipped behaviour unchanged while letting the tests load.
        enabled: process.env.EMBER_ENV !== 'test',
    },

    isDevelopingAddon() {
        return true;
    },
});
