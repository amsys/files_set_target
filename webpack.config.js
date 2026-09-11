const path = require('node:path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
    'share-target': path.join(__dirname, 'src', 'share-target.js'),
    'admin': path.join(__dirname, 'src', 'admin.js'),
}

module.exports = webpackConfig
