=== Firebase Connector ===
Contributors: anouarbenhamza
Tags: firebase, firestore, api, sync, posts, news, connector
Requires at least: 5.0
Tested up to: 6.2
Stable tag: 2.0.0
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Seamlessly sync news issues from Google Firebase Firestore directly into native WordPress posts.

== Description ==

Tired of managing content in two places? The Firebase Connector plugin bridges the gap between your Firebase Firestore database and your WordPress website.

Instead of relying on shortcodes to display dynamic data on a single page, this plugin implements a robust synchronization system. It periodically fetches "issues" (e.g., news roundups, articles) from your Firebase Cloud Functions and intelligently creates or updates them as native WordPress posts.

This approach provides massive benefits:
*   **Superior SEO:** Each issue gets its own URL, title, and content, making it fully indexable by search engines.
*   **Native WordPress Experience:** Content is stored as real posts, meaning they work with virtually all other plugins (SEO, caching, related posts, etc.) and are styled perfectly by your theme.
*   **Automated Workflow:** A WP-Cron job runs in the background to keep your site's content fresh without any manual intervention.
*   **Improved Performance:** Content is served from your WordPress database, which is typically faster and more reliable than making live API calls for every page view.

**Key Features:**
*   **Automatic Hourly Sync:** Keeps your WordPress content up-to-date with Firebase.
*   **Native Post Creation:** Converts Firebase issues into standard WordPress posts.
*   **Featured Image Support:** Automatically downloads the issue image and sets it as the post's featured image.
*   **Flexible List Shortcode:** Use `[firebase_issues_list]` to display a grid of your latest issues anywhere on your site.
*   **Manual Sync Control:** A "Sync Now" button in the admin settings for immediate updates.

== Installation ==

1.  Upload the `firebase-connector` folder to the `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Go to **Settings -> Firebase Connector** in your WordPress admin panel.
4.  Fill in your Firebase Cloud Functions URL and your secret API Token.
5.  Click the **"Sync Now"** button to perform the first import and create your initial posts.
6.  Go to any page or post and use the `[firebase_issues_list]` shortcode to display your newly created issue posts.

== Usage ==

### Configuration

All settings are located under **Settings -> Firebase Connector**.

*   **Cloud Functions Base URL:** The base URL for your project's Cloud Functions. (This is no longer directly used for API calls in version 2.0+, but is kept for potential future use. The API endpoints are currently hardcoded in `api-client.php`).
*   **API Token:** The secret token required to authenticate with your Cloud Functions.
*   **Default Issues Limit:** The default number of issues to show when using the `[firebase_issues_list]` shortcode.
*   **Default Language:** The default language for fetching issues.
*   **Manual Sync:** A button to trigger the sync process immediately.

### Displaying the Issues List

To display a grid of your synced issues, use the following shortcode on any page, post, or widget area:

`[firebase_issues_list]`

**Shortcode Attributes:**
*   `title`: (Optional) The heading to display above the list. Default: "News".
    *   Example: `[firebase_issues_list title="Latest Updates"]`
*   `limit`: (Optional) The number of issues to display. Overrides the default setting.
    *   Example: `[firebase_issues_list limit="6"]`
*   `lang`: (Optional) The language of issues to fetch. Overrides the default setting.
    *   Example: `[firebase_issues_list lang="de"]`

A complete example:
`[firebase_issues_list title="Our Top 5 Stories" limit="5"]`

== Frequently Asked Questions ==

**Q: Do I need a special page to show the single issue details?**
A: No. With version 2.0 and later, the plugin creates an actual WordPress post for each issue. The `[firebase_issues_list]` shortcode will link directly to these posts, which are then displayed by your theme's native post template. You should delete any old page that was using the `[firebase_single_issue]` shortcode.

**Q: Posts are not being created automatically.**
A: The automatic sync relies on WP-Cron, which only runs when someone visits your website. If you have a very low-traffic site, the cron job may not run frequently. For guaranteed execution, you can set up a real cron job on your server to hit the WordPress cron endpoint. Alternatively, you can always trigger a sync manually from the plugin's settings page.

**Q: The design of the single post looks slightly different from my other pages.**
A: This is expected and is a feature! The issue is now a Post, so its design is controlled by your theme's template for single posts (e.g., `single.php`). This means it will have the same layout, sidebars, and features (like comments) as your other blog posts, making it fit perfectly with your site.

**Q: How can I sync the issues to a Custom Post Type instead of the default 'Post'?**
A: This requires a small code modification. In the file `includes/sync-handler.php`, find the `firebase_connector_sync_issues_to_posts` and `firebase_connector_find_post_by_firebase_id` functions. In the arguments arrays within those functions, change `'post_type' => 'post'` to `'post_type' => 'your_custom_post_type_slug'`.

== Changelog ==

= 2.0.0 =
*   **MAJOR REFACTOR:** Plugin now syncs Firebase issues to create native WordPress posts instead of relying on a shortcode to display single issue details.
*   **NEW:** Added `includes/sync-handler.php` to manage all post creation and update logic.
*   **NEW:** Implemented a WP-Cron job (`hourly`) to automatically sync issues in the background. Schedule is set on plugin activation.
*   **NEW:** Added a "Sync Now" button to the admin settings page for manual synchronization.
*   **NEW:** Issues now have their main image set as the post's "Featured Image".
*   **UPDATE:** The `[firebase_issues_list]` shortcode has been updated to query for and link to the newly created posts instead of a generic page with URL parameters.
*   **DEPRECATED:** The `[firebase_single_issue]` shortcode and its corresponding page are no longer needed and have been removed. The page slug setting has also been removed.

= 1.0.0 =
*   Initial release.
*   Fetches issues from Firebase via API calls.
*   Uses `[firebase_issues_list]` shortcode to display a grid of issues.
*   Uses `[firebase_single_issue]` shortcode on a dedicated page to display issue details.
