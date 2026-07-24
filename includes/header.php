<?php
if (!isset($data)) {
    require_once __DIR__ . '/bootstrap.php';
    $data = load_site_data();
}
$site = $data['site'] ?? [];
$siteName = $site['name'] ?? '夏尼猫网址导航';
$siteSubtitle = $site['subtitle'] ?? '实用工具与优质站点聚合';
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($siteName); ?> - 上网从这里开始</title>
    <meta name="description" content="<?php echo e($siteSubtitle); ?>">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/theme-init.js"></script>
</head>
<body>
