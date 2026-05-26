# Autonomous Trading Service

Этот репозиторий заменен на стартовый автономный сервис для алгоритмической торговли на базе [Freqtrade](https://github.com/freqtrade/freqtrade).

Freqtrade - open-source crypto trading bot с поддержкой dry-run режима, backtesting, стратегий на Python, SQLite-хранилища, Telegram и Web UI.

## Важно про безопасность

- По умолчанию включен `dry_run: true`: бот не размещает реальные ордера.
- Реальные API-ключи биржи не хранятся в репозитории.
- Не включайте live trading, пока стратегия не протестирована на истории и в dry-run.
- Этот проект не является финансовой рекомендацией.

## Быстрый запуск

1. Установите Docker и Docker Compose.
2. Проверьте конфигурацию:

   ```bash
   docker compose config
   ```

3. Загрузите образ Freqtrade:

   ```bash
   docker compose pull
   ```

4. Запустите бота в dry-run режиме:

   ```bash
   docker compose up -d
   ```

5. Откройте Web UI:

   ```text
   http://localhost:8080
   ```

   Логин и пароль по умолчанию заданы в `user_data/config.json`. Смените их перед запуском на сервере.

## Проверки и полезные команды

```bash
docker compose run --rm freqtrade --version
docker compose run --rm freqtrade list-strategies --userdir /freqtrade/user_data
docker compose run --rm freqtrade show-config --config /freqtrade/user_data/config.json
docker compose logs -f freqtrade
docker compose down
```

## Структура

- `docker-compose.yml` - запуск официального Docker-образа `freqtradeorg/freqtrade:stable`.
- `user_data/config.json` - безопасная dry-run конфигурация.
- `user_data/strategies/SafeMomentumStrategy.py` - пример простой стратегии на EMA и momentum.
- `user_data/README.md` - где хранить стратегии, данные, логи и локальные настройки.

## Переход к реальной торговле

Для live trading нужно вручную изменить `dry_run`, добавить API-ключи биржи, ограничить права ключей, настроить пары и протестировать стратегию. Не коммитьте реальные ключи в Git.
