# catlist DokuWiki plugin.

Static tree-like page listing plugin for DokuWiki.

For detailed description and documentation, see the (plugin page)[https://www.dokuwiki.org/plugin:catlist].

### Contribution

Do what you want. Any help to add features or refactor the code is welcomed. The only requirement is backwards compatibility.

The plugin is not actively developped, but still maintained.

### Security

The plugin directly scans the file system for pages, and does not use the tree-walking DokuWiki primitive. DokuWiki permissions should be respected, but there is *no absolute guarantee*, as there may be unexplored corner cases. Use with care.