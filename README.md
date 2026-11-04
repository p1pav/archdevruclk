# archdev.ru/clk — сокращатель ссылок

Минималистичный сервис сокращения ссылок на чистом PHP (без фреймворков и Composer).
Умеет: создавать короткие ссылки, отдавать статистику кликов, редиректить по короткому коду
и не создавать дубликаты для уже сокращённых URL.

**Демо: https://archdev.ru/clk/**

---

## Возможности

* Сокращение длинных URL (только `http://` и `https://`).
* Идемпотентность: повторный запрос на тот же URL возвращает уже существующий код.
* Короткий код — base62 от `AUTO_INCREMENT` ID (без коллизий).
* Подсчёт кликов и API для получения статистики.
* Редирект `302 Found` на оригинальный URL.
* Простой адаптивный интерфейс на чистом HTML/CSS/JS (без сборки).
* Никаких зависимостей: только PHP + MySQL.

---

## Стек

| Слой       | Технология                                                  |
|------------|-------------------------------------------------------------|
| Backend    | PHP 8.1+ (`str_starts_with`, `intdiv`, `strict_types`)      |
| БД         | MySQL 5.7+ / MariaDB 10.3+                                  |
| Драйвер БД | PDO (prepared statements, `EMULATE_PREPARES = false`)       |
| Frontend   | Vanilla HTML + CSS + JS (fetch API)                         |
| Роутинг    | Ручной front controller (`index.php`)                       |

---

## Требования

- PHP **8.1+** с расширениями `pdo`, `pdo_mysql`, `mbstring`.
- MySQL **5.7+** или MariaDB **10.3+**.
- Веб-сервер Apache (с `mod_rewrite`) или Nginx.
- HTTPS (обязательно для работы кнопки «Копировать» — `navigator.clipboard` требует secure context).

---

## Установка

### 1. Клонирование

```bash
git clone https://github.com/p1pav/archdevruclk clk
cd clk
```

### 2. Конфигурация

```bash

cp config.php.example config.php
```

**config.php.example:**

```php
<?php
# example
return [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'mydatabase',
        'user' => 'myuser',
        'pass' => 'mypass',
        'charset' => 'utf8mb4',
    ],
    'base_url' => 'https://example.com/clk/',
];
```

### 3. База данных

```sql
CREATE DATABASE IF NOT EXISTS clk
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE clk;

CREATE TABLE links (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    long_url   VARCHAR(2048) NOT NULL,
    short_code VARCHAR(16)   NOT NULL,
    clicks     INT UNSIGNED  NOT NULL DEFAULT 0,
    created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_short_code (short_code),
    UNIQUE KEY uq_long_url   (long_url(255)),
    KEY idx_long_url (long_url(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4. Веб-сервер

**Apache**

В каталоге clk/ создайте .htaccess:

```apache
RewriteEngine On
RewriteBase /clk/
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

Убедитесь, что mod_rewrite включён и в конфиге Apache разрешён
AllowOverride All для каталога проекта.

**Nginx**

```nginx
location /clk/ {
    try_files $uri $uri/ /clk/index.php?$query_string;
}

location ~ \.php$ {
    include        fastcgi_params;
    fastcgi_pass   unix:/run/php/php8.1-fpm.sock;
    fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

### 5. Проверка

* Откройте https://домен/clk/ — должна появиться форма.
* Сократите тестовую ссылку и перейди по ней — проверь редирект и клики.

---

## API

Все ответы — application/json; charset=utf-8, если не указано иное.

### POST /clk/ — создать короткую ссылку

Тело: application/x-www-form-urlencoded, поле url.

```bash
curl -s -X POST https://archdev.ru/clk/ \
     -d 'url=https://example.com/very/long/path?with=query'
```

Успех 200:

```json
{
  "short":    "https://archdev.ru/clk/1",
  "code":     "1",
  "long":     "https://example.com/very/long/path?with=query",
  "existing": false,
  "clicks":   0
}
```

Если ссылка уже сокращалась — existing: true, code и clicks — от существующей записи.

**Ошибки:**
* 400   {"error":"Некорректный URL"} - Пустой/невалидный/не http(s)
* 500	{"error":"Ошибка БД: ..."} - Ошибка SQL

### GET /clk/api/clicks?code=... — статистика

```bash
curl -s 'https://archdev.ru/clk/api/clicks?code=1'
# {"clicks":42}
```
**Ошибки:**
* 400     {"error":"Не указан код"} - Параметр пуст
* 404	    {"error":"Ссылка не найдена"} - Код не существует

### GET /clk/{code} — редирект

Отвечает **302 Found** с заголовком **Location: <long_url>** и увеличивает счётчик.
Если код не найден — 404 и текст **Ссылка не найдена.**

---

## Как это работает

1. **Front controller.** Все запросы проходят через `index.php`.
2. **Подключение к БД.** `get_db()` в `db.php` — Singleton на `static`.
3. **Создание.** `create_short_link()` сначала ищет `long_url`.
4. **Переход.** `resolve_and_track()` выбирает URL по коду.
5. **Фронтенд.** Форма перехватывается JS, отправляется `fetch`.

---

## Структура проекта

```text
clk/
├── index.php # front controller
├── functions.php # логика
├── db.php # подключение к MySQL через PDO
├── config.php.example # шаблон конфигурации (копируется в config.php)
├── config.php # локальная конфигурация (создать из .example)
├── style.css # оформление
├── .htaccess # rewrite в index.php (для Apache)
├── .gitignore
└── README.md
```

---

## Безопасность

**Сделано:** prepared statements везде, `EMULATE_PREPARES=false`,
двойная валидация URL (`filter_var` + regex `^https?://`).

**На исправление:**
- нет защиты от SSRF (можно сократить `http://127.0.0.1/...`);
- нет rate limiting;
- короткие коды предсказуемы (base62 от ID);


## Лицензия

MIT. Проект создан в учебных целях. Используйте свободно.
