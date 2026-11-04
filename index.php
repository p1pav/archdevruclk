<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
}


# ini_set('display_errors', 1); / пользователю необязательно знать, для отладки раскомментировать
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/functions.php';

$config = require __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$prefix = '/clk/';
$code = '';
if (str_starts_with($uri, $prefix)) {
    $code = trim(substr($uri, strlen($prefix)), '/');
}

// --- 0. GET /clk/api/clicks?code=... ---
if ($method === 'GET' && $code === 'api/clicks') {
    header('Content-Type: application/json; charset=utf-8');
    $c = trim($_GET['code'] ?? '');
    if ($c === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Не указан код']);
        exit;
    }
    $clicks = get_clicks($c);
    if ($clicks === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Ссылка не найдена']);
        exit;
    }
    echo json_encode(['clicks' => $clicks]);
    exit;
}

// --- 1. POST: создание ссылки ---
if ($method === 'POST' && $code === '') {
    header('Content-Type: application/json; charset=utf-8');
    $longUrl = trim($_POST['url'] ?? '');

    if (!filter_var($longUrl, FILTER_VALIDATE_URL) ||
        !preg_match('#^https?://#i', $longUrl)) {
        http_response_code(400);
        echo json_encode(['error' => 'Некорректный URL']);
        exit;
    }

    $result = create_short_link($longUrl);
    echo json_encode([
        'short'    => $config['base_url'] . $result['code'],
        'code'     => $result['code'],
        'long'     => $longUrl,
        'existing' => $result['existing'],
        'clicks'   => $result['clicks'],
    ]);
    exit;
}

// --- 2. GET /clk/{code}: редирект ---
if ($method === 'GET' && $code !== '') {
    $longUrl = resolve_and_track($code);
    if ($longUrl === null) {
        http_response_code(404);
        echo 'Ссылка не найдена';
        exit;
    }
    header('Location: ' . $longUrl, true, 302);
    exit;
}

// --- 3. GET /clk/: HTML-форма ---
if ($method === 'GET' && $code === '') {
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Сокращатель ссылок</title>
    <link rel="stylesheet" href="/clk/style.css">
</head>
<body>
<div class="page">
    <div class="card">
<header class="card__header">
    <div class="brand">Title</div>
    <h1 class="card__title">Сократить ссылку</h1>
    <p class="card__subtitle">Вставьте длинный URL — получите короткую ссылку и статистику.</p>
</header>

        <form id="shorten-form" class="form" method="post" action="/clk/">
            <label class="form__label" for="url">Длинный URL</label>
            <div class="form__row">
                <input
                    class="form__input"
                    type="url"
                    id="url"
                    name="url"
                    placeholder="https://example.com/очень/длинный/url"
                    required
                    autocomplete="off"
                >
                <button class="btn btn--primary" type="submit">Сократить</button>
            </div>
        </form>

        <div id="result" class="result" hidden>
            <div class="result__alert" id="result-alert" hidden></div>

            <div class="result__block">
                <div class="result__label">Короткая ссылка</div>
                <div class="result__row">
                    <a id="result-short" class="result__link" href="#" target="_blank" rel="noopener"></a>
                    <button id="copy-btn" class="btn btn--ghost" type="button">Копировать</button>
                </div>
            </div>

            <div class="result__stats">
                <div class="stat">
                    <div class="stat__value" id="result-clicks">0</div>
                    <div class="stat__label">кликов</div>
                </div>
                <button id="refresh-btn" class="btn btn--ghost" type="button">Обновить</button>
            </div>
        </div>

        <p class="hint" id="hint" hidden></p>
    </div>
</div>

<script>
(() => {
    const form        = document.getElementById('shorten-form');
    const input       = document.getElementById('url');
    const result      = document.getElementById('result');
    const alertBox    = document.getElementById('result-alert');
    const shortLink   = document.getElementById('result-short');
    const clicksEl    = document.getElementById('result-clicks');
    const copyBtn     = document.getElementById('copy-btn');
    const refreshBtn  = document.getElementById('refresh-btn');
    const hint        = document.getElementById('hint');

    let currentCode = null;

    function showHint(text) {
        hint.textContent = text;
        hint.hidden = false;
    }
    function hideHint() { hint.hidden = true; }

    async function refreshClicks() {
        if (!currentCode) return;
        try {
            const r = await fetch(`/clk/api/clicks?code=${encodeURIComponent(currentCode)}`);
            const data = await r.json();
            if (r.ok && typeof data.clicks === 'number') {
                clicksEl.textContent = data.clicks;
            }
        } catch (_) { }
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        hideHint();
        result.hidden = true;
        alertBox.hidden = true;

        const fd = new FormData();
        fd.append('url', input.value.trim());

        let data = null;
        try {
            const r = await fetch('/clk/', { method: 'POST', body: fd });
            const text = await r.text();
            console.log('ОТВЕТ СЕРВЕРА:', text);
            data = JSON.parse(text);
            if (!r.ok) {
                showHint(data.error || 'Ошибка сервера');
                return;
            }
        } catch (err) {
            showHint('Не удалось разобрать ответ сервера (смотри консоль)');
            return;
        }

        currentCode = data.code;
        shortLink.textContent = data.short;
        shortLink.href = data.short;
        clicksEl.textContent = data.clicks ?? 0;
        result.hidden = false;

        if (data.existing) {
            alertBox.textContent = 'Эта ссылка уже сокращалась ранее — показана существующая короткая ссылка.';
            alertBox.hidden = false;
        }
    });

    copyBtn.addEventListener('click', async () => {
        if (!shortLink.textContent) return;
        try {
            await navigator.clipboard.writeText(shortLink.textContent);
            copyBtn.textContent = 'Скопировано!';
            setTimeout(() => (copyBtn.textContent = 'Копировать'), 1500);
        } catch (_) {
            showHint('Не удалось скопировать — выделите ссылку вручную.');
        }
    });

    refreshBtn.addEventListener('click', refreshClicks);
})();
</script>
</body>
</html>
    <?php
    exit;
}

http_response_code(405);
echo 'Method Not Allowed';
