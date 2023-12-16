# catlist DokuWiki plugin.

Static tree-like page listing plugin for DokuWiki.

For detailed description and documentation, see the [plugin page](https://www.dokuwiki.org/plugin:catlist).

### Contribution

Do what you want. Any help to add features or refactor the code is welcome. The only requirement is backwards compatibility and some consistence.

The plugin is not actively developped, but still somewhat maintained. Some features may be added if it is not too much work. As I'm not a user of DokuWiki anymore, I'm looking for a new maintainer for this plugin. Please step in if you're interested.

### Security

The plugin directly scans the file system for pages, and does not use the tree-walking DokuWiki primitive. DokuWiki permissions should be respected, but there is *no absolute guarantee*, as there may be unexplored corner cases. Use with care.
