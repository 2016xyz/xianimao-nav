/**
 * 子页：移动端导航折叠（不依赖 jQuery，footer 的 main.js 仍会处理主题）
 */
(function () {
    var toggle = document.getElementById('subpageNavToggle');
    var nav = document.getElementById('subpageNav');
    if (!toggle || !nav) return;

    function setOpen(open) {
        nav.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? '关闭菜单' : '打开菜单');
        var icon = toggle.querySelector('i');
        if (icon) {
            icon.className = open ? 'bi bi-x-lg' : 'bi bi-list';
        }
    }

    toggle.addEventListener('click', function () {
        setOpen(!nav.classList.contains('is-open'));
    });

    nav.querySelectorAll('a').forEach(function (a) {
        a.addEventListener('click', function () {
            if (window.matchMedia('(max-width: 767.98px)').matches) {
                setOpen(false);
            }
        });
    });

    window.addEventListener('resize', function () {
        if (window.matchMedia('(min-width: 768px)').matches) {
            setOpen(false);
        }
    });
})();
