(function () {
    'use strict';

    /* --------------------------------------------------------------- Dom */

    class Dom {
        static query(selector) {
            return document.querySelector(selector);
        }

        static escape(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        static clamp(value, min, max) {
            return Math.max(min, Math.min(max, value));
        }
    }

    /* ------------------------------------------------------------ Format */

    class Format {
        static shortDate(value) {
            if (!value) {
                return '';
            }
            const date = new Date(String(value).replace(' ', 'T'));
            if (isNaN(date.getTime())) {
                return value;
            }
            const now = new Date();
            if (date.toDateString() === now.toDateString()) {
                return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            }
            const opts = { month: 'short', day: 'numeric' };
            if (date.getFullYear() !== now.getFullYear()) {
                opts.year = 'numeric';
            }
            return date.toLocaleDateString([], opts);
        }

        static fullDate(value) {
            if (!value) {
                return '';
            }
            const date = new Date(String(value).replace(' ', 'T'));
            return isNaN(date.getTime()) ? String(value) : date.toLocaleString();
        }

        static maskToken(value) {
            return String(value).replace(/([?&]token=)[^&\s]*/, '$1\u2022\u2022\u2022\u2022\u2022\u2022');
        }
    }

    /* ------------------------------------------------------------- Icons */

    const ICON_PATHS = {
        read: '<path d="M3 8.5 6.5 12 13 4.5"/>',
        unread: '<circle cx="8" cy="8" r="5.5"/>',
        refresh: '<path d="M13.5 8a5.5 5.5 0 1 1-1.6-3.9"/><path d="M13.5 2.5V5H11"/>',
        rename: '<path d="M3 13h2.5L12 5.5 10.5 4 4 11.5V13z"/>',
        delete: '<path d="M4 5.5h8"/><path d="M6.5 5.5v-2h3v2"/><path d="M5.2 5.5 5.9 13h4.2l.7-7.5"/>'
    };

    class Icons {
        static svg(name) {
            return '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" ' +
                'stroke-linecap="round" stroke-linejoin="round">' + (ICON_PATHS[name] || '') + '</svg>';
        }
    }

    /* ----------------------------------------------------------- Favicon */

    class Favicon {
        static html(feed) {
            const letter = (feed.title || '?').trim().charAt(0).toUpperCase() || '?';
            const source = feed.site_url || feed.feed_url || '';
            let parsed = null;
            try {
                parsed = new URL(source);
            } catch (e) {
                parsed = null;
            }
            if (!parsed || (parsed.protocol !== 'http:' && parsed.protocol !== 'https:')) {
                return '<span class="feed-favicon" aria-hidden="true">' +
                    '<span class="feed-favicon-letter">' + Dom.escape(letter) + '</span></span>';
            }
            return '<span class="feed-favicon" aria-hidden="true">' +
                '<span class="feed-favicon-letter">' + Dom.escape(letter) + '</span>' +
                '<img src="' + Dom.escape(parsed.origin) + '/favicon.ico" alt="" loading="lazy" referrerpolicy="no-referrer">' +
                '</span>';
        }

        static wire(scope) {
            scope.querySelectorAll('.feed-favicon img').forEach(function (img) {
                img.addEventListener('error', function () { img.remove(); });
                if (img.complete && img.naturalWidth === 0) {
                    img.remove();
                }
            });
        }
    }

    /* --------------------------------------------------------------- Api */

    class Api {
        constructor(csrf, baseUrl) {
            this.csrf = csrf || '';
            this.baseUrl = baseUrl;
        }

        static humanizeError(code) {
            const map = {
                unauthorized: 'Your session expired. Please sign in again.',
                invalid_csrf: 'Security token expired. Please reload the page.',
                not_installed: 'The application is not installed.',
                missing_url: 'Please enter a URL.',
                already_exists: 'You are already subscribed to this feed.',
                not_found: 'That item no longer exists.',
                forbidden: 'You do not have permission to do that.',
                missing_file: 'Please choose a file to upload.',
                empty_file: 'The uploaded file is empty.',
                no_feeds_found: 'No feeds were found in that OPML file.',
                unknown_action: 'Unknown request.',
                database_error: 'A database error occurred. Changes may not be saved.',
                server_error: 'Something went wrong.'
            };
            if (map[code]) {
                return map[code];
            }
            if (!code) {
                return 'Request failed.';
            }
            return code.replace(/_/g, ' ').replace(/^./, function (c) { return c.toUpperCase(); });
        }

        async request(action, options) {
            const opts = options || {};
            const method = opts.method || 'GET';
            const params = opts.params || {};
            const url = new URL('api.php', this.baseUrl);
            url.searchParams.set('action', action);
            Object.keys(params).forEach((key) => {
                const value = params[key];
                if (value !== undefined && value !== null) {
                    url.searchParams.set(key, value);
                }
            });

            const init = {
                method: method,
                credentials: 'same-origin',
                headers: {}
            };

            if (method === 'POST') {
                init.headers['X-CSRF-Token'] = this.csrf;
                if (opts.form) {
                    opts.form.append('csrf', this.csrf);
                    init.body = opts.form;
                } else {
                    init.headers['Content-Type'] = 'application/json';
                    init.body = JSON.stringify(Object.assign({ csrf: this.csrf }, opts.body || {}));
                }
            }

            let response;
            try {
                response = await fetch(url, init);
            } catch {
                throw new Error('Could not reach the server. Check your connection.');
            }
            const contentType = response.headers.get('content-type') || '';
            const text = await response.text();
            let data = null;
            if (contentType.indexOf('application/json') !== -1) {
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    data = null;
                }
            }
            if (!response.ok) {
                if (data && data.message) {
                    throw new Error(data.message);
                }
                if (data && data.error) {
                    throw new Error(Api.humanizeError(data.error));
                }
                throw new Error('Request failed (' + response.status + ')');
            }
            return data;
        }

        get(action, params) {
            return this.request(action, { params: params });
        }

        post(action, body) {
            return this.request(action, { method: 'POST', body: body });
        }

        postForm(action, form) {
            return this.request(action, { method: 'POST', form: form });
        }
    }

    /* ----------------------------------------------------------- Toaster */

    class Toaster {
        constructor(root) {
            this.root = root;
        }

        show(message, type) {
            const el = document.createElement('div');
            el.className = 'toast ' + (type || '');
            el.textContent = message;
            this.root.appendChild(el);
            // Errors stay visible longer: they may explain unsaved changes.
            const visibleFor = type === 'error' ? 6000 : 3200;
            setTimeout(function () {
                el.style.opacity = '0';
                el.style.transition = 'opacity 0.2s ease';
                setTimeout(function () { el.remove(); }, 220);
            }, visibleFor);
        }
    }

    /* ------------------------------------------------------ ModalManager */

    class ModalManager {
        constructor(root, box) {
            this.root = root;
            this.box = box;
        }

        open(html, wide) {
            this.box.innerHTML = html;
            this.box.className = 'modal' + (wide ? ' wide' : '');
            this.root.hidden = false;
        }

        close() {
            this.root.hidden = true;
            this.box.innerHTML = '';
            this.box.className = 'modal';
        }

        confirm(options) {
            const title = options.title;
            const message = options.message;
            const confirmLabel = options.confirmLabel || 'Confirm';
            const danger = options.danger;
            return new Promise((resolve) => {
                this.open(
                    '<h2>' + Dom.escape(title) + '</h2>' +
                    '<p class="modal-sub">' + Dom.escape(message) + '</p>' +
                    '<div class="modal-actions">' +
                    '<button class="btn" data-choice="cancel">Cancel</button>' +
                    '<button class="btn ' + (danger ? 'danger' : 'primary') + '" data-choice="ok">' + Dom.escape(confirmLabel) + '</button>' +
                    '</div>'
                );
                this.box.querySelector('[data-choice="cancel"]').onclick = () => { this.close(); resolve(false); };
                this.box.querySelector('[data-choice="ok"]').onclick = () => { this.close(); resolve(true); };
                this.box.querySelector('[data-choice="ok"]').focus();
            });
        }

        prompt(options) {
            const title = options.title;
            const label = options.label;
            const value = options.value || '';
            return new Promise((resolve) => {
                this.open(
                    '<h2>' + Dom.escape(title) + '</h2>' +
                    '<label>' + Dom.escape(label) + '<input type="text" id="promptInput" value="' + Dom.escape(value) + '"></label>' +
                    '<div class="modal-actions">' +
                    '<button class="btn" data-choice="cancel">Cancel</button>' +
                    '<button class="btn primary" data-choice="ok">Save</button>' +
                    '</div>'
                );
                const input = this.box.querySelector('#promptInput');
                input.focus();
                input.select();
                const submit = () => {
                    const result = input.value.trim();
                    this.close();
                    resolve(result);
                };
                this.box.querySelector('[data-choice="cancel"]').onclick = () => { this.close(); resolve(null); };
                this.box.querySelector('[data-choice="ok"]').onclick = submit;
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        submit();
                    }
                });
            });
        }
    }

    /* ------------------------------------------------------ ColumnResizer */

    class ColumnResizer {
        constructor(root, storageKey, limits) {
            this.root = root;
            this.storageKey = storageKey;
            this.limits = limits;
        }

        restore() {
            try {
                const saved = JSON.parse(localStorage.getItem(this.storageKey) || 'null');
                if (!saved) {
                    return;
                }
                if (saved.sidebar) {
                    this.root.style.setProperty('--sidebar-width', saved.sidebar);
                }
                if (saved.list) {
                    this.root.style.setProperty('--list-width', saved.list);
                }
            } catch (e) { /* Storage unavailable. */ }
        }

        save() {
            try {
                const styles = getComputedStyle(this.root);
                localStorage.setItem(this.storageKey, JSON.stringify({
                    sidebar: styles.getPropertyValue('--sidebar-width').trim(),
                    list: styles.getPropertyValue('--list-width').trim()
                }));
            } catch (e) { /* Storage unavailable. */ }
        }

        attach() {
            document.querySelectorAll('.resizer').forEach((handle) => {
                handle.addEventListener('mousedown', (e) => {
                    if (window.matchMedia('(max-width: 900px)').matches) {
                        return;
                    }
                    const config = this.limits[handle.dataset.resize];
                    if (!config) {
                        return;
                    }
                    e.preventDefault();
                    const startX = e.clientX;
                    const startValue = parseFloat(getComputedStyle(this.root).getPropertyValue(config.cssVar)) || 0;
                    handle.classList.add('active');
                    document.body.classList.add('resizing');

                    const onMove = (ev) => {
                        const value = Dom.clamp(startValue + (ev.clientX - startX), config.min, config.max);
                        this.root.style.setProperty(config.cssVar, value + 'px');
                    };
                    const onUp = () => {
                        handle.classList.remove('active');
                        document.body.classList.remove('resizing');
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('mouseup', onUp);
                        this.save();
                    };
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('mouseup', onUp);
                });
            });
        }
    }

    /* ------------------------------------------------------------- Store */

    class Store {
        constructor() {
            this.feeds = [];
            this.totals = { unread: 0, total: 0 };
            this.posts = [];
            this.feedId = 0;
            this.postId = null;
            this.currentPost = null;
            this.unreadOnly = false;
            this.loadingPosts = false;
            this.loadingMore = false;
            this.hasMore = false;
        }

        feed(id) {
            return this.feeds.find(function (f) { return f.id === id; });
        }

        post(id) {
            return this.posts.find(function (p) { return p.id === id; });
        }

        adjustUnread(feedId, delta) {
            const feed = this.feed(feedId);
            if (feed) {
                feed.unread = Math.max(0, feed.unread + delta);
            }
            this.totals.unread = Math.max(0, this.totals.unread + delta);
        }
    }

    /* --------------------------------------------------------- ReaderApp */

    class ReaderApp {
        constructor(config) {
            this.config = config || {};
            this.api = new Api(this.config.csrf, document.baseURI);
            this.store = new Store();
            this.postsSeq = 0;
            this.pageSize = 50;
            this.toaster = new Toaster(Dom.query('#toastRoot'));
            this.modal = new ModalManager(Dom.query('#modalRoot'), Dom.query('#modalBox'));
            this.resizer = new ColumnResizer(document.documentElement, 'rsrss.columnWidths', {
                sidebar: { min: 200, max: 520, cssVar: '--sidebar-width' },
                list: { min: 240, max: 640, cssVar: '--list-width' }
            });
        }

        restoreWidths() {
            this.resizer.restore();
        }

        init() {
            this.resizer.attach();
            this.wire();
            this.setView('feeds');
            this.loadFeeds()
                .then(() => this.loadPosts())
                .catch((e) => {
                    const message = String(e.message);
                    if (message.indexOf('session') !== -1 || message.indexOf('signed in') !== -1) {
                        setTimeout(function () { location.reload(); }, 1200);
                    }
                    this.toast(e.message, 'error');
                });
        }

        /* ------------------------------------------------------- Wiring */

        wire() {
            Dom.query('#addFeedBtn').addEventListener('click', () => this.openAddFeed());
            Dom.query('#importBtn').addEventListener('click', () => Dom.query('#opmlFile').click());
            Dom.query('#exportBtn').addEventListener('click', () => {
                window.location.href = new URL('api.php?action=export_opml', location.href).href;
            });
            Dom.query('#opmlFile').addEventListener('change', (e) => {
                if (e.target.files.length) {
                    this.importOpml(e.target.files[0]);
                    e.target.value = '';
                }
            });

            Dom.query('#unreadToggle').addEventListener('change', (e) => {
                this.store.unreadOnly = e.target.checked;
                this.store.postId = null;
                this.store.currentPost = null;
                this.renderArticle();
                this.loadPosts();
            });

            Dom.query('#postList').addEventListener('scroll', () => this.maybeLoadMore());

            Dom.query('#markAllReadBtn').addEventListener('click', () => {
                this.modal.confirm({
                    title: 'Mark all read',
                    message: 'Mark every article in all of your feeds as read?',
                    confirmLabel: 'Mark all read',
                    danger: false
                }).then((ok) => {
                    if (!ok) {
                        return;
                    }
                    return this.api.post('mark_all_read', {}).then(() => {
                        this.store.feeds.forEach(function (f) { f.unread = 0; });
                        this.store.totals.unread = 0;
                        this.store.posts.forEach(function (p) { p.is_read = 1; });
                        this.renderFeeds();
                        this.renderPosts();
                        this.toast('All articles marked read.', 'success');
                    }).catch((e) => this.toast(e.message, 'error'));
                });
            });

            Dom.query('#menuToggle').addEventListener('click', () => this.setView('feeds'));
            Dom.query('#backToFeeds').addEventListener('click', () => this.setView('feeds'));
            Dom.query('#backToList').addEventListener('click', () => this.setView('list'));

            const userBtn = Dom.query('#userBtn');
            const dropdown = Dom.query('#userDropdown');
            userBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                dropdown.hidden = !dropdown.hidden;
            });
            document.addEventListener('click', (e) => {
                if (!e.target.closest('.user-menu')) {
                    dropdown.hidden = true;
                }
            });
            dropdown.querySelectorAll('[data-menu]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    dropdown.hidden = true;
                    if (btn.dataset.menu === 'admin') {
                        this.openAdmin();
                    }
                    if (btn.dataset.menu === 'account') {
                        this.openAccount();
                    }
                });
            });

            const cronBtn = Dom.query('#cronInfoBtn');
            if (cronBtn) {
                cronBtn.addEventListener('click', () => this.openCronInfo());
            }

            this.modal.root.addEventListener('click', (e) => {
                if (e.target.closest('[data-close]')) {
                    this.modal.close();
                }
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    this.modal.close();
                    this.closeMenus();
                }
            });
        }

        setView(view) {
            document.body.dataset.view = view;
        }

        closeMenus() {
            const dropdown = document.getElementById('userDropdown');
            if (dropdown) {
                dropdown.hidden = true;
            }
        }

        toast(message, type) {
            this.toaster.show(message, type);
        }

        /* --------------------------------------------------- Secret reveal */

        revealToggle(box, blockId, buttonId, masked, full) {
            const block = box.querySelector('#' + blockId);
            const btn = box.querySelector('#' + buttonId);
            btn.onclick = () => {
                const shown = btn.dataset.shown === '1';
                btn.dataset.shown = shown ? '' : '1';
                block.textContent = shown ? masked : full;
                btn.textContent = shown ? 'Show full URL' : 'Hide URL';
            };
        }

        /* ------------------------------------------------------- Loading */

        loadFeeds() {
            return this.api.get('get_feeds').then((data) => {
                this.store.feeds = data.feeds;
                this.store.totals = data.totals;
                if (this.store.feedId > 0 && !this.store.feeds.some((f) => f.id === this.store.feedId)) {
                    this.store.feedId = 0;
                }
                this.renderFeeds();
                this.updateListTitle();
            });
        }

        loadPosts(reset = true) {
            const store = this.store;
            if (!reset) {
                // Next page for infinite scroll: continue strictly after the
                // last loaded post, so pages stay correct even when posts are
                // inserted or marked read between requests.
                if (!store.hasMore || store.loadingPosts || store.loadingMore || !store.posts.length) {
                    return Promise.resolve();
                }
                store.loadingMore = true;
            } else {
                store.posts = [];
                store.loadingMore = false;
                store.loadingPosts = true;
                store.hasMore = false;
            }
            this.renderPosts();
            const seq = ++this.postsSeq;
            const params = {
                feed_id: store.feedId > 0 ? store.feedId : '',
                unread_only: store.unreadOnly ? 1 : '',
                limit: this.pageSize
            };
            if (!reset) {
                const last = store.posts[store.posts.length - 1];
                params.before_date = last.sort_date;
                params.before_id = last.id;
            }
            return this.api.get('get_posts', params).then((data) => {
                if (seq !== this.postsSeq) {
                    return null; // Superseded by a newer load.
                }
                const incoming = data.posts || [];
                if (!reset) {
                    const seen = {};
                    store.posts.forEach(function (p) { seen[p.id] = true; });
                    incoming.forEach(function (p) {
                        if (!seen[p.id]) {
                            store.posts.push(p);
                        }
                    });
                    store.loadingMore = false;
                } else {
                    store.posts = incoming;
                    store.loadingPosts = false;
                }
                store.hasMore = incoming.length >= this.pageSize;
                this.renderPosts();
                this.maybeLoadMore();
            }).catch((e) => {
                if (seq !== this.postsSeq) {
                    return null;
                }
                if (!reset) {
                    store.loadingMore = false;
                } else {
                    store.loadingPosts = false;
                }
                this.renderPosts();
                this.toast(e.message, 'error');
            });
        }

        maybeLoadMore() {
            const store = this.store;
            if (!store.hasMore || store.loadingPosts || store.loadingMore) {
                return;
            }
            const el = Dom.query('#postList');
            if (!el || el.clientHeight === 0) {
                return; // The list is not visible (mobile article view).
            }
            // Fetch the next page when the bottom is within reach, or when
            // the list is too short to scroll at all.
            if (el.scrollHeight - el.clientHeight - el.scrollTop <= 400) {
                this.loadPosts(false);
            }
        }

        refreshAll() {
            return this.loadFeeds().then(() => this.loadPosts()).then(() => {
                if (this.store.postId) {
                    return this.api.get('get_post', { post_id: this.store.postId }).then((data) => {
                        this.store.currentPost = data.post;
                        this.renderArticle();
                    }).catch(function () { /* Post may have been removed. */ });
                }
                return null;
            });
        }

        /* ---------------------------------------------------- Selection */

        selectFeed(feedId) {
            this.store.feedId = feedId;
            this.store.postId = null;
            this.store.currentPost = null;
            this.renderFeeds();
            this.updateListTitle();
            this.renderArticle();
            this.setView('list');
            this.loadPosts();
        }

        selectPost(postId) {
            const item = this.store.post(postId);
            const wasUnread = item && !item.is_read;
            this.store.postId = postId;
            this.renderPosts();
            this.api.get('get_post', { post_id: postId }).then((data) => {
                this.store.currentPost = data.post;
                this.renderArticle();
                this.setView('article');
                if (wasUnread) {
                    if (item) {
                        item.is_read = 1;
                    }
                    data.post.is_read = 1;
                    this.store.adjustUnread(data.post.feed_id, -1);
                    this.renderFeeds();
                    this.renderPosts();
                    this.updateArticleButtons();
                    this.api.post('mark_read', { post_id: postId, read: 1 })
                        .catch((e) => {
                            // Nothing was persisted. Roll the optimistic update
                            // back so the interface matches the database again,
                            // and tell the user why. Only revert while the store
                            // still shows our optimistic value, so a later action
                            // (toggle, reload) is never overwritten.
                            const current = this.store.post(postId);
                            if (current && current.is_read === 1) {
                                current.is_read = 0;
                                this.store.adjustUnread(current.feed_id, 1);
                                this.renderFeeds();
                                this.renderPosts();
                            }
                            if (this.store.currentPost
                                && this.store.currentPost.id === postId
                                && this.store.currentPost.is_read === 1) {
                                this.store.currentPost.is_read = 0;
                                this.updateArticleButtons();
                            }
                            this.toast('Could not mark the article as read. ' + e.message, 'error');
                        });
                }
            }).catch((e) => this.toast(e.message, 'error'));
        }

        toggleRead(post) {
            const newRead = post.is_read ? 0 : 1;
            return this.api.post('mark_read', { post_id: post.id, read: newRead }).then(() => {
                const item = this.store.post(post.id);
                const previouslyRead = item ? !!item.is_read : !!post.is_read;
                post.is_read = newRead;
                if (item) {
                    item.is_read = newRead;
                }
                if (previouslyRead !== !!newRead) {
                    this.store.adjustUnread(post.feed_id, newRead ? -1 : 1);
                }
                this.renderFeeds();
                if (this.store.unreadOnly && newRead) {
                    this.store.posts = this.store.posts.filter(function (p) { return p.id !== post.id; });
                }
                this.renderPosts();
                this.updateArticleButtons();
            }).catch((e) => this.toast(e.message, 'error'));
        }

        /* ----------------------------------------------------- Rendering */

        renderFeeds() {
            const el = Dom.query('#feedList');
            if (!this.store.feeds.length) {
                el.innerHTML = '<div class="list-empty">No feeds yet.<br>Use &ldquo;Add feed&rdquo; to get started.</div>';
                return;
            }

            const groups = { unread: [], read: [], inactive: [], empty: [] };
            this.store.feeds.forEach((feed) => {
                if ((feed.total || 0) === 0) {
                    groups.empty.push(feed);
                } else if (feed.inactive) {
                    groups.inactive.push(feed);
                } else if ((feed.unread || 0) > 0) {
                    groups.unread.push(feed);
                } else {
                    groups.read.push(feed);
                }
            });

            let html = this.feedRow({ id: 0, title: 'All items', unread: this.store.totals.unread }, true);
            const sections = [
                { key: 'unread', title: 'Unread', feeds: groups.unread },
                { key: 'read', title: 'Read', feeds: groups.read },
                { key: 'inactive', title: 'Inactive', feeds: groups.inactive },
                { key: 'empty', title: 'Empty', feeds: groups.empty }
            ];
            sections.forEach((section) => {
                if (!section.feeds.length) {
                    return;
                }
                html += '<div class="feed-section feed-section--' + section.key + '">';
                html += '<div class="feed-section-title">' + section.title + '</div>';
                section.feeds.forEach((feed) => {
                    html += this.feedRow(feed, false);
                });
                html += '</div>';
            });
            el.innerHTML = html;

            el.querySelectorAll('.feed-item').forEach((node) => {
                node.addEventListener('click', () => this.selectFeed(parseInt(node.dataset.id, 10)));
            });
            Favicon.wire(el);
        }

        feedRow(feed, isAll) {
            const unread = feed.unread || 0;
            let classes = 'feed-item';
            if (isAll) {
                classes += ' all-feeds';
            }
            if (this.store.feedId === feed.id) {
                classes += ' active';
            }
            if (unread > 0) {
                classes += ' has-unread';
            }

            return '<div class="' + classes + '" data-id="' + feed.id + '">' +
                '<div class="feed-main">' +
                (isAll ? '' : Favicon.html(feed)) +
                '<span class="feed-title">' + Dom.escape(feed.title) + '</span>' +
                '<span class="feed-count">' + unread + '</span>' +
                '</div>' +
                '</div>';
        }

        renderListActions() {
            const container = Dom.query('#listActions');
            if (!container) {
                return;
            }
            const feed = this.store.feedId > 0 ? this.store.feed(this.store.feedId) : null;
            if (!feed) {
                container.innerHTML = '';
                return;
            }
            container.innerHTML =
                '<button class="feed-action" data-act="read" title="Mark all as read">' + Icons.svg('read') + '</button>' +
                '<button class="feed-action" data-act="unread" title="Mark all as unread">' + Icons.svg('unread') + '</button>' +
                '<button class="feed-action" data-act="refresh" title="Refresh now">' + Icons.svg('refresh') + '</button>' +
                '<button class="feed-action" data-act="edit" title="Edit feed">' + Icons.svg('rename') + '</button>' +
                '<button class="feed-action danger" data-act="delete" title="Delete feed">' + Icons.svg('delete') + '</button>';
            container.querySelectorAll('.feed-action').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const current = this.store.feed(this.store.feedId);
                    if (current) {
                        this.handleFeedAction(btn.dataset.act, current);
                    }
                });
            });
        }

        updateListTitle() {
            let title = 'All items';
            if (this.store.feedId > 0) {
                const feed = this.store.feed(this.store.feedId);
                title = feed ? feed.title : 'Feed';
            }
            Dom.query('#listTitle').textContent = title;
            this.renderListActions();
        }

        renderPosts() {
            const el = Dom.query('#postList');
            if (this.store.loadingPosts) {
                el.innerHTML = '<div class="list-empty"><span class="spinner"></span></div>';
                return;
            }
            if (!this.store.posts.length) {
                el.innerHTML = '<div class="list-empty">No articles' + (this.store.unreadOnly ? ' unread' : '') + '.</div>';
                return;
            }
            let html = this.store.posts.map((post) => {
                const date = Format.shortDate(post.pub_date);
                let classes = 'post-item';
                if (!post.is_read) {
                    classes += ' unread';
                }
                if (this.store.postId === post.id) {
                    classes += ' active';
                }
                return '<button class="' + classes + '" data-id="' + post.id + '">' +
                    '<div class="post-title">' +
                    (post.is_read ? '' : '<span class="unread-dot"></span>') +
                    Dom.escape(post.title) +
                    '</div>' +
                    '<div class="post-meta">' +
                    '<span class="post-feed">' + Dom.escape(post.feed_title) + '</span>' +
                    (date ? '<span class="dot">&middot;</span><span>' + Dom.escape(date) + '</span>' : '') +
                    '</div>' +
                    '</button>';
            }).join('');
            if (this.store.loadingMore) {
                html += '<div class="list-loading"><span class="spinner"></span></div>';
            }
            el.innerHTML = html;

            el.querySelectorAll('.post-item').forEach((node) => {
                node.addEventListener('click', () => this.selectPost(parseInt(node.dataset.id, 10)));
            });
        }

        renderArticle() {
            const body = Dom.query('#articleBody');
            const post = this.store.currentPost;
            if (!post) {
                body.innerHTML = '<div class="empty-state"><p>Select an article to read it here.</p></div>';
                this.updateArticleButtons();
                return;
            }
            const date = Format.fullDate(post.pub_date);
            let meta = '<span>' + Dom.escape(post.feed_title) + '</span>';
            if (post.author) {
                meta += '<span>&middot;</span><span>' + Dom.escape(post.author) + '</span>';
            }
            if (date) {
                meta += '<span>&middot;</span><span>' + Dom.escape(date) + '</span>';
            }
            body.innerHTML =
                '<article>' +
                '<header class="article-header">' +
                '<h1>' + Dom.escape(post.title) + '</h1>' +
                '<div class="article-sub">' + meta + '</div>' +
                '</header>' +
                '<div class="article-content">' + (post.content || '<p><em>This article has no content.</em></p>') + '</div>' +
                '</article>';
            body.scrollTop = 0;
            this.updateArticleButtons();
        }

        updateArticleButtons() {
            const post = this.store.currentPost;
            const external = Dom.query('#openExternalBtn');
            const toggle = Dom.query('#toggleReadBtn');
            if (!post) {
                external.disabled = true;
                toggle.disabled = true;
                return;
            }
            const safeLink = /^https?:\/\//i.test(post.url || '');
            external.disabled = !safeLink;
            external.onclick = function () {
                if (safeLink) {
                    window.open(post.url, '_blank', 'noopener,noreferrer');
                }
            };
            toggle.disabled = false;
            toggle.textContent = post.is_read ? 'Mark unread' : 'Mark read';
            toggle.onclick = () => this.toggleRead(post);
        }

        /* ------------------------------------------------ Feed actions */

        handleFeedAction(act, feed) {
            if (act === 'read' || act === 'unread') {
                const read = act === 'read';
                return this.api.post('mark_feed_status', { feed_id: feed.id, read: read ? 1 : 0 })
                    .then(() => {
                        const delta = read ? -feed.unread : (feed.total - feed.unread);
                        feed.unread = read ? 0 : feed.total;
                        this.store.totals.unread = Math.max(0, this.store.totals.unread + delta);
                        if (this.store.feedId === feed.id || this.store.feedId === 0) {
                            this.store.posts.forEach((p) => {
                                if (this.store.feedId === 0 || p.feed_id === feed.id) {
                                    p.is_read = read ? 1 : 0;
                                }
                            });
                        }
                        this.renderFeeds();
                        this.renderPosts();
                        if (this.store.currentPost && this.store.currentPost.feed_id === feed.id) {
                            this.store.currentPost.is_read = read ? 1 : 0;
                            this.updateArticleButtons();
                        }
                        this.toast(read ? 'Marked feed as read.' : 'Marked feed as unread.', 'success');
                    })
                    .catch((e) => this.toast(e.message, 'error'));
            }

            if (act === 'refresh') {
                this.toast('Refreshing ' + feed.title + '...');
                return this.api.post('refresh_feed', { feed_id: feed.id }).then((res) => {
                    this.toast(res.new ? res.new + ' new article(s).' : 'No new articles.', 'success');
                    return this.refreshAll();
                }).catch((e) => this.toast(e.message, 'error'));
            }

            if (act === 'edit') {
                this.openEditFeed(feed);
                return null;
            }

            if (act === 'delete') {
                return this.modal.confirm({
                    title: 'Delete feed',
                    message: 'Delete "' + feed.title + '" and all of its articles? This cannot be undone.',
                    confirmLabel: 'Delete feed',
                    danger: true
                }).then((ok) => {
                    if (!ok) {
                        return null;
                    }
                    return this.api.post('remove_feed', { feed_id: feed.id }).then(() => {
                        if (this.store.feedId === feed.id) {
                            this.store.feedId = 0;
                        }
                        this.toast('Feed deleted.', 'success');
                        return this.refreshAll();
                    }).catch((e) => this.toast(e.message, 'error'));
                });
            }

            return null;
        }

        /* --------------------------------------------------- Add / OPML */

        openAddFeed() {
            this.modal.open(
                '<h2>Add feed</h2>' +
                '<p class="modal-sub">Enter an RSS/Atom feed URL or a website address.</p>' +
                '<label>Feed or site URL<input type="text" id="feedUrl" placeholder="https://example.com/feed" autofocus></label>' +
                '<label>Title <span style="font-weight:400">(optional)</span><input type="text" id="feedTitle" placeholder="Auto-detected"></label>' +
                '<div class="modal-actions">' +
                '<button class="btn" data-close>Cancel</button>' +
                '<button class="btn primary" id="feedSubmit">Add feed</button>' +
                '</div>'
            );

            const box = this.modal.box;
            const input = box.querySelector('#feedUrl');
            const button = box.querySelector('#feedSubmit');

            const submit = () => {
                const url = input.value.trim();
                const title = box.querySelector('#feedTitle').value.trim();
                if (!url) {
                    input.focus();
                    return;
                }
                button.disabled = true;
                button.textContent = 'Adding...';
                this.api.post('add_feed', { url: url, title: title }).then((res) => {
                    this.modal.close();
                    this.toast('Feed added' + (res.new ? ' (' + res.new + ' new)' : '') + '.', 'success');
                    return this.refreshAll();
                }).catch((e) => {
                    button.disabled = false;
                    button.textContent = 'Add feed';
                    this.toast(e.message, 'error');
                });
            };

            button.onclick = submit;
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    submit();
                }
            });
        }

        openEditFeed(feed) {
            this.modal.open(
                '<h2>Edit feed</h2>' +
                '<p class="modal-sub">Change the feed title or its URL.</p>' +
                '<label>Title<input type="text" id="editFeedTitle" value="' + Dom.escape(feed.title) + '"></label>' +
                '<label>Feed URL<input type="text" id="editFeedUrl" value="' + Dom.escape(feed.feed_url || '') + '" spellcheck="false"></label>' +
                '<div class="modal-actions">' +
                '<button class="btn" data-close>Cancel</button>' +
                '<button class="btn primary" id="editFeedSave">Save</button>' +
                '</div>'
            );

            const box = this.modal.box;
            const titleInput = box.querySelector('#editFeedTitle');
            const urlInput = box.querySelector('#editFeedUrl');
            const save = () => {
                const title = titleInput.value.trim();
                const feedUrl = urlInput.value.trim();
                if (!title || !feedUrl) {
                    (title ? urlInput : titleInput).focus();
                    return;
                }
                const button = box.querySelector('#editFeedSave');
                button.disabled = true;
                button.textContent = 'Saving...';
                this.api.post('update_feed', { feed_id: feed.id, title: title, feed_url: feedUrl }).then((res) => {
                    this.modal.close();
                    this.toast('Feed updated' + (res.new ? ' (' + res.new + ' new)' : '') + '.', 'success');
                    return this.refreshAll();
                }).catch((e) => {
                    button.disabled = false;
                    button.textContent = 'Save';
                    this.toast(e.message, 'error');
                });
            };
            box.querySelector('#editFeedSave').onclick = save;
            [titleInput, urlInput].forEach((input) => {
                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        save();
                    }
                });
            });
        }

        importOpml(file) {
            const form = new FormData();
            form.append('opml', file);
            this.toast('Importing ' + file.name + '...');
            this.api.postForm('import_opml', form).then((res) => {
                this.toast('Imported ' + res.imported + ' feed(s), skipped ' + res.skipped + '.', 'success');
                return this.refreshAll();
            }).catch((e) => this.toast(e.message, 'error'));
        }

        /* ---------------------------------------------------------- Admin */

        openAdmin() {
            this.modal.open('<h2>Admin panel</h2><p class="modal-sub">Loading&hellip;</p>', true);
            Promise.all([this.api.get('get_users'), this.api.get('get_settings')]).then((results) => {
                this.renderAdmin(results[0].users, results[1]);
            }).catch((e) => {
                this.modal.close();
                this.toast(e.message, 'error');
            });
        }

        renderAdmin(users, settings) {
            const me = this.config.user || {};
            const rows = users.map((u) => {
                const you = u.id === me.id ? ' <span class="badge">you</span>' : '';
                const role = u.is_admin ? '<span class="badge admin">admin</span>' : '<span class="badge">user</span>';
                const del = u.id === me.id ? '' : '<button class="btn small danger" data-del="' + u.id + '">Delete</button>';
                return '<tr>' +
                    '<td>' + Dom.escape(u.username) + you + '</td>' +
                    '<td>' + Dom.escape(u.email) + '</td>' +
                    '<td>' + role + '</td>' +
                    '<td>' + u.feed_count + '</td>' +
                    '<td><div class="row-actions">' +
                    '<button class="btn small" data-edit="' + u.id + '">Edit</button>' + del +
                    '</div></td>' +
                    '</tr>';
            }).join('');

            const cronUrl = new URL('cron.php?token=' + encodeURIComponent(settings.cron_secret), location.href).href;

            this.modal.open(
                '<h2>Admin panel</h2>' +
                '<p class="modal-sub">Manage users and server settings.</p>' +
                '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px">' +
                '<h3 class="section-label" style="border:0;margin:0;padding:0">Users</h3>' +
                '<button class="btn small primary" id="newUserBtn">+ New user</button>' +
                '</div>' +
                '<table class="user-table">' +
                '<thead><tr><th>User</th><th>Email</th><th>Role</th><th>Feeds</th><th></th></tr></thead>' +
                '<tbody>' + rows + '</tbody>' +
                '</table>' +
                '<h3 class="section-label">Synchronization</h3>' +
                '<label>Purge read articles older than (days, 0 = never)' +
                '<input type="number" id="retentionInput" min="0" max="3650" value="' + settings.retention_days + '"></label>' +
                '<label>Web cron URL' +
                '<div class="code-block" id="cronUrlBlock">' + Dom.escape(Format.maskToken(cronUrl)) + '</div></label>' +
                '<button type="button" class="btn small" id="cronUrlReveal">Show full URL</button>' +
                '<div class="modal-actions">' +
                '<button class="btn" data-close>Close</button>' +
                '<button class="btn primary" id="saveSettingsBtn">Save settings</button>' +
                '</div>',
                true
            );

            const box = this.modal.box;
            box.querySelector('#newUserBtn').onclick = () => this.renderUserForm(null);
            this.revealToggle(box, 'cronUrlBlock', 'cronUrlReveal', Format.maskToken(cronUrl), cronUrl);
            box.querySelectorAll('[data-edit]').forEach((btn) => {
                btn.onclick = () => {
                    const user = users.find((u) => u.id === parseInt(btn.dataset.edit, 10));
                    this.renderUserForm(user);
                };
            });
            box.querySelectorAll('[data-del]').forEach((btn) => {
                btn.onclick = () => {
                    const user = users.find((u) => u.id === parseInt(btn.dataset.del, 10));
                    this.modal.confirm({
                        title: 'Delete user',
                        message: 'Delete "' + user.username + '" and all of their feeds?',
                        confirmLabel: 'Delete user',
                        danger: true
                    }).then((ok) => {
                        if (!ok) {
                            return null;
                        }
                        return this.api.post('delete_user', { id: user.id }).then(() => {
                            this.toast('User deleted.', 'success');
                            this.openAdmin();
                        }).catch((e) => this.toast(e.message, 'error'));
                    });
                };
            });
            box.querySelector('#saveSettingsBtn').onclick = () => {
                const value = parseInt(box.querySelector('#retentionInput').value, 10) || 0;
                this.api.post('save_settings', { retention_days: value })
                    .then(() => this.toast('Settings saved.', 'success'))
                    .catch((e) => this.toast(e.message, 'error'));
            };
        }

        renderUserForm(user) {
            const isNew = !user;
            this.modal.open(
                '<h2>' + (isNew ? 'New user' : 'Edit user') + '</h2>' +
                '<p class="modal-sub">' + (isNew ? 'Create a new account.' : 'Update account details.') + '</p>' +
                '<label>Username<input type="text" id="uName" value="' + (isNew ? '' : Dom.escape(user.username)) + '"></label>' +
                '<label>Email<input type="email" id="uEmail" value="' + (isNew ? '' : Dom.escape(user.email)) + '"></label>' +
                '<label>Password ' + (isNew ? '' : '<span style="font-weight:400">(blank = unchanged)</span>') +
                '<input type="password" id="uPass"></label>' +
                '<label class="checkbox-row"><input type="checkbox" id="uAdmin"' + (user && user.is_admin ? ' checked' : '') + '> Administrator</label>' +
                '<div class="modal-actions">' +
                '<button class="btn" id="uBack">Back</button>' +
                '<button class="btn primary" id="uSave">Save</button>' +
                '</div>',
                true
            );

            const box = this.modal.box;
            box.querySelector('#uBack').onclick = () => this.openAdmin();
            box.querySelector('#uSave').onclick = () => {
                const payload = {
                    username: box.querySelector('#uName').value.trim(),
                    email: box.querySelector('#uEmail').value.trim(),
                    password: box.querySelector('#uPass').value,
                    is_admin: box.querySelector('#uAdmin').checked ? 1 : 0
                };
                const request = isNew
                    ? this.api.post('create_user', payload)
                    : this.api.post('update_user', Object.assign({ id: user.id }, payload));
                request.then(() => {
                    this.toast(isNew ? 'User created.' : 'User updated.', 'success');
                    this.openAdmin();
                }).catch((e) => this.toast(e.message, 'error'));
            };
        }

        /* -------------------------------------------------- Account / cron */

        openAccount() {
            const me = this.config.user || {};
            this.modal.open(
                '<h2>Change password</h2>' +
                '<p class="modal-sub">Signed in as ' + Dom.escape(me.username) + '.</p>' +
                '<label>Current password<input type="password" id="curPass"></label>' +
                '<label>New password<input type="password" id="newPass"></label>' +
                '<label>Confirm new password<input type="password" id="conPass"></label>' +
                '<div class="modal-actions">' +
                '<button class="btn" data-close>Cancel</button>' +
                '<button class="btn primary" id="pwSave">Update password</button>' +
                '</div>'
            );
            const box = this.modal.box;
            box.querySelector('#pwSave').onclick = () => {
                this.api.post('change_password', {
                    current_password: box.querySelector('#curPass').value,
                    new_password: box.querySelector('#newPass').value,
                    confirm_password: box.querySelector('#conPass').value
                }).then(() => {
                    this.modal.close();
                    this.toast('Password updated.', 'success');
                }).catch((e) => this.toast(e.message, 'error'));
            };
        }

        openCronInfo() {
            const url = new URL(this.config.cronUrl, location.href).href;
            const masked = Format.maskToken(url);
            const dir = location.pathname.replace(/[^/]*$/, '');
            this.modal.open(
                '<h2>Feed synchronization</h2>' +
                '<p class="modal-sub">New articles are imported by the cron worker.</p>' +
                '<h3 class="section-label">Option 1 &mdash; system crontab</h3>' +
                '<div class="code-block">* * * * * php ' + Dom.escape(dir) + 'cron.php</div>' +
                '<h3 class="section-label">Option 2 &mdash; web cron URL</h3>' +
                '<div class="code-block" id="cronInfoUrlBlock">' + Dom.escape(masked) + '</div>' +
                '<button type="button" class="btn small" id="cronInfoReveal">Show full URL</button>' +
                '<div class="modal-actions"><button class="btn primary" data-close>Done</button></div>'
            );
            this.revealToggle(this.modal.box, 'cronInfoUrlBlock', 'cronInfoReveal', masked, url);
        }
    }

    const reader = new ReaderApp(window.RSRSS || {});
    reader.restoreWidths();
    document.addEventListener('DOMContentLoaded', function () {
        reader.init();
    });
})();
