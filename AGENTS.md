# Real Simple RSS Reader Technical Specification & Guidelines

This document serves as the primary guidance for AI agents and developers implementing or modifying this single-directory, multi-user Real Simple RSS Reader application.

---

## 1\. Project Overview & Architecture

The objective is to build a modern, lightweight, self-hosted PHP RSS reader that operates out of a single directory on a web server. The backend uses MySQL/MariaDB, initialized via a first-run administrative setup wizard.

### Key Constraints

-   **Deployment Location:** Single directory (e.g., `/var/www/html/reader/` or `/public_html/rss/`).
-   **PHP Version:** PHP 8.4+ (using PDO for database operations). The minimum is enforced at runtime by `lib.php` and declared in `composer.json`.
-   **Database:** MySQL / MariaDB.
-   **UI Design:** Modern, flat design with standard UI colors. **No gradients.** Responsive 2-column desktop layout.

---

## 2\. Directory Structure & Security (.htaccess)

Since the entire application resides in a single web-accessible directory, file access control must be enforced via Apache `.htaccess` (or equivalent Nginx block rules if translated).

### Single Directory Layout

```text
/ (Reader Directory)
├── index.php          # Main application router and frontend container
├── api.php            # AJAX API endpoints (Feeds, Posts, Users, OPML)
├── cron.php           # Background worker for fetching feed updates
├── setup.php          # Initial setup wizard (locked after completion)
├── lib.php            # Shared library (DB, auth, sanitizer, feed parsing)
├── config.php         # Database configuration (generated on setup, protected)
├── config.sample.php  # Configuration template
├── schema.sql         # Database schema executed by the installer
├── composer.json      # PHP 8.4+ platform requirements
├── README.md          # Project documentation and installation guide
├── LICENSE            # MIT license
├── .htaccess          # Access restrictions & URL routing
├── assets/
│   ├── app.js         # Frontend logic (Fetch API, state management)
│   └── app.css        # Modern flat styling (no gradients)
├── tests/             # Standalone test suite: php tests/run.php (dev-only)
└── vendor/            # Optional dependencies (e.g., SimplePie, XML parser)
```

### `.htaccess` Security Rules

```apache
# Block access to sensitive configuration and system files
<FilesMatch "^(config\.php|\.htaccess|schema\.sql)$">
    Require all denied
</FilesMatch>

# Prevent directory browsing
Options -Indexes

# Ensure PHP files are executed, not displayed as plaintext
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
```

---

## 3\. Database Schema & First-Run Setup

When a user accesses `index.php` and `config.php` does not exist (or indicates incomplete setup), redirect automatically to `setup.php`.

### Initial Setup Flow

1.  **DB Connection:** Admin inputs MySQL Host, Port, Database Name, DB User, and DB Password.
2.  **Database Initialization:** App creates required tables automatically.
3.  **Admin Account Creation:** Prompt for initial Admin Username, Email, and Password (hashed using `password_hash()` with `PASSWORD_DEFAULT`).
4.  **Config Generation:** App writes credentials safely to `config.php` and locks `setup.php`.
5.  **Least-Privilege Notice:** Inform the admin that `CREATE` was only required for installation and that the database account can now be reduced to `SELECT, INSERT, UPDATE, DELETE`.

### Essential Schema (`schema.sql`)

```sql
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_admin TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS feeds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    feed_url TEXT NOT NULL,
    site_url TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    feed_id INT NOT NULL,
    guid VARCHAR(255) NOT NULL,
    title VARCHAR(255) NOT NULL,
    url TEXT NOT NULL,
    content LONGTEXT,
    author VARCHAR(100),
    pub_date DATETIME,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_post (feed_id, guid),
    FOREIGN KEY (feed_id) REFERENCES feeds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 4\. UI / UX Design Specifications

### Visual Language

-   **Style:** Flat, clean, minimalist design with crisp borders (`1px solid #e2e8f0`) and subtle solid hover backgrounds (`#f1f5f9`).
-   **Strict Rule:** **Do not use CSS gradients** (`linear-gradient`, `radial-gradient`). Use solid hex/RGB color palettes (e.g., Tailwind-style neutral palette).

### 2-Pane Layout Structure

```text
+------------------------------------+---------------------------------------+
| LEFT PANE (Sidebar: 300px - 380px) | RIGHT PANE (List + Article)           |
+------------------------------------+---------------------------------------+
| [Header: App Name | Admin | Logout]| [Feed title | feed actions | Unread |✓]|
| [+ Add Feed] [OPML Import/Export]  |---------------------------------------|
|                                    | Post list                             |
| All items (Unread Count)           | - Post 1                              |
| UNREAD                             | - Post 2                              |
| - Feed 1 (3 unread)                |---------------------------------------|
| READ                               | Article header + content body         |
| - Feed 2 (0 unread)                |                                       |
| INACTIVE                           |                                       |
| - Feed 3 (no post in a year)       |                                       |
| EMPTY                              |                                       |
| - Feed 4 (no posts)                |                                       |
+------------------------------------+---------------------------------------+
```

### UI Interaction Rules

1.  **Feed Selection:** Clicking a feed filters the post list or populates items in the right pane.
2.  **Auto-Mark Read:** Selecting or viewing a post immediately sends a background `POST /api.php?action=mark_read` request and decrements the feed's unread counter in the left pane.
3.  **Feed Sections:** The sidebar feed list is grouped under small headers, in this order (sections with no feeds are hidden):
    -   **Unread:** has at least one unread post.
    -   **Read:** has posts, all read, updated within the last year.
    -   **Inactive:** newest post is older than one year.
    -   **Empty:** has no posts. Classification precedence is Empty → Inactive → Unread → Read (each feed appears in exactly one section). Each section has a subtle background (Unread `#fff`, Read `#eee`, Inactive `#ddd`, Empty `#fdd`) and its title is flanked by a short horizontal rule on each side.
4.  **Feed Actions:** Quick controls for the currently selected feed are shown at the top of the middle column: **Mark all read**, **Mark all unread**, **Refresh now**, **Edit feed** (title and URL), **Delete feed**.

---

## 5\. Core Functionality & Features

### 1\. Feed Fetching & Cron Job

-   File: `cron.php`
-   Execution: Can be run via system crontab (`* * * * * php /path/to/cron.php`) or web call with a secret token query parameter (`cron.php?token=YOUR_CRON_SECRET`).
-   Logic:
    1.  Fetch all distinct feeds from `feeds` table.
    2.  Parse RSS/Atom feeds using PHP `SimpleXML` or `SimplePie`.
    3.  Upsert incoming posts matching `(feed_id, guid)` to avoid duplicates.
    4.  Limit post retention if necessary to keep the database lightweight.

### 2\. OPML Import & Export

-   **Import:** Accept an uploaded `.opml` or `.xml` file. Parse feed URLs and titles, inserting them for the currently logged-in user. Trigger an immediate background fetch for newly imported feeds.
-   **Export:** Generate a valid OPML XML document response containing all subscriptions of the current user for download (`Content-Type: text/x-opml`).

### 3\. User Management & Admin Interface

-   **Authentication:** Session-based user login (`session_start()`). Sessions are long-lived (one-year cookie + matching GC lifetime, stored in a dedicated `sessions/` directory) so logins do not expire.
-   **User Roles:**
    -   `is_admin = 0`: Regular user. Can manage their own feeds, import/export OPML, and read posts.
    -   `is_admin = 1`: Administrator. Has access to an "Admin Panel" modal/view to:
        -   Create new users.
        -   Edit user credentials/roles.
        -   Delete users and their associated feed data.

---

## 6\. Implementation Checklist for Agents

When implementing features or bug fixes in this codebase, ensure compliance with the following steps:

1.  **Database Access:** Always use prepared PDO statements to prevent SQL injection.
2.  **Security:** Sanitize HTML content rendered in the right pane (e.g., using `HTMLPurifier` or `DOMDocument` sanitization) to prevent XSS attacks from external RSS content.
3.  **API Routing:** Structure `api.php` using JSON responses (`header('Content-Type: application/json')`) handling standard CRUD actions:
    -   `GET /api.php?action=get_feeds`
    -   `GET /api.php?action=get_posts&feed_id=X`
    -   `POST /api.php?action=add_feed`
    -   `POST /api.php?action=remove_feed`
    -   `POST /api.php?action=mark_read`
    -   `POST /api.php?action=mark_feed_status` (bulk read/unread)
    -   `POST /api.php?action=import_opml`
    -   `GET /api.php?action=export_opml`
4.  **CSS Directives:** Verify that no stylesheet file contains `gradient` keywords. Maintain flat borders, clear typography, and clean contrast ratios.
5.  **JavaScript Style:** Write frontend code as ES6 classes (`assets/app.js` uses `Dom`, `Format`, `Icons`, `Favicon`, `Api`, `Toaster`, `ModalManager`, `ColumnResizer`, `Store` and the `ReaderApp` controller). Prefer `class` for stateful/cohesive components, `const`/`let`, arrow functions and template-free string building only where it aids readability; keep small pure helpers as static methods or standalone functions.
