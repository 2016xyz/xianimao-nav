<?php
/**
 * 图形验证码（GD，无 GD 时回退文本会话码）
 */

if (!function_exists('captcha_session_key')) {
    function captcha_session_key()
    {
        return 'admin_captcha_code';
    }

    function captcha_generate_code($length = 4)
    {
        // 去掉易混字符
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, $max)];
        }
        return $code;
    }

    /**
     * 创建并写入 session，返回验证码字符串
     */
    function captcha_create($length = 4)
    {
        $code = captcha_generate_code($length);
        $_SESSION[captcha_session_key()] = strtoupper($code);
        $_SESSION[captcha_session_key() . '_at'] = time();
        $_SESSION[captcha_session_key() . '_tries'] = 0;
        return $code;
    }

    /**
     * 校验验证码（不区分大小写），成功后销毁；失败累计次数，超过 12 次作废
     */
    function captcha_verify($input, $consume = true)
    {
        $expect = (string) ($_SESSION[captcha_session_key()] ?? '');
        $at = (int) ($_SESSION[captcha_session_key() . '_at'] ?? 0);
        $tries = (int) ($_SESSION[captcha_session_key() . '_tries'] ?? 0);
        $input = strtoupper(trim((string) $input));
        if ($expect === '' || $input === '') {
            return false;
        }
        if ($at > 0 && (time() - $at) > 600) {
            if ($consume) {
                captcha_clear();
            }
            return false;
        }
        if ($tries >= 12) {
            captcha_clear();
            return false;
        }
        $ok = hash_equals($expect, $input);
        if (!$ok) {
            $_SESSION[captcha_session_key() . '_tries'] = $tries + 1;
            return false;
        }
        if ($consume) {
            captcha_clear();
        }
        return true;
    }

    function captcha_clear()
    {
        unset(
            $_SESSION[captcha_session_key()],
            $_SESSION[captcha_session_key() . '_at'],
            $_SESSION[captcha_session_key() . '_tries']
        );
    }

    /**
     * 输出 PNG 验证码图片
     */
    function captcha_output_image($width = 120, $height = 44)
    {
        $code = captcha_create(4);

        if (!function_exists('imagecreatetruecolor')) {
            // 无 GD：输出简易 SVG
            header('Content-Type: image/svg+xml; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            $esc = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $width . '" height="' . (int) $height . '">';
            echo '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#eef2ff"/><stop offset="100%" stop-color="#e0f2fe"/></linearGradient></defs>';
            echo '<rect width="100%" height="100%" fill="url(#g)" rx="8"/>';
            for ($i = 0; $i < 5; $i++) {
                $x1 = random_int(0, $width);
                $y1 = random_int(0, $height);
                $x2 = random_int(0, $width);
                $y2 = random_int(0, $height);
                echo '<line x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2 . '" stroke="#a5b4fc" stroke-width="1" opacity="0.6"/>';
            }
            echo '<text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" font-family="Segoe UI, Microsoft YaHei, sans-serif" font-size="22" font-weight="700" fill="#3730a3" letter-spacing="4">' . $esc . '</text>';
            echo '</svg>';
            return;
        }

        $img = imagecreatetruecolor($width, $height);
        $bg1 = imagecolorallocate($img, 238, 242, 255);
        $bg2 = imagecolorallocate($img, 224, 242, 254);
        // 简单竖直渐变
        for ($y = 0; $y < $height; $y++) {
            $t = $y / max(1, $height - 1);
            $r = (int) (238 + (224 - 238) * $t);
            $g = (int) (242 + (242 - 242) * $t);
            $b = (int) (255 + (254 - 255) * $t);
            $c = imagecolorallocate($img, $r, $g, $b);
            imageline($img, 0, $y, $width, $y, $c);
        }

        // 干扰线
        for ($i = 0; $i < 6; $i++) {
            $c = imagecolorallocate($img, random_int(120, 180), random_int(120, 180), random_int(180, 220));
            imageline($img, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $c);
        }
        // 噪点
        for ($i = 0; $i < 80; $i++) {
            $c = imagecolorallocate($img, random_int(100, 200), random_int(100, 200), random_int(100, 200));
            imagesetpixel($img, random_int(0, $width - 1), random_int(0, $height - 1), $c);
        }

        $colors = [
            imagecolorallocate($img, 55, 48, 163),
            imagecolorallocate($img, 79, 70, 229),
            imagecolorallocate($img, 14, 116, 144),
            imagecolorallocate($img, 124, 58, 237),
        ];
        $len = strlen($code);
        $slot = $width / ($len + 1);
        for ($i = 0; $i < $len; $i++) {
            $ch = $code[$i];
            $size = 5;
            $x = (int) ($slot * ($i + 0.55) + random_int(-2, 2));
            $y = (int) ($height / 2 + random_int(-6, 6));
            $color = $colors[$i % count($colors)];
            imagestring($img, $size, $x, (int) ($y - 8), $ch, $color);
        }

        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        imagepng($img);
        imagedestroy($img);
    }
}
