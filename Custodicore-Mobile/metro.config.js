const { getDefaultConfig } = require('expo/metro-config');

/** @type {import('expo/metro-config').MetroConfig} */
const config = getDefaultConfig(__dirname);

// Let Babel transform modern syntax (e.g. private class fields in react-native)
// before Hermes bytecode compilation.
config.transformer.hermesParser = false;

module.exports = config;
