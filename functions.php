<?php
require_once __DIR__ . '/db.php';

const BASE62_ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

function base62_encode(int $num): string {
    if ($num === 0) return BASE62_ALPHABET[0];
    $out = '';
    $base = strlen(BASE62_ALPHABET);
    while ($num > 0) {
        $out = BASE62_ALPHABET[$num % $base] . $out;
        $num = intdiv($num, $base);
    }
    return $out;
}

function create_short_link(string $longUrl): array {
    $pdo = get_db();
    try {
        // Проверяем, есть ли уже такая ссылка
        $stmt = $pdo->prepare("SELECT short_code, clicks FROM links WHERE long_url = ? LIMIT 1");
        $stmt->execute([$longUrl]);
        $existing = $stmt->fetch();
        if ($existing && $existing['short_code']) {
            return [
                'code'     => $existing['short_code'],
                'existing' => true,
                'clicks'   => (int)$existing['clicks'],
            ];
        }

        // Временный уникальный код (не NULL), чтобы пройти NOT NULL + UNIQUE
        $tmpCode = '~' . bin2hex(random_bytes(8));

        $stmt = $pdo->prepare("INSERT INTO links (long_url, short_code) VALUES (?, ?)");
        $stmt->execute([$longUrl, $tmpCode]);
        $id = (int)$pdo->lastInsertId();

        $code = base62_encode($id);
        $stmt = $pdo->prepare("UPDATE links SET short_code = ? WHERE id = ?");
        $stmt->execute([$code, $id]);

        return ['code' => $code, 'existing' => false, 'clicks' => 0];
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        exit;
    }
}

function resolve_and_track(string $code): ?string {
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT id, long_url FROM links WHERE short_code = ?");
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $pdo->prepare("UPDATE links SET clicks = clicks + 1 WHERE id = ?")
        ->execute([$row['id']]);

    return $row['long_url'];
}

/**
 * Возвращает количество кликов по коду или null, если код не найден.
 */
function get_clicks(string $code): ?int {
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT clicks FROM links WHERE short_code = ?");
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ? (int)$row['clicks'] : null;
}
