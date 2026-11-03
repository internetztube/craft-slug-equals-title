# The demo site's templates

`dev/dev.sh seed` copies these into the dev project's `templates/` and points every section, category group and the product type at them. Three files: `_layout.twig` (the frame), `index.twig` (the home page: every element with its address and whether its slug follows its title, through the plugin's service `craft.app.plugins.getPlugin('slug-equals-title').slugs.isEnabled(element)`), `_element.twig` (an article, page, category or product). Served at http://localhost:8080/ (German site: /de/).
