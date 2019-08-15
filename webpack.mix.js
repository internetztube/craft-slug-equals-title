let mix = require('laravel-mix');

mix.js('src/js/exclude-from-rewrite/app.js', 'src/resources/exclude-from-rewrite/app.js')
  .sass('src/css/exclude-from-rewrite/app.scss', 'src/resources/exclude-from-rewrite/app.css');