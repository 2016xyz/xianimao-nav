<?php
/**
 * 本地程序版本信息（更新系统读写；勿放密钥）
 * 发布新版本时请同步递增 version / build，并尽量填写 commit。
 */
return [
    'name' => '网址导航',
    'version' => '1.2.5',
    'build' => '20260726',
    // 最近发布对应的 git commit（短/长 SHA 均可）；空则仅按 version 比较
    // 发布提交后由 chore 同步为实际 SHA
    'commit' => 'f769074',
    // 默认更新通道：master 分支最新提交，或 GitHub releases
    'channel' => 'master',
    'repo' => '2016xyz/xianimao-nav',
    'repo_url' => 'https://github.com/2016xyz/xianimao-nav',
];
