=== CDNHub ===
Contributors: bdSpark
Tags: cdn, github, media, images, jsdelivr, performance, offload
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 26.2.2
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
* **One Commit per Image**: The original and all its sizes are committed together, keeping the repository history clean.
* **Fresh CDN Copies**: Overwritten or deleted files are purged from the jsDelivr cache automatically.
* **Encrypted Token**: GitHub access tokens are encrypted at rest with AES-256-GCM and scrubbed from logs. The plugin never stores a token in plain text: if the server lacks OpenSSL, the token is not saved and an admin notice explains why. Also supports `CDNHUB_GITHUB_TOKEN` in `wp-config.php`.
* **SVG Checks**: SVG files containing scripts, event handlers, CSS escapes, unsafe animation targets or other unsafe code are rejected and stay on your server.
* **Developer Extensible**: Pluggable provider interface (`CDNHub_Provider`) and `cdnhub_provider` filter.

= Open Source & Author Attribution =
This plugin is open-source software under GPLv2 or later. Anyone is free to use, run, and improve the plugin. However, original Author attribution to bdSpark (https://bdspark.com) must be preserved in all copies, forks, and derivative works; original authorship credit cannot be removed or altered.

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

= CDNHub says it can no longer read my token. Why? =
The saved token is encrypted with a key derived from your WordPress security salts. If the salts in `wp-config.php` change, enter the token again in Tools > CDNHub (or define `CDNHUB_GITHUB_TOKEN`).

== Changelog ==

= 26.2.2 =
* Each image (all sizes) is now uploaded as a single commit via the Git Data API; deletions are batched too.
* jsDelivr cache is purged when a file is overwritten or deleted (`cdnhub_purge_urls` filter for other CDNs).
* Uploads that would exceed the PHP memory limit are refused up front instead of crashing, and are not retried.
* SVG checks now parse the whole file as XML and catch entity-encoded and hidden payloads.
* The per-upload "Upload to GitHub" choice now requires a valid nonce.
* An admin notice explains when the saved token cannot be decrypted (changed salts).
* Deactivation clears scheduled uploads; activation re-queues pending ones; uninstall removes all plugin options and transients.
* Fixed: the local-copy cleanup tool lost its total count on the first batch.
* Composer support: autoloading, PHPUnit tests, and WordPress coding standards.
* Corrected the documented encryption algorithm (AES-256-GCM).
* Security: a token is never stored in plain text when OpenSSL is unavailable (the save is refused with an error); owner, repository and branch names are validated.
* Security: SVG files are also checked for CSS escape tricks, commented-out URLs, unsafe `<animate>`/`<set>` targets and `xml:base`.
* Replacing a file with the same name is now detected by its content hash, and adoption handles the same content stored in several folders.
* CDN rewriting keeps `?query` strings and `#fragments`; bulk-tool counters no longer include excluded images.
* Uninstall also removes per-user flash messages and cleans every site in a multisite network.

= 26.2.1 =
* Initial public release with full GitHub integration.
* Added jsDelivr, GitHub Raw, GitHub Pages, and Custom CDN URL modes.
* Added "Adopt" tool for existing repository media.
* Added per-image Exclude from CDN functionality.
* Added automated WP-Cron retries and AES-256-GCM token encryption.

== Upgrade Notice ==

= 26.2.2 =
Security and reliability fixes, plus the author website link (https://bdspark.com). Update recommended.

= 26.2.1 =
Initial release.
