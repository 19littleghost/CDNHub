# CDNHub 🚀

[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg?logo=wordpress)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-8892BF.svg?logo=php)](https://php.net)
[![Version](https://img.shields.io/badge/Version-26.2.1-success.svg)](https://github.com/19littleghost/CDNHub/releases)
[![License](https://img.shields.io/badge/License-GPLv2%20or%20later-orange.svg)](LICENSE)

> **Effortlessly offload WordPress images to GitHub and serve them blazing fast via free global CDNs (jsDelivr, GitHub Raw, GitHub Pages, or your own custom domain).**

Upload images the normal way in your WordPress admin or block editor — **CDNHub handles storage, synchronization, thumbnail variants, and CDN URL rewriting automatically.**

---

## 📑 Table of Contents

- [Why CDNHub?](#-why-cdnhub)
- [Key Features](#-key-features)
- [How It Works](#-how-it-works)
- [Quick Start Guide](#-quick-start-guide)
  - [1. GitHub Repository Setup](#1-github-repository-setup)
  - [2. Generate GitHub Personal Access Token](#2-generate-github-personal-access-token)
  - [3. Configure CDNHub in WordPress](#3-configure-cdnhub-in-wordpress)
- [Detailed Configuration](#-detailed-configuration)
  - [Storage & URL Modes](#storage--url-modes)
  - [Folder Organization](#folder-organization)
  - [Upload Rules & Conflict Strategies](#upload-rules--conflict-strategies)
- [Tools & Maintenance](#-tools--maintenance)
  - [Bulk Upload Existing Images](#bulk-upload-existing-images)
  - [Smart "Adopt" Tool](#smart-adopt-tool)
  - [Exclude Specific Images from CDN](#exclude-specific-images-from-cdn)
- [Security & Privacy](#-security--privacy)
- [Developer Hooks & Custom Providers](#-developer-hooks--custom-providers)
- [System Requirements](#-system-requirements)
- [License](#-license)

---

## 🌟 Why CDNHub?

- **Save Web Hosting Disk Space & Inodes**: Free your server from storing gigabytes of media files.
- **Slash Bandwidth Costs**: Deliver images through global edge networks instead of consuming host bandwidth.
- **Fast Global Delivery**: Images are cached and distributed worldwide via **jsDelivr** (powered by Fastly & Cloudflare) or your own custom CDN.
- **Zero Workflow Disruption**: Writers and editors continue uploading media normally; everything is transparently handled in the background or instantly.
- **Fail-Safe Reliability**: Local copies are retained by default. If an upload fails, your site seamlessly falls back to the local image and retries automatically via WP-Cron.

---

## ✨ Key Features

| Feature | Description |
|---|---|
| **Automatic Multi-Size Upload** | Syncs original media plus all WordPress-generated thumbnail variations automatically. |
| **Instant or Background Uploads** | Choose between immediate sync during media upload or asynchronous background processing via WP-Cron. |
| **Multiple CDN Delivery Modes** | Serve media via **jsDelivr** (fastest free edge network), **GitHub Raw**, **GitHub Pages**, or a **Custom Domain / Template**. |
| **Flexible Folder Organization** | Organize files by Year/Month (`2026/10`), Year, Post ID, Flat folder, or custom token patterns like `{year}/{month}`. |
| **Duplicate & Conflict Protection** | Uses SHA-1 hash matching to prevent uploading duplicates, with configurable conflict strategies (unique renaming, overwrite, or safe fail). |
| **"Adopt" Existing Media Tool** | Already pushed your `wp-content/uploads` folder to GitHub? CDNHub matches media by content hash and adopts them instantly without re-uploading. |
| **Selective "Exclude from CDN"** | Easily mark sensitive files, private documents, or site logos to stay strictly on your local web server. |
| **Media Library Integration** | Displays CDN sync badges (`On CDN`, `Queued`, `Failed`, `Excluded`), per-image sync triggers, and bulk actions in WordPress Media Library. |
| **Bank-Grade Token Security** | GitHub access tokens are encrypted at rest with AES-256-CBC and scrubbed from activity logs. Supports `wp-config.php` constants. |
| **Developer Extensible** | Modular `CDNHub_Provider` interface and `cdnhub_provider` filter allow custom storage adapters (e.g. GitLab, S3, R2). |

---

## 🔄 How It Works

```
[ User uploads image in WordPress ]
                 │
                 ▼
[ WordPress generates thumbnails & sizes ]
                 │
                 ▼
     ┌───────────────────────┐
     │ Is CDNHub Active &    │─── No ────► [ Stored and served locally ]
     │ Image Supported?      │
     └───────────────────────┘
                 │ Yes
                 ▼
    [ Upload to GitHub via REST API ] ────── Failure ──► [ Fallback to local copy ]
                 │                                        [ Queued for WP-Cron retry ]
                 │ Success
                 ▼
[ Store remote path & SHA in attachment metadata ]
                 │
                 ▼
[ Frontend Requests (the_content, srcset, src) ]
                 │
                 ▼
[ CDNHub Rewriter swaps local URL with CDN URL ]
  e.g. https://cdn.jsdelivr.net/gh/owner/repo@branch/media/2026/10/photo.jpg
```

---

## 🚀 Quick Start Guide

### 1. GitHub Repository Setup
1. Create a **Public** repository on GitHub (e.g., `media-cdn` or `cdn-assets`).
   *(Note: jsDelivr requires a public repository. If using a private repository, you can configure GitHub Pages or a custom proxy).*
2. Initialize it with a `README.md` or at least one initial commit.

### 2. Generate GitHub Personal Access Token
1. Go to **GitHub Settings** > **Developer Settings** > **Personal Access Tokens** > **Fine-grained tokens** (or [click here](https://github.com/settings/tokens?type=beta)).
2. Click **Generate new token**.
3. Configure the token:
   - **Token name**: `CDNHub - WordPress Media`
   - **Repository access**: Select *Only select repositories* and choose your media repository.
   - **Permissions**: Under **Repository permissions**, set **Contents** to `Read and write`.
4. Click **Generate token** and copy the resulting string (`github_pat_...`).

### 3. Configure CDNHub in WordPress
1. Log in to WordPress Admin and navigate to **Tools > CDNHub**.
2. On the **Connection** tab:
   - **GitHub Token**: Paste your fine-grained token (or set it in `wp-config.php`).
   - **Repository Owner**: Your GitHub username or organization (e.g., `19littleghost`).
   - **Repository Name**: The media repository name (e.g., `media-cdn`).
   - **Branch**: Default branch, typically `main`.
3. Click **Test connection** to verify API access and permissions.
4. Click **Save Changes**.

Upload an image in any post or the Media Library — CDNHub will automatically handle the rest!

---

## ⚙️ Detailed Configuration

### Storage & URL Modes

Under **Tools > CDNHub > Storage & URLs**, choose your preferred CDN delivery mode:

| Mode | URL Structure | Description |
|---|---|---|
| **jsDelivr (Recommended)** | `https://cdn.jsdelivr.net/gh/:owner/:repo@:branch/:path` | Global CDN with multi-POP network, DDoS protection, and zero configuration. |
| **GitHub Pages** | `https://:owner.github.io/:repo/:path` | Ideal if you have GitHub Pages enabled on your media repository. |
| **GitHub Raw** | `https://raw.githubusercontent.com/:owner/:repo/:branch/:path` | Direct raw access from GitHub’s storage nodes. |
| **Custom Domain** | `https://cdn.example.com/{path}` | Point your custom subdomain (e.g. Cloudflare CNAME to jsDelivr) with tokens: `{owner}`, `{repo}`, `{branch}`, `{path}`. |

### Folder Organization

Select how media files are organized in the GitHub repository:
- **Year / Month** (Default): `media/2026/10/image.jpg`
- **Year Only**: `media/2026/image.jpg`
- **Post ID**: `media/123/image.jpg` (or `media/unattached/image.jpg`)
- **Flat Folder**: All files placed directly in the base folder.
- **Custom Pattern**: Define your own structure using `{year}`, `{month}`, `{day}`, and `{post_id}` (e.g. `uploads/{year}/{month}`).

### Upload Rules & Conflict Strategies

Under **Tools > CDNHub > Upload rules**:
- **Supported Formats**: Select allowed formats: `JPG`, `PNG`, `GIF`, `WebP`, `AVIF`, and `SVG` (all SVG uploads are sanitized against malicious code).
- **Max File Size**: Cap the maximum file size (in MB) eligible for CDN upload.
- **Upload Mode**:
  - `Instant`: Uploads in real-time when saving media.
  - `Background`: Queues files for background transfer via WP-Cron (faster editor experience).
- **Conflict Strategy**:
  - `Unique (Default)`: Appends an incremented suffix (`-1`, `-2`) if a file with the same name exists.
  - `Overwrite`: Commits the new file over the existing remote file.
  - `Fail`: Cancels remote sync safely and retains the file locally.
- **Local Storage Management**: Choose whether to keep local copies as a safety backup (recommended) or remove them to preserve web host storage.

---

## 🛠️ Tools & Maintenance

Navigate to **Tools > CDNHub > Tools** for batch operations:

### Bulk Upload Existing Images
Migrate your current WordPress Media Library to GitHub with a single click. The tool processes images in safe batches with real-time progress indicators.

### Smart "Adopt" Tool
If you have already uploaded your media files to GitHub via Git CLI:
1. CDNHub queries the GitHub Git Trees API to index all remote files and their Git SHA-1 hashes.
2. It matches existing WordPress media against the remote repository by content hash.
3. Matching files are instantly marked as **synced** without re-uploading a single byte.

### Exclude Specific Images from CDN
Need your site logo, invoices, or private downloads to be hosted strictly on your origin server?
- In the Media Library list table, click **Exclude from CDN** next to any media item.
- Excluded items will never be sent to GitHub, and any CDN references in content will automatically resolve back to your local server.

---

## 🔐 Security & Privacy

- **Encrypted Credentials**: Tokens entered via the admin UI are encrypted at rest using AES-256-CBC with your WordPress installation's secure keys and salts.
- **Log Scrubbing**: All API tokens and credentials are automatically redacted and masked in activity logs.
- **wp-config.php Support**: For maximum security, define your token in `wp-config.php` so it is never saved to the database:
  ```php
  define( 'CDNHUB_GITHUB_TOKEN', 'github_pat_11A...your_token_here...' );
  ```
- **SVG Protection**: Any uploaded SVG files undergo security validation to strip embedded scripts, external entities, and potential XSS vectors.

---

## 🔌 Developer Hooks & Custom Providers

### Custom Provider Interface
CDNHub is built around the `CDNHub_Provider` interface. You can hook into `cdnhub_provider` to replace the default GitHub provider with a custom storage implementation:

```php
add_filter( 'cdnhub_provider', function( $provider, $settings ) {
    // Return an instance of an object implementing CDNHub_Provider
    return new My_Custom_Storage_Provider( $settings );
}, 10, 2 );
```

---

## 📋 System Requirements

- **WordPress**: 5.8 or higher (tested up to 6.8)
- **PHP**: 7.4 or higher (supports PHP 8.0, 8.1, 8.2, 8.3+)
- **PHP Extensions**: `curl`, `json`, `openssl`
- **GitHub Account & Repository**: Public repository for jsDelivr CDN delivery

---

## 📄 License & Terms of Use

This project is open-source software licensed under the **GNU General Public License v2.0 or later (GPL-2.0-or-later)** with mandatory author attribution terms. See the full [LICENSE](LICENSE) for details.

### Summary of Terms:
- ✅ **Free to Use**: Anyone is free to install, run, and use this plugin on personal and commercial sites.
- ✅ **Free to Improve & Modify**: Anyone can fork, customize, extend, and submit improvements to the plugin.
- ⚠️ **Author Attribution Cannot Be Changed**: Original author attribution (`bdSpark`) and repository links **must remain intact** in all copies, forks, modifications, and derivative works. You may not remove, alter, or replace the original author credit or claim original authorship.

Developed with ❤️ by [bdSpark](https://github.com/19littleghost)
