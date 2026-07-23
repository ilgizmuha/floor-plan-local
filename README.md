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

Технический анализ использует SMA20/SMA50, EMA12/EMA26, RSI14, MACD, Bollinger Bands, momentum, volatility, volume ratio, support/resistance, стакан, деривативы и Fear & Greed. Помимо крипты поддерживаются TradFi-инструменты Bybit: металлы (`XAUUSDT`, `XAGUSDT`, `XAUTUSDT`), нефть (`CLUSDT`), акции (`TSLAUSDT`, `NVDAUSDT` и др.) через `BRAIN_LINEAR_SYMBOLS`. Класс актива (`crypto` / `metal` / `commodity` / `stock`) виден в WordPress-панели.

Опциональные AI-аналитики подключаются через DeepSeek/OpenAI-compatible API (`AI_ANALYST_*`) и Cursor SDK (`CURSOR_ANALYST_*`). Сейчас VPS настроен на DeepSeek (`AI_ANALYST_BASE_URL=https://api.deepseek.com`, `AI_ANALYST_MODEL=deepseek-chat`). Для Cursor Analyst нужен `CURSOR_API_KEY` из Cursor Dashboard. Базовые правила дают первый сигнал, DeepSeek и Cursor подтверждают/отклоняют его, затем risk manager принимает финальное разрешение.

WordPress используется только как будущая панель управления и просмотра. Bybit-ключи, анализатор и торговая логика должны оставаться вне WordPress/OCR-плагина.

Для Cursor SDK нужен Node.js `>=22.13`; VPS обновлён до Node.js 22.

Paper-trading включается настройками `PAPER_*`. Это виртуальная торговля: мозг открывает/закрывает позиции только в файлах `/opt/trading-brain/data/paper-state.json` и `/opt/trading-brain/data/paper-trades.jsonl`. По умолчанию paper работает и на крипте, и на TradFi (`XAUUSDT`, `XAGUSDT`, `TSLAUSDT`, `NVDAUSDT`, `CLUSDT`, `XAUTUSDT`) через `PAPER_SYMBOLS`. Качество сигналов по горизонтам 15м/1ч/4ч пишется в `/opt/trading-brain/data/quality.json` и отображается в WordPress-панели.

Новостной слой читает RSS (`BRAIN_NEWS_SOURCES`) и HTML-источники (`BRAIN_HTML_NEWS_SOURCES`). Сейчас подключены Cointelegraph, CoinDesk, Google News, ForkLog и публичная Telegram-лента ForkLog. Также используется Crypto Fear & Greed Index (`FEAR_GREED_ENABLED`, API alternative.me, без ключа). X, Feedly, CryptoPanic и приватные Telegram-каналы стоит подключать отдельными API-токенами, чтобы не зависеть от нестабильного scraping.

Команды:

```bash
npm run brain:once
npm run brain:status
```

На VPS рекомендуемая папка: `/opt/trading-brain`. Журнал решений: `/opt/trading-brain/data/decisions.jsonl`.

## WordPress-панель Trading Brain

Отдельный плагин находится в `wp-plugins/trading-brain-panel`. Он добавляет админ-страницу **Trading Brain** и показывает статус сервиса, последние сигналы, новости и кнопки включить/выключить/перезапустить анализатор.

Плагин не хранит Bybit-ключи и не содержит торговую логику. На VPS он работает через ограниченный helper `deploy/trading-brain-panel-helper.sh`, установленный как `/usr/local/bin/trading-brain-panel`.

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
