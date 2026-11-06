<?php
/**
 * Prompt Forge · 提示词生成后端
 *
 * 用 LLM 生成高质量提示词，而不是模板拼接。
 * 统一输出（不区模型），重点解决长上下文下的角色漂移问题。
 *
 * 部署：
 *   1) 在本目录 config.php 增加 prompt_forge_api_key / prompt_forge_api_base / prompt_forge_model
 *   2) 确保 api/.store 可写（限流计数存在那里）
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$ROOT  = dirname(__DIR__);
$STORE = __DIR__ . '/.store';
@mkdir($STORE, 0755, true);

// ---------------------------------------------------------------- 配置
$CFG = [
    'prompt_forge_api_base' => 'https://lbwapishop.online/v1',
    'prompt_forge_api_key'  => '',
    'prompt_forge_model'    => 'deepseek-v4-flash',
    'prompt_forge_rpm'      => 6,      // 每 IP 每分钟次数
    'prompt_forge_daily'    => 60,     // 每 IP 每天次数
    'prompt_forge_max_in'   => 4000,   // 输入字符上限
    'prompt_forge_timeout'  => 90,
];
if (is_file(__DIR__ . '/config.php')) {
    $ov = require __DIR__ . '/config.php';
    if (is_array($ov)) $CFG = array_merge($CFG, $ov);
}

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', (string)$_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function rate_limit(string $store, string $ip, int $rpm, int $daily): void {
    $f = $store . '/pf_rate.json';
    $fp = @fopen($f, 'c+');
    if (!$fp) return;                    // 存储不可用时放行，不阻断用户
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $db  = json_decode($raw ?: '{}', true) ?: [];
    $now = time();
    $today = gmdate('Y-m-d', $now);

    // 清理 2 天前的记录，避免文件无限增长
    foreach ($db as $k => $v) {
        if (!isset($v['t']) || $v['t'] < $now - 172800) unset($db[$k]);
    }

    $rec = $db[$ip] ?? ['win' => $now, 'cnt' => 0, 'day' => $today, 'dcnt' => 0];
    if ($now - (int)$rec['win'] >= 60) { $rec['win'] = $now; $rec['cnt'] = 0; }
    if (($rec['day'] ?? '') !== $today) { $rec['day'] = $today; $rec['dcnt'] = 0; }

    if ((int)$rec['cnt'] >= $rpm) {
        flock($fp, LOCK_UN); fclose($fp);
        fail('请求太频繁，请稍后再试', 429);
    }
    if ((int)$rec['dcnt'] >= $daily) {
        flock($fp, LOCK_UN); fclose($fp);
        fail('今日次数已用完，明天再来', 429);
    }
    $rec['cnt']++; $rec['dcnt']++;
    $db[$ip] = $rec;

    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($db, JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

// ---------------------------------------------------------------- 输入
$raw = file_get_contents('php://input');
$in  = json_decode($raw ?: '{}', true);
if (!is_array($in)) fail('请求格式错误');

$mode   = (string)($in['mode'] ?? 'anchor');
$input  = trim((string)($in['input'] ?? ''));
$ctx    = trim((string)($in['context'] ?? ''));
$tone   = (string)($in['tone'] ?? 'precise');
$lang   = (string)($in['lang'] ?? 'zh');

$MODES = ['anchor', 'compress', 'resume', 'scenario', 'diagnose'];
if (!in_array($mode, $MODES, true)) fail('未知模式');
if ($input === '') fail('请填写需求描述');
if (mb_strlen($input) > $CFG['prompt_forge_max_in']) fail('输入过长，请精简到 ' . $CFG['prompt_forge_max_in'] . ' 字以内');
if (mb_strlen($ctx) > 12000) $ctx = mb_substr($ctx, 0, 12000);

rate_limit($STORE, client_ip(), (int)$CFG['prompt_forge_rpm'], (int)$CFG['prompt_forge_daily']);

if (empty($CFG['prompt_forge_api_key'])) fail('服务未配置，请联系站长', 503);

// ---------------------------------------------------------------- 元提示词
$toneMap = [
    'precise'  => '精确、克制、信息密度高，不要客套话',
    'friendly' => '自然友好，像同事之间沟通',
    'strict'   => '严格、命令式，强调边界与格式约束',
];
$toneHint = $toneMap[$tone] ?? $toneMap['precise'];

$sys = <<<'SYS'
你是顶尖的提示词工程师，专门设计「能撑住长对话」的指令。

你的输出会被用户直接贴进 AI 客户端作为系统提示词或上下文注入。因此必须满足：

1. 自包含：不依赖用户后续补充，单看这段就能让模型明确自己是谁、要做什么、怎么输出。
2. 抗漂移：显式写明「在多轮对话中始终保持」「若你发现自己偏离，请立即回到」这类锚定语句。
3. 可验证：输出格式要具体到字段、层级、长度，而不是「请详细说明」。
4. 有边界：写清不做什么、遇到不确定时怎么办。
5. 无模型依赖：不要出现「你是 GPT/Claude/DeepSeek」这类绑定具体模型的表述。
6. 直接可用：只输出提示词正文本身，不要有「以下是提示词」这类前言，也不要解释你做了什么。

风格要求：__TONE__

用中文输出（除非用户明确要求其他语言）。
SYS;
$sys = str_replace('__TONE__', $toneHint, $sys);

$taskMap = [
    'anchor' => <<<'T'
【任务：生成角色锚定提示词】
用户要为一个长期使用的 AI 助手设定身份与规则。请生成一段系统提示词，包含：
- 角色定位（做什么、服务谁）
- 能力边界（能做什么、不做什么、遇到超出范围时怎么回应）
- 输出规范（结构、长度、语气、是否用列表/表格）
- 长对话锚定（明确要求在多轮中保持角色，偏离时自我纠正）
- 上下文优先级（当信息冲突时以什么为准）
T,

    'compress' => <<<'T'
【任务：把长对话压缩成可续接的状态快照】
用户会给你一段已有的对话内容或要点。你要输出一段「状态快照」提示词，用于在上下文快满时替换掉原始历史，让模型能无缝继续。

必须包含：
- 任务目标（当前在做什么，最终要交付什么）
- 已确认的事实与决策（列出关键结论，不要过程）
- 已排除的方案（明确写「不要再提」）
- 当前进度（完成到哪一步，下一步是什么）
- 待确认问题（还没定的事）
- 风格与格式约定（之前约定的输出规范）

要求：信息密度极高，用列表而非段落，总长度控制在 800 字内。
T,

    'resume' => <<<'T'
【任务：生成对话纠偏指令】
用户的长对话跑偏了（角色漂移、开始拒绝、输出格式乱了）。你要输出一段注入用的指令，把它拉回来。

必须包含：
- 明确重申角色与任务
- 指出当前偏差是什么（用中性描述，不要指责）
- 要求立即回到什么状态
- 给出接下来第一个动作的具体要求
- 一句防止再次漂移的锚定语

要求：短，200 字内，语气坚定但不啰嗦。不要说教。
T,

    'scenario' => <<<'T'
【任务：按场景生成专用提示词】
根据用户描述的任务，生成一段针对性提示词。包含：
- 任务定义与交付标准
- 输入假设（用户会提供什么）
- 处理步骤（分几步，每步做什么）
- 输出格式（具体到结构）
- 质量标准（什么样算合格）
- 常见失败模式与规避方式
T,

    'diagnose' => <<<'T'
【任务：诊断提示词问题并给出改进版】
用户会给你一段他们正在用的提示词，或者描述遇到的问题。你要：
1. 先用 3-5 行指出问题所在（为什么它在长对话里会失效）
2. 然后给出改写后的完整提示词
格式：
## 问题诊断
（简短列出）

## 改进版提示词
（完整可直接使用）
T,
];
$task = $taskMap[$mode] ?? $taskMap['anchor'];

$userMsg = $task . "\n\n---\n用户需求：\n" . $input;
if ($ctx !== '') {
    $userMsg .= "\n\n---\n已有对话/上下文（供参考，不要照抄）：\n" . $ctx;
}

// ---------------------------------------------------------------- 调用
$payload = json_encode([
    'model'       => $CFG['prompt_forge_model'],
    'messages'    => [
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user',   'content' => $userMsg],
    ],
    'temperature' => 0.6,
    'max_tokens'  => 2000,
    'stream'      => false,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init(rtrim($CFG['prompt_forge_api_base'], '/') . '/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => (int)$CFG['prompt_forge_timeout'],
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $CFG['prompt_forge_api_key'],
    ],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);
curl_close($ch);

if ($resp === false) fail('生成服务连接失败：' . $cerr, 502);
$data = json_decode((string)$resp, true);
if (!is_array($data)) fail('生成服务返回异常', 502);
if ($code !== 200 || !isset($data['choices'][0]['message']['content'])) {
    $m = $data['error']['message'] ?? ('上游返回 ' . $code);
    fail('生成失败：' . mb_substr((string)$m, 0, 120), 502);
}

$text = trim((string)$data['choices'][0]['message']['content']);
if ($text === '') fail('生成结果为空，请换个描述重试', 502);

// 去掉模型偶尔加的前言
$text = preg_replace('/^(好的[，,]?\s*)?(以下|下面是)[^\n]*\n+/u', '', $text) ?? $text;
$text = trim($text);

echo json_encode([
    'ok'     => true,
    'mode'   => $mode,
    'prompt' => $text,
    'chars'  => mb_strlen($text),
    'usage'  => $data['usage'] ?? null,
], JSON_UNESCAPED_UNICODE);
