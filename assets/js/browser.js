(function (window, $, wp) {
    'use strict';
    var config = window.LMB;
    if (!config || !$) return;

    var i18n = config.i18n || {};
    var listingCache = {};
    var listingCacheLimit = 30;

    function t(key, fallback) {
        return i18n[key] || fallback || key;
    }

    function request(method, endpoint, data) {
        return $.ajax({
            url: config.api + endpoint,
            method: method,
            data: method === 'GET' ? data : JSON.stringify(data),
            contentType: method === 'GET' ? 'application/x-www-form-urlencoded; charset=UTF-8' : 'application/json; charset=UTF-8',
            dataType: 'json',
            timeout: 60000,
            headers: { 'X-WP-Nonce': config.nonce }
        });
    }

    function errorText(xhr) {
        return (xhr && xhr.responseJSON && xhr.responseJSON.message) || t('err_request');
    }

    function button(text, callback, className) {
        return $('<button>', { type: 'button', 'class': className || 'button' }).text(text).on('click', callback);
    }

    function parentDir(path) {
        return path.split('/').slice(0, -1).join('/');
    }

    function itemKey(item) {
        return item.root + ':' + item.path;
    }

    function matchesType(mime, type) {
        if (!type) return true;
        if (Array.isArray(type)) return type.some(function (entry) { return matchesType(mime, entry); });
        return mime === type || mime.indexOf(String(type).replace(/\/\*$/, '') + '/') === 0;
    }

    function contextFor(frame) {
        var state = frame.state();
        var library = state && state.get('library');
        var acf = frame.acf;
        var type = frame.options.library && frame.options.library.type;
        if (!type && library && library.props) type = library.props.get('type');

        var allowed = (acf && acf.get('allowedTypes')) || '';
        var extensions = String(allowed).toLowerCase().replace(/[.\s]/g, '').split(',').filter(Boolean);
        var field = (acf && acf.get('field')) || '';
        if (!field && library && library.mirroring && library.mirroring.args) field = library.mirroring.args._acfuploader || '';

        var postId = (wp.media.view.settings.post && wp.media.view.settings.post.id) || 0;
        if (frame.options.library && frame.options.library.uploadedTo) postId = frame.options.library.uploadedTo;
        if (window.acf && typeof window.acf.get === 'function' && /^\d+$/.test(String(window.acf.get('post_id')))) {
            postId = Number(window.acf.get('post_id'));
        }

        return {
            multiple: !!(state && state.get('multiple')),
            field: typeof field === 'string' ? field : '',
            postId: Number(postId) || 0,
            accepts: function (item) {
                var ext = item.name.split('.').pop().toLowerCase();
                return matchesType(item.mime, type) && (!extensions.length || extensions.indexOf(ext) !== -1);
            }
        };
    }

    /** Reusable file browser for both the admin page and the native media modal. */
    function FileBrowser(element, options) {
        this.$el = $(element).addClass('lm-browser');
        this.options = options || {};
        this.roots = config.roots || [];
        this.root = this.roots.length ? this.roots[0].id : '';
        this.path = '';
        this.page = 1;
        this.search = '';
        this.type = '';
        this.selected = {};
        this.items = [];
        this.serial = 0;
        this.busy = false;
        this.destroyed = false;
        this.render();
    }

    FileBrowser.prototype.render = function () {
        var self = this;
        this.$el.empty();
        this.$tabs = $('<div>', { 'class': 'lm-roots', role: 'tablist' }).appendTo(this.$el);

        this.roots.forEach(function (root) {
            button(root.name, function () {
                if (self.busy) return;
                self.root = root.id; self.path = ''; self.page = 1; self.search = '';
                self.$search.val(''); self.load();
            }, 'lm-root').attr({ role: 'tab', 'data-root': root.id }).appendTo(self.$tabs);
        });

        if (config.settings) {
            $('<a>', { href: config.settings, 'class': 'lm-configure', target: '_blank', rel: 'noopener' })
                .text(t('configure_link')).appendTo(this.$tabs);
        }

        var $body = $('<div>', { 'class': 'lm-body' }).appendTo(this.$el);
        var $main = $('<div>', { 'class': 'lm-main' }).appendTo($body);
        this.$crumbs = $('<nav>', { 'class': 'lm-breadcrumbs' }).appendTo($main);

        var $tools = $('<div>', { 'class': 'lm-tools' }).appendTo($main);
        var $searchLabel = $('<label>').text(t('search_label') + ' ').appendTo($tools);
        this.$search = $('<input>', { type: 'search', placeholder: t('search_placeholder'), 'class': 'lm-search' })
            .appendTo($searchLabel)
            .on('input', function () {
                clearTimeout(self.searchTimer);
                self.search = this.value;
                self.searchTimer = setTimeout(function () { self.page = 1; self.load(); }, 220);
            });

        var $typeLabel = $('<label>').text(t('type_label') + ' ').appendTo($tools);
        var $type = $('<select>', { 'class': 'lm-type' }).appendTo($typeLabel);
        [
            ['', t('type_all')],
            ['application/pdf', t('type_pdf')],
            ['image', t('type_image')],
            ['audio', t('type_audio')],
            ['video', t('type_video')]
        ].forEach(function (entry) {
            $('<option>', { value: entry[0] }).text(entry[1]).appendTo($type);
        });
        $type.on('change', function () { self.type = this.value; self.page = 1; self.load(); });

        button(t('refresh_btn'), function () { if (!self.busy) self.load(true); }).appendTo($tools);

        this.$status = $('<p>', { 'class': 'lm-status', role: 'status', 'aria-live': 'polite' }).appendTo($main);
        this.$grid = $('<div>', { 'class': 'lm-grid', role: 'group' }).appendTo($main);
        this.$pager = $('<div>', { 'class': 'lm-pager' }).appendTo($main);
        this.$details = $('<aside>', { 'class': 'lm-details' }).appendTo($body);

        this.details(null);
        if (!this.roots.length) {
            this.status(t('no_folders'));
            return;
        }
        this.load();
    };

    FileBrowser.prototype.status = function (message, isError) {
        this.$status.text(message).toggleClass('lm-error', !!isError);
    };

    FileBrowser.prototype.breadcrumbs = function () {
        var self = this;
        var root = this.roots.filter(function (entry) { return entry.id === self.root; })[0];
        this.$tabs.find('[data-root]').each(function () {
            $(this).attr('aria-selected', $(this).attr('data-root') === self.root ? 'true' : 'false');
        });
        this.$crumbs.empty();
        if (this.path) button(t('up_btn'), function () { self.navigate(parentDir(self.path)); }, 'button lm-up').appendTo(this.$crumbs);
        button(root ? root.name : '', function () { self.navigate(''); }, 'button-link').appendTo(this.$crumbs);

        var accumulated = [];
        this.path.split('/').filter(Boolean).forEach(function (part) {
            accumulated.push(part);
            var target = accumulated.join('/');
            $('<span>').text(' / ').appendTo(self.$crumbs);
            button(part, function () { self.navigate(target); }, 'button-link').appendTo(self.$crumbs);
        });
    };

    FileBrowser.prototype.navigate = function (path) {
        if (this.busy) return;
        this.path = path; this.page = 1; this.search = ''; this.$search.val(''); this.load();
    };

    FileBrowser.prototype.load = function (force) {
        if (!this.root || this.destroyed || this.busy) return;
        var self = this;
        var params = { root: this.root, dir: this.path, page: this.page, search: this.search, type: this.type };
        var cacheKey = JSON.stringify(params);

        if (!force && this.xhr && this.loadingKey === cacheKey) return;
        var serial = ++this.serial;
        if (this.xhr) { this.xhr.abort(); this.xhr = null; }
        this.loadingKey = null;
        this.breadcrumbs();

        if (force) {
            Object.keys(listingCache).forEach(function (k) {
                if (listingCache[k].root === params.root && listingCache[k].dir === params.dir) delete listingCache[k];
            });
        }

        if (listingCache[cacheKey]) {
            var cached = listingCache[cacheKey];
            delete listingCache[cacheKey];
            listingCache[cacheKey] = cached;
            this.showListing(cached.data);
            return;
        }

        this.$grid.empty(); this.$pager.empty(); this.status(t('loading'));
        this.loadingKey = cacheKey;
        this.xhr = request('GET', 'files', params);
        this.xhr.done(function (data) {
            if (self.destroyed || serial !== self.serial) return;
            var keys = Object.keys(listingCache);
            if (keys.length >= listingCacheLimit) delete listingCache[keys[0]];
            listingCache[cacheKey] = { root: params.root, dir: params.dir, data: data };
            self.showListing(data);
        }).fail(function (xhr, status) {
            if (status !== 'abort' && !self.destroyed && serial === self.serial) self.status(errorText(xhr), true);
        }).always(function () {
            if (serial === self.serial) { self.xhr = null; self.loadingKey = null; }
        });
    };

    FileBrowser.prototype.showListing = function (data) {
        var self = this;
        this.items = data.items || [];
        this.page = data.page;
        this.$pager.empty();
        this.renderItems();

        if (data.pages > 1) {
            button(t('prev_btn'), function () { self.page--; self.load(); }).prop('disabled', data.page <= 1).appendTo(this.$pager);
            var info = t('page_info').replace('%1$d', data.page).replace('%2$d', data.pages).replace('%3$d', data.total);
            $('<span>').text(info).appendTo(this.$pager);
            button(t('next_btn'), function () { self.page++; self.load(); }).prop('disabled', data.page >= data.pages).appendTo(this.$pager);
        }
    };

    FileBrowser.prototype.renderItems = function () {
        var self = this;
        this.$grid.empty();
        var fragment = document.createDocumentFragment();

        this.items.forEach(function (item) {
            var folder = item.kind === 'folder';
            var allowed = folder || !self.options.accepts || self.options.accepts(item);
            var icon = folder ? 'portfolio' : item.mime.indexOf('image/') === 0 ? 'format-image' : item.mime.indexOf('audio/') === 0 ? 'media-audio' : item.mime.indexOf('video/') === 0 ? 'media-video' : 'media-document';

            var $item = button('', function () {
                if (self.busy) return;
                if (folder) { self.navigate(item.path); return; }
                self.choose(item);
            }, 'lm-item').attr({ title: item.name, 'data-kind': item.kind, 'data-path': item.path, 'aria-label': item.name });

            $('<span>', { 'class': 'dashicons dashicons-' + icon, 'aria-hidden': 'true' }).appendTo($item);
            $('<span>', { 'class': 'lm-name' }).text(item.name).appendTo($item);

            if (!folder) {
                $('<small>').text(item.sizeText || '').appendTo($item);
                $item.attr('aria-pressed', self.selected[itemKey(item)] ? 'true' : 'false');
                if (!allowed) {
                    $item.prop('disabled', true).attr('title', t('not_allowed_field'));
                    $('<small>').text(t('not_allowed_field')).appendTo($item);
                }
            }
            fragment.appendChild($item[0]);
        });

        this.$grid.append(fragment);
        this.updateSelection();
    };

    FileBrowser.prototype.updateSelection = function () {
        var self = this;
        this.$grid.find('[data-kind="file"]').each(function () {
            var isSelected = !!self.selected[self.root + ':' + $(this).attr('data-path')];
            $(this).attr('aria-pressed', isSelected ? 'true' : 'false');
        });
        var count = this.values().length;
        if (!this.items.length) {
            this.status(t('empty_folder'));
        } else if (this.options.inspectOnly) {
            this.status(t('status_inspect'));
        } else {
            this.status(count ? t('status_selected').replace('%d', count) : t('status_select'));
        }
    };

    FileBrowser.prototype.choose = function (item) {
        if (this.busy || (this.options.accepts && !this.options.accepts(item))) return;
        var k = itemKey(item);
        if (this.options.multiple) {
            if (this.selected[k]) {
                delete this.selected[k];
            } else {
                if (this.values().length >= 50) { this.status(t('max_selection'), true); return; }
                this.selected[k] = item;
            }
        } else {
            this.selected = {};
            this.selected[k] = item;
        }
        this.details(item);
        this.updateSelection();
        if (this.options.onChange) this.options.onChange(this.values());
    };

    FileBrowser.prototype.values = function () {
        var self = this;
        return Object.keys(this.selected).map(function (k) { return self.selected[k]; });
    };

    FileBrowser.prototype.details = function (item) {
        var self = this;
        this.$details.empty();
        $('<h3>').text(t('details_title')).appendTo(this.$details);
        if (!item) {
            $('<p>').text(t('details_empty')).appendTo(this.$details);
            return;
        }
        if (item.mime.indexOf('image/') === 0) {
            $('<img>', { src: item.url, alt: '', 'class': 'lm-preview', loading: 'lazy' }).appendTo(this.$details);
        }
        $('<strong>', { 'class': 'lm-detail-name' }).text(item.name).appendTo(this.$details);
        $('<p>').text((item.sizeText || '') + (item.date ? ' | ' + item.date : '')).appendTo(this.$details);
        $('<p>').text(item.path).appendTo(this.$details);
        $('<label>').text(t('file_url')).appendTo(this.$details);

        var $url = $('<textarea>', { readonly: true, rows: 4, 'class': 'lm-url' }).val(item.url).appendTo(this.$details);
        button(t('copy_link'), function () {
            function fallback() { $url[0].focus(); $url[0].select(); self.status(t('link_selected')); }
            if (window.navigator.clipboard && window.isSecureContext) {
                window.navigator.clipboard.writeText(item.url).then(function () { if (!self.destroyed) self.status(t('link_copied')); }, fallback);
            } else {
                fallback();
            }
        }).appendTo(this.$details);

        $('<a>', { href: item.url, target: '_blank', rel: 'noopener noreferrer', 'class': 'button' }).text(t('open_file')).appendTo(this.$details);
        $('<p>', { 'class': 'description' }).text(t('no_copy_note')).appendTo(this.$details);
    };

    FileBrowser.prototype.setBusy = function (busy) {
        this.busy = busy;
        this.$el.attr('aria-busy', busy ? 'true' : 'false').toggleClass('lm-busy', busy);
        this.$el.find('input, select').prop('disabled', busy);
    };

    FileBrowser.prototype.destroy = function () {
        this.destroyed = true; ++this.serial;
        clearTimeout(this.searchTimer);
        if (this.xhr) this.xhr.abort();
    };

    // Admin page initialization
    $(function () {
        var browser = document.getElementById('lm-browser');
        if (browser) new FileBrowser(browser, { inspectOnly: true });

        $('#lm-add-folder').on('click', function () {
            var value = window.prompt(t('add_folder_prompt'));
            if (!value || !value.trim()) return;
            var $input = $('#lm-folders');
            var existing = $input.val().trim();
            $input.val((existing ? existing + '\n' : '') + value.trim()).trigger('change');
            $input[0].focus();
        });
    });

    // WordPress Media Modal & ACF integration
    if (!wp || !wp.media || !wp.media.view || !wp.media.view.MediaFrame.Select) return;
    var mode = 'lm-files';
    var actionNames = ['select', 'insert', 'gallery', 'playlist', 'video-playlist'];

    var LocalView = wp.media.View.extend({
        className: 'lm-media-content',
        initialize: function () {
            this.context = contextFor(this.controller);
            this.epoch = 0;
            this.listenTo(this.controller, 'close', this.cancelPending);
        },
        render: function () {
            if (this.browser) return this;
            var self = this;
            this.browser = new FileBrowser(this.el, {
                accepts: this.context.accepts,
                multiple: this.context.multiple,
                onChange: function () { self.refreshButton(); }
            });
            var toolbar = this.controller.toolbar.get();
            if (this.controller.content.mode() === mode && toolbar) this.adaptToolbar(toolbar);
            return this;
        },
        refreshButton: function () {
            if (this.confirmButton && this.browser) {
                this.confirmButton.model.set('disabled', this.browser.busy || !this.browser.values().length);
            }
        },
        adaptToolbar: function (toolbar) {
            if (!toolbar || !this.browser) return;
            if (toolbar.get('lm-select')) {
                this.confirmButton = toolbar.get('lm-select');
                this.refreshButton();
                return;
            }
            var self = this;
            var name = actionNames.filter(function (c) { return !!toolbar.get(c); })[0];
            if (!name) {
                this.browser.status(t('err_custom_toolbar'), true);
                return;
            }
            var nativeButton = toolbar.get(name);
            if (!nativeButton.options || typeof nativeButton.options.click !== 'function') {
                this.browser.status(t('err_custom_toolbar'), true);
                return;
            }
            this.nativeButton = nativeButton;
            this.nativeClick = nativeButton.options.click;
            var label = nativeButton.model.get('text') || t('select_btn');

            actionNames.forEach(function (c) {
                var original = toolbar.get(c);
                if (original) original.$el.hide();
            });
            toolbar.set('lm-select', {
                text: label, style: 'primary', priority: 80, disabled: true,
                click: function () { self.confirm(); }
            });
            this.confirmButton = toolbar.get('lm-select');
            this.refreshButton();
        },
        confirm: function () {
            if (!this.browser || this.browser.busy || !this.browser.values().length) return;
            var self = this;
            var frame = this.controller;
            var state = frame.state();
            var epoch = ++this.epoch;
            var nativeButton = this.nativeButton;
            var nativeClick = this.nativeClick;

            var files = this.browser.values().map(function (item) { return { root: item.root, path: item.path }; });
            this.browser.setBusy(true);
            this.browser.status(t('preparing'));
            this.refreshButton();

            this.registration = request('POST', 'register', {
                files: files,
                post_id: this.context.postId,
                acf_field: this.context.field
            });

            this.registration.done(function (result) {
                if (self.disposed || epoch !== self.epoch || frame.state() !== state || frame.content.get() !== self) return;
                try {
                    var attachments = result && result.attachments;
                    if (!Array.isArray(attachments) || !attachments.length) throw new Error(t('err_request'));
                    var selection = state.get('selection');
                    var models = attachments.map(function (data) {
                        var model = wp.media.attachment(data.id);
                        model.set(typeof model.parse === 'function' ? model.parse(data) : data);
                        return model;
                    });
                    selection.reset(models);
                    self.registration = null;
                    nativeClick.call(nativeButton);
                    if (!self.disposed && self.browser) {
                        self.browser.selected = {};
                        self.browser.details(null);
                        self.browser.updateSelection();
                        self.refreshButton();
                    }
                } catch (err) {
                    if (!self.disposed && self.browser) self.browser.status(err.message || t('err_request'), true);
                } finally {
                    if (!self.disposed && epoch === self.epoch) {
                        self.browser.setBusy(false); self.refreshButton(); self.registration = null;
                    }
                }
            }).fail(function (xhr, status) {
                if (status !== 'abort' && !self.disposed && epoch === self.epoch) self.browser.status(errorText(xhr), true);
            }).always(function () {
                if (!self.disposed && epoch === self.epoch) {
                    self.browser.setBusy(false); self.refreshButton(); self.registration = null;
                }
            });
        },
        cancelPending: function () {
            ++this.epoch;
            if (this.registration) { this.registration.abort(); this.registration = null; }
            if (this.browser) { this.browser.setBusy(false); this.refreshButton(); }
        },
        dispose: function () {
            this.disposed = true; this.cancelPending();
            if (this.browser) this.browser.destroy();
            return wp.media.View.prototype.dispose.apply(this, arguments);
        }
    });

    function wire(frame) {
        if (frame._lmWired) return;
        frame._lmWired = true;
        frame.on('router:render:browse', function (router) {
            router.set({ 'lm-files': { text: t('tab_title'), priority: 10 } });
        });
        frame.on('content:create:' + mode, function (region) {
            frame.$el.removeClass('hide-toolbar');
            region.view = new LocalView({ controller: frame });
        });
        frame.on('content:activate:' + mode, function () {
            frame.toolbar.render();
        });
        frame.on('toolbar:render', function (toolbar) {
            var view = frame.content.get();
            if (frame.content.mode() === mode && view instanceof LocalView) view.adaptToolbar(toolbar);
        });
        frame.on('content:activate', function () {
            var toolbar = frame.toolbar.get();
            if (frame.content.mode() !== mode && toolbar && toolbar.get('lm-select')) frame.toolbar.render();
        });
    }

    var proto = wp.media.view.MediaFrame.Select.prototype;
    var bindHandlers = proto.bindHandlers;
    proto.bindHandlers = function () {
        bindHandlers.apply(this, arguments);
        wire(this);
    };
})(window, window.jQuery, window.wp);