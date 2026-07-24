/**
 * 申请收录 / 在线留言：发送邮箱验证码
 */
(function () {
  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  var btn = qs('#btn-send-code');
  if (!btn) return;

  var emailInput = qs('#email');
  var hint = qs('#code-hint');
  var form = btn.closest('form');
  var scope = btn.getAttribute('data-scope') || 'message';
  var cooldown = 0;
  var timer = null;

  function setHint(text, type) {
    if (!hint) return;
    hint.textContent = text;
    hint.classList.remove('is-ok', 'is-err');
    if (type === 'ok') hint.classList.add('is-ok');
    if (type === 'err') hint.classList.add('is-err');
  }

  function tick() {
    if (cooldown <= 0) {
      btn.disabled = false;
      btn.textContent = '发送验证码';
      if (timer) {
        clearInterval(timer);
        timer = null;
      }
      return;
    }
    btn.disabled = true;
    btn.textContent = cooldown + 's 后重发';
    cooldown -= 1;
  }

  function startCooldown(sec) {
    cooldown = sec || 60;
    tick();
    timer = setInterval(tick, 1000);
  }

  function getCsrf() {
    var el = form ? form.querySelector('input[name="csrf_token"]') : null;
    return el ? el.value : '';
  }

  btn.addEventListener('click', function () {
    if (btn.disabled) return;
    var email = (emailInput && emailInput.value || '').trim();
    if (!email) {
      setHint('请先填写邮箱', 'err');
      if (emailInput) emailInput.focus();
      return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setHint('邮箱格式不正确', 'err');
      if (emailInput) emailInput.focus();
      return;
    }

    btn.disabled = true;
    btn.textContent = '发送中…';
    setHint('正在发送验证码…', '');

    var body = new FormData();
    body.append('email', email);
    body.append('scope', scope);
    body.append('csrf_token', getCsrf());

    fetch('api/send_form_code.php', {
      method: 'POST',
      body: body,
      credentials: 'same-origin'
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (data && data.ok) {
          setHint(data.message || '验证码已发送', 'ok');
          startCooldown(60);
          var codeInput = qs('#email_code');
          if (codeInput) codeInput.focus();
        } else {
          setHint((data && data.message) || '发送失败', 'err');
          btn.disabled = false;
          btn.textContent = '发送验证码';
        }
      })
      .catch(function () {
        setHint('网络错误，请稍后重试', 'err');
        btn.disabled = false;
        btn.textContent = '发送验证码';
      });
  });
})();
