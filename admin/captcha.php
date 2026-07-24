<?php
/**
 * 图形验证码输出
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/captcha.php';

// 已登录也可刷新（测试），不强制未登录
captcha_output_image(128, 46);
exit;
