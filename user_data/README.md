# Freqtrade user data

Этот каталог монтируется в контейнер как `/freqtrade/user_data`.

## Файлы в Git

- `config.json` - dry-run конфигурация без реальных API-ключей.
- `strategies/SafeMomentumStrategy.py` - стартовая стратегия.

## Локальные файлы

Следующие данные создаются во время работы и игнорируются Git:

- `logs/`
- `data/`
- `backtest_results/`
- `hyperopt_results/`
- `trades*.sqlite`
- `config.private.json`
- `secrets.json`

Храните реальные ключи только локально и не добавляйте их в репозиторий.
