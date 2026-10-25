=== CDNHub ===
Contributors: bdSpark
Tags: cdn, github, media, images, jsdelivr, performance, offload
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 26.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Effortlessly offload WordPress images to GitHub and serve them blazing fast via free global CDNs (jsDelivr, GitHub Raw, GitHub Pages, or Custom Domain).

== Description ==

Upload images the normal way in your WordPress editor. CDNHub automatically stores them in a GitHub repository and serves them through a high-performance global Content Delivery Network (CDN) like jsDelivr.

Free up web host storage, decrease server inode usage, reduce hosting bandwidth expenses, and speed up global page load times without changing your existing publishing workflow.

= Key Features =
* **Automatic Multi-Size Upload**: Uploads original media and all generated thumbnail sizes.
* **Instant or Background Queue**: Immediate upload or asynchronous background synchronization via WP-Cron.
* **Multiple CDN Delivery Modes**: jsDelivr (recommended edge network), GitHub Raw, GitHub Pages, or Custom Domain template.
* **Flexible Folder Organization**: Organize files by Year/Month, Year, Post ID, flat folder, or custom pattern (`{year}/{month}`).
* **Smart Duplicate & Conflict Protection**: SHA-1 content hash detection prevents duplicate uploads; configurable conflict strategies (unique, overwrite, or fail).
* **"Adopt" Existing Media Tool**: Already pushed media to GitHub? CDNHub matches files by content hash and adopts them without re-uploading.
* **Exclude from CDN**: Keep any image (e.g., logo, private files) on your origin server only.
* **Fail-Safe & Auto-Retry**: Keeps local copies by default; failed uploads fall back to local files and retry automatically.
* **Media Library Integration**: Custom status column, one-click sync/re-sync buttons, and bulk upload actions.
* **Bank-Grade Security**: GitHub access tokens are encrypted at rest with AES-256-CBC and scrubbed from logs. Also supports `CDNHUB_GITHUB_TOKEN` in `wp-config.php`.
* **Developer Extensible**: Pluggable provider interface (`CDNHub_Provider`) and `cdnhub_provider` filter.

= Open Source & Author Attribution =
This plugin is open-source software under GPLv2 or later. Anyone is free to use, run, and improve the plugin. However, original Author attribution to bdSpark must be preserved in all copies, forks, and derivative works; original authorship credit cannot be removed or altered.

== Installation ==

1. Upload the `cdnhub` directory to your `/wp-content/plugins/` directory, or install the plugin ZIP file via **Plugins > Add New > Upload Plugin**.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Create a public repository on GitHub (e.g. `media-cdn`) with at least one commit.
4. In GitHub Settings, generate a Fine-grained Personal Access Token with **Contents: Read and write** permissions for the repository.
5. In WordPress admin, go to **Tools > CDNHub**, enter your token, repository owner, repository name, and branch.
6. Click **Test connection**, then **Save Changes**.
7. Start uploading media as usual!

== Frequently Asked Questions ==

= Is jsDelivr free to use? =
Yes! jsDelivr provides a free, fast, and multi-CDN network (powered by Cloudflare, Fastly, and others) for public GitHub repositories.

= Can I keep local copies of my images? =
Yes. By default, CDNHub keeps local files on your server. If an upload fails or the remote service is temporarily unreachable, your site will seamlessly fall back to local files.

= What happens if I deactivate or delete the plugin? =
Your images remain safely in your GitHub repository and on your local web server. CDNHub never deletes your media upon deactivation.

= Can I exclude site logos or private files from the CDN? =
Yes! You can mark any media item as "Excluded from CDN" in the Media Library. It will stay local and never be uploaded to GitHub.

= Can I define my GitHub token in wp-config.php? =
Yes! Add the following line to `wp-config.php`:
`define( 'CDNHUB_GITHUB_TOKEN', 'github_pat_your_token_here' );`

== Changelog ==

= 26.2.1 =
* Initial public release with full GitHub integration.
* Added jsDelivr, GitHub Raw, GitHub Pages, and Custom CDN URL modes.
* Added "Adopt" tool for existing repository media.
* Added per-image Exclude from CDN functionality.
* Added automated WP-Cron retries and AES-256-CBC token encryption.

== Upgrade Notice ==

= 26.2.1 =
Initial release.
