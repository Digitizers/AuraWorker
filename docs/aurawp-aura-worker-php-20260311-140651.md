# Plugin Check Report

**Plugin:** AuraWP
**Generated at:** 2026-03-11 14:06:51


## `includes/class-aura-worker.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 56 | 17 | ERROR | WordPress.WP.I18n.NonSingularStringLiteralText | The $text parameter must be a single text string literal. Found: 'This site uses the AuraWP plugin to enable remote management from the Aura dashboard (my-aura.app). ' .\n 'When connected, the Aura dashboard may access site health information including WordPress version, ' .\n 'PHP version, installed plugins and themes, and database metadata. No personal user data is collected ' .\n 'or transmitted by this plugin.' | [Docs](https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/#basic-strings) |

## `aura-worker.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 0 | 0 | ERROR | plugin_updater_detected | Including An Update Checker / Changing Updates functionality. Plugin Updater detected. Use of the Update URI header is not allowed in plugins hosted on WordPress.org. | [Docs](https://developer.wordpress.org/plugins/wordpress-org/common-issues/#update-checker) |
| 0 | 0 | WARNING | plugin_header_nonexistent_domain_path | The "Domain Path" header in the plugin file must point to an existing folder. Found: "languages" | [Docs](https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/#domain-path) |
| 0 | 0 | WARNING | trademarked_term | The plugin name includes a restricted term. Your chosen plugin name - "AuraWP" - contains the restricted term "wp" which cannot be used at all in your plugin name. |  |
| 0 | 0 | WARNING | trademarked_term | The plugin slug includes a restricted term. Your plugin slug - "aurawp" - contains the restricted term "wp" which cannot be used at all in your plugin slug. |  |

## `includes/class-aura-worker-updater.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 39 | 4 | WARNING | Squiz.PHP.DiscouragedFunctions.Discouraged | The use of function ini_set() is discouraged |  |

## `includes/class-aura-worker-api.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 182 | 45 | WARNING | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |  |
| 182 | 45 | WARNING | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |  |

## `readme.txt`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 0 | 0 | WARNING | trademarked_term | The plugin name includes a restricted term. Your chosen plugin name - "AuraWP" - contains the restricted term "wp" which cannot be used at all in your plugin name. |  |
