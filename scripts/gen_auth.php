<?php
$hash = password_hash('admin123', PASSWORD_DEFAULT);
$content = "<?php\n/**\n * 后台登录账号\n * 默认：admin / admin123\n */\nreturn [\n    'username' => 'admin',\n    'password_hash' => " . var_export($hash, true) . ",\n];\n";
file_put_contents(dirname(__DIR__) . '/config/auth.php', $content);
echo $hash, PHP_EOL;
echo password_verify('admin123', $hash) ? "verify_ok\n" : "verify_fail\n";
