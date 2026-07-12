<?php

declare(strict_types=1);

$config = require dirname(__DIR__).'/config.php';
if (! is_array($config)) {
    $config = [];
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonResponse(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function textResponse(string $content, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $content;
    exit;
}

function requestHeader(string $name): string
{
    $key = 'HTTP_'.strtoupper(str_replace('-', '_', $name));

    return is_string($_SERVER[$key] ?? null) ? (string) $_SERVER[$key] : '';
}

function storageDir(array $config): string
{
    return rtrim((string) ($config['storage_dir'] ?? dirname(__DIR__).'/storage/articles'), '/');
}

function storageRoot(array $config): string
{
    return dirname(storageDir($config));
}

function siteSettingsFile(array $config): string
{
    return storageRoot($config).'/site-settings.json';
}

function imageAssetsDir(array $config): string
{
    return staticRoot($config).'/assets/images';
}

function normalizeArticleTextAdUrl(string $url): string
{
    $normalized = trim($url);
    if ($normalized === '' || str_starts_with($normalized, '//')) {
        return '';
    }

    if (str_starts_with($normalized, '/')) {
        return $normalized;
    }

    if (preg_match('#^https?://#i', $normalized) === 1) {
        return $normalized;
    }

    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $normalized) === 1) {
        return '';
    }

    return '/'.ltrim($normalized, '/');
}

function normalizeArticleTextAdColor(string $color): string
{
    $color = trim($color);
    if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) !== 1) {
        return '#2563eb';
    }

    $hex = ltrim(strtolower($color), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }

    return '#'.$hex;
}

function normalizeArticleTextAdTrackingParam(string $trackingParam): string
{
    $trackingParam = ltrim(trim($trackingParam), "? \t\n\r\0\x0B");
    if (
        $trackingParam === ''
        || strlen($trackingParam) > 250
        || str_contains($trackingParam, '://')
        || str_starts_with($trackingParam, '/')
        || preg_match('/^[A-Za-z0-9._~%=&+;,:@-]+$/', $trackingParam) !== 1
    ) {
        return '';
    }

    return $trackingParam;
}

function normalizeArticleTextAdLinks(mixed $links, bool $enabledOnly = false, int $maxLinks = 10): array
{
    if (! is_array($links)) {
        return [];
    }

    $normalized = [];
    foreach ($links as $link) {
        if (! is_array($link)) {
            continue;
        }

        $text = trim((string) ($link['text'] ?? ''));
        $url = normalizeArticleTextAdUrl((string) ($link['url'] ?? ''));
        if ($text === '' || $url === '') {
            continue;
        }

        $enabled = ! empty($link['enabled']);
        if ($enabledOnly && ! $enabled) {
            continue;
        }

        $normalized[] = [
            'id' => trim((string) ($link['id'] ?? '')),
            'text' => $text,
            'url' => $url,
            'text_color' => normalizeArticleTextAdColor((string) ($link['text_color'] ?? '#2563eb')),
            'open_new_tab' => ! empty($link['open_new_tab']),
            'tracking_enabled' => ! empty($link['tracking_enabled']),
            'tracking_param' => normalizeArticleTextAdTrackingParam((string) ($link['tracking_param'] ?? '')),
            'enabled' => $enabled,
            'sort_order' => (int) ($link['sort_order'] ?? count($normalized) * 10),
        ];
    }

    usort($normalized, static fn (array $a, array $b): int => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));

    return array_slice(array_values($normalized), 0, max(0, $maxLinks));
}

function legacyArticleTextAdToLink(array $ad): array
{
    return [
        'id' => trim((string) ($ad['id'] ?? '')),
        'text' => trim((string) ($ad['text'] ?? '')),
        'url' => (string) ($ad['url'] ?? ''),
        'text_color' => (string) ($ad['text_color'] ?? '#2563eb'),
        'open_new_tab' => ! empty($ad['open_new_tab']),
        'tracking_enabled' => ! empty($ad['tracking_enabled']),
        'tracking_param' => (string) ($ad['tracking_param'] ?? ''),
        'enabled' => ! empty($ad['enabled']),
        'sort_order' => (int) ($ad['sort_order'] ?? 0),
    ];
}

function normalizeArticleTextAds(mixed $ads, bool $enabledOnly = false, int $maxModules = 30): array
{
    if (! is_array($ads)) {
        return [];
    }

    $normalized = [];
    foreach ($ads as $ad) {
        if (! is_array($ad)) {
            continue;
        }

        $placement = (string) ($ad['placement'] ?? 'content_top');
        if (! in_array($placement, ['content_top', 'content_bottom'], true)) {
            continue;
        }

        $enabled = ! empty($ad['enabled']);
        if ($enabledOnly && ! $enabled) {
            continue;
        }

        $links = normalizeArticleTextAdLinks(
            is_array($ad['links'] ?? null) ? $ad['links'] : [legacyArticleTextAdToLink($ad)],
            $enabledOnly
        );
        if ($links === []) {
            continue;
        }

        $id = trim((string) ($ad['id'] ?? ''));
        $name = trim((string) ($ad['name'] ?? ''));
        $sortOrder = (int) ($ad['sort_order'] ?? count($normalized) * 10);

        $normalized[] = [
            'schema_version' => 2,
            'id' => $id !== '' ? $id : 'article_text_module_'.md5($placement.'|'.$name.'|'.$sortOrder.'|'.json_encode($links)),
            'name' => $name !== '' ? $name : (string) ($links[0]['text'] ?? 'Text Ad Module'),
            'placement' => $placement,
            'enabled' => $enabled,
            'sort_order' => $sortOrder,
            'links' => $links,
        ];
    }

    usort($normalized, static function (array $a, array $b): int {
        $order = ((int) $a['sort_order']) <=> ((int) $b['sort_order']);

        return $order !== 0 ? $order : strcmp((string) $a['name'], (string) $b['name']);
    });

    return array_slice(array_values($normalized), 0, max(0, $maxModules));
}

function normalizeSiteSettings(array $settings, array $config = []): array
{
    $siteName = trim((string) ($settings['site_name'] ?? $config['site_name'] ?? 'GEOFlow Target Site'));
    $siteName = $siteName !== '' ? $siteName : 'GEOFlow Target Site';
    $frontMode = (string) ($settings['front_mode'] ?? $config['front_mode'] ?? 'static');
    $frontMode = in_array($frontMode, ['static', 'rewrite'], true) ? $frontMode : 'static';

    return [
        'site_name' => $siteName,
        'site_subtitle' => trim((string) ($settings['site_subtitle'] ?? $config['site_subtitle'] ?? '')),
        'site_description' => trim((string) ($settings['site_description'] ?? $config['site_description'] ?? '由 GEOFlow 自动分发和管理的目标站点。')),
        'site_keywords' => trim((string) ($settings['site_keywords'] ?? $config['site_keywords'] ?? '')),
        'copyright_info' => trim((string) ($settings['copyright_info'] ?? $config['copyright_info'] ?? '© '.date('Y').' '.$siteName)),
        'site_logo' => trim((string) ($settings['site_logo'] ?? $config['site_logo'] ?? '')),
        'site_favicon' => trim((string) ($settings['site_favicon'] ?? $config['site_favicon'] ?? '')),
        'seo_title_template' => trim((string) ($settings['seo_title_template'] ?? $config['seo_title_template'] ?? '{title} - {site_name}')),
        'seo_description_template' => trim((string) ($settings['seo_description_template'] ?? $config['seo_description_template'] ?? '{description}')),
        'featured_limit' => min(100, max(1, (int) ($settings['featured_limit'] ?? $config['featured_limit'] ?? 6))),
        'per_page' => min(200, max(1, (int) ($settings['per_page'] ?? $config['per_page'] ?? 12))),
        'article_text_ads' => normalizeArticleTextAds($settings['article_text_ads'] ?? $config['article_text_ads'] ?? []),
        'active_theme' => trim((string) ($settings['active_theme'] ?? $config['active_theme'] ?? '')),
        'front_mode' => $frontMode,
    ];
}

function siteSettings(array $config): array
{
    $settings = $config;
    $settingsFile = siteSettingsFile($config);
    if (is_file($settingsFile)) {
        $decoded = json_decode((string) file_get_contents($settingsFile), true);
        if (is_array($decoded)) {
            $settings = array_merge($settings, $decoded);
        }
    }

    return normalizeSiteSettings($settings, $config);
}

function activeTheme(array $settings): string
{
    $theme = strtolower(trim((string) ($settings['active_theme'] ?? '')));

    return $theme !== '' ? $theme : 'default';
}

function themeClass(array $settings): string
{
    $theme = activeTheme($settings);
    if (str_contains($theme, 'toutiao')) {
        return 'target-theme-toutiao';
    }
    if (str_contains($theme, 'netease')) {
        return 'target-theme-netease';
    }
    if (str_contains($theme, 'tdwh')) {
        return 'target-theme-tdwh';
    }

    if (str_contains($theme, 'apparel-sourcing-intelligence')) {
        return 'target-theme-apparel';
    }

    if (str_contains($theme, 'fashion-insight')) {
        return 'target-theme-fashion';
    }

    if (str_contains($theme, 'boutiquesourcingpro')) {
        return 'target-theme-boutique';
    }

    return 'target-theme-default';
}

function stripLeadingTitleHeading(string $content, string $title): string
{
    $title = trim($title);
    if ($title === '') {
        return $content;
    }

    $pattern = '/^\s*#\s*'.preg_quote($title, '/').'\s*(?:\r?\n)+/u';

    return (string) preg_replace($pattern, '', $content, 1);
}

function safeContentUrl(string $url): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    if ($url === '') {
        return '';
    }

    $lower = strtolower($url);
    if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:text/html')) {
        return '';
    }

    if (preg_match('~^(?:https?://|/|#)~i', $url) === 1) {
        return $url;
    }

    return '';
}

function inlineMarkdown(string $text): string
{
    $tokens = [];
    $store = static function (string $html) use (&$tokens): string {
        $token = '@@GFMD'.count($tokens).'@@';
        $tokens[$token] = $html;

        return $token;
    };

    $text = preg_replace_callback('/`([^`]+)`/u', static fn (array $m): string => $store('<code>'.h((string) $m[1]).'</code>'), $text) ?? $text;
    $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+(".*?"|\'.*?\'))?\)/u', static function (array $m) use ($store): string {
        $url = safeContentUrl((string) ($m[2] ?? ''));
        if ($url === '') {
            return h((string) ($m[1] ?? ''));
        }

        return $store('<img loading="lazy" decoding="async" src="'.h($url).'" alt="'.h((string) ($m[1] ?? '')).'">');
    }, $text) ?? $text;
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', static function (array $m) use ($store): string {
        $url = safeContentUrl((string) ($m[2] ?? ''));
        if ($url === '') {
            return (string) ($m[1] ?? '');
        }

        return $store('<a href="'.h($url).'" rel="nofollow noopener noreferrer">'.h((string) ($m[1] ?? '')).'</a>');
    }, $text) ?? $text;

    $html = h($text);
    foreach ($tokens as $token => $value) {
        $html = str_replace($token, $value, $html);
    }

    $html = preg_replace('/\*\*(.+?)\*\*/us', '<strong>$1</strong>', $html) ?? $html;
    $html = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/us', '<em>$1</em>', $html) ?? $html;

    return $html;
}

function isMarkdownTableDivider(string $line): bool
{
    $line = trim($line);
    if (! str_contains($line, '|')) {
        return false;
    }

    $cells = array_filter(array_map('trim', explode('|', trim($line, '|'))), static fn (string $cell): bool => $cell !== '');
    if ($cells === []) {
        return false;
    }

    foreach ($cells as $cell) {
        if (preg_match('/^:?-{3,}:?$/', $cell) !== 1) {
            return false;
        }
    }

    return true;
}

function markdownTableCells(string $line): array
{
    return array_map('trim', explode('|', trim($line, '|')));
}

function markdownTableToHtml(array $rows): string
{
    if (count($rows) < 2 || ! isMarkdownTableDivider((string) $rows[1])) {
        return '<p>'.inlineMarkdown(implode(' ', array_map('trim', $rows))).'</p>';
    }

    $header = markdownTableCells((string) $rows[0]);
    $bodyRows = array_slice($rows, 2);
    $html = '<div class="article-table-wrap"><table class="article-table"><thead><tr>';
    foreach ($header as $cell) {
        $html .= '<th>'.inlineMarkdown($cell).'</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($bodyRows as $row) {
        if (trim((string) $row) === '') {
            continue;
        }
        $html .= '<tr>';
        foreach (markdownTableCells((string) $row) as $cell) {
            $html .= '<td>'.inlineMarkdown($cell).'</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';

    return $html;
}

function markdownListToHtml(array $items, string $tag): string
{
    $html = '<'.$tag.'>';
    foreach ($items as $item) {
        $html .= '<li>'.inlineMarkdown((string) $item).'</li>';
    }

    return $html.'</'.$tag.'>';
}

function markdownToHtml(string $markdown, string $title = ''): string
{
    $markdown = trim(stripLeadingTitleHeading($markdown, $title));
    if ($markdown === '') {
        return '';
    }

    $lines = preg_split('/\R/u', $markdown) ?: [];
    $html = [];
    $paragraph = [];
    $flushParagraph = static function () use (&$paragraph, &$html): void {
        if ($paragraph === []) {
            return;
        }
        $html[] = '<p>'.inlineMarkdown(implode("\n", $paragraph)).'</p>';
        $paragraph = [];
    };

    for ($i = 0, $total = count($lines); $i < $total; $i++) {
        $line = rtrim((string) $lines[$i]);
        $trimmed = trim($line);
        if ($trimmed === '') {
            $flushParagraph();
            continue;
        }

        if (str_starts_with($trimmed, '```')) {
            $flushParagraph();
            $code = [];
            $i++;
            while ($i < $total && ! str_starts_with(trim((string) $lines[$i]), '```')) {
                $code[] = (string) $lines[$i];
                $i++;
            }
            $html[] = '<pre><code>'.h(implode("\n", $code)).'</code></pre>';
            continue;
        }

        if (preg_match('/^(#{1,6})\s+(.+)$/u', $trimmed, $m) === 1) {
            $flushParagraph();
            $level = min(6, max(1, strlen((string) $m[1])));
            $html[] = '<h'.$level.'>'.inlineMarkdown((string) $m[2]).'</h'.$level.'>';
            continue;
        }

        if (str_contains($trimmed, '|') && isset($lines[$i + 1]) && isMarkdownTableDivider((string) $lines[$i + 1])) {
            $flushParagraph();
            $rows = [$line, (string) $lines[$i + 1]];
            $i += 2;
            while ($i < $total && str_contains((string) $lines[$i], '|') && trim((string) $lines[$i]) !== '') {
                $rows[] = (string) $lines[$i];
                $i++;
            }
            $i--;
            $html[] = markdownTableToHtml($rows);
            continue;
        }

        if (preg_match('/^>\s?(.*)$/u', $trimmed, $m) === 1) {
            $flushParagraph();
            $quote = [(string) $m[1]];
            while (isset($lines[$i + 1]) && preg_match('/^>\s?(.*)$/u', trim((string) $lines[$i + 1]), $next) === 1) {
                $quote[] = (string) $next[1];
                $i++;
            }
            $html[] = '<blockquote><p>'.inlineMarkdown(implode("\n", $quote)).'</p></blockquote>';
            continue;
        }

        if (preg_match('/^[-*+]\s+(.+)$/u', $trimmed, $m) === 1) {
            $flushParagraph();
            $items = [(string) $m[1]];
            while (isset($lines[$i + 1]) && preg_match('/^[-*+]\s+(.+)$/u', trim((string) $lines[$i + 1]), $next) === 1) {
                $items[] = (string) $next[1];
                $i++;
            }
            $html[] = markdownListToHtml($items, 'ul');
            continue;
        }

        if (preg_match('/^\d+[.)]\s+(.+)$/u', $trimmed, $m) === 1) {
            $flushParagraph();
            $items = [(string) $m[1]];
            while (isset($lines[$i + 1]) && preg_match('/^\d+[.)]\s+(.+)$/u', trim((string) $lines[$i + 1]), $next) === 1) {
                $items[] = (string) $next[1];
                $i++;
            }
            $html[] = markdownListToHtml($items, 'ol');
            continue;
        }

        $paragraph[] = $trimmed;
    }
    $flushParagraph();

    return implode("\n", $html);
}

function sanitizeArticleHtml(string $html): string
{
    $html = preg_replace('#<(script|style|iframe|object|embed|form)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $html = preg_replace('#</?(script|style|iframe|object|embed|form)\b[^>]*>#i', '', $html) ?? $html;
    $html = preg_replace('/\s+on[a-z0-9_-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
    $html = preg_replace('/\s+(href|src)\s*=\s*([\'"])\s*(javascript:|data:text\/html)[^\'"]*\2/i', ' $1="#"', $html) ?? $html;

    return $html;
}

function articleContentHtml(array $article): string
{
    $html = is_string($article['content_html'] ?? null) ? trim((string) $article['content_html']) : '';
    if ($html !== '') {
        return sanitizeArticleHtml($html);
    }

    return markdownToHtml((string) ($article['content'] ?? ''), (string) ($article['title'] ?? ''));
}

function articleTextAdUrlWithTracking(string $url, bool $trackingEnabled, string $trackingParam): string
{
    if (! $trackingEnabled || $trackingParam === '') {
        return $url;
    }

    $fragment = '';
    $baseUrl = $url;
    $hashPosition = strpos($url, '#');
    if ($hashPosition !== false) {
        $fragment = substr($url, $hashPosition);
        $baseUrl = substr($url, 0, $hashPosition);
    }

    $separator = str_contains($baseUrl, '?')
        ? (str_ends_with($baseUrl, '?') || str_ends_with($baseUrl, '&') ? '' : '&')
        : '?';

    return $baseUrl.$separator.$trackingParam.$fragment;
}

function renderArticleTextAds(array $settings, string $placement, int $limit = 2): string
{
    if (! in_array($placement, ['content_top', 'content_bottom'], true)) {
        return '';
    }

    $ads = normalizeArticleTextAds($settings['article_text_ads'] ?? [], true);
    $matched = array_values(array_filter(
        $ads,
        static fn (array $module): bool => ($module['placement'] ?? '') === $placement && ($module['links'] ?? []) !== []
    ));

    if ($matched === []) {
        return '';
    }

    $placementClass = str_replace('_', '-', $placement);
    $html = '<div class="article-text-ads article-text-ads--'.h($placementClass).'" data-placement="'.h($placement).'">';
    foreach (array_slice($matched, 0, max(1, $limit)) as $module) {
        $html .= '<div class="article-text-ad-module" data-module-id="'.h((string) $module['id']).'">';
        foreach ((array) ($module['links'] ?? []) as $link) {
            if (! is_array($link) || empty($link['enabled'])) {
                continue;
            }

            $url = articleTextAdUrlWithTracking((string) $link['url'], (bool) $link['tracking_enabled'], (string) $link['tracking_param']);
            $target = ! empty($link['open_new_tab']) ? ' target="_blank"' : '';
            $style = '--article-text-ad-color: '.h((string) $link['text_color']).';';

            $html .= '<a class="article-text-ad-link" href="'.h($url).'" rel="noopener sponsored nofollow"'.$target.' style="'.$style.'">';
            $html .= '<span class="article-text-ad-text">'.h((string) $link['text']).'</span>';
            $html .= '</a>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

function keywordTags(string $keywords): array
{
    $keywords = trim($keywords);
    if ($keywords === '') {
        return [];
    }

    $parts = preg_split('/[,，、\n]+/u', $keywords) ?: [];
    $tags = [];
    foreach ($parts as $part) {
        $tag = trim((string) $part);
        if ($tag !== '' && ! in_array($tag, $tags, true)) {
            $tags[] = $tag;
        }
    }

    return array_slice($tags, 0, 12);
}

function articleImageUrl(array $article): string
{
    $heroImageUrl = safeContentUrl((string) ($article['hero_image_url'] ?? ''));
    if ($heroImageUrl !== '') {
        return $heroImageUrl;
    }

    $html = is_string($article['content_html'] ?? null) ? (string) $article['content_html'] : '';
    if ($html !== '' && preg_match('/<img\b[^>]*\bsrc=(["\'])(.*?)\1/iu', $html, $matches) === 1) {
        return safeContentUrl((string) ($matches[2] ?? ''));
    }

    $markdown = is_string($article['content'] ?? null) ? (string) $article['content'] : '';
    if ($markdown !== '' && preg_match('/!\[[^\]]*\]\(([^)\s]+)(?:\s+["\'][^"\']*["\'])?\)/u', $markdown, $matches) === 1) {
        return safeContentUrl((string) ($matches[1] ?? ''));
    }

    return '';
}

function articleSummary(array $article, int $limit = 160): string
{
    $summary = trim((string) ($article['excerpt'] ?? $article['meta_description'] ?? ''));
    if ($summary !== '') {
        return $summary;
    }

    return mb_substr(trim(strip_tags((string) ($article['content'] ?? ''))), 0, $limit);
}

function articleMetaDescription(array $article, int $limit = 160): string
{
    $description = trim((string) ($article['meta_description'] ?? ''));
    if ($description === '') {
        $description = trim((string) ($article['excerpt'] ?? ''));
    }
    if ($description === '') {
        $description = trim(strip_tags((string) ($article['content_html'] ?? '')));
    }
    if ($description === '') {
        $description = trim(strip_tags((string) ($article['content'] ?? '')));
    }
    $description = preg_replace('/\s+/u', ' ', $description) ?: $description;

    return mb_substr($description, 0, $limit);
}

function articleMetaKeywords(array $article): string
{
    $keywords = trim((string) ($article['keywords'] ?? ''));
    if ($keywords === '') {
        return '';
    }

    return implode(',', keywordTags($keywords));
}

function renderTemplateString(string $template, array $vars): string
{
    foreach ($vars as $key => $value) {
        $template = str_replace('{'.$key.'}', (string) $value, $template);
    }

    return $template;
}

function jsonLdScript(array $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if (! is_string($json) || $json === '') {
        return '';
    }

    return '<script type="application/ld+json">'.$json.'</script>';
}

function configuredBasePath(array $config): string
{
    $basePath = (string) ($config['base_path'] ?? '');
    if ($basePath === '') {
        $publicBaseUrl = (string) ($config['public_base_url'] ?? '');
        $parsedPath = parse_url($publicBaseUrl, PHP_URL_PATH);
        $basePath = is_string($parsedPath) ? $parsedPath : '';
    }

    $basePath = trim($basePath, '/');

    return $basePath === '' ? '' : '/'.$basePath;
}

function normalizeRequestPath(array $config, string $path): string
{
    $path = '/'.ltrim($path, '/');
    $path = rtrim($path, '/') ?: '/';
    $basePath = configuredBasePath($config);

    if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath.'/'))) {
        $path = substr($path, strlen($basePath));
        $path = is_string($path) && $path !== '' ? $path : '/';
    }

    $path = '/'.ltrim($path, '/');
    if ($path === '/index.php' || str_starts_with($path, '/index.php/')) {
        $path = substr($path, strlen('/index.php'));
        $path = is_string($path) && $path !== '' ? $path : '/';
    }

    return rtrim($path, '/') ?: '/';
}

function shouldUseIndexPhpPath(array $config): bool
{
    $basePath = configuredBasePath($config);
    if ($basePath !== '' && str_ends_with($basePath, '/index.php')) {
        return false;
    }

    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $requestPath = is_string($requestPath) ? $requestPath : '';

    return str_ends_with($scriptName, '/index.php') && str_contains($requestPath, '/index.php');
}

function sitePath(array $config, string $path): string
{
    $basePath = configuredBasePath($config);
    $path = '/'.ltrim($path, '/');
    if (shouldUseIndexPhpPath($config)) {
        $basePath = rtrim($basePath, '/').'/index.php';
    }

    return ($basePath !== '' ? $basePath : '').($path === '/' ? '/' : $path);
}

function siteUrl(array $config, string $path): string
{
    $publicBaseUrl = rtrim((string) ($config['public_base_url'] ?? ''), '/');
    if ($publicBaseUrl === '') {
        return sitePath($config, $path);
    }

    $scheme = parse_url($publicBaseUrl, PHP_URL_SCHEME);
    $host = parse_url($publicBaseUrl, PHP_URL_HOST);
    if (! is_string($scheme) || ! is_string($host) || $scheme === '' || $host === '') {
        return sitePath($config, $path);
    }

    $port = parse_url($publicBaseUrl, PHP_URL_PORT);
    $origin = $scheme.'://'.$host.(is_int($port) ? ':'.$port : '');

    return $origin.sitePath($config, $path);
}

function staticPublishEnabled(array $config): bool
{
    return (string) siteSettings($config)['front_mode'] === 'static'
        && (bool) ($config['static_publish_enabled'] ?? true);
}

function staticRoot(array $config): string
{
    return rtrim((string) ($config['static_output_dir'] ?? dirname(__DIR__)), '/');
}

function staticBasePath(array $config): string
{
    $basePath = configuredBasePath($config);
    if ($basePath === '/index.php') {
        return '';
    }
    if (str_ends_with($basePath, '/index.php')) {
        $basePath = substr($basePath, 0, -strlen('/index.php'));
    }

    return rtrim((string) $basePath, '/');
}

function staticSitePath(array $config, string $path): string
{
    $basePath = staticBasePath($config);
    $path = '/'.trim($path, '/');
    if ($path === '/') {
        return ($basePath !== '' ? $basePath : '').'/';
    }

    return ($basePath !== '' ? $basePath : '').$path.'/';
}

function frontSitePath(array $config, string $path): string
{
    return staticPublishEnabled($config) ? staticSitePath($config, $path) : sitePath($config, $path);
}

function frontAssetPath(array $config, string $path): string
{
    $basePath = staticBasePath($config);
    $path = '/'.ltrim($path, '/');

    return ($basePath !== '' ? $basePath : '').$path;
}

function frontVersionedAssetPath(array $config, string $path): string
{
    $assetPath = frontAssetPath($config, $path);
    $filePath = staticRoot($config).'/'.ltrim($path, '/');
    $settings = siteSettings($config);
    $versionSeed = implode('|', [
        (string) ($settings['active_theme'] ?? ''),
        is_file($filePath) ? (string) filemtime($filePath) : '',
    ]);
    $separator = str_contains($assetPath, '?') ? '&' : '?';

    return $assetPath.$separator.'v='.substr(hash('sha256', $versionSeed), 0, 12);
}

function frontSiteUrl(array $config, string $path): string
{
    $publicBaseUrl = rtrim((string) ($config['public_base_url'] ?? ''), '/');
    $scheme = parse_url($publicBaseUrl, PHP_URL_SCHEME);
    $host = parse_url($publicBaseUrl, PHP_URL_HOST);
    if (! is_string($scheme) || ! is_string($host) || $scheme === '' || $host === '') {
        return frontSitePath($config, $path);
    }

    $port = parse_url($publicBaseUrl, PHP_URL_PORT);
    $origin = $scheme.'://'.$host.(is_int($port) ? ':'.$port : '');

    return $origin.frontSitePath($config, $path);
}

function renderHomePageHtml(array $config): string
{
    ob_start();
    renderHomePage($config);

    return (string) ob_get_clean();
}

function renderArticlePageHtml(array $config, string $slug): string
{
    ob_start();
    renderArticlePage($config, $slug);

    return (string) ob_get_clean();
}

function writeStaticFile(array $config, string $relativePath, string $html): void
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        jsonResponse(500, ['ok' => false, 'error' => 'invalid_static_path']);
    }

    $file = staticRoot($config).'/'.$relativePath;
    $directory = dirname($file);
    if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
        jsonResponse(500, ['ok' => false, 'error' => 'static_directory_not_writable', 'path' => $relativePath]);
    }
    if (file_put_contents($file, $html) === false) {
        jsonResponse(500, ['ok' => false, 'error' => 'static_file_not_writable', 'path' => $relativePath]);
    }
}

function writeJsonFile(string $file, array $payload, string $error): void
{
    $directory = dirname($file);
    if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
        jsonResponse(500, ['ok' => false, 'error' => $error]);
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (! is_string($json) || file_put_contents($file, $json) === false) {
        jsonResponse(500, ['ok' => false, 'error' => $error]);
    }
}

function removeStaticArticle(array $config, string $slug): void
{
    if ($slug === '') {
        return;
    }

    $directory = staticRoot($config).'/article/'.safeFileName($slug);
    $file = $directory.'/index.html';
    if (is_file($file)) {
        @unlink($file);
    }
    if (is_dir($directory)) {
        @rmdir($directory);
    }
}

function removeStaticDirectory(string $directory): void
{
    if (! is_dir($directory) || is_link($directory)) {
        return;
    }

    $entries = scandir($directory);
    if (! is_array($entries)) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;
        if (is_dir($path) && ! is_link($path)) {
            removeStaticDirectory($path);
        } elseif (is_file($path) || is_link($path)) {
            @unlink($path);
        }
    }

    @rmdir($directory);
}

function pruneStaticArticlePages(array $config, array $activeSlugs): int
{
    $articleRoot = staticRoot($config).'/article';
    if (! is_dir($articleRoot)) {
        return 0;
    }

    $active = [];
    foreach ($activeSlugs as $slug) {
        $safeSlug = safeFileName((string) $slug);
        if ($safeSlug !== '') {
            $active[$safeSlug] = true;
        }
    }

    $entries = scandir($articleRoot);
    if (! is_array($entries)) {
        return 0;
    }

    $removed = 0;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || isset($active[$entry])) {
            continue;
        }

        $path = $articleRoot.'/'.$entry;
        if (! is_dir($path) || is_link($path)) {
            continue;
        }

        removeStaticDirectory($path);
        $removed++;
    }

    return $removed;
}

function rebuildStaticSite(array $config): array
{
    if (! staticPublishEnabled($config)) {
        return ['enabled' => false, 'articles' => 0];
    }

    writeStaticFile($config, 'index.html', renderHomePageHtml($config));
    writeStaticFile($config, 'llms.txt', renderLlmsText($config));
    writeStaticFile($config, 'sitemap.txt', renderSitemapText($config));

    $count = 0;
    $activeSlugs = [];
    foreach (loadArticles($config) as $article) {
        $slug = (string) ($article['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        $activeSlugs[] = $slug;
        writeStaticFile($config, 'article/'.safeFileName($slug).'/index.html', renderArticlePageHtml($config, $slug));
        $count++;
    }
    $removed = pruneStaticArticlePages($config, $activeSlugs);

    return ['enabled' => true, 'articles' => $count, 'removed' => $removed];
}

function ensureStorage(array $config): void
{
    $dir = storageDir($config);
    if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
        jsonResponse(500, ['ok' => false, 'error' => 'storage_not_writable']);
    }
}

function ensureImageAssets(array $config): void
{
    $dir = imageAssetsDir($config);
    if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
        jsonResponse(500, ['ok' => false, 'error' => 'image_assets_not_writable']);
    }
}

function maxAssetBytes(array $config): int
{
    return max(1024, (int) ($config['max_asset_bytes'] ?? 5242880));
}

function safeFileName(string $value): string
{
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value);

    return trim(is_string($safe) ? $safe : '', '-_.') ?: hash('sha256', $value);
}

function safeAssetFileName(string $value): string
{
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '-', basename($value));

    return trim(is_string($safe) ? $safe : '', '-_.') ?: hash('sha256', $value).'.img';
}

function localizeArticleAssets(array $config, array $article, array $assets): array
{
    $images = is_array($assets['images'] ?? null) ? $assets['images'] : [];
    if ($images === []) {
        return $article;
    }

    ensureImageAssets($config);
    $maxBytes = maxAssetBytes($config);
    $replacements = [];
    foreach ($images as $image) {
        if (! is_array($image)) {
            continue;
        }
        $sourceUrl = trim((string) ($image['source_url'] ?? ''));
        if ($sourceUrl === '') {
            continue;
        }

        $filename = safeAssetFileName((string) ($image['filename'] ?? hash('sha256', $sourceUrl).'.img'));
        $target = imageAssetsDir($config).'/'.$filename;
        $content = '';
        if (is_string($image['content_base64'] ?? null) && (string) $image['content_base64'] !== '') {
            $decoded = base64_decode((string) $image['content_base64'], true);
            $content = is_string($decoded) ? $decoded : '';
            if ($content !== '' && strlen($content) > $maxBytes) {
                $content = '';
            }
        } elseif (preg_match('~^https?://~i', $sourceUrl) === 1) {
            $context = stream_context_create([
                'http' => ['timeout' => 5, 'follow_location' => 1],
                'https' => ['timeout' => 5, 'follow_location' => 1],
            ]);
            $remote = @file_get_contents($sourceUrl, false, $context, 0, $maxBytes + 1);
            $content = is_string($remote) ? $remote : '';
            if ($content !== '' && strlen($content) > $maxBytes) {
                $content = '';
            }
        }

        if ($content !== '' && file_put_contents($target, $content) !== false) {
            $localizedUrl = frontAssetPath($config, '/assets/images/'.$filename);
            $replacements[$sourceUrl] = $localizedUrl;
            $path = parse_url($sourceUrl, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $replacements[$path] = $localizedUrl;
            }
        }
    }

    if ($replacements === []) {
        return $article;
    }

    foreach (['content', 'content_html', 'hero_image_url'] as $field) {
        if (is_string($article[$field] ?? null)) {
            $article[$field] = str_replace(array_keys($replacements), array_values($replacements), (string) $article[$field]);
        }
    }

    return $article;
}

function verifySignedRequest(array $config, string $method, string $path, string $body): array
{
    $expectedKeyId = (string) ($config['key_id'] ?? '');
    $secret = (string) ($config['secret'] ?? '');
    if ($expectedKeyId === '' || $secret === '') {
        jsonResponse(500, ['ok' => false, 'error' => 'agent_not_configured']);
    }

    $keyId = requestHeader('X-GEOFlow-Key-Id');
    $timestamp = requestHeader('X-GEOFlow-Timestamp');
    $nonce = requestHeader('X-GEOFlow-Nonce');
    $idempotencyKey = requestHeader('X-GEOFlow-Idempotency-Key');
    $bodyHash = requestHeader('X-GEOFlow-Body-SHA256');
    $signature = requestHeader('X-GEOFlow-Signature');
    $event = requestHeader('X-GEOFlow-Event');

    if ($keyId === '' || $timestamp === '' || $nonce === '' || $idempotencyKey === '' || $bodyHash === '' || $signature === '' || $event === '') {
        jsonResponse(401, ['ok' => false, 'error' => 'missing_signature_headers']);
    }
    if (! hash_equals($expectedKeyId, $keyId)) {
        jsonResponse(403, ['ok' => false, 'error' => 'key_id_not_allowed']);
    }

    try {
        $requestTime = new DateTimeImmutable($timestamp);
    } catch (Throwable) {
        jsonResponse(401, ['ok' => false, 'error' => 'invalid_timestamp']);
    }

    $clockSkew = max(30, (int) ($config['clock_skew_seconds'] ?? 300));
    if (abs(time() - $requestTime->getTimestamp()) > $clockSkew) {
        jsonResponse(401, ['ok' => false, 'error' => 'timestamp_out_of_range']);
    }

    $bodyForSignature = $method === 'GET' && $body === '' ? '{}' : $body;
    if (! hash_equals(hash('sha256', $bodyForSignature), $bodyHash)) {
        jsonResponse(401, ['ok' => false, 'error' => 'body_hash_mismatch']);
    }

    $expectedSignature = hash_hmac('sha256', $method."\n".$path."\n".$timestamp."\n".$nonce."\n".$bodyHash, $secret);
    if (! hash_equals($expectedSignature, $signature)) {
        jsonResponse(401, ['ok' => false, 'error' => 'signature_invalid']);
    }

    return [
        'event' => $event,
        'idempotency_key' => $idempotencyKey,
    ];
}

function articleFiles(array $config): array
{
    $files = glob(storageDir($config).'/*.json');

    return is_array($files) ? $files : [];
}

function loadArticles(array $config): array
{
    $articles = [];
    foreach (articleFiles($config) as $file) {
        $record = json_decode((string) file_get_contents($file), true);
        if (! is_array($record) || ! is_array($record['article'] ?? null)) {
            continue;
        }
        $record['article']['_file'] = $file;
        $articles[] = $record['article'];
    }

    usort($articles, fn (array $a, array $b): int => strcmp((string) ($b['published_at'] ?? $b['updated_at'] ?? ''), (string) ($a['published_at'] ?? $a['updated_at'] ?? '')));

    return $articles;
}

function findArticle(array $config, string $slug): ?array
{
    foreach (loadArticles($config) as $article) {
        if ((string) ($article['slug'] ?? '') === $slug) {
            return $article;
        }
    }

    return null;
}

function articleCategoryName(array $article): string
{
    return is_array($article['category'] ?? null) ? (string) ($article['category']['name'] ?? 'Insight') : 'Insight';
}

function articleCategorySlug(array $article): string
{
    return is_array($article['category'] ?? null) ? (string) ($article['category']['slug'] ?? '') : '';
}

function articleDate(array $article, string $format = 'Y-m-d'): string
{
    $date = substr((string) ($article['published_at'] ?? $article['updated_at'] ?? ''), 0, 10);
    if ($date === '') {
        return '';
    }
    $timestamp = strtotime($date);

    return $timestamp ? date($format, $timestamp) : $date;
}

function pageSeoPayload(array $settings, string $title, array $pageMeta = []): array
{
    $siteName = (string) $settings['site_name'];
    $hasMetaDescription = array_key_exists('description', $pageMeta);
    $hasMetaKeywords = array_key_exists('keywords', $pageMeta);
    $metaDescription = trim((string) ($pageMeta['description'] ?? ''));
    $metaKeywords = trim((string) ($pageMeta['keywords'] ?? ''));
    $canonicalUrl = trim((string) ($pageMeta['canonical_url'] ?? ''));
    $ogType = trim((string) ($pageMeta['og_type'] ?? 'website'));
    $isArticle = $ogType === 'article' || ! empty($pageMeta['article_page']);

    $titleTemplate = (string) ($settings['seo_title_template'] ?? '{title} - {site_name}');
    $descriptionTemplate = (string) ($settings['seo_description_template'] ?? '{description}');

    $pageTitle = $isArticle
        ? $title
        : renderTemplateString($titleTemplate, [
            'title' => $title,
            'site_name' => $siteName,
            'category' => '',
        ]);

    $description = $isArticle && $hasMetaDescription && $metaDescription !== ''
        ? $metaDescription
        : renderTemplateString($descriptionTemplate, [
            'description' => $hasMetaDescription ? $metaDescription : (string) $settings['site_description'],
            'site_name' => $siteName,
            'keywords' => $hasMetaKeywords ? $metaKeywords : (string) $settings['site_keywords'],
        ]);

    return [
        'page_title' => $pageTitle,
        'description' => $description,
        'keywords' => $hasMetaKeywords ? $metaKeywords : (string) $settings['site_keywords'],
        'canonical_url' => $canonicalUrl,
        'og_type' => $ogType,
    ];
}

function pageHeader(array $config, string $title, array $pageMeta = []): void
{
    $settings = siteSettings($config);
    $siteName = (string) $settings['site_name'];
    $themeClass = themeClass($settings);
    if ($themeClass === 'target-theme-apparel') {
        apparelPageHeader($config, $settings, $title, $pageMeta);

        return;
    }
    $seo = pageSeoPayload($settings, $title, $pageMeta);
    $homeUrl = frontSitePath($config, '/');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>'.h((string) $seo['page_title']).'</title><meta name="description" content="'.h((string) $seo['description']).'">';
    $keywords = (string) $seo['keywords'];
    if ($keywords !== '') {
        echo '<meta name="keywords" content="'.h($keywords).'">';
    }
    if ((string) $seo['canonical_url'] !== '') {
        echo '<link rel="canonical" href="'.h((string) $seo['canonical_url']).'">';
    }
    echo '<meta property="og:title" content="'.h((string) $seo['page_title']).'"><meta property="og:description" content="'.h((string) $seo['description']).'"><meta property="og:type" content="'.h((string) $seo['og_type']).'">';
    if ((string) $seo['canonical_url'] !== '') {
        echo '<meta property="og:url" content="'.h((string) $seo['canonical_url']).'">';
    }
    echo '<meta property="og:site_name" content="'.h($siteName).'">';
    if ((string) $settings['site_favicon'] !== '') {
        echo '<link rel="icon" href="'.h((string) $settings['site_favicon']).'">';
    }
    echo '<link rel="stylesheet" href="'.h(frontVersionedAssetPath($config, '/assets/css/site.css')).'">';
    echo '<script defer src="'.h(frontVersionedAssetPath($config, '/assets/js/site.js')).'"></script>';
    echo '</head><body class="'.h($themeClass).'"><header><div class="wrap bar"><a class="brand" href="'.h($homeUrl).'">'.h($siteName).'</a><nav><a href="'.h($homeUrl).'">首页</a></nav></div></header><main class="wrap">';
}

function pageFooter(array $config): void
{
    $settings = siteSettings($config);
    if (themeClass($settings) === 'target-theme-apparel') {
        echo '</main><footer><div class="asi-shell">'.h((string) $settings['copyright_info']).'</div></footer></body></html>';

        return;
    }

    echo '</main><footer><div class="wrap">'.h((string) $settings['copyright_info']).'</div></footer></body></html>';
}

function apparelPageHeader(array $config, array $settings, string $title, array $pageMeta = []): void
{
    $siteName = (string) $settings['site_name'];
    $seo = pageSeoPayload($settings, $title, $pageMeta);
    $homeUrl = frontSitePath($config, '/');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>'.h((string) $seo['page_title']).'</title><meta name="description" content="'.h((string) $seo['description']).'">';
    $keywords = (string) $seo['keywords'];
    if ($keywords !== '') {
        echo '<meta name="keywords" content="'.h($keywords).'">';
    }
    if ((string) $seo['canonical_url'] !== '') {
        echo '<link rel="canonical" href="'.h((string) $seo['canonical_url']).'">';
    }
    echo '<meta property="og:title" content="'.h((string) $seo['page_title']).'"><meta property="og:description" content="'.h((string) $seo['description']).'"><meta property="og:type" content="'.h((string) $seo['og_type']).'">';
    if ((string) $seo['canonical_url'] !== '') {
        echo '<meta property="og:url" content="'.h((string) $seo['canonical_url']).'">';
    }
    echo '<meta property="og:site_name" content="'.h($siteName).'">';
    if ((string) $settings['site_favicon'] !== '') {
        echo '<link rel="icon" href="'.h((string) $settings['site_favicon']).'">';
    }
    echo '<link rel="stylesheet" href="'.h(frontVersionedAssetPath($config, '/assets/css/site.css')).'">';
    echo '<script defer src="'.h(frontVersionedAssetPath($config, '/assets/js/site.js')).'"></script>';
    echo '</head><body class="target-theme-apparel"><header><div class="asi-topline"><div class="asi-shell asi-topline-row"><span>Global apparel sourcing, trade policy and supplier intelligence</span><span>'.h(date('l, F j, Y')).'</span></div></div>';
    echo '<div class="asi-masthead"><div class="asi-shell asi-masthead-row"><a class="asi-brand" href="'.h($homeUrl).'"><span class="asi-brand-kicker">Independent Market Briefing</span><span class="asi-brand-name">'.h($siteName).'</span></a>';
    echo '<form class="asi-search" action="'.h($homeUrl).'" method="get"><input type="search" name="search" placeholder="Search intelligence"><button type="submit">Search</button></form></div>';
    echo '<nav class="asi-nav asi-shell" aria-label="Primary"><a class="is-active" href="'.h($homeUrl).'">Latest</a>';
    echo '</nav></div></header><main class="wrap">';
}

function renderHomePage(array $config): void
{
    $settings = siteSettings($config);
    if (themeClass($settings) === 'target-theme-apparel') {
        renderApparelHomePage($config, $settings);

        return;
    }

    if (themeClass($settings) === 'target-theme-fashion') {
        renderFashionHomePage($config, $settings);

        return;
    }

    $siteName = (string) $settings['site_name'];
    $articles = array_slice(loadArticles($config), 0, (int) $settings['per_page']);
    pageHeader($config, '首页');
    echo jsonLdScript([
        "@context"=>"https://schema.org",
        "@type"=>"WebSite",
        "name"=>$siteName,
        "url"=>frontSiteUrl($config, '/'),
        "description"=>(string) $settings['site_description'],
    ]);
    echo '<section class="hero"><h1>'.h($siteName).'</h1><p>'.h((string) $settings['site_description']).'</p></section>';
    if ($articles === []) {
        echo '<div class="card empty">暂无文章。请先从 GEOFlow 发布一篇绑定此渠道的文章。</div>';
        pageFooter($config);
        return;
    }

    echo '<section class="list">';
    foreach ($articles as $article) {
        $slug = (string) ($article['slug'] ?? '');
        $title = (string) ($article['title'] ?? '未命名文章');
        $category = is_array($article['category'] ?? null) ? (string) ($article['category']['name'] ?? '默认分类') : '默认分类';
        $publishedAt = substr((string) ($article['published_at'] ?? $article['updated_at'] ?? ''), 0, 10);
        $summary = (string) ($article['excerpt'] ?? $article['meta_description'] ?? '');
        $articleUrl = frontSitePath($config, '/article/'.rawurlencode($slug));
        echo '<article class="card"><div class="meta"><span class="chip">'.h($category).'</span><span>'.h($publishedAt).'</span></div>';
        echo '<h2><a href="'.h($articleUrl).'">'.h($title).'</a></h2>';
        echo '<p class="summary">'.h($summary !== '' ? $summary : mb_substr(strip_tags((string) ($article['content'] ?? '')), 0, 160)).'</p>';
        echo '<a class="read" href="'.h($articleUrl).'">阅读全文</a></article>';
    }
    echo '</section>';
    pageFooter($config);
}

function renderApparelHomePage(array $config, array $settings): void
{
    $siteName = (string) $settings['site_name'];
    $articles = array_slice(loadArticles($config), 0, (int) $settings['per_page']);
    $lead = $articles[0] ?? null;
    $headlines = array_slice($articles, 1, 3);
    $latest = $lead ? array_slice($articles, 1) : $articles;

    pageHeader($config, 'Latest Intelligence');
    echo jsonLdScript([
        "@context"=>"https://schema.org",
        "@type"=>"CollectionPage",
        "name"=>"Latest Intelligence - ".$siteName,
        "url"=>frontSiteUrl($config, '/'),
        "description"=>(string) $settings['site_description'],
    ]);
    echo '<div class="asi-shell asi-page">';

    if ($lead) {
        $leadUrl = frontSitePath($config, '/article/'.rawurlencode((string) ($lead['slug'] ?? '')));
        echo '<section class="asi-hero"><article class="asi-lead">';
        renderApparelVisual($config, $lead, 'asi-lead-visual', articleCategoryName($lead));
        echo '<div class="asi-lead-copy"><div class="asi-kicker">Lead Analysis</div><h1><a href="'.h($leadUrl).'">'.h((string) ($lead['title'] ?? 'Untitled Article')).'</a></h1>';
        echo '<p>'.h(articleSummary($lead, 240) ?: (string) $settings['site_description']).'</p></div></article>';
        echo '<aside class="asi-hero-rail"><section class="asi-briefing"><span>Today\'s Briefing</span><strong>'.h((string) ($settings['site_subtitle'] ?: 'Buyers are rebalancing sourcing maps as cost, speed and compliance collide.')).'</strong><div><small>Daily market note</small><small>'.h(date('H:i T')).'</small></div></section>';
        echo '<section class="asi-headline-stack">';
        foreach ($headlines as $headline) {
            $url = frontSitePath($config, '/article/'.rawurlencode((string) ($headline['slug'] ?? '')));
            echo '<article class="asi-mini-story">';
            renderApparelVisual($config, $headline, 'asi-mini-visual');
            echo '<div><h2><a href="'.h($url).'">'.h((string) ($headline['title'] ?? 'Untitled Article')).'</a></h2><div class="asi-meta"><span>'.h(articleCategoryName($headline)).'</span><time>'.h(articleDate($headline, 'M j')).'</time></div></div></article>';
        }
        if ($headlines === []) {
            echo '<div class="empty">No featured stories yet.</div>';
        }
        echo '</section></aside></section>';
    }

    echo '<div class="asi-content-grid"><section class="asi-feed-section"><div class="asi-section-head"><span>Latest Intelligence</span><small>Updated continuously</small></div><div class="asi-feed-list">';
    if ($latest === []) {
        echo '<div class="empty">No articles yet.</div>';
    }
    foreach ($latest as $article) {
        renderApparelArticleCard($config, $article);
    }
    echo '</div></section>';
    renderApparelSidebar($config, $settings, $articles);
    echo '</div></div>';
    pageFooter($config);
}

function renderApparelVisual(array $config, array $article, string $class, string $badge = ''): void
{
    $url = frontSitePath($config, '/article/'.rawurlencode((string) ($article['slug'] ?? '')));
    $title = (string) ($article['title'] ?? 'Untitled Article');
    $image = articleImageUrl($article);
    $initial = mb_strtoupper(mb_substr(articleCategoryName($article), 0, 1));
    echo '<a class="asi-visual '.h($class).'" href="'.h($url).'" aria-label="'.h($title).'">';
    if ($image !== '') {
        echo '<img src="'.h($image).'" alt="'.h($title).'" loading="lazy" decoding="async">';
    } else {
        echo '<span class="asi-visual-pattern"><span>'.h($initial).'</span></span>';
    }
    if ($badge !== '') {
        echo '<span class="asi-visual-badge">'.h($badge).'</span>';
    }
    echo '</a>';
}

function renderApparelArticleCard(array $config, array $article): void
{
    $url = frontSitePath($config, '/article/'.rawurlencode((string) ($article['slug'] ?? '')));
    echo '<article class="asi-card">';
    renderApparelVisual($config, $article, 'asi-card-visual');
    echo '<div class="asi-card-copy"><div class="asi-meta"><span>'.h(articleCategoryName($article)).'</span><time>'.h(articleDate($article, 'M j, Y')).'</time></div>';
    echo '<h2><a href="'.h($url).'">'.h((string) ($article['title'] ?? 'Untitled Article')).'</a></h2>';
    $summary = articleSummary($article, 180);
    if ($summary !== '') {
        echo '<p>'.h($summary).'</p>';
    }
    echo '</div></article>';
}

function renderApparelSidebar(array $config, array $settings, array $articles): void
{
    echo '<aside class="asi-sidebar"><section class="asi-panel asi-briefing-panel"><span class="asi-panel-kicker">Daily Briefing</span><h2>'.h((string) ($settings['site_subtitle'] ?: 'Compliance costs are now a sourcing decision.')).'</h2>';
    if ((string) $settings['site_description'] !== '') {
        echo '<p>'.h((string) $settings['site_description']).'</p>';
    }
    echo '</section><section class="asi-panel"><div class="asi-panel-head"><h2>Editor Picks</h2></div><div class="asi-rank-list">';
    foreach (array_slice($articles, 0, 6) as $index => $article) {
        $url = frontSitePath($config, '/article/'.rawurlencode((string) ($article['slug'] ?? '')));
        echo '<a class="asi-rank-item" href="'.h($url).'"><span>'.($index + 1).'</span><strong>'.h((string) ($article['title'] ?? 'Untitled Article')).'</strong></a>';
    }
    echo '</div></section></aside>';
}

function renderFashionHomePage(array $config, array $settings): void
{
    $siteName = (string) $settings['site_name'];
    $description = (string) $settings['site_description'];
    $subtitle = trim((string) ($settings['site_subtitle'] ?? ''));
    $articles = array_slice(loadArticles($config), 0, (int) $settings['per_page']);
    $featured = array_slice($articles, 0, min(3, count($articles)));
    $latest = array_slice($articles, 0);

    pageHeader($config, '首页');
    echo jsonLdScript([
        "@context"=>"https://schema.org",
        "@type"=>"WebSite",
        "name"=>$siteName,
        "url"=>frontSiteUrl($config, '/'),
        "description"=>$description,
    ]);
    echo '<section class="fashion-hero"><div class="fashion-wordmark">TREND</div><div class="fashion-hero-inner">';
    echo '<span class="fashion-kicker">Apparel &amp; Textile Intelligence</span>';
    echo '<h1>'.h($siteName).'</h1>';
    echo '<p>'.h($subtitle !== '' ? $subtitle : ($description !== '' ? $description : 'Global sourcing updates, supply chain dynamics, and forward-looking fashion market analytics.')).'</p>';
    echo '<form class="fashion-search" action="'.h(frontSitePath($config, '/')).'" method="get"><input type="search" name="search" placeholder="Search trends, fabrics, materials..."><button type="submit">Search</button></form>';
    echo '</div></section>';

    if ($articles === []) {
        echo '<section class="fashion-empty"><h2>No Articles Yet</h2><p>Stay tuned! Premium sourcing and textile research reports are coming soon.</p></section>';
        pageFooter($config);

        return;
    }

    if ($featured !== []) {
        echo '<section class="fashion-section"><div class="fashion-section-head"><h2>Vanguard Choice</h2><span>Curated Highlights</span></div>';
        $first = $featured[0];
        $firstUrl = frontSitePath($config, '/article/'.rawurlencode((string) ($first['slug'] ?? '')));
        $firstImage = articleImageUrl($first);
        echo '<div class="fashion-feature-grid"><article class="fashion-feature-card">';
        if ($firstImage !== '') {
            echo '<img src="'.h($firstImage).'" alt="'.h((string) ($first['title'] ?? '')).'" loading="lazy" decoding="async">';
        }
        echo '<div class="fashion-feature-overlay"></div><div class="fashion-feature-content"><span>Featured Report</span>';
        echo '<h3><a href="'.h($firstUrl).'">'.h((string) ($first['title'] ?? 'Untitled Article')).'</a></h3>';
        echo '<p>'.h(articleSummary($first, 220)).'</p><div><time>'.h(substr((string) ($first['published_at'] ?? $first['updated_at'] ?? ''), 0, 10)).'</time><a href="'.h($firstUrl).'">Read Analysis</a></div></div></article>';
        echo '<div class="fashion-feature-side">';
        foreach (array_slice($featured, 1, 2) as $item) {
            $url = frontSitePath($config, '/article/'.rawurlencode((string) ($item['slug'] ?? '')));
            $category = is_array($item['category'] ?? null) ? (string) ($item['category']['name'] ?? 'Insight') : 'Insight';
            echo '<article><div><span>'.h($category).'</span><time>'.h(substr((string) ($item['published_at'] ?? $item['updated_at'] ?? ''), 0, 10)).'</time></div>';
            echo '<h3><a href="'.h($url).'">'.h((string) ($item['title'] ?? 'Untitled Article')).'</a></h3><p>'.h(articleSummary($item, 120)).'</p><a href="'.h($url).'">Read Report</a></article>';
        }
        if (count($featured) === 1) {
            echo '<article class="fashion-feature-placeholder"><span>Tailored Trends for Apparel Sourcing</span></article>';
        }
        echo '</div></div></section>';
    }

    echo '<section class="fashion-section"><div class="fashion-section-head"><h2>Latest Intelligence</h2><span>Apparel &amp; Materials Research</span></div><div class="fashion-card-grid">';
    foreach ($latest as $article) {
        renderFashionArticleCard($config, $article);
    }
    echo '</div></section>';
    pageFooter($config);
}

function renderFashionArticleCard(array $config, array $article): void
{
    $slug = (string) ($article['slug'] ?? '');
    $url = frontSitePath($config, '/article/'.rawurlencode($slug));
    $title = (string) ($article['title'] ?? 'Untitled Article');
    $image = articleImageUrl($article);
    $category = is_array($article['category'] ?? null) ? (string) ($article['category']['name'] ?? 'Insight') : 'Insight';
    $date = substr((string) ($article['published_at'] ?? $article['updated_at'] ?? ''), 0, 10);

    echo '<article class="fashion-card">';
    echo '<a class="fashion-card-media" href="'.h($url).'">';
    if ($image !== '') {
        echo '<img src="'.h($image).'" alt="'.h($title).'" loading="lazy" decoding="async">';
    }
    echo '</a><div class="fashion-card-meta"><span>'.h($category).'</span><time>'.h($date).'</time></div>';
    echo '<h3><a href="'.h($url).'">'.h($title).'</a></h3>';
    $summary = articleSummary($article, 140);
    if ($summary !== '') {
        echo '<p>'.h($summary).'</p>';
    }
    echo '<div class="fashion-card-foot"><a href="'.h($url).'">Read Report</a></div></article>';
}

function renderArticlePage(array $config, string $slug): void
{
    $article = findArticle($config, $slug);
    if (! $article) {
        http_response_code(404);
        pageHeader($config, '文章不存在');
        echo '<a class="back" href="'.h(frontSitePath($config, '/')).'">返回首页</a><div class="card empty">文章不存在。</div>';
        pageFooter($config);
        return;
    }

    $title = (string) ($article['title'] ?? '未命名文章');
    $category = is_array($article['category'] ?? null) ? (string) ($article['category']['name'] ?? '默认分类') : '默认分类';
    $publishedAt = substr((string) ($article['published_at'] ?? $article['updated_at'] ?? ''), 0, 10);
    $settings = siteSettings($config);
    $articleUrl = frontSiteUrl($config, '/article/'.rawurlencode($slug));
    $articleDescription = articleMetaDescription($article);
    pageHeader($config, $title, [
        'description' => $articleDescription,
        'keywords' => articleMetaKeywords($article),
        'canonical_url' => $articleUrl,
        'og_type' => 'article',
    ]);
    echo jsonLdScript([
        "@context"=>"https://schema.org",
        "@type"=>"Article",
        "headline"=>$title,
        "description"=>$articleDescription,
        "datePublished"=>(string) ($article['published_at'] ?? ''),
        "dateModified"=>(string) ($article['updated_at'] ?? ''),
        "mainEntityOfPage"=>$articleUrl,
        "author"=>[
            "@type"=>"Person",
            "name"=>is_array($article['author'] ?? null) ? (string) ($article['author']['name'] ?? 'GEOFlow') : 'GEOFlow',
        ],
        "publisher"=>[
            "@type"=>"Organization",
            "name"=>(string) $settings['site_name'],
        ],
    ]);
    echo jsonLdScript([
        "@context"=>"https://schema.org",
        "@type"=>"BreadcrumbList",
        "itemListElement"=>[
            ["@type"=>"ListItem", "position"=>1, "name"=>"首页", "item"=>frontSiteUrl($config, '/')],
            ["@type"=>"ListItem", "position"=>2, "name"=>$title, "item"=>frontSiteUrl($config, '/article/'.rawurlencode($slug))],
        ],
    ]);
    $themeClass = themeClass($settings);
    $isFashion = $themeClass === 'target-theme-fashion';
    $isApparel = $themeClass === 'target-theme-apparel';
    echo $isApparel ? '<div class="asi-shell asi-article-layout"><main class="asi-article-column"><nav class="asi-breadcrumb"><a href="'.h(frontSitePath($config, '/')).'">Latest</a><span>/</span><span>'.h($category).'</span></nav>' : '';
    echo '<a class="back" href="'.h(frontSitePath($config, '/')).'">'.($isFashion || $isApparel ? 'Back to Reports' : '返回首页').'</a><article class="'.($isApparel ? 'asi-article' : 'card detail').'">';
    if ($isFashion) {
        echo '<div class="fashion-article-kicker"><span>'.h($category).'</span><time>'.h($publishedAt).'</time></div>';
    } elseif ($isApparel) {
        echo '<header class="asi-article-head"><a class="asi-article-section" href="'.h(frontSitePath($config, '/')).'">'.h($category).'</a>';
    } else {
        echo '<div class="meta"><span class="chip">'.h($category).'</span><span>'.h($publishedAt).'</span></div>';
    }
    echo '<h1>'.h($title).'</h1>';
    if ($isApparel) {
        echo '<div class="asi-post-info"><time>'.h($publishedAt).'</time>';
        if (is_array($article['author'] ?? null)) {
            echo '<span>'.h((string) ($article['author']['name'] ?? '')).'</span>';
        }
        echo '</div>';
    }
    $excerpt = (string) ($article['excerpt'] ?? '');
    if ($excerpt !== '') {
        echo '<p class="summary">'.h($excerpt).'</p>';
    }
    if ($isApparel) {
        echo '</header>';
        renderApparelVisual($config, $article, 'asi-article-visual');
    }
    echo '<div class="'.($isApparel ? 'asi-prose content' : 'content').'">'.renderArticleTextAds($settings, 'content_top').articleContentHtml($article).renderArticleTextAds($settings, 'content_bottom').'</div>';
    $tags = keywordTags((string) ($article['keywords'] ?? ''));
    if ($tags !== []) {
        echo '<div class="tags">';
        foreach ($tags as $tag) {
            echo '<span>'.h($tag).'</span>';
        }
        echo '</div>';
    }
    echo '</article>';
    if ($isApparel) {
        echo '</main>';
        renderApparelSidebar($config, $settings, loadArticles($config));
        echo '</div>';
    }
    pageFooter($config);
}

function textMapLine(string $value): string
{
    $value = trim(strip_tags($value));
    $value = preg_replace('/\s+/u', ' ', $value);

    return trim(is_string($value) ? $value : '');
}

function renderLlmsText(array $config): string
{
    $settings = siteSettings($config);
    $siteName = textMapLine((string) $settings['site_name']);
    $description = textMapLine((string) $settings['site_description']);
    $lines = [
        '# '.($siteName !== '' ? $siteName : 'GEOFlow Target Site'),
        '',
    ];
    if ($description !== '') {
        $lines[] = '> '.$description;
        $lines[] = '';
    }

    $lines[] = '## Site';
    $lines[] = '';
    $lines[] = '- Home: '.frontSiteUrl($config, '/');
    $lines[] = '- Sitemap: '.frontSiteUrl($config, '/sitemap.txt');
    $lines[] = '';
    $lines[] = '## Articles';
    $lines[] = '';

    $articles = loadArticles($config);
    if ($articles === []) {
        $lines[] = 'No articles have been published yet.';
    } else {
        foreach (array_slice($articles, 0, 200) as $article) {
            $slug = (string) ($article['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $title = textMapLine((string) ($article['title'] ?? 'Untitled Article'));
            $summary = textMapLine((string) ($article['excerpt'] ?? $article['meta_description'] ?? ''));
            if ($summary === '') {
                $summary = textMapLine(mb_substr(strip_tags((string) ($article['content'] ?? '')), 0, 180));
            }
            $line = '- '.($title !== '' ? $title : $slug).' - '.frontSiteUrl($config, '/article/'.rawurlencode($slug));
            if ($summary !== '') {
                $line .= ' - '.$summary;
            }
            $lines[] = $line;
        }
    }

    return rtrim(implode("\n", $lines))."\n";
}

function renderSitemapText(array $config): string
{
    $urls = [frontSiteUrl($config, '/')];
    foreach (loadArticles($config) as $article) {
        $slug = (string) ($article['slug'] ?? '');
        if ($slug !== '') {
            $urls[] = frontSiteUrl($config, '/article/'.rawurlencode($slug));
        }
    }

    return implode("\n", array_values(array_unique($urls)))."\n";
}

function handleHealth(array $config, string $method, string $path, string $body): void
{
    $verified = verifySignedRequest($config, $method, $path, $body);
    jsonResponse(200, [
        'ok' => true,
        'service' => 'geoflow-target-site',
        'event' => $verified['event'],
        'time' => gmdate('c'),
    ]);
}

function handleArticlePublish(array $config, string $method, string $path, string $body): void
{
    $verified = verifySignedRequest($config, $method, $path, $body);
    if ($verified['event'] !== 'article.publish') {
        jsonResponse(422, ['ok' => false, 'error' => 'unsupported_event']);
    }

    $payload = json_decode($body, true);
    if (! is_array($payload) || ! is_array($payload['article'] ?? null)) {
        jsonResponse(422, ['ok' => false, 'error' => 'invalid_article_payload']);
    }

    ensureStorage($config);
    $article = localizeArticleAssets($config, $payload['article'], is_array($payload['assets'] ?? null) ? $payload['assets'] : []);
    $slug = is_scalar($article['slug'] ?? null) && (string) $article['slug'] !== '' ? (string) $article['slug'] : 'article-'.(string) ($article['id'] ?? hash('sha256', (string) $verified['idempotency_key']));
    $file = storageDir($config).'/'.safeFileName($slug).'.json';
    $response = [
        'ok' => true,
        'remote_id' => 'geoflow-'.$slug,
        'remote_url' => frontSiteUrl($config, '/article/'.rawurlencode($slug)),
    ];

    writeJsonFile($file, [
        'received_at' => gmdate('c'),
        'idempotency_key' => $verified['idempotency_key'],
        'article' => $article,
        'response' => $response,
    ], 'article_storage_not_writable');

    $response['static'] = rebuildStaticSite($config);

    jsonResponse(200, $response);
}

function handleArticleUpdate(array $config, string $method, string $path, string $body, string $pathSlug): void
{
    $verified = verifySignedRequest($config, $method, $path, $body);
    if ($verified['event'] !== 'article.update') {
        jsonResponse(422, ['ok' => false, 'error' => 'unsupported_event']);
    }

    $payload = json_decode($body, true);
    if (! is_array($payload) || ! is_array($payload['article'] ?? null)) {
        jsonResponse(422, ['ok' => false, 'error' => 'invalid_article_payload']);
    }

    ensureStorage($config);
    $article = localizeArticleAssets($config, $payload['article'], is_array($payload['assets'] ?? null) ? $payload['assets'] : []);
    $slug = is_scalar($article['slug'] ?? null) && (string) $article['slug'] !== '' ? (string) $article['slug'] : $pathSlug;
    if ($slug === '') {
        jsonResponse(422, ['ok' => false, 'error' => 'missing_slug']);
    }

    $file = storageDir($config).'/'.safeFileName($slug).'.json';
    $response = [
        'ok' => true,
        'updated' => true,
        'remote_id' => 'geoflow-'.$slug,
        'remote_url' => frontSiteUrl($config, '/article/'.rawurlencode($slug)),
    ];

    writeJsonFile($file, [
        'received_at' => gmdate('c'),
        'idempotency_key' => $verified['idempotency_key'],
        'article' => $article,
        'response' => $response,
    ], 'article_storage_not_writable');

    $response['static'] = rebuildStaticSite($config);

    jsonResponse(200, $response);
}

function handleArticleDelete(array $config, string $method, string $path, string $body, string $pathSlug): void
{
    $verified = verifySignedRequest($config, $method, $path, $body);
    if ($verified['event'] !== 'article.delete') {
        jsonResponse(422, ['ok' => false, 'error' => 'unsupported_event']);
    }

    $payload = json_decode($body, true);
    $article = is_array($payload) && is_array($payload['article'] ?? null) ? $payload['article'] : [];
    $slug = is_scalar($article['slug'] ?? null) && (string) $article['slug'] !== '' ? (string) $article['slug'] : $pathSlug;
    if ($slug === '') {
        jsonResponse(422, ['ok' => false, 'error' => 'missing_slug']);
    }

    ensureStorage($config);
    $file = storageDir($config).'/'.safeFileName($slug).'.json';
    if (is_file($file)) {
        @unlink($file);
    }
    removeStaticArticle($config, $slug);
    $static = rebuildStaticSite($config);

    jsonResponse(200, [
        'ok' => true,
        'deleted' => true,
        'remote_id' => 'geoflow-'.$slug,
        'static' => $static,
    ]);
}

function handleSiteSettingsUpdate(array $config, string $method, string $path, string $body): void
{
    $verified = verifySignedRequest($config, $method, $path, $body);
    if ($verified['event'] !== 'site.settings.update') {
        jsonResponse(422, ['ok' => false, 'error' => 'unsupported_event']);
    }

    $payload = json_decode($body, true);
    if (! is_array($payload) || ! is_array($payload['settings'] ?? null)) {
        jsonResponse(422, ['ok' => false, 'error' => 'invalid_settings_payload']);
    }

    ensureStorage($config);
    $settings = normalizeSiteSettings($payload['settings'], $config);
    writeJsonFile(siteSettingsFile($config), $settings, 'site_settings_not_writable');
    $static = rebuildStaticSite($config);

    jsonResponse(200, [
        'ok' => true,
        'updated' => true,
        'site_name' => $settings['site_name'],
        'active_theme' => $settings['active_theme'],
        'static' => $static,
    ]);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) && $path !== '' ? rtrim($path, '/') : '/';
$path = normalizeRequestPath($config, $path === '' ? '/' : $path);
$body = file_get_contents('php://input');
$body = is_string($body) ? $body : '';

if ($method === 'GET' && $path === '/geoflow-agent/v1/health') {
    handleHealth($config, $method, $path, $body);
}
if ($method === 'POST' && $path === '/geoflow-agent/v1/articles') {
    handleArticlePublish($config, $method, $path, $body);
}
// POST /geoflow-agent/v1/articles/{slug}/update
if ($method === 'POST' && preg_match('#^/geoflow-agent/v1/articles/([^/]+)/update$#', $path, $m) === 1) {
    handleArticleUpdate($config, $method, $path, $body, rawurldecode((string) $m[1]));
}
// POST /geoflow-agent/v1/articles/{slug}/delete
if ($method === 'POST' && preg_match('#^/geoflow-agent/v1/articles/([^/]+)/delete$#', $path, $m) === 1) {
    handleArticleDelete($config, $method, $path, $body, rawurldecode((string) $m[1]));
}
if ($method === 'POST' && $path === '/geoflow-agent/v1/site-settings') {
    handleSiteSettingsUpdate($config, $method, $path, $body);
}
if ($method === 'GET' && $path === '/') {
    renderHomePage($config);
    exit;
}
if ($method === 'GET' && $path === '/llms.txt') {
    textResponse(renderLlmsText($config));
}
if ($method === 'GET' && $path === '/sitemap.txt') {
    textResponse(renderSitemapText($config));
}
if ($method === 'GET' && str_starts_with($path, '/article/')) {
    renderArticlePage($config, rawurldecode(substr($path, 9)));
    exit;
}

http_response_code(404);
renderHomePage($config);