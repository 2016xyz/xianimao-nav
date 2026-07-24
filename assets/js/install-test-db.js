/**
 * 安装向导：测试数据库连接
 */
(function () {
  'use strict';
  var btn = document.getElementById('btnTestDb');
  var result = document.getElementById('testResult');
  if (!btn || !result) return;
  btn.addEventListener('click', function () {
    result.textContent = '测试中…';
    result.style.color = '#64748b';
    var form = document.getElementById('installForm');
    if (!form) return;
    var fd = new FormData(form);
    fd.set('action', 'test_db');
    fd.set('ajax', '1');
    fetch('install.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        result.textContent = data.message || (data.ok ? '连接成功' : '连接失败');
        result.style.color = data.ok ? '#059669' : '#dc2626';
      })
      .catch(function () {
        result.textContent = '请求失败';
        result.style.color = '#dc2626';
      });
  });
})();
