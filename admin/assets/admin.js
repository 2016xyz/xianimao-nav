(function () {
  'use strict';

  // 侧栏折叠由 admin-vue.js (Vue 3) 接管；此处仅保留业务交互

  // 表单自动提交（替代 onchange 内联）
  document.querySelectorAll('[data-auto-submit]').forEach(function (el) {
    el.addEventListener('change', function () {
      if (el.form) el.form.submit();
    });
  });

  // 打开 / 关闭模态框
  document.querySelectorAll('[data-open-modal]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-open-modal');
      var modal = document.getElementById(id);
      if (modal) modal.classList.add('open');
    });
  });

  document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var modal = btn.closest('.modal-backdrop');
      if (modal) modal.classList.remove('open');
    });
  });

  document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) backdrop.classList.remove('open');
    });
  });

  // 删除确认（表单）
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var msg = form.getAttribute('data-confirm') || '确定要删除吗？';
      if (!window.confirm(msg)) {
        e.preventDefault();
      }
    });
  });

  // 添加表格行（兼容 data-rows / #rows / 当前表 tbody）
  document.querySelectorAll('[data-add-row]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tplId = btn.getAttribute('data-template') || 'row-template';
      var tpl = document.getElementById(tplId);
      var rowsId = btn.getAttribute('data-rows') || 'rows';
      var tbody = document.getElementById(rowsId);
      if (!tbody) {
        var table = document.getElementById('editable-table');
        tbody = table ? table.querySelector('tbody') : null;
      }
      if (!tpl || !tbody) return;

      var empty = tbody.querySelector('.empty-row');
      if (empty) empty.remove();

      var node = tpl.content.cloneNode(true);
      tbody.appendChild(node);

      var last = tbody.querySelector('tr:last-child input[name="name[]"]');
      if (last) last.focus();
    });
  });

  // 删除表格行（兼容 data-remove-row / .btn-remove-row）
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-remove-row], .btn-remove-row');
    if (!btn) return;
    // AI 生成按钮勿拦截
    if (btn.classList.contains('btn-ai-desc')) return;

    var msg = btn.getAttribute('data-confirm') || '确定删除这一行？';
    if (!window.confirm(msg)) return;

    var tr = btn.closest('tr');
    if (!tr) return;
    var tbody = tr.parentElement;
    tr.remove();

    if (tbody && tbody.querySelectorAll('tr').length === 0) {
      var empty = document.createElement('tr');
      empty.className = 'empty-row';
      var colCount = 5;
      var tableEl = tbody.closest('table');
      var theadRow = tableEl && tableEl.querySelector('thead tr');
      if (theadRow && theadRow.children && theadRow.children.length) {
        colCount = theadRow.children.length;
      }
      empty.innerHTML =
        '<td colspan="' +
        colCount +
        '" class="muted" style="text-align:center;padding:28px;">暂无数据，请点击添加</td>';
      tbody.appendChild(empty);
    }
  });

  // 自营站点：AI 生成介绍
  var table = document.getElementById('editable-table');
  if (table && window.NAV_AI) {
    table.addEventListener('click', function (e) {
      var btn = e.target.closest('.btn-ai-desc');
      if (!btn) return;
      e.preventDefault();
      if (!window.NAV_AI.ready) {
        alert('请先在「AI 配置」中填写 URL、Key 并选择模型');
        return;
      }
      var tr = btn.closest('tr');
      if (!tr) return;
      var nameInput = tr.querySelector('input[name="name[]"]');
      var urlInput = tr.querySelector('input[name="url[]"]');
      var descInput = tr.querySelector('input[name="desc[]"]');
      var name = nameInput ? nameInput.value.trim() : '';
      var url = urlInput ? urlInput.value.trim() : '';
      if (!name && !url) {
        alert('请先填写站点名称或链接');
        return;
      }
      var oldText = btn.textContent;
      btn.classList.add('is-loading');
      btn.textContent = '生成中…';
      btn.disabled = true;

      var body = new FormData();
      body.append('csrf_token', window.NAV_AI.csrf || '');
      body.append('name', name);
      body.append('url', url);

      fetch(window.NAV_AI.endpoint, {
        method: 'POST',
        body: body,
        credentials: 'same-origin'
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data && data.ok && data.text) {
            if (descInput) descInput.value = data.text;
          } else {
            alert((data && data.message) || '生成失败');
          }
        })
        .catch(function () {
          alert('网络错误，生成失败');
        })
        .finally(function () {
          btn.classList.remove('is-loading');
          btn.textContent = oldText;
          btn.disabled = false;
        });
    });
  }
})();
