# 部署说明

## 环境要求

- PHP 7.4+（用到 `declare(strict_types=1)` 和 `??`，7.0 也行但未测试）
- 扩展：`curl`、`json`、`mbstring`
- 可写目录：`server/.store/`

## 三种部署方式

### A. 宝塔面板（最简单）

1. 新建站点，PHP 选 8.x
2. 上传 `web/` 里的 `index.html` 到站点根目录
3. 上传 `server/` 到站点根目录下的 `api/` 目录
4. 复制 `server/config.sample.php` 为 `server/config.php`，填入 API 信息
5. 浏览器访问 `https://你的域名/`

### B. Nginx 手动配置

```nginx
server {
    listen 443 ssl http2;
    server_name prompt.example.com;

    root /var/www/prompt-forge;
    index index.html;

    # 前端
    location / {
        try_files $uri $uri/ /index.html;
    }

    # 后端
    location = /api/prompt.php {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/prompt-forge/server/prompt.php;
    }

    # 保护配置和计数文件
    location ~ /(config\.php|\.store) {
        deny all;
    }
}
```

### C. Docker

```dockerfile
FROM php:8.3-apache
RUN docker-php-ext-install curl
COPY web/ /var/www/html/
COPY server/ /var/www/html/api/
RUN mkdir -p /var/www/html/api/.store && chown -R www-data:www-data /var/www/html/api/.store
```

## 验证部署

```bash
# 健康检查（应该返回 JSON 错误而不是 500）
curl https://你的域名/api/prompt.php -X POST \
  -H 'Content-Type: application/json' \
  -d '{"mode":"anchor","input":"测试"}'
```

正常应该返回一段生成的提示词。如果返回：
- `服务未配置` → config.php 里 api_key 是空的
- `生成服务连接失败` → api_base 写错了，或者服务器出不了网
- `HTTP 500` → 看 PHP 错误日志

## 成本估算

以 `deepseek-v4-flash` 为例，一次生成约消耗 1400 tokens：

```
输入 ~420 tokens + 输出 ~980 tokens
```

按每百万 token 0.5 元计算，**一次生成约 0.0007 元**，一千次不到 1 块钱。

用 GPT-4o-mini 稍贵，约 0.001 元/次。

## 防护建议

公开部署前，务必确认：

- [ ] `server/config.php` 权限设为 640，属主是 PHP 进程用户
- [ ] `.store/` 目录加了 `.htaccess` 或 nginx deny
- [ ] 限流值按你的实际流量调整（默认 6 次/分钟/IP）
- [ ] 考虑加 Cloudflare 或类似 CDN，挡掉恶意刷量

## 自定义

### 换提示词风格

改 `server/prompt.php` 里的 `$sys` 变量。这是控制输出质量的核心，建议保留「自包含 / 抗漂移 / 可验证 / 有边界 / 无模型依赖」这五条原则。

### 加新模式

1. 在 `$MODES` 数组里加模式名
2. 在 `$taskMap` 里加对应的任务描述
3. 前端 `MODES` 对象里加一个 tab 配置

### 加缓存

高频使用建议加一层缓存，按 `mode + md5(input + context)` 做键：

```php
$cacheKey = md5($mode . $input . $ctx . $tone);
$cacheFile = $STORE . '/cache_' . $cacheKey . '.json';
if (is_file($cacheFile) && time() - filemtime($cacheFile) < 86400) {
    echo file_get_contents($cacheFile);
    exit;
}
// ... 生成后
file_put_contents($cacheFile, $result);
```
