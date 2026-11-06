<?php
/**
 * 复制为 config.php 后填值。config.php 已在 .gitignore 中，不会被提交。
 */
return [
    // 任意 OpenAI 兼容接口
    'prompt_forge_api_base' => 'https://api.openai.com/v1',
    'prompt_forge_api_key'  => 'sk-your-key-here',

    // 建议用小模型：这个工具的 prompt 不复杂，小模型又快又便宜
    'prompt_forge_model'    => 'gpt-4o-mini',

    // 限流
    'prompt_forge_rpm'      => 6,
    'prompt_forge_daily'    => 60,

    // 输入约束
    'prompt_forge_max_in'   => 4000,
    'prompt_forge_timeout'  => 90,
];
