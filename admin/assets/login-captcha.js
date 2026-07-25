/**
 * 登录页：验证码刷新 + 密码显示切换
 */
(function () {
  function refreshCaptcha() {
    var img = document.getElementById('captcha-img');
    if (!img) return;
    var base = img.getAttribute('src') || 'captcha.php';
    var pure = base.split('?')[0] || 'captcha.php';
    img.src = pure + '?t=' + Date.now();
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.captcha-img-btn');
    if (btn) {
      e.preventDefault();
      refreshCaptcha();
      var input = document.getElementById('captcha');
      if (input) {
        input.focus();
        input.select && input.select();
      }
      return;
    }

    var toggle = e.target.closest('[data-pwd-toggle]');
    if (toggle) {
      e.preventDefault();
      var wrap = toggle.closest('.input-with-ico');
      var input = wrap ? wrap.querySelector('input') : document.getElementById('password');
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      toggle.classList.toggle('is-on', show);
      toggle.setAttribute('aria-label', show ? '隐藏密码' : '显示密码');
      var eye = toggle.querySelector('.ico-eye');
      var eyeOff = toggle.querySelector('.ico-eye-off');
      if (eye) eye.hidden = show;
      if (eyeOff) eyeOff.hidden = !show;
    }
  });

  // 验证码加载失败时再试一次
  document.addEventListener('DOMContentLoaded', function () {
    var img = document.getElementById('captcha-img');
    if (!img) return;
    img.addEventListener('error', function () {
      if (img.dataset.retried === '1') return;
      img.dataset.retried = '1';
      setTimeout(refreshCaptcha, 400);
    });
  });
})();
