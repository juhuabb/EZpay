<?php
// APP热更新版本配置
// 每次发布新wgt包后，修改下面的版本号和下载地址
return [
    // 最新wgt资源包版本号（必须大于manifest.json中的version，格式如 1.0.1）
    'wgt_version' => '1.0.0',
    
    // wgt包下载地址（把wgt文件上传到服务器public目录后填写完整URL）
    'wgt_url' => '',
    
    // 更新标题
    'update_title' => '发现新版本',
    
    // 更新内容（每行一条）
    'update_content' => "1. 优化用户体验\n2. 修复已知问题",
    
    // 是否强制更新（true=用户必须更新才能继续使用）
    'force_update' => false,
];
