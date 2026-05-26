from pandas import DataFrame

from freqtrade.strategy import IStrategy


class SafeMomentumStrategy(IStrategy):
    """Simple dry-run starter strategy.

    It is intentionally conservative and meant as a template for testing,
    not as a promise of profitability.
    """

    INTERFACE_VERSION = 3

    timeframe = "5m"
    startup_candle_count = 60
    can_short = False

    minimal_roi = {
        "0": 0.02,
        "60": 0.01,
        "180": 0
    }

    stoploss = -0.05
    trailing_stop = True
    trailing_stop_positive = 0.01
    trailing_stop_positive_offset = 0.02
    trailing_only_offset_is_reached = True

    process_only_new_candles = True
    use_exit_signal = True
    exit_profit_only = False
    ignore_roi_if_entry_signal = False

    def populate_indicators(self, dataframe: DataFrame, metadata: dict) -> DataFrame:
        dataframe["ema_fast"] = dataframe["close"].ewm(span=12, adjust=False).mean()
        dataframe["ema_slow"] = dataframe["close"].ewm(span=26, adjust=False).mean()
        dataframe["volume_mean"] = dataframe["volume"].rolling(window=20).mean()
        dataframe["momentum"] = dataframe["close"].pct_change(periods=12)
        return dataframe

    def populate_entry_trend(self, dataframe: DataFrame, metadata: dict) -> DataFrame:
        dataframe.loc[
            (
                (dataframe["ema_fast"] > dataframe["ema_slow"])
                & (dataframe["momentum"] > 0)
                & (dataframe["volume"] > dataframe["volume_mean"])
            ),
            "enter_long",
        ] = 1
        return dataframe

    def populate_exit_trend(self, dataframe: DataFrame, metadata: dict) -> DataFrame:
        dataframe.loc[
            (
                (dataframe["ema_fast"] < dataframe["ema_slow"])
                | (dataframe["momentum"] < 0)
            ),
            "exit_long",
        ] = 1
        return dataframe
