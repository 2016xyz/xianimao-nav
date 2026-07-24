$(function () {
    /* ===== Bootstrap Tooltip ===== */
    if (typeof bootstrap !== 'undefined') {
        $('[data-bs-toggle="tooltip"]').each(function () {
            new bootstrap.Tooltip(this, { trigger: 'hover' });
        });
    }

    /* ===== Search Engine Switching ===== */
    var $activeTab = $('#searchTabs .search-tab.active');
    var currentSearchUrl = $activeTab.data('url') || 'https://www.baidu.com/s?wd=';

    $('#searchTabs').on('click', '.search-tab', function () {
        $('.search-tab').removeClass('active');
        $(this).addClass('active');
        currentSearchUrl = $(this).data('url');
        var ph = $(this).data('placeholder');
        if (ph) {
            $('#searchInput').attr('placeholder', ph);
        }
    });

    function isSafeHttpUrl(url) {
        if (!url || typeof url !== 'string') return false;
        return /^https?:\/\//i.test(url.trim());
    }

    $('#searchForm').on('submit', function (e) {
        e.preventDefault();
        var q = $.trim($('#searchInput').val());
        if (!q) {
            $('#searchInput').focus();
            return;
        }
        var url = currentSearchUrl;
        if (!isSafeHttpUrl(url)) {
            return;
        }
        if (url.indexOf('{q}') !== -1) {
            url = url.split('{q}').join(encodeURIComponent(q));
        } else {
            url = url + encodeURIComponent(q);
        }
        window.open(url, '_blank');
    });

    /* ===== Theme Toggle ===== */
    function setThemeCookie(value) {
        document.cookie = 'theme=' + encodeURIComponent(value)
            + ';path=/;max-age=31536000;SameSite=Lax';
        try { localStorage.setItem('theme', value); } catch (e) { /* ignore */ }
    }

    function updateThemeIcon(theme) {
        $('#themeIcon').attr('class', theme === 'light' ? 'bi bi-moon-stars-fill' : 'bi bi-sun-fill');
    }

    var savedTheme = $('html').attr('data-theme') || 'light';
    updateThemeIcon(savedTheme);

    $('#themeToggle').on('click', function () {
        var current = $('html').attr('data-theme') || 'light';
        var next = current === 'light' ? 'dark' : 'light';
        $('html').attr('data-theme', next);
        setThemeCookie(next);
        updateThemeIcon(next);
    });

    /* ===== Hot item click ===== */
    $(document).on('click', '.hot-item', function () {
        var url = $(this).data('url');
        if (url && isSafeHttpUrl(String(url))) {
            window.open(url, '_blank');
        }
    });

    /* ===== Hot refresh: reload page for that board section ===== */
    $(document).on('click', '.hot-refresh-btn', function (e) {
        e.stopPropagation();
        var $btn = $(this);
        $btn.addClass('spinning');
        setTimeout(function () {
            window.location.reload();
        }, 400);
    });

    /* ===== Hot List Horizontal Drag Scroll ===== */
    var $wrapper = $('#hotListWrapper');
    var isDown = false, startX, scrollLeft;
    $wrapper.on('mousedown', function (e) {
        isDown = true;
        $(this).css('cursor', 'grabbing');
        startX = e.pageX - this.offsetLeft;
        scrollLeft = this.scrollLeft;
    });
    $wrapper.on('mouseleave mouseup', function () {
        isDown = false;
        $(this).css('cursor', '');
    });
    $wrapper.on('mousemove', function (e) {
        if (!isDown) return;
        e.preventDefault();
        var x = e.pageX - this.offsetLeft;
        this.scrollLeft = scrollLeft - (x - startX) * 1.5;
    });

    /* ===== Back to Top ===== */
    var $backToTop = $('#backToTop');
    $(window).on('scroll', function () {
        if ($(this).scrollTop() > 300) {
            $backToTop.addClass('visible');
        } else {
            $backToTop.removeClass('visible');
        }
    });
    $backToTop.on('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
});
