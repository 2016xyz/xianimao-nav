/**
 * 站点设置：页脚自定义链接增删
 */
(function () {
  'use strict';
  var addBtn = document.getElementById('add-footer-link');
  var table = document.getElementById('footer-links-table');
  var tpl = document.getElementById('footer-link-template');
  if (!addBtn || !table || !tpl) return;
  var tbody = table.querySelector('tbody');
  if (!tbody) return;
  addBtn.addEventListener('click', function () {
    tbody.appendChild(tpl.content.cloneNode(true));
  });
  tbody.addEventListener('click', function (e) {
    var btn = e.target.closest('.btn-remove-footer-link');
    if (!btn) return;
    var tr = btn.closest('tr');
    if (!tr) return;
    if (tbody.querySelectorAll('tr').length <= 1) {
      tr.querySelectorAll('input').forEach(function (inp) {
        inp.value = '';
      });
      return;
    }
    tr.remove();
  });
})();
