/**
 * 主题初始化（在 head 中尽早执行，避免闪烁）
 * 外链脚本以满足 CSP script-src 'self'
 */
(function () {
  var t = null;
  try {
    t = localStorage.getItem('theme');
  } catch (e) {}
  if (!t) {
    var m = document.cookie.match(/(?:^|;\s*)theme=([^;]+)/);
    if (m) {
      try {
        t = decodeURIComponent(m[1]);
      } catch (e2) {
        t = m[1];
      }
    }
  }
  if (t !== 'light' && t !== 'dark') {
    t = null;
  }
  if (!t) {
    try {
      t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    } catch (e3) {
      t = 'light';
    }
  }
  document.documentElement.setAttribute('data-theme', t);
})();
