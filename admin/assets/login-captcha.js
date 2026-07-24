/**
 * 后台登录：刷新图形验证码
 */
(function () {
  'use strict';
  function refreshCaptcha() {
    var img = document.getElementById('captcha-img');
    if (!img) return;
    img.src = 'captcha.php?t=' + Date.now();
    var input = document.getElementById('captcha');
    if (input) input.value = '';
  }
  document.querySelectorAll('.captcha-img-btn').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      refreshCaptcha();
    });
  });
  var img = document.getElementById('captcha-img');
  if (img) {
    img.addEventListener('click', refreshCaptcha);
  }
})();
