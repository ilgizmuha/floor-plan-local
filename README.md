# Локальная страница векторной копии плана

Страница без плагина WordPress: загрузка плана и получение SVG через Vectorizer.AI.

## Запуск

### Вариант 1: Node.js (рекомендуется)

1. В папке `floor-plan-local` выполните:
   ```bash
   npm install
   npm start
   ```
2. Откройте в браузере: **http://localhost:8080**
3. Выберите файл плана (PNG/JPG) и нажмите «Векторизовать».

### Вариант 2: PHP

1. В папке `floor-plan-local` выполните:
   ```bash
   php -S localhost:8080
   ```
2. Откройте в браузере: **http://localhost:8080**

## Настройки

- **Node.js:** скопируйте `config.json.example` в `config.json` и укажите `vectorizer_username` и `vectorizer_password` (ключи с [vectorizer.ai](https://vectorizer.ai)).
- **PHP:** скопируйте `config.php.example` в `config.php` или `config.local.php` и укажите те же переменные.

## Структура

- `index.html` — форма загрузки и отображение результата (SVG или ошибка).
- **Node:** `server.js` — сервер и API; `config.json` — ключи.
- **PHP:** `api/vectorize.php` — приём файла и запрос к Vectorizer.AI; `config.php` — ключи.
