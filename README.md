=== Firebase Connector ===
Contributors: anouarbenhamza
Tags: firebase, firestore, api, sync, posts, news, connector, ajax, bulk edit
Requires at least: 5.5
Tested up to: 6.5
Stable tag: 3.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Seamlessly sync news issues from Google Firebase Firestore into native WordPress posts with powerful interactive admin tools.
Dedicated to squirrel-news: https://squirrel-news.net/

== Description ==

The Firebase Connector plugin transforms your WordPress site into a dynamic frontend for your Firebase Firestore database. It moves beyond simple display shortcodes to provide a robust, professional-grade synchronization system.

The plugin fetches "issues" (e.g., news roundups, articles) from your Firebase Cloud Functions and intelligently creates or updates them as native WordPress posts. This provides massive benefits for SEO, performance, and compatibility with the entire WordPress ecosystem.

The centerpiece is the **Interactive Sync Tool**, an AJAX-powered admin page that gives you full control over the synchronization process. You can scan for differences, link existing content, and create, publish, or update posts individually or in bulk, all without reloading the page.

**Key Features:**
*   **Powerful Interactive Admin Tool:** Scan for differences between Firebase and WordPress, see the status of each issue (Synced, Missing, Unlinked), and perform actions.
*   **Bulk Actions:** Create, link, or publish hundreds of posts with a few clicks, using a progress bar for clear feedback.
*   **Automatic Background Syncing:** A configurable WP-Cron job keeps your site's content fresh. Enable it and choose a schedule (hourly, daily, weekly) that suits your needs.
*   **Native Post Creation:** Converts Firebase issues into standard WordPress posts for superior SEO and plugin compatibility.
*   **Featured Image Support:** Intelligently downloads and attaches images, preventing duplicates.
*   **Infinite Scroll Shortcode:** Use `[firebase_issues_list]` to display a fast-loading grid of your issues that automatically loads more as the user scrolls.

== Installation ==

1.  Upload the `firebase-connector` folder to the `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  A new **"Firebase Connector"** menu will appear in your WordPress admin sidebar.
4.  Go to **Firebase Connector -> Settings** and enter your API Token and configure other options.
5.  Go to **Firebase Connector -> Tools** to begin the initial content sync.

== Tutorial: Initial Setup and Syncing Your Content ==

This tutorial will guide you through syncing your content for the first time. This process is designed to be safe and give you full control.

**Step 1: Configure Your Settings**

1.  Navigate to **Firebase Connector -> Settings**.
2.  Enter your secret **API Token**. This is the most important step.
3.  In the "Automation Settings" section, set the **Admin Tools Fetch Limit** to a high number (e.g., `300`) to ensure it can see all your historical issues.
4.  Set the **Ongoing Sync Fetch Limit** to a smaller number (e.g., `50`) for efficient daily checks.
5.  Leave **Enable Automatic Sync** unchecked for now.
6.  Configure your desired "Frontend" settings.
7.  Click **Save Settings**.

**Step 2: Scan for Differences**

1.  Navigate to **Firebase Connector -> Tools**.
2.  Click the **"Scan All Issues"** button.
3.  The interactive table will load and show you the status of each issue from Firebase compared to your WordPress posts. You will see several statuses:
    *   **Match Found (Unlinked):** The plugin found a WordPress post with the same title. It just needs to be linked. This is for your manually-created posts.
    *   **Missing:** The plugin could not find any post for this issue. It needs to be created.
    *   **Synced (Protected):** A post that is linked but will not be updated by the plugin.
    *   **Synced (Managed):** A post that was created by the plugin and will be updated automatically.

**Step 3: Link Your Existing Posts**

1.  Use the filter dropdown to select **"Unlinked Matches Only"**.
2.  Check the "Select All" box at the top of the table.
3.  Click the **"Link Selected Matches"** button. The tool will process each one, and their status will change to "Synced (Protected)".

**Step 4: Create Missing Posts as Drafts**

1.  Use the filter dropdown to select **"Missing Only"**.
2.  Check the "Select All" box.
3.  Click the **"Create Selected Missing"** button.
4.  The tool will create all the missing posts and their status will change to **"Draft (Managed)"**. They are not yet visible on your live site.

**Step 5: Review and Publish**

1.  Go to the main WordPress **Posts -> All Posts** screen. You will see all the new posts with a "Draft" status.
2.  Click "Preview" on a few to ensure the layout and content look correct with your theme.
3.  Go back to **Firebase Connector -> Tools**. Filter for **"Drafts Only"**.
4.  Select the drafts you are happy with and click the **"Publish Selected Drafts"** button. They are now live.

**Step 6: Enable Automation**

Once you are confident that everything is working perfectly, go back to **Firebase Connector -> Settings**, check the **Enable Automatic Sync** box, choose your schedule, and save. The plugin will now handle everything for you in the background.

== Shortcode Usage ==

To display a grid of your synced issues with infinite scroll, use the following shortcode on any page:

`[firebase_issues_list]`

**Attributes:**
*   `title`: The heading to display above the list. Default: "News".
    *   Example: `[firebase_issues_list title="Latest Updates"]`
*   `lang`: The language of issues to fetch. Overrides the default setting.
    *   Example: `[firebase_issues_list lang="de"]`

== Frequently Asked Questions ==

**Q: My "Create Post" button is returning an error.**
A: This is almost always because the "Post Author" or "Post Category" ID is incorrect for your specific WordPress site. These are hard-coded in the `/includes/sync-handler.php` file in the `firebase_processor_ajax_handler` function. You must find the correct User ID and Category ID from your admin dashboard and update these values in the code.

**Q: The layout of my single posts is wrong.**
A: The plugin generates standard WordPress content blocks. The final appearance is controlled by your theme's CSS. The plugin includes a basic stylesheet (`/css/frontend-styles.css`) with layout rules. You can add your own overriding styles to this file or in your theme's Customizer (`Appearance -> Customize -> Additional CSS`).

== Changelog ==

= 3.0.0 =
*   **FEATURE:** Added a powerful, AJAX-powered Interactive Sync Tool for managing content.
*   **FEATURE:** Added bulk actions: Create, Link, and Publish selected items.
*   **FEATURE:** Added an "Unlink" action for individual posts.
*   **FEATURE:** Added a "Refresh" action to update a single managed post.
*   **FEATURE:** Added a "Quick Sync" button with a progress bar for manual headless syncs.
*   **FEATURE:** Implemented "Infinite Scroll" for the `[firebase_issues_list]` shortcode.
*   **IMPROVEMENT:** Refactored entire admin UI into "Tools" and "Settings" submenus.
*   **IMPROVEMENT:** Refactored PHP files into a cleaner, more organized structure (`ajax-handlers.php`, `cron-handler.php`, `post-helpers.php`).
*   **IMPROVEMENT:** Implemented a robust "brute-force" title matching system to find unlinked posts reliably.
*   **IMPROVEMENT:** Added intelligent image handling to prevent duplicate downloads.
*   **IMPROVEMENT:** Added full control over the automatic sync schedule (enable/disable, frequency).

= 2.0.0 =
*   Major refactor to sync Firebase issues to native WordPress posts.
*   Added WP-Cron job for basic automation.

= 1.0.0 =
*   Initial release. Fetched data live on page load using shortcodes.