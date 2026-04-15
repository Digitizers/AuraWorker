# Plugin Check Report

**Plugin:** Digitizer Site Worker for Aura
**Generated at:** 2026-04-15 18:06:01


## `includes/class-aura-worker-health.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 68 | 11 | ERROR | WordPress.WP.AlternativeFunctions.file_system_operations_fopen | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fopen(). |  |
| 72 | 11 | ERROR | WordPress.WP.AlternativeFunctions.file_system_operations_fread | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fread(). |  |
| 73 | 3 | ERROR | WordPress.WP.AlternativeFunctions.file_system_operations_fclose | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fclose(). |  |

## `includes/class-aura-worker-rollback.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 45 | 18 | ERROR | WordPress.DateTime.RestrictedFunctions.date_date | date() is affected by runtime timezone changes which can cause date/time to be incorrectly displayed. Use gmdate() instead. |  |
| 134 | 6 | ERROR | WordPress.WP.AlternativeFunctions.unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file. |  |
| 168 | 21 | ERROR | WordPress.WP.AlternativeFunctions.file_system_operations_rmdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: rmdir(). |  |
| 168 | 53 | ERROR | WordPress.WP.AlternativeFunctions.unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file. |  |
| 170 | 3 | ERROR | WordPress.WP.AlternativeFunctions.file_system_operations_rmdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: rmdir(). |  |

## `readme.txt`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 0 | 0 | ERROR | outdated_tested_upto_header | Tested up to: 6.7 < 6.9. The "Tested up to" value in your plugin is not set to the current version of WordPress. This means your plugin will not show up in searches, as we require plugins to be compatible and documented as tested up to the most recent version of WordPress. | [Docs](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/#readme-header-information) |
| 0 | 0 | ERROR | stable_tag_mismatch | Mismatched Stable Tag: 1.3.5 != 2.0.0. Your Stable Tag is meant to be the stable version of your plugin and it needs to be exactly the same with the Version in your main plugin file's header. Any mismatch can prevent users from downloading the correct plugin files from WordPress.org. | [Docs](https://developer.wordpress.org/plugins/wordpress-org/common-issues/#incorrect-stable-tag) |
| 0 | 0 | WARNING | mismatched_plugin_name | Plugin name "Digitizer Site Worker" is different from the name declared in plugin header "Digitizer Site Worker for Aura". | [Docs](https://developer.wordpress.org/plugins/wordpress-org/common-issues/#incomplete-readme) |

## `includes/class-aura-worker-updater.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 681 | 5 | WARNING | Squiz.PHP.DiscouragedFunctions.Discouraged | The use of function set_time_limit() is discouraged |  |
