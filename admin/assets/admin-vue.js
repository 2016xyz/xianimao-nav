/**
 * 后台 Vue 3 增强层（不接管业务 HTML，避免 PHP 内容进入 Vue 模板）
 * - 侧栏折叠 / 移动端菜单
 * - Flash 关闭
 * - 按钮涟漪与样式增强
 */
(function () {
  'use strict';

  if (typeof Vue === 'undefined') {
    console.warn('[admin-vue] Vue 未加载');
    return;
  }

  var bootEl = document.getElementById('admin-boot');
  var boot = { pageTitle: '', username: '管理员', page: '', hasFlash: false };
  if (bootEl) {
    try {
      boot = JSON.parse(bootEl.textContent || '{}');
    } catch (e) { /* ignore */ }
  }

  var STORAGE_KEY = 'admin_nav_collapsed';

  Vue.createApp({
    data: function () {
      var mobile = window.matchMedia('(max-width: 900px)').matches;
      var collapsed = false;
      try {
        collapsed = localStorage.getItem(STORAGE_KEY) === '1';
      } catch (e) { /* ignore */ }
      return {
        pageTitle: boot.pageTitle || '',
        username: boot.username || '管理员',
        collapsed: mobile ? false : collapsed,
        navOpen: false,
        isMobile: mobile
      };
    },
    watch: {
      collapsed: function () { this.syncShell(); },
      navOpen: function () { this.syncShell(); },
      isMobile: function () { this.syncShell(); this.syncToggleLabel(); }
    },
    mounted: function () {
      var self = this;
      this.shell = document.getElementById('adminShell');
      this.toggleBtn = document.getElementById('adminNavToggle');
      this.toggleLabel = document.getElementById('adminNavToggleLabel');
      this.content = document.getElementById('adminContent');

      this.syncShell();
      this.syncToggleLabel();

      if (this.toggleBtn) {
        this.toggleBtn.addEventListener('click', function () {
          self.toggleNav();
        });
      }

      var nav = document.getElementById('adminNav');
      if (nav) {
        nav.querySelectorAll('a').forEach(function (a) {
          a.addEventListener('click', function () {
            if (self.isMobile) self.navOpen = false;
          });
        });
      }

      document.querySelectorAll('[data-flash-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var flash = document.getElementById('adminFlash');
          if (flash) {
            flash.classList.add('is-hiding');
            setTimeout(function () {
              if (flash.parentNode) flash.parentNode.removeChild(flash);
            }, 220);
          }
        });
      });

      // 内容区 + 顶栏/侧栏按钮（含登录页）
      this.enhanceButtons(this.shell || document.body);
      this.observeContent();

      this.mq = window.matchMedia('(max-width: 900px)');
      this.onMq = function () {
        self.isMobile = self.mq.matches;
        if (self.isMobile) {
          self.collapsed = false;
          self.navOpen = false;
        } else {
          self.navOpen = false;
          try {
            self.collapsed = localStorage.getItem(STORAGE_KEY) === '1';
          } catch (e) { /* ignore */ }
        }
        self.syncShell();
        self.syncToggleLabel();
      };
      if (typeof this.mq.addEventListener === 'function') {
        this.mq.addEventListener('change', this.onMq);
      } else if (typeof this.mq.addListener === 'function') {
        this.mq.addListener(this.onMq);
      }
    },
    methods: {
      toggleNav: function () {
        if (this.isMobile) {
          this.navOpen = !this.navOpen;
        } else {
          this.collapsed = !this.collapsed;
          try {
            localStorage.setItem(STORAGE_KEY, this.collapsed ? '1' : '0');
          } catch (e) { /* ignore */ }
        }
        this.syncShell();
        this.syncToggleLabel();
      },
      syncShell: function () {
        if (!this.shell) return;
        this.shell.classList.toggle('is-collapsed', !!this.collapsed && !this.isMobile);
        this.shell.classList.toggle('is-nav-open', !!this.navOpen && !!this.isMobile);
        if (this.toggleBtn) {
          var expanded = this.isMobile ? this.navOpen : !this.collapsed;
          this.toggleBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
      },
      syncToggleLabel: function () {
        if (!this.toggleLabel) return;
        if (this.isMobile) {
          this.toggleLabel.textContent = this.navOpen ? '收起' : '菜单';
        } else {
          this.toggleLabel.textContent = this.collapsed ? '展开' : '折叠';
        }
      },
      enhanceButtons: function (root) {
        if (!root) return;
        root.querySelectorAll('.btn, a.btn, button.btn').forEach(function (btn) {
          if (btn.dataset.vueBtn === '1') return;
          btn.dataset.vueBtn = '1';
          btn.classList.add('btn-vue');
          if (window.getComputedStyle(btn).position === 'static') {
            btn.style.position = 'relative';
          }
          btn.addEventListener('click', function (ev) {
            if (btn.disabled) return;
            var rect = btn.getBoundingClientRect();
            var ripple = document.createElement('span');
            ripple.className = 'btn-ripple';
            var size = Math.max(rect.width, rect.height) * 1.2;
            var x = (ev.clientX || rect.left + rect.width / 2) - rect.left - size / 2;
            var y = (ev.clientY || rect.top + rect.height / 2) - rect.top - size / 2;
            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = x + 'px';
            ripple.style.top = y + 'px';
            btn.appendChild(ripple);
            setTimeout(function () {
              if (ripple.parentNode) ripple.parentNode.removeChild(ripple);
            }, 560);
          });
        });
      },
      observeContent: function () {
        var root = this.content;
        if (!root || typeof MutationObserver === 'undefined') return;
        var self = this;
        this.mo = new MutationObserver(function () {
          self.enhanceButtons(root);
        });
        this.mo.observe(root, { childList: true, subtree: true });
      }
    }
  }).mount('#admin-vue-root');
})();
