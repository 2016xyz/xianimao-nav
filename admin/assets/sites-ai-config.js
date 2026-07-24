/**
 * 自营站点页：从 data 属性注入 NAV_AI（避免内联 script）
 */
(function () {
  'use strict';
  var el = document.getElementById('nav-ai-config');
  if (!el) return;
  window.NAV_AI = {
    ready: el.getAttribute('data-ready') === '1',
    csrf: el.getAttribute('data-csrf') || '',
    endpoint: el.getAttribute('data-endpoint') || 'ai_generate.php',
  };
})();
