/**
 * 热榜后台：行上移/下移
 */
(function () {
  'use strict';
  var tbody = document.getElementById('hot-rows');
  if (!tbody) return;
  tbody.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-move]');
    if (!btn) return;
    var tr = btn.closest('tr');
    if (!tr) return;
    if (btn.getAttribute('data-move') === 'up') {
      if (tr.previousElementSibling) {
        tbody.insertBefore(tr, tr.previousElementSibling);
      }
    } else if (tr.nextElementSibling) {
      tbody.insertBefore(tr.nextElementSibling, tr);
    }
  });
})();
