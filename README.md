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

## Bybit API трейдинг

Скрипт `scripts/bybit-trader.js` позволяет проверить доступ к Bybit API, посмотреть баланс и создать ордер. По умолчанию реальные ордера не отправляются: включен `BYBIT_DRY_RUN=true`.

1. На VPS скопируйте пример настроек:
   ```bash
   cp .env.example .env
   ```
2. В `.env` укажите `BYBIT_API_KEY` и `BYBIT_API_SECRET`. Для ключа на Bybit включайте только `read` и `trade`, выключайте `withdrawal`, добавляйте IP whitelist VPS.
3. Проверьте публичный API:
   ```bash
   npm run bybit:health
   ```
4. Проверьте приватный API и баланс:
   ```bash
   npm run bybit:balance
   ```
5. Сформируйте ордер без отправки:
   ```bash
   npm run bybit:order -- --symbol BTCUSDT --side Buy --order-type Limit --qty 0.0001 --price 50000
   ```
6. Реальный ордер отправится только при двух условиях:
   ```bash
   BYBIT_DRY_RUN=false npm run bybit:order -- --symbol BTCUSDT --side Buy --order-type Limit --qty 0.0001 --price 50000 --confirm-live-order
   ```

## Гибридный торговый мозг

`scripts/trading-brain.js` — read-only анализатор для VPS. Он не отправляет ордера: собирает рынок Bybit, считает индикаторы, читает публичные RSS-новости, прогоняет сигнал через risk manager и пишет решения в JSONL.

WordPress используется только как будущая панель управления и просмотра. Bybit-ключи, анализатор и торговая логика должны оставаться вне WordPress/OCR-плагина.

Команды:

```bash
npm run brain:once
npm run brain:status
```

На VPS рекомендуемая папка: `/opt/trading-brain`. Журнал решений: `/opt/trading-brain/data/decisions.jsonl`.

## Подключение к GitHub

1. Создайте новый репозиторий на [github.com](https://github.com/new) (без README и .gitignore).
2. В папке `floor-plan-local` выполните (подставьте свой URL репозитория):

   ```bash
   git remote add origin https://github.com/ВАШ_ЛОГИН/floor-plan-local.git
   git push -u origin main
   ```

   Либо через SSH:

   ```bash
   git remote add origin git@github.com:ВАШ_ЛОГИН/floor-plan-local.git
   git push -u origin main
   ```

3. После первого push при необходимости настройте имя и email для коммитов:
   ```bash
   git config user.name "Ваше Имя"
   git config user.email "ваш@email.com"
   ```

## Структура

- `index.html` — форма загрузки и отображение результата (SVG или ошибка).
- **Node:** `server.js` — сервер и API; `config.json` — ключи (скопируйте из `config.json.example`).
- **PHP:** `api/vectorize.php` — приём файла и запрос к Vectorizer.AI; `config.php` — ключи (скопируйте из `config.php.example`).
