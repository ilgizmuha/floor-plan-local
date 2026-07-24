#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const ENV_PATHS = [
  path.join(process.cwd(), '.env'),
  path.join(__dirname, '.env'),
  path.join(path.resolve(__dirname, '..'), '.env')
];

for (const filePath of [...new Set(ENV_PATHS)]) {
  loadDotEnv(filePath);
}

const command = process.argv[2] || 'help';
const args = parseArgs(process.argv.slice(3));

const config = {
  baseUrl: env('BYBIT_BASE_URL', 'https://api.bybit.com').replace(/\/+$/, ''),
  category: env('BRAIN_CATEGORY', 'spot'),
  symbols: splitList(env('BRAIN_SYMBOLS', 'BTCUSDT,ETHUSDT,SOLUSDT,XRPUSDT,LINKUSDT,XAUUSDT,XAGUSDT,TSLAUSDT,NVDAUSDT,CLUSDT,XAUTUSDT,USDTEUR,BTCEUR,ETHEUR')),
  linearSymbols: new Set(splitList(env('BRAIN_LINEAR_SYMBOLS', 'XAUUSDT,XAGUSDT,TSLAUSDT,NVDAUSDT,CLUSDT'))),
  interval: env('BRAIN_INTERVAL', '15'),
  klineLimit: numberEnv('BRAIN_KLINE_LIMIT', 96),
  minConfidence: numberEnv('BRAIN_MIN_CONFIDENCE', 65),
  maxRiskScore: numberEnv('BRAIN_MAX_RISK_SCORE', 55),
  maxPositionUsd: numberEnv('BRAIN_MAX_POSITION_USD', 20),
  maxDailyLossUsd: numberEnv('BRAIN_MAX_DAILY_LOSS_USD', 5),
  dataDir: env('BRAIN_DATA_DIR', path.join(process.cwd(), 'data')),
  newsSources: splitList(env('BRAIN_NEWS_SOURCES', 'https://cointelegraph.com/rss,https://www.coindesk.com/arc/outboundfeeds/rss/')),
  htmlNewsSources: splitList(env('BRAIN_HTML_NEWS_SOURCES', 'https://forklog.com/en/news-and-analysis/,https://t.me/s/forklogfeed')),
  newsLookbackHours: numberEnv('BRAIN_NEWS_LOOKBACK_HOURS', 12),
  fearGreed: {
    enabled: env('FEAR_GREED_ENABLED', 'true') === 'true',
    url: env('FEAR_GREED_URL', 'https://api.alternative.me/fng/'),
    timeoutMs: numberEnv('FEAR_GREED_TIMEOUT_MS', 10000)
  },
  loopIntervalSeconds: numberEnv('BRAIN_LOOP_INTERVAL_SECONDS', 300),
  dryRun: env('BRAIN_DRY_RUN', 'true') !== 'false',
  ai: {
    enabled: env('AI_ANALYST_ENABLED', 'false') === 'true',
    provider: env('AI_ANALYST_PROVIDER', 'openai-compatible'),
    baseUrl: env('AI_ANALYST_BASE_URL', 'https://api.openai.com/v1').replace(/\/+$/, ''),
    apiKey: env('AI_ANALYST_API_KEY', ''),
    model: env('AI_ANALYST_MODEL', 'gpt-4o-mini'),
    timeoutMs: numberEnv('AI_ANALYST_TIMEOUT_MS', 20000),
    minConfidence: numberEnv('AI_ANALYST_MIN_CONFIDENCE', 60)
  },
  cursor: {
    enabled: env('CURSOR_ANALYST_ENABLED', 'false') === 'true',
    apiKey: env('CURSOR_API_KEY', ''),
    model: env('CURSOR_ANALYST_MODEL', 'auto'),
    timeoutMs: numberEnv('CURSOR_ANALYST_TIMEOUT_MS', 60000),
    minConfidence: numberEnv('CURSOR_ANALYST_MIN_CONFIDENCE', 60),
    cwd: env('CURSOR_ANALYST_CWD', process.cwd())
  },
  algoVault: {
    enabled: env('ALGOVAULT_ENABLED', 'true') === 'true',
    mcpUrl: env('ALGOVAULT_MCP_URL', 'https://api.algovault.com/mcp'),
    exchange: env('ALGOVAULT_EXCHANGE', 'BYBIT'),
    fetchRegime: env('ALGOVAULT_FETCH_REGIME', 'true') === 'true',
    minConfidence: numberEnv('ALGOVAULT_MIN_CONFIDENCE', 55),
    timeoutMs: numberEnv('ALGOVAULT_TIMEOUT_MS', 20000),
    attachToCursor: env('ALGOVAULT_ATTACH_TO_CURSOR', 'true') === 'true'
  },
  paper: {
    enabled: env('PAPER_TRADING_ENABLED', 'true') === 'true',
    startBalanceUsd: numberEnv('PAPER_START_BALANCE_USD', 1000),
    maxPositionUsd: numberEnv('PAPER_MAX_POSITION_USD', 20),
    minConfidence: numberEnv('PAPER_MIN_CONFIDENCE', 70),
    feeRate: numberEnv('PAPER_FEE_RATE', 0.001),
    symbols: splitList(env('PAPER_SYMBOLS', 'BTCUSDT,ETHUSDT,SOLUSDT,XAUUSDT,XAGUSDT,TSLAUSDT,NVDAUSDT,CLUSDT,XAUTUSDT,USDTEUR,BTCEUR,ETHEUR')),
    requireDeepSeekOk: env('PAPER_REQUIRE_DEEPSEEK_OK', 'true') === 'true',
    requireCursorOk: env('PAPER_REQUIRE_CURSOR_OK', 'true') === 'true'
  },
  scalp: {
    enabled: env('SCALP_ENABLED', 'true') === 'true',
    interval: env('SCALP_INTERVAL', '5'),
    klineLimit: numberEnv('SCALP_KLINE_LIMIT', 120),
    minConfidence: numberEnv('SCALP_MIN_CONFIDENCE', 62),
    takeProfitPct: numberEnv('SCALP_TAKE_PROFIT_PCT', 0.35),
    stopLossPct: numberEnv('SCALP_STOP_LOSS_PCT', 0.18),
    maxSpreadPct: numberEnv('SCALP_MAX_SPREAD_PCT', 0.08),
    paperEnabled: env('SCALP_PAPER_ENABLED', 'true') === 'true',
    maxPositionUsd: numberEnv('SCALP_MAX_POSITION_USD', 15),
    symbols: splitList(env('SCALP_SYMBOLS', 'BTCUSDT,ETHUSDT,SOLUSDT,USDTEUR,BTCEUR,ETHEUR')),
    knowledgePath: env('SCALP_KNOWLEDGE_PATH', path.join(__dirname, 'knowledge', 'scalping-kb.json'))
  },
  regime: {
    enabled: env('REGIME_GATE_ENABLED', 'true') === 'true',
    adxTrendMin: numberEnv('REGIME_ADX_TREND_MIN', 22),
    volatilityHighPct: numberEnv('REGIME_VOLATILITY_HIGH_PCT', 3.2),
    rangeTrendMaxPct: numberEnv('REGIME_RANGE_TREND_MAX_PCT', 0.35)
  },
  ensemble: {
    enabled: env('ENSEMBLE_SCORING_ENABLED', 'true') === 'true',
    weights: {
      technical: numberEnv('ENSEMBLE_WEIGHT_TECHNICAL', 0.4),
      microstructure: numberEnv('ENSEMBLE_WEIGHT_MICROSTRUCTURE', 0.15),
      derivatives: numberEnv('ENSEMBLE_WEIGHT_DERIVATIVES', 0.2),
      sentiment: numberEnv('ENSEMBLE_WEIGHT_SENTIMENT', 0.25)
    }
  },
  qualityFeedback: {
    enabled: env('QUALITY_FEEDBACK_ENABLED', 'true') === 'true',
    minSamples: numberEnv('QUALITY_FEEDBACK_MIN_SAMPLES', 5),
    poorHitRatePct: numberEnv('QUALITY_FEEDBACK_POOR_HIT_RATE', 45),
    goodHitRatePct: numberEnv('QUALITY_FEEDBACK_GOOD_HIT_RATE', 55),
    confidencePenalty: numberEnv('QUALITY_FEEDBACK_CONFIDENCE_PENALTY', 8),
    confidenceBonus: numberEnv('QUALITY_FEEDBACK_CONFIDENCE_BONUS', 3)
  }
};

main().catch((error) => {
  console.error('Error:', error.message);
  if (error.details) {
    console.error(JSON.stringify(error.details, null, 2));
  }
  process.exit(1);
});

async function main() {
  if (command === 'help' || args.help) {
    printHelp();
    return;
  }

  if (command === 'once') {
    const report = await runBrainCycle();
    console.log(JSON.stringify(report, null, 2));
    return;
  }

  if (command === 'status') {
    const latest = readLatestDecisions(numberArg(args.limit, 5));
    console.log(JSON.stringify(latest, null, 2));
    return;
  }

  if (command === 'loop') {
    console.log(`Trading brain loop started. interval=${config.loopIntervalSeconds}s dryRun=${config.dryRun}`);
    while (true) {
      try {
        const report = await runBrainCycle();
        console.log(`${report.timestamp} ${report.summary}`);
      } catch (error) {
        console.error(new Date().toISOString(), error.message);
      }
      await sleep(config.loopIntervalSeconds * 1000);
    }
  }

  throw new Error(`Unknown command: ${command}`);
}

async function runBrainCycle() {
  ensureDir(config.dataDir);
  const priorQuality = readJsonFile(path.join(config.dataDir, 'quality.json')) || { symbols: {} };
  const qualityFeedback = buildQualityFeedback(priorQuality);
  const [markets, news, fearGreed] = await Promise.all([
    collectMarkets(),
    collectNews().catch((error) => ({
      sourceCount: 0,
      items: [],
      score: 0,
      error: error.message
    })),
    collectFearGreed().catch((error) => ({
      available: false,
      error: error.message
    }))
  ]);

  const decisions = await Promise.all(markets.map(async (market) => {
    const regime = detectMarketRegime(market);
    let signal = analyzeMarket(market, news, fearGreed, qualityFeedback);
    signal = applyRegimeToSignal(signal, regime, market);
    signal.regime = regime;
    const [aiAnalyst, algoVaultAnalyst] = await Promise.all([
      runAiAnalyst(market, news, signal, fearGreed),
      runAlgoVaultAnalyst(market)
    ]);
    const cursorAnalyst = await runCursorAnalyst(market, news, signal, aiAnalyst, algoVaultAnalyst, fearGreed);
    const consensus = applyRegimeToConsensus(
      applyAlgoVaultConsensus(
        combineSignals(signal, aiAnalyst, cursorAnalyst),
        algoVaultAnalyst
      ),
      regime,
      signal
    );
    const risk = applyRiskManager(consensus, market, aiAnalyst, cursorAnalyst, fearGreed, signal, regime);
    const scalpSignal = applyRegimeToScalp(analyzeScalpStrategy(market, fearGreed, regime), regime, market);
    return {
      timestamp: new Date().toISOString(),
      symbol: market.symbol,
      market,
      news: summarizeNewsForDecision(news),
      fearGreed,
      signal,
      scalpSignal,
      aiAnalyst,
      algoVaultAnalyst,
      cursorAnalyst,
      consensus,
      risk,
      qualityFeedback: getSymbolQualityFeedback(qualityFeedback, market.symbol),
      finalAction: risk.allowed ? consensus.action : 'WAIT',
      dryRun: config.dryRun
    };
  }));

  appendJsonl(path.join(config.dataDir, 'decisions.jsonl'), decisions);
  const paper = updatePaperState(decisions);
  const quality = computeSignalQuality(readAllDecisions());
  writeJson(path.join(config.dataDir, 'latest.json'), {
    timestamp: new Date().toISOString(),
    dryRun: config.dryRun,
    decisions,
    paper,
    quality
  });

  return {
    timestamp: new Date().toISOString(),
    dryRun: config.dryRun,
    summary: decisions.map((item) => `${item.symbol}:${item.finalAction}:${item.consensus.confidence}`).join(' '),
    decisions,
    paper,
    quality
  };
}

async function collectMarkets() {
  const results = [];
  for (const symbol of config.symbols) {
    const category = marketCategory(symbol);
    const assetClass = marketAssetClass(symbol);
    const scalpQuery = config.scalp.enabled ? bybitPublic('/v5/market/kline', {
      category,
      symbol,
      interval: config.scalp.interval,
      limit: String(config.scalp.klineLimit)
    }) : Promise.resolve(null);

    const [tickerData, klineData, scalpKlineData, orderBookData, derivativesTickerData, openInterestData] = await Promise.all([
      bybitPublic('/v5/market/tickers', { category, symbol }),
      bybitPublic('/v5/market/kline', {
        category,
        symbol,
        interval: config.interval,
        limit: String(config.klineLimit)
      }),
      scalpQuery,
      bybitPublicOrNull('/v5/market/orderbook', { category, symbol, limit: '50' }),
      bybitPublicOrNull('/v5/market/tickers', { category: 'linear', symbol }),
      bybitPublicOrNull('/v5/market/open-interest', { category: 'linear', symbol, intervalTime: '5min', limit: '2' })
    ]);

    const ticker = first(tickerData.result && tickerData.result.list);
    const rawKlines = klineData.result && klineData.result.list ? klineData.result.list : [];
    const candles = rawKlines
      .map((row) => ({
        start: Number(row[0]),
        open: Number(row[1]),
        high: Number(row[2]),
        low: Number(row[3]),
        close: Number(row[4]),
        volume: Number(row[5])
      }))
      .sort((a, b) => a.start - b.start);

    if (!ticker || candles.length < 30) {
      throw new Error(`Not enough market data for ${symbol}`);
    }

    const closes = candles.map((candle) => candle.close);
    const highs = candles.map((candle) => candle.high);
    const lows = candles.map((candle) => candle.low);
    const volumes = candles.map((candle) => candle.volume);
    const last = closes[closes.length - 1];
    const previous = closes[closes.length - 2];
    const sma20 = average(closes.slice(-20));
    const sma50 = average(closes.slice(-50));
    const ema12Values = emaSeries(closes, 12);
    const ema26Values = emaSeries(closes, 26);
    const ema12 = ema12Values[ema12Values.length - 1];
    const ema26 = ema26Values[ema26Values.length - 1];
    const macdValues = ema12Values.map((value, index) => value - ema26Values[index]);
    const macdSignalValues = emaSeries(macdValues, 9);
    const macdLine = macdValues[macdValues.length - 1];
    const macdSignal = macdSignalValues[macdSignalValues.length - 1];
    const macdHistogram = macdLine - macdSignal;
    const previousMacdHistogram = macdValues[macdValues.length - 2] - macdSignalValues[macdSignalValues.length - 2];
    const bollingerStdDev = standardDeviation(closes.slice(-20));
    const bollingerUpper = sma20 + (2 * bollingerStdDev);
    const bollingerLower = sma20 - (2 * bollingerStdDev);
    const bollingerWidthPct = safeDivide(bollingerUpper - bollingerLower, sma20) * 100;
    const bollingerPosition = safeDivide(last - bollingerLower, bollingerUpper - bollingerLower);
    const priorWindow = candles.slice(-51, -1);
    const support = Math.min(...priorWindow.map((candle) => candle.low));
    const resistance = Math.max(...priorWindow.map((candle) => candle.high));
    const distanceToSupportPct = percentChange(support, last);
    const distanceToResistancePct = percentChange(last, resistance);
    const rsi14 = rsi(closes, 14);
    const momentumPct = percentChange(previous, last);
    const trendPct = percentChange(sma50, sma20);
    const volatilityPct = averageTrueRangePercent(candles.slice(-14));
    const adx14 = adx(candles, 14);
    const volumeRatio = safeDivide(average(volumes.slice(-5)), average(volumes.slice(-30)));
    const orderBook = summarizeOrderBook(orderBookData);
    const derivatives = summarizeDerivatives(derivativesTickerData, openInterestData);
    const scalpIndicators = buildScalpIndicators(parseKlineRows(scalpKlineData));

    results.push({
      symbol,
      category,
      assetClass,
      lastPrice: Number(ticker.lastPrice || last),
      change24hPct: Number(ticker.price24hPcnt || 0) * 100,
      turnover24h: Number(ticker.turnover24h || 0),
      volume24h: Number(ticker.volume24h || 0),
      indicators: {
        sma20: round(sma20, 4),
        sma50: round(sma50, 4),
        ema12: round(ema12, 4),
        ema26: round(ema26, 4),
        macdLine: round(macdLine, 4),
        macdSignal: round(macdSignal, 4),
        macdHistogram: round(macdHistogram, 4),
        macdHistogramDelta: round(macdHistogram - previousMacdHistogram, 4),
        bollingerUpper: round(bollingerUpper, 4),
        bollingerMiddle: round(sma20, 4),
        bollingerLower: round(bollingerLower, 4),
        bollingerWidthPct: round(bollingerWidthPct, 3),
        bollingerPosition: round(bollingerPosition, 3),
        support: round(support, 4),
        resistance: round(resistance, 4),
        distanceToSupportPct: round(distanceToSupportPct, 3),
        distanceToResistancePct: round(distanceToResistancePct, 3),
        rsi14: round(rsi14, 2),
        momentumPct: round(momentumPct, 3),
        trendPct: round(trendPct, 3),
        volatilityPct: round(volatilityPct, 3),
        adx14: round(adx14, 2),
        volumeRatio: round(volumeRatio, 3)
      },
      orderBook,
      derivatives,
      scalpIndicators
    });
  }
  return results;
}

function parseKlineRows(klineData) {
  const rawKlines = klineData && klineData.result && klineData.result.list ? klineData.result.list : [];
  return rawKlines
    .map((row) => ({
      start: Number(row[0]),
      open: Number(row[1]),
      high: Number(row[2]),
      low: Number(row[3]),
      close: Number(row[4]),
      volume: Number(row[5])
    }))
    .sort((a, b) => a.start - b.start);
}

function buildScalpIndicators(candles) {
  if (!candles || candles.length < 30) {
    return { available: false };
  }

  const closes = candles.map((candle) => candle.close);
  const volumes = candles.map((candle) => candle.volume);
  const last = closes[closes.length - 1];
  const ema9Values = emaSeries(closes, 9);
  const ema21Values = emaSeries(closes, 21);
  const ema9 = ema9Values[ema9Values.length - 1];
  const ema21 = ema21Values[ema21Values.length - 1];
  const sma50 = average(closes.slice(-50));
  const priorWindow = candles.slice(-21, -1);
  const support = Math.min(...priorWindow.map((candle) => candle.low));
  const resistance = Math.max(...priorWindow.map((candle) => candle.high));
  const last3 = closes.slice(-3);
  const impulseUp = last3.length === 3 && last3[2] > last3[1] && last3[1] > last3[0];
  const impulseDown = last3.length === 3 && last3[2] < last3[1] && last3[1] < last3[0];
  const volumeRatio = safeDivide(average(volumes.slice(-3)), average(volumes.slice(-20)));
  const distanceToEma21Pct = percentChange(ema21, last);
  const pivotWindow = candles.slice(-13, -1);
  const pivotHigh = Math.max(...pivotWindow.map((candle) => candle.high));
  const pivotLow = Math.min(...pivotWindow.map((candle) => candle.low));
  const pivotClose = pivotWindow[pivotWindow.length - 1].close;
  const pivot = (pivotHigh + pivotLow + pivotClose) / 3;
  const pivotR1 = (2 * pivot) - pivotLow;
  const pivotS1 = (2 * pivot) - pivotHigh;
  const pivotR2 = pivot + (pivotHigh - pivotLow);
  const pivotS2 = pivot - (pivotHigh - pivotLow);
  const nearPivotS1 = Math.abs(percentChange(pivotS1, last)) <= 0.12;
  const nearPivot = Math.abs(percentChange(pivot, last)) <= 0.12;
  const nearPivotR1 = Math.abs(percentChange(pivotR1, last)) <= 0.12;
  const midPivotRange = last > pivotS1 && last < pivotR1
    && !nearPivotS1 && !nearPivot && !nearPivotR1;

  return {
    available: true,
    timeframe: `${config.scalp.interval}m`,
    ema9: round(ema9, 4),
    ema21: round(ema21, 4),
    sma50: round(sma50, 4),
    rsi7: round(rsi(closes, 7), 2),
    volumeRatio: round(volumeRatio, 3),
    impulseUp,
    impulseDown,
    priceAboveEma21: last > ema21,
    distanceToEma21Pct: round(distanceToEma21Pct, 3),
    support: round(support, 4),
    resistance: round(resistance, 4),
    distanceToSupportPct: round(percentChange(support, last), 3),
    distanceToResistancePct: round(percentChange(last, resistance), 3),
    momentumPct: round(percentChange(closes[closes.length - 2], last), 3),
    pivots: {
      pp: round(pivot, 4),
      r1: round(pivotR1, 4),
      s1: round(pivotS1, 4),
      r2: round(pivotR2, 4),
      s2: round(pivotS2, 4),
      nearS1: nearPivotS1,
      nearPp: nearPivot,
      nearR1: nearPivotR1,
      midRange: midPivotRange,
      distanceToPpPct: round(percentChange(pivot, last), 3),
      distanceToS1Pct: round(percentChange(pivotS1, last), 3),
      distanceToR1Pct: round(percentChange(last, pivotR1), 3)
    }
  };
}

function loadScalpingKnowledge() {
  const filePath = config.scalp.knowledgePath;
  const fallback = {
    version: 0,
    books: [],
    sources: [
      'Murphy — trend / volume / S/R',
      'Solabuto — impulse / quick exits'
    ]
  };
  try {
    const data = readJsonFile(filePath);
    if (!data) {
      return fallback;
    }
    return data;
  } catch (error) {
    return { ...fallback, error: error.message };
  }
}

function analyzeScalpStrategy(market, fearGreed = {}, regime = {}) {
  if (!config.scalp.enabled) {
    return {
      enabled: false,
      strategy: 'scalping_kb_v1',
      action: null,
      confidence: 0,
      reasons: ['Scalp strategy disabled']
    };
  }

  const knowledge = loadScalpingKnowledge();
  const bookSources = (knowledge.books || []).map((book) => `${book.author} — ${book.title}`);
  const scalp = market.scalpIndicators || {};
  const pivots = scalp.pivots || {};
  const trend15m = market.indicators.sma20 > market.indicators.sma50 ? 'up' : 'down';
  if (!scalp.available) {
    return {
      enabled: true,
      strategy: 'scalping_kb_v1',
      action: 'WAIT',
      confidence: 0,
      trend15m,
      knowledgeBooks: bookSources,
      reasons: ['Not enough 5m data for scalp']
    };
  }

  let score = 0;
  const reasons = [];
  const appliedRules = [];

  // Murphy / Young: higher-TF trend filter + wait for setup
  if (trend15m === 'up') {
    score += 12;
    reasons.push('Murphy/Young: trade with 15m uptrend');
    appliedRules.push('wait_setup');
  } else {
    score -= 14;
    reasons.push('Murphy/Young: 15m downtrend, long scalp filtered');
    appliedRules.push('wait_setup');
  }

  // Murphy / Borovkov: volume confirms move; cut noise when volume dead
  if (scalp.volumeRatio > 1.12) {
    score += 8;
    reasons.push('Murphy: volume confirms short-term move');
  } else if (scalp.volumeRatio < 0.85) {
    score -= 6;
    reasons.push('Borovkov/Young: weak volume — cut noise, skip scalp');
    appliedRules.push('cut_noise');
  }

  // Solabuto: impulse
  if (scalp.impulseUp) {
    score += 10;
    reasons.push('Solabuto: bullish 5m impulse');
  } else if (scalp.impulseDown) {
    score -= 10;
    reasons.push('Solabuto: bearish 5m impulse');
  }

  // Borovkov: no chase — only pullback to EMA21
  if (trend15m === 'up' && scalp.priceAboveEma21 && scalp.distanceToEma21Pct >= 0 && scalp.distanceToEma21Pct <= 0.35) {
    score += 8;
    reasons.push('Borovkov: pullback to fair price (EMA21), no chase');
    appliedRules.push('no_chase');
  } else if (trend15m === 'up' && scalp.distanceToEma21Pct > 0.55) {
    score -= 8;
    reasons.push('Borovkov: stretched from EMA21 — do not chase');
    appliedRules.push('no_chase');
  }

  if (scalp.ema9 > scalp.ema21) {
    score += 5;
  } else {
    score -= 5;
  }

  // Shiryaev: Pivot Points S/R
  if (pivots.nearS1 && trend15m === 'up' && scalp.rsi7 >= 34) {
    score += 9;
    reasons.push('Shiryaev: Pivot S1 bounce zone (conservative long)');
    appliedRules.push('pivot_sr');
  } else if (pivots.nearPp && trend15m === 'up' && scalp.impulseUp) {
    score += 5;
    reasons.push('Shiryaev: Pivot PP with impulse');
    appliedRules.push('pivot_sr');
  } else if (pivots.nearR1) {
    score -= 8;
    reasons.push('Shiryaev: near Pivot R1 — avoid new long');
    appliedRules.push('pivot_sr');
  } else if (pivots.midRange && !scalp.impulseUp) {
    score -= 5;
    reasons.push('Shiryaev: mid Pivot range without impulse — wait');
    appliedRules.push('avoid_mid_range');
  }

  if (scalp.distanceToResistancePct >= 0 && scalp.distanceToResistancePct < 0.25) {
    score -= 9;
    reasons.push('Solabuto/Shiryaev: too close to resistance');
  }

  if (scalp.distanceToSupportPct >= 0 && scalp.distanceToSupportPct < 0.35 && scalp.rsi7 >= 34) {
    score += 5;
    reasons.push('Murphy: support bounce zone');
  }

  if (scalp.rsi7 >= 42 && scalp.rsi7 <= 58) {
    score += 6;
    reasons.push('RSI7 in scalp entry zone');
  } else if (scalp.rsi7 > 68) {
    score -= 8;
    reasons.push('RSI7 overheated for scalp long');
  }

  // CScalp: order book / walls / imbalance
  const orderBook = market.orderBook || {};
  if (orderBook.available) {
    if (orderBook.pressure === 'buy') {
      score += 5;
      reasons.push('CScalp: order book buy pressure');
      appliedRules.push('orderbook_confirm');
    } else if (orderBook.pressure === 'sell') {
      score -= 6;
      reasons.push('CScalp: order book sell pressure — no long');
      appliedRules.push('orderbook_confirm');
    }

    if (orderBook.imbalance > 0.3) {
      score += 4;
      reasons.push('CScalp: bid depth imbalance supports long');
      appliedRules.push('depth_imbalance');
    } else if (orderBook.imbalance < -0.3) {
      score -= 4;
      reasons.push('CScalp: ask depth imbalance blocks long');
      appliedRules.push('depth_imbalance');
    }

    const askWall = orderBook.askWall || {};
    const bidWall = orderBook.bidWall || {};
    const lastPrice = market.lastPrice;
    if (askWall.price && lastPrice && percentChange(lastPrice, askWall.price) < 0.2 && askWall.usd > (orderBook.askDepthUsd || 0) * 0.25) {
      score -= 7;
      reasons.push('CScalp: nearby ask wall blocks long');
      appliedRules.push('wall_filter');
    }
    if (bidWall.price && lastPrice && percentChange(bidWall.price, lastPrice) < 0.2 && bidWall.usd > (orderBook.bidDepthUsd || 0) * 0.25) {
      score += 4;
      reasons.push('CScalp: nearby bid wall supports long');
      appliedRules.push('wall_filter');
    }

    // Borovkov: microstructure — max spread
    if (orderBook.spreadPct > config.scalp.maxSpreadPct) {
      score -= 12;
      reasons.push('Borovkov: spread too wide for microscopic profit');
      appliedRules.push('max_spread');
    }
  }

  if (fearGreed.available && fearGreed.value >= 78) {
    score -= 6;
    reasons.push('Macro greed filter: avoid aggressive scalp long');
  }

  // Young: incomplete setup → force WAIT bias
  const setupReady = trend15m === 'up'
    && scalp.impulseUp
    && scalp.volumeRatio >= 0.95
    && (scalp.distanceToEma21Pct <= 0.4 || pivots.nearS1 || pivots.nearPp);
  if (!setupReady && score > 0) {
    score -= 4;
    reasons.push('Young: incomplete short-term setup — reduce conviction');
    appliedRules.push('wait_setup');
  }

  const confidence = clamp(Math.round(50 + score), 0, 100);
  let action = 'WAIT';
  if (trend15m === 'up' && confidence >= config.scalp.minConfidence && setupReady) {
    action = 'BUY';
  } else if (trend15m === 'up' && confidence >= config.scalp.minConfidence && !setupReady) {
    action = 'WAIT';
    reasons.push('Young/Borovkov: confidence ok but setup incomplete — WAIT');
  } else if (trend15m === 'down' && confidence <= 38) {
    action = 'SELL';
  } else if (confidence >= 52) {
    action = 'HOLD';
  }

  return {
    enabled: true,
    strategy: 'scalping_kb_v1',
    sources: [
      ...bookSources,
      'Murphy — Technical Analysis of the Futures Markets (trend, volume, S/R)',
      'Solabuto — impulse, sizing, quick exits'
    ],
    knowledgeVersion: knowledge.version || 1,
    appliedRules: [...new Set(appliedRules)],
    timeframe: scalp.timeframe,
    trend15m,
    setupReady,
    pivots,
    action,
    confidence,
    score: round(score, 2),
    takeProfitPct: config.scalp.takeProfitPct,
    stopLossPct: config.scalp.stopLossPct,
    reasons
  };
}

async function collectNews() {
  const allItems = [];
  const sourceErrors = {};
  const cutoff = Date.now() - config.newsLookbackHours * 60 * 60 * 1000;

  for (const source of config.newsSources) {
    try {
      const response = await fetch(source, { headers: { 'User-Agent': 'TradingBrain/1.0' } });
      if (!response.ok) {
        sourceErrors[sourceLabel(source)] = `HTTP ${response.status}`;
        continue;
      }
      const xml = await response.text();
      const items = parseRss(xml)
        .filter((item) => !item.timestamp || item.timestamp >= cutoff)
        .slice(0, 20)
        .map((item) => ({ ...item, source }));
      allItems.push(...items);
    } catch (error) {
      sourceErrors[sourceLabel(source)] = error.message;
    }
  }

  for (const source of config.htmlNewsSources) {
    try {
      const response = await fetch(source, { headers: { 'User-Agent': 'TradingBrain/1.0' } });
      if (!response.ok) {
        sourceErrors[sourceLabel(source)] = `HTTP ${response.status}`;
        continue;
      }
      const html = await response.text();
      const items = parseHtmlNews(html, source)
        .filter((item) => !item.timestamp || item.timestamp >= cutoff)
        .slice(0, 20)
        .map((item) => ({ ...item, source, sourceLabel: sourceLabel(source) }));
      allItems.push(...items);
    } catch (error) {
      sourceErrors[sourceLabel(source)] = error.message;
    }
  }

  const scored = allItems.map((item) => ({
    ...item,
    sentiment: scoreText(`${item.title} ${item.description}`)
  }));
  const score = scored.length ? round(average(scored.map((item) => item.sentiment.score)), 2) : 0;
  const sourceCounts = {};
  for (const item of scored) {
    const label = item.sourceLabel || sourceLabel(item.source);
    sourceCounts[label] = (sourceCounts[label] || 0) + 1;
  }

  return {
    sourceCount: config.newsSources.length,
    itemCount: scored.length,
    sourceCounts,
    sourceErrors,
    score,
    items: scored
      .sort((a, b) => Math.abs(b.sentiment.score) - Math.abs(a.sentiment.score))
      .slice(0, 8)
      .map((item) => ({
        title: item.title,
        link: item.link,
        source: item.sourceLabel || sourceLabel(item.source),
        publishedAt: item.publishedAt,
        score: item.sentiment.score,
        hits: item.sentiment.hits
      }))
  };
}

async function collectFearGreed() {
  if (!config.fearGreed.enabled) {
    return {
      available: false,
      enabled: false,
      reason: 'disabled'
    };
  }

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), config.fearGreed.timeoutMs);
  try {
    const url = new URL(config.fearGreed.url);
    url.searchParams.set('limit', '1');
    url.searchParams.set('format', 'json');
    const response = await fetch(url, {
      signal: controller.signal,
      headers: { 'User-Agent': 'TradingBrain/1.0' }
    });
    const data = parseJson(await response.text());
    if (!response.ok || !data || !Array.isArray(data.data) || !data.data.length) {
      throw new Error(`Fear & Greed API HTTP ${response.status}`);
    }

    const latest = data.data[0];
    const value = clamp(Math.round(Number(latest.value) || 0), 0, 100);
    const classification = String(latest.value_classification || classifyFearGreed(value));
    const timestamp = latest.timestamp ? new Date(Number(latest.timestamp) * 1000).toISOString() : null;

    return {
      available: true,
      enabled: true,
      value,
      classification,
      score: scoreFearGreed(value),
      timestamp,
      source: 'alternative.me'
    };
  } finally {
    clearTimeout(timeout);
  }
}

function classifyFearGreed(value) {
  if (value <= 24) {
    return 'Extreme Fear';
  }
  if (value <= 44) {
    return 'Fear';
  }
  if (value <= 55) {
    return 'Neutral';
  }
  if (value <= 74) {
    return 'Greed';
  }
  return 'Extreme Greed';
}

function buildQualityFeedback(quality = {}) {
  const symbols = quality.symbols || {};
  const feedback = { symbols: {}, enabled: config.qualityFeedback.enabled };
  if (!config.qualityFeedback.enabled) {
    return feedback;
  }

  for (const [symbol, data] of Object.entries(symbols)) {
    const horizon = data.horizons && data.horizons['15m'];
    if (!horizon || horizon.evaluated < config.qualityFeedback.minSamples) {
      feedback.symbols[symbol] = {
        minConfidenceDelta: 0,
        confidenceDelta: 0,
        reason: 'insufficient samples',
        evaluated: horizon ? horizon.evaluated : 0
      };
      continue;
    }

    let minConfidenceDelta = 0;
    let confidenceDelta = 0;
    let reason = 'neutral';
    if (horizon.hitRatePct < config.qualityFeedback.poorHitRatePct) {
      minConfidenceDelta = config.qualityFeedback.confidencePenalty;
      confidenceDelta = -5;
      reason = `poor 15m hit-rate ${horizon.hitRatePct}%`;
    } else if (horizon.hitRatePct >= config.qualityFeedback.goodHitRatePct && horizon.avgEdgePct > 0) {
      minConfidenceDelta = -config.qualityFeedback.confidenceBonus;
      confidenceDelta = 2;
      reason = `good 15m hit-rate ${horizon.hitRatePct}%`;
    }

    feedback.symbols[symbol] = {
      minConfidenceDelta,
      confidenceDelta,
      hitRatePct: horizon.hitRatePct,
      avgEdgePct: horizon.avgEdgePct,
      evaluated: horizon.evaluated,
      reason
    };
  }

  return feedback;
}

function getSymbolQualityFeedback(qualityFeedback, symbol) {
  return (qualityFeedback.symbols && qualityFeedback.symbols[symbol]) || {
    minConfidenceDelta: 0,
    confidenceDelta: 0,
    reason: 'no data'
  };
}

function resolveEnsembleWeights(market) {
  const weights = { ...config.ensemble.weights };
  const derivatives = market.derivatives || {};
  const orderBook = market.orderBook || {};

  if (!derivatives.available) {
    weights.technical += weights.derivatives * 0.6;
    weights.sentiment += weights.derivatives * 0.4;
    weights.derivatives = 0;
  }
  if (!orderBook.available) {
    weights.technical += weights.microstructure;
    weights.microstructure = 0;
  }

  const total = Object.values(weights).reduce((sum, value) => sum + value, 0) || 1;
  return Object.fromEntries(Object.entries(weights).map(([key, value]) => [key, round(value / total, 4)]));
}

function detectMarketRegime(market) {
  const indicators = market.indicators || {};
  const adxValue = indicators.adx14 || 0;
  const volatility = indicators.volatilityPct || 0;
  const trendMagnitude = Math.abs(indicators.trendPct || 0);
  const bullish = indicators.sma20 > indicators.sma50;
  const reasons = [];

  if (volatility >= config.regime.volatilityHighPct) {
    reasons.push(`volatility ${volatility}% above ${config.regime.volatilityHighPct}%`);
    return {
      regime: 'volatile',
      strength: round(volatility, 2),
      adx: adxValue,
      preferredStrategy: 'wait',
      allowSwingBuy: false,
      allowSwingSell: true,
      allowScalp: false,
      reasons
    };
  }

  if (adxValue >= config.regime.adxTrendMin && trendMagnitude >= config.regime.rangeTrendMaxPct) {
    const regime = bullish ? 'trend_up' : 'trend_down';
    reasons.push(`ADX ${adxValue} with ${bullish ? 'bullish' : 'bearish'} trend ${round(indicators.trendPct, 2)}%`);
    return {
      regime,
      strength: round(adxValue, 2),
      adx: adxValue,
      preferredStrategy: bullish ? 'trend_follow' : 'defensive',
      allowSwingBuy: bullish,
      allowSwingSell: !bullish,
      allowScalp: bullish,
      reasons
    };
  }

  if (adxValue < config.regime.adxTrendMin && trendMagnitude < config.regime.rangeTrendMaxPct) {
    reasons.push(`ADX ${adxValue} low, trend magnitude ${round(trendMagnitude, 2)}%`);
    return {
      regime: 'range',
      strength: round(adxValue, 2),
      adx: adxValue,
      preferredStrategy: 'mean_reversion',
      allowSwingBuy: indicators.bollingerPosition <= 0.45 && indicators.rsi14 < 55,
      allowSwingSell: indicators.bollingerPosition >= 0.55 && indicators.rsi14 > 45,
      allowScalp: true,
      reasons
    };
  }

  reasons.push('mixed signals between trend and range');
  return {
    regime: 'transition',
    strength: round(adxValue, 2),
    adx: adxValue,
    preferredStrategy: 'cautious',
    allowSwingBuy: bullish && indicators.rsi14 < 68,
    allowSwingSell: !bullish && indicators.rsi14 > 32,
    allowScalp: bullish,
    reasons
  };
}

function applyRegimeToSignal(signal, regime, market) {
  const next = { ...signal, regimeApplied: config.regime.enabled };
  if (!config.regime.enabled) {
    return next;
  }

  const indicators = market.indicators || {};
  if (signal.action === 'BUY' && !regime.allowSwingBuy) {
    next.action = 'WAIT';
    next.confidence = Math.min(signal.confidence, 45);
    next.reasons = [...(signal.reasons || []), `Regime gate (${regime.regime}): swing BUY blocked`];
    next.regimeBlock = 'swing_buy';
    return next;
  }

  if (signal.action === 'SELL' && !regime.allowSwingSell) {
    next.action = 'HOLD';
    next.confidence = Math.min(signal.confidence, 50);
    next.reasons = [...(signal.reasons || []), `Regime gate (${regime.regime}): swing SELL softened`];
    return next;
  }

  if (regime.regime === 'range' && signal.action === 'BUY' && indicators.bollingerPosition > 0.72) {
    next.action = 'WAIT';
    next.confidence = Math.min(signal.confidence, 48);
    next.reasons = [...(signal.reasons || []), 'Regime gate (range): BUY blocked near upper Bollinger'];
    return next;
  }

  if (regime.regime === 'range' && signal.action === 'BUY' && indicators.bollingerPosition < 0.35) {
    next.confidence = clamp(next.confidence + 4, 0, 100);
    next.reasons = [...(signal.reasons || []), 'Regime gate (range): mean-reversion support boost'];
  }

  if (regime.regime === 'trend_up' && signal.action === 'BUY') {
    next.confidence = clamp(next.confidence + 3, 0, 100);
    next.reasons = [...(signal.reasons || []), 'Regime gate (trend_up): trend-follow boost'];
  }

  return next;
}

function applyRegimeToConsensus(consensus, regime, signal) {
  const next = {
    ...consensus,
    regime: signal.regime || regime,
    components: signal.components,
    ensembleWeights: signal.ensembleWeights,
    effectiveMinConfidence: signal.effectiveMinConfidence,
    qualityFeedback: signal.qualityFeedback
  };

  if (!config.regime.enabled) {
    return next;
  }

  if (consensus.action === 'BUY' && !regime.allowSwingBuy) {
    next.action = 'WAIT';
    next.confidence = Math.min(consensus.confidence, 42);
    next.source = `${consensus.source || 'rules'}_regime_block`;
    next.reasons = [...(consensus.reasons || []), `Regime gate (${regime.regime}) blocks consensus BUY`];
  }

  return next;
}

function applyRegimeToScalp(scalpSignal, regime, market) {
  const next = { ...scalpSignal, regime: regime.regime };
  if (!config.regime.enabled || !scalpSignal.enabled) {
    return next;
  }

  if (!regime.allowScalp && scalpSignal.action === 'BUY') {
    next.action = 'WAIT';
    next.confidence = Math.min(scalpSignal.confidence, 40);
    next.reasons = [...(scalpSignal.reasons || []), `Regime gate (${regime.regime}): scalp blocked`];
    return next;
  }

  if (regime.regime === 'volatile') {
    next.action = 'WAIT';
    next.confidence = Math.min(scalpSignal.confidence, 35);
    next.reasons = [...(scalpSignal.reasons || []), 'Regime gate (volatile): scalp blocked'];
    return next;
  }

  if (regime.regime === 'range' && scalpSignal.action === 'BUY') {
    const indicators = market.indicators || {};
    if (indicators.bollingerPosition > 0.68) {
      next.action = 'WAIT';
      next.confidence = Math.min(scalpSignal.confidence, 42);
      next.reasons = [...(scalpSignal.reasons || []), 'Regime gate (range): scalp blocked near resistance'];
    }
  }

  return next;
}

function scoreTechnicalComponent(market) {
  const indicators = market.indicators;
  let score = 0;
  const reasons = [];

  if (indicators.sma20 > indicators.sma50) {
    score += 18;
    reasons.push('short trend above long trend');
  } else {
    score -= 18;
    reasons.push('short trend below long trend');
  }

  if (indicators.ema12 > indicators.ema26) {
    score += 8;
    reasons.push('EMA12 above EMA26');
  } else {
    score -= 8;
    reasons.push('EMA12 below EMA26');
  }

  if (indicators.macdLine > indicators.macdSignal && indicators.macdHistogram > 0) {
    score += 10;
    reasons.push('MACD bullish');
  } else if (indicators.macdLine < indicators.macdSignal && indicators.macdHistogram < 0) {
    score -= 10;
    reasons.push('MACD bearish');
  }

  if (indicators.macdHistogramDelta > 0) {
    score += 4;
    reasons.push('MACD histogram improving');
  } else if (indicators.macdHistogramDelta < 0) {
    score -= 4;
    reasons.push('MACD histogram weakening');
  }

  if (indicators.bollingerPosition > 1) {
    score -= 7;
    reasons.push('price above upper Bollinger band');
  } else if (indicators.bollingerPosition < 0) {
    score -= 8;
    reasons.push('price below lower Bollinger band');
  } else if (indicators.bollingerPosition >= 0.25 && indicators.bollingerPosition <= 0.75) {
    score += 3;
    reasons.push('price inside balanced Bollinger zone');
  }

  if (indicators.distanceToResistancePct >= 0 && indicators.distanceToResistancePct < 0.35) {
    score -= 5;
    reasons.push('price close to resistance');
  }

  if (indicators.distanceToSupportPct >= 0 && indicators.distanceToSupportPct < 0.35 && indicators.rsi14 >= 40) {
    score += 4;
    reasons.push('price near support with acceptable RSI');
  }

  if (indicators.rsi14 >= 45 && indicators.rsi14 <= 62) {
    score += 12;
    reasons.push('RSI in constructive range');
  } else if (indicators.rsi14 > 72) {
    score -= 16;
    reasons.push('RSI overheated');
  } else if (indicators.rsi14 < 32) {
    score -= 8;
    reasons.push('RSI weak/oversold');
  }

  if (indicators.momentumPct > 0) {
    score += Math.min(12, indicators.momentumPct * 8);
    reasons.push('positive short momentum');
  } else {
    score += Math.max(-12, indicators.momentumPct * 8);
    reasons.push('negative short momentum');
  }

  if (indicators.volumeRatio > 1.15) {
    score += 8;
    reasons.push('volume expansion');
  }

  if (market.change24hPct < -4) {
    score -= 10;
    reasons.push('large 24h drawdown');
  }

  if (market.change24hPct > 8) {
    score -= 8;
    reasons.push('large 24h pump risk');
  }

  return { score: round(score, 2), reasons };
}

function scoreSentimentComponent(news, fearGreed = {}) {
  let score = 0;
  const reasons = [];

  if (news.score > 0) {
    score += Math.min(10, news.score);
    reasons.push('news sentiment positive');
  } else if (news.score < 0) {
    score += Math.max(-14, news.score);
    reasons.push('news sentiment negative');
  }

  if (fearGreed.available) {
    const fearGreedScore = fearGreed.score || scoreFearGreed(fearGreed.value);
    score += fearGreedScore;
    if (fearGreedScore > 0) {
      reasons.push(`Fear & Greed supportive (${fearGreed.value}, ${fearGreed.classification})`);
    } else if (fearGreedScore < 0) {
      reasons.push(`Fear & Greed cautious (${fearGreed.value}, ${fearGreed.classification})`);
    } else {
      reasons.push(`Fear & Greed neutral (${fearGreed.value})`);
    }
  }

  return { score: round(score, 2), reasons };
}

function scoreMicrostructureComponent(market) {
  let score = 0;
  const reasons = [];
  const orderBook = market.orderBook || {};

  if (!orderBook.available) {
    return { score: 0, reasons: ['order book unavailable'] };
  }

  if (orderBook.pressure === 'buy') {
    score += 6;
    reasons.push('order book buy pressure');
  } else if (orderBook.pressure === 'sell') {
    score -= 6;
    reasons.push('order book sell pressure');
  }

  if (orderBook.imbalance > 0.3) {
    score += 3;
    reasons.push('strong bid-side depth');
  } else if (orderBook.imbalance < -0.3) {
    score -= 3;
    reasons.push('strong ask-side depth');
  }

  if (orderBook.spreadPct > 0.12) {
    score -= 4;
    reasons.push('wide order book spread');
  }

  return { score: round(score, 2), reasons };
}

function scoreDerivativesComponent(market) {
  let score = 0;
  const reasons = [];
  const derivatives = market.derivatives || {};
  const indicators = market.indicators || {};

  if (!derivatives.available) {
    return { score: 0, reasons: ['derivatives unavailable'] };
  }

  if (derivatives.fundingRatePct > 0.03) {
    score -= 5;
    reasons.push('crowded long funding');
  } else if (derivatives.fundingRatePct < -0.03) {
    score += 4;
    reasons.push('negative funding supports squeeze');
  }

  if (derivatives.basisPct > 0.15) {
    score += 3;
    reasons.push('futures premium over index');
  } else if (derivatives.basisPct < -0.15) {
    score -= 3;
    reasons.push('futures discount to index');
  }

  if (derivatives.openInterestChangePct > 2 && indicators.momentumPct > 0) {
    score += 4;
    reasons.push('rising open interest with momentum');
  } else if (derivatives.openInterestChangePct < -2 && indicators.momentumPct < 0) {
    score -= 4;
    reasons.push('falling open interest with weakness');
  }

  return { score: round(score, 2), reasons };
}

function scoreFearGreed(value) {
  if (value <= 20) {
    return 3;
  }
  if (value <= 35) {
    return 1;
  }
  if (value >= 80) {
    return -8;
  }
  if (value >= 65) {
    return -5;
  }
  if (value >= 56) {
    return -2;
  }
  return 0;
}

function analyzeMarket(market, news, fearGreed = {}, qualityFeedback = { symbols: {} }) {
  const symbolFeedback = getSymbolQualityFeedback(qualityFeedback, market.symbol);
  const components = {
    technical: scoreTechnicalComponent(market),
    sentiment: scoreSentimentComponent(news, fearGreed),
    microstructure: scoreMicrostructureComponent(market),
    derivatives: scoreDerivativesComponent(market)
  };
  const ensembleWeights = resolveEnsembleWeights(market);
  let score = 0;
  const reasons = [];

  if (config.ensemble.enabled) {
    for (const [name, component] of Object.entries(components)) {
      const weight = ensembleWeights[name] || 0;
      score += component.score * weight;
      if (component.reasons.length) {
        reasons.push(`${name}: ${component.reasons.slice(0, 2).join('; ')}`);
      }
    }
    score = round(score, 2);
  } else {
    score = components.technical.score
      + components.sentiment.score
      + components.microstructure.score
      + components.derivatives.score;
    reasons.push(...components.technical.reasons, ...components.sentiment.reasons);
  }

  let confidence = clamp(Math.round(50 + score + (symbolFeedback.confidenceDelta || 0)), 0, 100);
  const effectiveMinConfidence = clamp(
    config.minConfidence + (symbolFeedback.minConfidenceDelta || 0),
    55,
    90
  );

  let action = 'HOLD';
  if (confidence >= effectiveMinConfidence) {
    action = 'BUY';
  } else if (confidence <= 35) {
    action = 'SELL';
  }

  if (symbolFeedback.reason && symbolFeedback.reason !== 'no data' && symbolFeedback.reason !== 'neutral') {
    reasons.push(`Quality feedback: ${symbolFeedback.reason}`);
  }

  return {
    action,
    confidence,
    score,
    components: Object.fromEntries(Object.entries(components).map(([name, component]) => [name, {
      score: component.score,
      weightedScore: round(component.score * (ensembleWeights[name] || 0), 2),
      reasons: component.reasons
    }])),
    ensembleWeights,
    effectiveMinConfidence,
    qualityFeedback: symbolFeedback,
    source: config.ensemble.enabled ? 'ensemble_rules' : 'rules_only',
    reasons
  };
}

async function runAiAnalyst(market, news, signal, fearGreed = {}) {
  if (!config.ai.enabled) {
    return {
      enabled: false,
      status: 'disabled',
      provider: config.ai.provider,
      model: config.ai.model,
      action: null,
      confidence: 0,
      riskLevel: 'unknown',
      veto: false,
      reasoning: 'AI Analyst is disabled. Set AI_ANALYST_ENABLED=true and AI_ANALYST_API_KEY to enable.',
      factors: []
    };
  }

  if (!config.ai.apiKey) {
    return {
      enabled: true,
      status: 'missing_api_key',
      provider: config.ai.provider,
      model: config.ai.model,
      action: null,
      confidence: 0,
      riskLevel: 'unknown',
      veto: true,
      reasoning: 'AI Analyst is enabled but AI_ANALYST_API_KEY is missing.',
      factors: []
    };
  }

  try {
    const response = await aiRequest(buildAiMessages(market, news, signal, fearGreed));
    return normalizeAiVerdict(response);
  } catch (error) {
    return {
      enabled: true,
      status: 'error',
      provider: config.ai.provider,
      model: config.ai.model,
      action: null,
      confidence: 0,
      riskLevel: 'unknown',
      veto: true,
      reasoning: `AI Analyst error: ${error.message}`,
      factors: []
    };
  }
}

function buildAiMessages(market, news, signal, fearGreed = {}) {
  const payload = {
    symbol: market.symbol,
    market: {
      lastPrice: market.lastPrice,
      change24hPct: market.change24hPct,
      turnover24h: market.turnover24h,
      volume24h: market.volume24h,
      indicators: market.indicators,
      orderBook: market.orderBook || { available: false },
      derivatives: market.derivatives || { available: false }
    },
    news: summarizeNewsForDecision(news),
    fearGreed: summarizeFearGreedForDecision(fearGreed),
    ruleSignal: signal,
    constraints: {
      mode: 'dry-run analysis only',
      allowedActions: ['BUY', 'SELL', 'HOLD', 'WAIT'],
      noLeverage: true,
      preferCapitalProtection: true
    }
  };

  return [
    {
      role: 'system',
      content: [
        'You are a conservative crypto trading analyst.',
        'Return only valid JSON.',
        'Do not suggest leverage.',
        'If data is mixed, uncertain, or news risk is elevated, prefer HOLD or WAIT.',
        'JSON schema: {"action":"BUY|SELL|HOLD|WAIT","confidence":0-100,"riskLevel":"low|medium|high","veto":boolean,"reasoning":"short reason","factors":["factor"]}.'
      ].join(' ')
    },
    {
      role: 'user',
      content: JSON.stringify(payload)
    }
  ];
}

async function aiRequest(messages) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), config.ai.timeoutMs);
  try {
    const response = await fetch(`${config.ai.baseUrl}/chat/completions`, {
      method: 'POST',
      signal: controller.signal,
      headers: {
        'Authorization': `Bearer ${config.ai.apiKey}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        model: config.ai.model,
        messages,
        temperature: 0.1,
        response_format: { type: 'json_object' }
      })
    });
    const text = await response.text();
    const data = parseJson(text);
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }
    const content = data && data.choices && data.choices[0] && data.choices[0].message
      ? data.choices[0].message.content
      : '';
    const parsed = parseJson(content);
    if (!parsed) {
      throw new Error('AI response was not valid JSON');
    }
    return parsed;
  } finally {
    clearTimeout(timeout);
  }
}

function normalizeAiVerdict(raw) {
  const action = normalizeAction(raw.action);
  const confidence = clamp(Math.round(Number(raw.confidence) || 0), 0, 100);
  const riskLevel = ['low', 'medium', 'high'].includes(String(raw.riskLevel || '').toLowerCase())
    ? String(raw.riskLevel).toLowerCase()
    : 'unknown';
  const factors = Array.isArray(raw.factors) ? raw.factors.map((item) => String(item)).slice(0, 6) : [];

  return {
    enabled: true,
    status: 'ok',
    provider: config.ai.provider,
    model: config.ai.model,
    action,
    confidence,
    riskLevel,
    veto: Boolean(raw.veto),
    reasoning: String(raw.reasoning || '').slice(0, 500),
    factors
  };
}

async function runCursorAnalyst(market, news, signal, aiAnalyst, algoVaultAnalyst = {}, fearGreed = {}) {
  if (!config.cursor.enabled) {
    return {
      enabled: false,
      status: 'disabled',
      provider: 'cursor',
      model: config.cursor.model,
      action: null,
      confidence: 0,
      riskLevel: 'unknown',
      veto: false,
      reasoning: 'Cursor Analyst is disabled. Set CURSOR_ANALYST_ENABLED=true and CURSOR_API_KEY to enable.',
      factors: []
    };
  }

  if (!config.cursor.apiKey) {
    return {
      enabled: true,
      status: 'missing_api_key',
      provider: 'cursor',
      model: config.cursor.model,
      action: null,
      confidence: 0,
      riskLevel: 'unknown',
      veto: true,
      reasoning: 'Cursor Analyst is enabled but CURSOR_API_KEY is missing.',
      factors: []
    };
  }

  try {
    const raw = await cursorRequest(buildCursorPrompt(market, news, signal, aiAnalyst, algoVaultAnalyst, fearGreed));
    return normalizeCursorVerdict(raw);
  } catch (error) {
    return {
      enabled: true,
      status: 'error',
      provider: 'cursor',
      model: config.cursor.model,
      action: null,
      confidence: 0,
      riskLevel: 'unknown',
      veto: true,
      reasoning: `Cursor Analyst error: ${error.message}`,
      factors: []
    };
  }
}

function buildCursorPrompt(market, news, signal, aiAnalyst, algoVaultAnalyst = {}, fearGreed = {}) {
  return [
    'You are Cursor Analyst, an independent conservative reviewer for a crypto trading brain.',
    'Do not inspect or modify files. Use only the JSON payload in this prompt.',
    'AlgoVault MCP quant signals are pre-fetched in algoVaultAnalyst. You may also call get_trade_call via MCP if needed.',
    'Return only valid JSON with this schema:',
    '{"action":"BUY|SELL|HOLD|WAIT","confidence":0-100,"riskLevel":"low|medium|high","veto":boolean,"reasoning":"short reason","factors":["factor"]}',
    'Prefer HOLD or WAIT when signal quality is weak, AI providers disagree, news risk is high, Fear & Greed is extreme, or edge is unclear.',
    JSON.stringify({
      symbol: market.symbol,
      market: {
        lastPrice: market.lastPrice,
        change24hPct: market.change24hPct,
        turnover24h: market.turnover24h,
        volume24h: market.volume24h,
        indicators: market.indicators,
        orderBook: market.orderBook || { available: false },
        derivatives: market.derivatives || { available: false }
      },
      news: summarizeNewsForDecision(news),
      fearGreed: summarizeFearGreedForDecision(fearGreed),
      ruleSignal: signal,
      deepSeekAnalyst: aiAnalyst,
      algoVaultAnalyst,
      constraints: {
        mode: 'dry-run analysis only',
        allowedActions: ['BUY', 'SELL', 'HOLD', 'WAIT'],
        noLeverage: true,
        preferCapitalProtection: true
      }
    })
  ].join('\n\n');
}

async function runAlgoVaultAnalyst(market) {
  if (!config.algoVault.enabled) {
    return {
      enabled: false,
      status: 'disabled',
      provider: 'algovault',
      model: 'crypto-quant-signal-mcp',
      action: null,
      confidence: 0,
      riskLevel: 'unknown',
      veto: false,
      reasoning: 'AlgoVault analyst is disabled. Set ALGOVAULT_ENABLED=true to enable.',
      factors: [],
      regime: null,
      indicators: null
    };
  }

  try {
    const coin = symbolToCoin(market.symbol);
    const timeframe = intervalToTimeframe(config.interval);
    const exchange = config.algoVault.exchange;
    const [tradeCall, regime] = await Promise.all([
      algoVaultMcpCall('get_trade_call', {
        coin,
        timeframe,
        exchange,
        includeReasoning: true
      }),
      config.algoVault.fetchRegime
        ? algoVaultMcpCall('get_market_regime', { coin, timeframe, exchange })
        : Promise.resolve(null)
    ]);

    return normalizeAlgoVaultVerdict(tradeCall, regime, { coin, timeframe, exchange });
  } catch (error) {
    return {
      enabled: true,
      status: 'error',
      provider: 'algovault',
      model: 'crypto-quant-signal-mcp',
      action: null,
      confidence: 0,
      riskLevel: 'unknown',
      veto: false,
      reasoning: `AlgoVault analyst error: ${error.message}`,
      factors: [],
      regime: null,
      indicators: null
    };
  }
}

async function algoVaultMcpCall(toolName, args) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), config.algoVault.timeoutMs);
  try {
    const response = await fetch(config.algoVault.mcpUrl, {
      method: 'POST',
      signal: controller.signal,
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json, text/event-stream'
      },
      body: JSON.stringify({
        jsonrpc: '2.0',
        id: Date.now(),
        method: 'tools/call',
        params: {
          name: toolName,
          arguments: args
        }
      })
    });
    const text = await response.text();
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }
    return parseAlgoVaultMcpPayload(text);
  } finally {
    clearTimeout(timeout);
  }
}

function parseAlgoVaultMcpPayload(text) {
  const dataLines = text
    .split('\n')
    .filter((line) => line.startsWith('data: '))
    .map((line) => line.slice(6));

  for (const line of dataLines.reverse()) {
    const envelope = parseJson(line);
    if (!envelope || !envelope.result || !Array.isArray(envelope.result.content)) {
      continue;
    }
    for (const block of envelope.result.content) {
      if (!block || block.type !== 'text' || !block.text) {
        continue;
      }
      const parsed = parseJsonLoose(block.text);
      if (parsed && typeof parsed === 'object') {
        if (parsed.error || parsed.error_code || parsed.code === 'TIER_LIMIT_REACHED') {
          throw new Error(parsed.message || parsed.error || parsed.error_code || 'AlgoVault MCP error');
        }
        return parsed;
      }
    }
  }

  throw new Error('AlgoVault MCP response did not contain JSON payload');
}

function normalizeAlgoVaultVerdict(tradeCall, regime, meta = {}) {
  const action = normalizeAction(tradeCall && tradeCall.call);
  const confidence = clamp(Math.round(Number(tradeCall && tradeCall.confidence) || 0), 0, 100);
  const regimeLabel = (regime && regime.regime) || (tradeCall && tradeCall.regime) || null;
  const riskLevel = regimeLabel === 'VOLATILE' ? 'high' : (confidence < 45 ? 'medium' : 'low');

  return {
    enabled: true,
    status: 'ok',
    provider: 'algovault',
    model: 'crypto-quant-signal-mcp',
    action,
    confidence,
    riskLevel,
    veto: false,
    reasoning: (tradeCall && tradeCall.reasoning) || 'AlgoVault composite quant call',
    factors: buildAlgoVaultFactors(tradeCall, regime),
    regime: regimeLabel,
    indicators: tradeCall && tradeCall.indicators ? tradeCall.indicators : null,
    price: tradeCall && tradeCall.price ? tradeCall.price : null,
    exchange: meta.exchange || config.algoVault.exchange,
    timeframe: meta.timeframe || intervalToTimeframe(config.interval),
    coin: meta.coin || null,
    raw: {
      tradeCall,
      regime
    }
  };
}

function buildAlgoVaultFactors(tradeCall, regime) {
  const factors = [];
  if (tradeCall && tradeCall.regime) {
    factors.push(`regime:${tradeCall.regime}`);
  }
  if (regime && regime.regime && regime.regime !== tradeCall.regime) {
    factors.push(`regime_check:${regime.regime}`);
  }
  if (tradeCall && tradeCall.indicators) {
    const indicators = tradeCall.indicators;
    if (indicators.funding_state) {
      factors.push(`funding:${indicators.funding_state}`);
    }
    if (typeof indicators.oi_change_pct === 'number') {
      factors.push(`oi_change:${indicators.oi_change_pct}%`);
    }
    if (indicators.trend_persistence) {
      factors.push(`trend:${indicators.trend_persistence}`);
    }
  }
  return factors;
}

function applyAlgoVaultConsensus(consensus, algoVaultAnalyst = {}) {
  const next = {
    ...consensus,
    algoVaultAgreement: algoVaultAnalyst.status || 'not_checked',
    reasons: [...(consensus.reasons || [])]
  };

  if (!algoVaultAnalyst.enabled || algoVaultAnalyst.status === 'disabled') {
    return next;
  }

  if (algoVaultAnalyst.status !== 'ok' || !algoVaultAnalyst.action) {
    next.reasons.push(algoVaultAnalyst.reasoning || 'AlgoVault analyst unavailable');
    return next;
  }

  const algoAction = algoVaultAnalyst.action;
  const algoConfidence = algoVaultAnalyst.confidence || 0;

  if (algoConfidence < config.algoVault.minConfidence) {
    if (consensus.action === 'BUY' || consensus.action === 'SELL') {
      next.action = 'HOLD';
      next.confidence = Math.min(consensus.confidence, algoConfidence, 55);
      next.source = 'algovault_low_confidence';
      next.reasons.push(`AlgoVault confidence below minimum (${algoConfidence})`);
    }
    return next;
  }

  if (algoAction === 'HOLD' || algoAction === 'WAIT') {
    if (consensus.action === 'BUY' || consensus.action === 'SELL') {
      next.action = 'HOLD';
      next.confidence = Math.min(consensus.confidence, algoConfidence);
      next.source = 'algovault_hold';
      next.algoVaultAgreement = 'hold';
      next.reasons.push(`AlgoVault suggests no trade: ${algoVaultAnalyst.reasoning}`);
    }
    return next;
  }

  if (algoAction !== consensus.action) {
    next.action = 'WAIT';
    next.confidence = Math.min(consensus.confidence, algoConfidence);
    next.source = 'algovault_disagree';
    next.algoVaultAgreement = 'disagree';
    next.reasons.push(`AlgoVault disagrees (${algoAction}): ${algoVaultAnalyst.reasoning}`);
    return next;
  }

  next.confidence = clamp(Math.round((consensus.confidence + algoConfidence) / 2) + 3, 0, 100);
  next.source = `${consensus.source || 'rules'}_algovault_agree`;
  next.algoVaultAgreement = 'agree';
  next.reasons.push(`AlgoVault agrees (${algoAction}, ${algoConfidence}): ${algoVaultAnalyst.reasoning}`);
  return next;
}

function symbolToCoin(symbol) {
  return String(symbol || '').replace(/USDT$/i, '');
}

function marketCategory(symbol) {
  return config.linearSymbols.has(symbol) ? 'linear' : config.category;
}

function marketAssetClass(symbol) {
  if (/^(XAU|XAG|PAXG)/.test(symbol)) {
    return 'metal';
  }
  if (symbol === 'CLUSDT') {
    return 'commodity';
  }
  if (/^(TSLA|AAPL|NVDA|MSFT|GOOGL|META|COIN|MSTR|HOOD|ORCL|INTC|MU|TSM|SNDK|CRCL)USDT$/.test(symbol)) {
    return 'stock';
  }
  if (/^(USDT|USDC)(EUR|GBP)$/.test(symbol) || /^(BTC|ETH)(EUR|GBP)$/.test(symbol)) {
    return 'forex';
  }
  if (symbol.endsWith('USDT')) {
    return 'crypto';
  }
  return 'other';
}

function intervalToTimeframe(interval) {
  const value = String(interval || '15');
  if (/^\d+m$/i.test(value)) {
    return value.toLowerCase();
  }
  if (/^\d+h$/i.test(value)) {
    return value.toLowerCase();
  }
  if (/^\d+d$/i.test(value)) {
    return value.toLowerCase();
  }
  const minutes = Number(value);
  if (!Number.isFinite(minutes) || minutes <= 0) {
    return '15m';
  }
  if (minutes < 60) {
    return `${minutes}m`;
  }
  if (minutes % 60 === 0) {
    return `${minutes / 60}h`;
  }
  return '15m';
}

async function cursorRequest(prompt) {
  const { Agent } = await import('@cursor/sdk');
  const options = {
    apiKey: config.cursor.apiKey,
    model: { id: config.cursor.model },
    local: { cwd: config.cursor.cwd, settingSources: [] }
  };

  if (config.algoVault.enabled && config.algoVault.attachToCursor) {
    options.mcpServers = {
      'crypto-quant-signal': {
        type: 'http',
        url: config.algoVault.mcpUrl
      }
    };
  }

  const request = Agent.prompt(prompt, options);

  const result = await withTimeout(request, config.cursor.timeoutMs, 'Cursor Analyst timed out');
  if (result.status !== 'finished') {
    throw new Error(`Cursor run ended with status ${result.status}`);
  }
  const parsed = parseJsonLoose(result.result || '');
  if (!parsed) {
    throw new Error('Cursor response was not valid JSON');
  }
  return parsed;
}

function normalizeCursorVerdict(raw) {
  const normalized = normalizeAiVerdict(raw);
  return {
    ...normalized,
    provider: 'cursor',
    model: config.cursor.model
  };
}

function combineSignals(ruleSignal, aiAnalyst, cursorAnalyst = {}) {
  if (!aiAnalyst.enabled || aiAnalyst.status === 'disabled') {
    return combineWithCursorOnly(ruleSignal, cursorAnalyst);
  }

  if (aiAnalyst.status !== 'ok' || aiAnalyst.veto) {
    return {
      action: 'WAIT',
      confidence: Math.min(ruleSignal.confidence, 40),
      score: ruleSignal.score,
      source: 'ai_veto',
      aiAgreement: aiAnalyst.status,
      cursorAgreement: cursorAnalyst.status || 'not_checked',
      reasons: [...ruleSignal.reasons, aiAnalyst.reasoning || 'AI analyst vetoed the signal']
    };
  }

  if (!aiAnalyst.action || aiAnalyst.confidence < config.ai.minConfidence) {
    return {
      action: ruleSignal.action === 'BUY' ? 'HOLD' : ruleSignal.action,
      confidence: Math.min(ruleSignal.confidence, aiAnalyst.confidence || 50),
      score: ruleSignal.score,
      source: 'ai_low_confidence',
      aiAgreement: 'low_confidence',
      cursorAgreement: cursorAnalyst.status || 'not_checked',
      reasons: [...ruleSignal.reasons, 'AI analyst confidence below minimum']
    };
  }

  if (cursorAnalyst.enabled && cursorAnalyst.status !== 'disabled') {
    if (cursorAnalyst.status !== 'ok' || cursorAnalyst.veto) {
      return {
        action: 'WAIT',
        confidence: Math.min(ruleSignal.confidence, aiAnalyst.confidence, 40),
        score: ruleSignal.score,
        source: 'cursor_veto',
        aiAgreement: 'checked',
        cursorAgreement: cursorAnalyst.status,
        reasons: [...ruleSignal.reasons, cursorAnalyst.reasoning || 'Cursor Analyst vetoed the signal']
      };
    }
    if (cursorAnalyst.confidence < config.cursor.minConfidence) {
      return {
        action: aiAnalyst.action === 'BUY' ? 'HOLD' : aiAnalyst.action,
        confidence: Math.min(ruleSignal.confidence, aiAnalyst.confidence, cursorAnalyst.confidence || 50),
        score: ruleSignal.score,
        source: 'cursor_low_confidence',
        aiAgreement: 'checked',
        cursorAgreement: 'low_confidence',
        reasons: [...ruleSignal.reasons, 'Cursor Analyst confidence below minimum']
      };
    }
    if (cursorAnalyst.action !== aiAnalyst.action || cursorAnalyst.action !== ruleSignal.action) {
      return {
        action: 'WAIT',
        confidence: Math.min(ruleSignal.confidence, aiAnalyst.confidence, cursorAnalyst.confidence),
        score: ruleSignal.score,
        source: 'multi_ai_disagree',
        aiAgreement: 'mixed',
        cursorAgreement: 'disagree',
        reasons: [...ruleSignal.reasons, `Cursor Analyst disagrees: ${cursorAnalyst.action} - ${cursorAnalyst.reasoning}`]
      };
    }
  }

  if (aiAnalyst.action === ruleSignal.action) {
    const activeConfidences = [ruleSignal.confidence, aiAnalyst.confidence];
    if (cursorAnalyst.status === 'ok') {
      activeConfidences.push(cursorAnalyst.confidence);
    }
    return {
      action: ruleSignal.action,
      confidence: clamp(Math.round(average(activeConfidences)) + 5, 0, 100),
      score: ruleSignal.score,
      source: cursorAnalyst.status === 'ok' ? 'rules_deepseek_cursor_consensus' : 'rules_ai_consensus',
      aiAgreement: 'agree',
      cursorAgreement: cursorAnalyst.status === 'ok' ? 'agree' : (cursorAnalyst.status || 'not_enabled'),
      reasons: [...ruleSignal.reasons, `AI analyst agrees: ${aiAnalyst.reasoning}`]
    };
  }

  return {
    action: 'WAIT',
    confidence: Math.min(ruleSignal.confidence, aiAnalyst.confidence),
    score: ruleSignal.score,
    source: 'rules_ai_disagree',
    aiAgreement: 'disagree',
    cursorAgreement: cursorAnalyst.status || 'not_checked',
    reasons: [...ruleSignal.reasons, `AI analyst disagrees: ${aiAnalyst.action} - ${aiAnalyst.reasoning}`]
  };
}

function combineWithCursorOnly(ruleSignal, cursorAnalyst) {
  if (!cursorAnalyst.enabled || cursorAnalyst.status === 'disabled') {
    return {
      ...ruleSignal,
      source: 'rules_only',
      aiAgreement: 'not_enabled',
      cursorAgreement: 'not_enabled',
      reasons: [...ruleSignal.reasons, 'AI analyst disabled', 'Cursor Analyst disabled']
    };
  }

  if (cursorAnalyst.status !== 'ok' || cursorAnalyst.veto) {
    return {
      action: 'WAIT',
      confidence: Math.min(ruleSignal.confidence, 40),
      score: ruleSignal.score,
      source: 'cursor_veto',
      aiAgreement: 'not_enabled',
      cursorAgreement: cursorAnalyst.status,
      reasons: [...ruleSignal.reasons, cursorAnalyst.reasoning || 'Cursor Analyst vetoed the signal']
    };
  }

  if (cursorAnalyst.action === ruleSignal.action && cursorAnalyst.confidence >= config.cursor.minConfidence) {
    return {
      action: ruleSignal.action,
      confidence: clamp(Math.round((ruleSignal.confidence + cursorAnalyst.confidence) / 2) + 5, 0, 100),
      score: ruleSignal.score,
      source: 'rules_cursor_consensus',
      aiAgreement: 'not_enabled',
      cursorAgreement: 'agree',
      reasons: [...ruleSignal.reasons, `Cursor Analyst agrees: ${cursorAnalyst.reasoning}`]
    };
  }

  return {
    action: 'WAIT',
    confidence: Math.min(ruleSignal.confidence, cursorAnalyst.confidence || 40),
    score: ruleSignal.score,
    source: 'rules_cursor_disagree',
    aiAgreement: 'not_enabled',
    cursorAgreement: 'disagree',
    reasons: [...ruleSignal.reasons, `Cursor Analyst does not confirm: ${cursorAnalyst.action || 'none'} - ${cursorAnalyst.reasoning}`]
  };
}

function normalizeAction(action) {
  const value = String(action || '').toUpperCase();
  return ['BUY', 'SELL', 'HOLD', 'WAIT'].includes(value) ? value : null;
}

function applyRiskManager(signal, market, aiAnalyst = {}, cursorAnalyst = {}, fearGreed = {}, ruleSignal = {}, regime = {}) {
  const i = market.indicators;
  let riskScore = 0;
  const blocks = [];
  const minConfidence = Number(ruleSignal.effectiveMinConfidence) || config.minConfidence;

  if (!config.dryRun) {
    blocks.push('live mode disabled for this brain stage');
  }

  if (signal.confidence < minConfidence && signal.action === 'BUY') {
    blocks.push(`confidence below effective minimum (${minConfidence})`);
  }

  if (config.regime.enabled && regime.regime === 'volatile' && signal.action === 'BUY') {
    riskScore += 20;
    blocks.push('volatile regime blocks entries');
  }

  if (config.regime.enabled && regime.regime === 'trend_down' && signal.action === 'BUY') {
    riskScore += 15;
    blocks.push('downtrend regime blocks swing BUY');
  }

  if (aiAnalyst.enabled && aiAnalyst.status !== 'disabled') {
    if (aiAnalyst.status !== 'ok') {
      blocks.push('AI analyst unavailable');
    }
    if (aiAnalyst.riskLevel === 'high') {
      riskScore += 25;
      blocks.push('AI analyst marked high risk');
    }
  }

  if (cursorAnalyst.enabled && cursorAnalyst.status !== 'disabled') {
    if (cursorAnalyst.status !== 'ok') {
      blocks.push('Cursor Analyst unavailable');
    }
    if (cursorAnalyst.riskLevel === 'high') {
      riskScore += 25;
      blocks.push('Cursor Analyst marked high risk');
    }
  }

  if (i.volatilityPct > 3.5) {
    riskScore += 25;
    blocks.push('volatility too high');
  } else {
    riskScore += i.volatilityPct * 4;
  }

  if (i.rsi14 > 76) {
    riskScore += 18;
    blocks.push('RSI extreme');
  }

  if (market.change24hPct < -8 || market.change24hPct > 12) {
    riskScore += 18;
    blocks.push('24h move outside safe range');
  }

  const orderBook = market.orderBook || {};
  if (orderBook.available && orderBook.spreadPct > 0.15) {
    riskScore += 15;
    blocks.push('order book spread too wide');
  }

  const derivatives = market.derivatives || {};
  if (derivatives.available) {
    if (Math.abs(derivatives.fundingRatePct) > 0.08) {
      riskScore += 12;
      blocks.push('extreme funding rate');
    }
    if (Math.abs(derivatives.basisPct) > 0.5) {
      riskScore += 10;
      blocks.push('futures basis too stretched');
    }
  }

  if (fearGreed.available) {
    if (fearGreed.value >= 80 && signal.action === 'BUY') {
      riskScore += 15;
      blocks.push('extreme market greed');
    } else if (fearGreed.value >= 75) {
      riskScore += 8;
    } else if (fearGreed.value <= 15) {
      riskScore += 10;
      blocks.push('extreme market fear');
    }
  }

  if (config.maxPositionUsd <= 0) {
    blocks.push('max position is zero');
  }

  if (riskScore > config.maxRiskScore) {
    blocks.push('risk score above maximum');
  }

  return {
    allowed: blocks.length === 0 && config.dryRun,
    riskScore: round(riskScore, 2),
    maxRiskScore: config.maxRiskScore,
    maxPositionUsd: config.maxPositionUsd,
    maxDailyLossUsd: config.maxDailyLossUsd,
    effectiveMinConfidence: minConfidence,
    regime: regime.regime || null,
    blocks
  };
}

async function bybitPublic(endpoint, query = {}) {
  const url = new URL(endpoint, config.baseUrl);
  for (const [key, value] of Object.entries(query)) {
    url.searchParams.set(key, value);
  }
  const response = await fetch(url);
  const text = await response.text();
  const data = parseJson(text);
  if (!response.ok || !data || data.retCode !== 0) {
    const error = new Error(`Bybit public request failed for ${endpoint}`);
    error.details = data || text;
    throw error;
  }
  return data;
}

async function bybitPublicOrNull(endpoint, query = {}) {
  try {
    return await bybitPublic(endpoint, query);
  } catch (error) {
    return null;
  }
}

function summarizeOrderBook(data) {
  const result = data && data.result ? data.result : {};
  const bids = Array.isArray(result.b) ? result.b.map(([price, size]) => ({ price: Number(price), size: Number(size) })).filter((level) => level.price && level.size) : [];
  const asks = Array.isArray(result.a) ? result.a.map(([price, size]) => ({ price: Number(price), size: Number(size) })).filter((level) => level.price && level.size) : [];
  if (!bids.length || !asks.length) {
    return { available: false };
  }

  const bestBid = bids[0].price;
  const bestAsk = asks[0].price;
  const mid = (bestBid + bestAsk) / 2;
  const bidDepthUsd = depthUsd(bids.slice(0, 20));
  const askDepthUsd = depthUsd(asks.slice(0, 20));
  const bidWall = largestWall(bids.slice(0, 20));
  const askWall = largestWall(asks.slice(0, 20));
  const imbalance = safeDivide(bidDepthUsd - askDepthUsd, bidDepthUsd + askDepthUsd);

  return {
    available: true,
    bestBid,
    bestAsk,
    spreadPct: round(safeDivide(bestAsk - bestBid, mid) * 100, 5),
    bidDepthUsd: round(bidDepthUsd, 2),
    askDepthUsd: round(askDepthUsd, 2),
    imbalance: round(imbalance, 4),
    bidWall,
    askWall,
    pressure: imbalance > 0.15 ? 'buy' : (imbalance < -0.15 ? 'sell' : 'neutral')
  };
}

function summarizeDerivatives(tickerData, openInterestData) {
  const ticker = first(tickerData && tickerData.result && tickerData.result.list);
  if (!ticker) {
    return { available: false };
  }

  const markPrice = Number(ticker.markPrice || 0);
  const indexPrice = Number(ticker.indexPrice || 0);
  const openInterest = Number(ticker.openInterest || 0);
  const openInterestValue = Number(ticker.openInterestValue || 0);
  const fundingRatePct = Number(ticker.fundingRate || 0) * 100;
  const openInterestList = openInterestData && openInterestData.result && openInterestData.result.list ? openInterestData.result.list : [];
  const oiNow = Number(openInterestList[0] && openInterestList[0].openInterest || openInterest);
  const oiPrev = Number(openInterestList[1] && openInterestList[1].openInterest || 0);

  return {
    available: true,
    markPrice,
    indexPrice,
    basisPct: indexPrice ? round(percentChange(indexPrice, markPrice), 4) : 0,
    fundingRatePct: round(fundingRatePct, 5),
    openInterest: round(openInterest, 4),
    openInterestValue: round(openInterestValue, 2),
    openInterestChangePct: oiPrev ? round(percentChange(oiPrev, oiNow), 4) : 0
  };
}

function depthUsd(levels) {
  return levels.reduce((sum, level) => sum + (level.price * level.size), 0);
}

function largestWall(levels) {
  const wall = levels.reduce((best, level) => {
    const usd = level.price * level.size;
    return usd > best.usd ? { price: level.price, size: level.size, usd } : best;
  }, { price: 0, size: 0, usd: 0 });
  return {
    price: wall.price,
    size: round(wall.size, 6),
    usd: round(wall.usd, 2)
  };
}

function parseRss(xml) {
  const matches = xml.match(/<item\b[\s\S]*?<\/item>/gi) || xml.match(/<entry\b[\s\S]*?<\/entry>/gi) || [];
  return matches.map((item) => {
    const title = decodeEntities(stripTags(pickTag(item, 'title')));
    const description = decodeEntities(stripTags(pickTag(item, 'description') || pickTag(item, 'summary') || pickTag(item, 'content:encoded')));
    const link = decodeEntities(pickTag(item, 'link') || pickLinkHref(item));
    const publishedAt = decodeEntities(pickTag(item, 'pubDate') || pickTag(item, 'updated') || pickTag(item, 'published'));
    const timestamp = publishedAt ? Date.parse(publishedAt) : 0;
    return { title, description, link, publishedAt, timestamp };
  }).filter((item) => item.title);
}

function parseHtmlNews(html, source) {
  if (source.includes('t.me/')) {
    return parseTelegramHtml(html, source);
  }
  if (source.includes('forklog.com')) {
    return parseForkLogHtml(html, source);
  }
  return parseGenericHtmlNews(html, source);
}

function parseForkLogHtml(html, source) {
  const seen = new Set();
  const items = [];
  const linkPattern = /<a\b[^>]*href=["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/gi;
  let match;
  while ((match = linkPattern.exec(html)) && items.length < 40) {
    const link = absolutizeUrl(decodeEntities(match[1]), source);
    if (!/forklog\.com\/(en\/)?[a-z0-9-]+/i.test(link) || seen.has(link)) {
      continue;
    }
    const title = decodeEntities(stripTags(match[2]));
    if (!isUsefulNewsTitle(title)) {
      continue;
    }
    seen.add(link);
    items.push({
      title,
      description: '',
      link,
      publishedAt: '',
      timestamp: 0
    });
  }
  return items;
}

function parseTelegramHtml(html, source) {
  const chunks = html.match(/<div class="tgme_widget_message_wrap[\s\S]*?(?=<div class="tgme_widget_message_wrap|<\/body>)/g) || [];
  return chunks.map((chunk) => {
    const title = decodeEntities(stripTags(pickClass(chunk, 'tgme_widget_message_text')));
    const publishedAt = decodeEntities(pickAttr(pickClassBlock(chunk, 'tgme_widget_message_date'), 'datetime'));
    const link = absolutizeUrl(decodeEntities(pickAttr(pickClassBlock(chunk, 'tgme_widget_message_date'), 'href')), source);
    return {
      title,
      description: title,
      link,
      publishedAt,
      timestamp: publishedAt ? Date.parse(publishedAt) : 0
    };
  }).filter((item) => isUsefulNewsTitle(item.title));
}

function parseGenericHtmlNews(html, source) {
  const matches = html.match(/<a\b[^>]*href=["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/gi) || [];
  const seen = new Set();
  const items = [];
  for (const raw of matches) {
    const href = pickAttr(raw, 'href');
    const title = decodeEntities(stripTags(raw));
    const link = absolutizeUrl(decodeEntities(href), source);
    if (!isUsefulNewsTitle(title) || seen.has(link)) {
      continue;
    }
    seen.add(link);
    items.push({ title, description: '', link, publishedAt: '', timestamp: 0 });
    if (items.length >= 20) {
      break;
    }
  }
  return items;
}

function scoreText(text) {
  const lower = text.toLowerCase();
  const positive = [
    'etf inflow', 'approval', 'approved', 'bullish', 'surge', 'rally', 'record high',
    'accumulation', 'institutional', 'adoption', 'rate cut', 'easing', 'breakout'
  ];
  const negative = [
    'hack', 'exploit', 'lawsuit', 'sec charges', 'outflow', 'ban', 'banned',
    'crackdown', 'liquidation', 'selloff', 'bearish', 'recession', 'rate hike'
  ];
  const hits = [];
  let score = 0;
  for (const word of positive) {
    if (containsTerm(lower, word)) {
      score += 2;
      hits.push(`+${word}`);
    }
  }
  for (const word of negative) {
    if (containsTerm(lower, word)) {
      score -= 3;
      hits.push(`-${word}`);
    }
  }
  return { score, hits };
}

function summarizeNewsForDecision(news) {
  return {
    score: news.score || 0,
    itemCount: news.itemCount || 0,
    sourceCounts: news.sourceCounts || {},
    sourceErrors: news.sourceErrors || {},
    error: news.error,
    top: (news.items || []).slice(0, 3)
  };
}

function summarizeFearGreedForDecision(fearGreed = {}) {
  if (!fearGreed || !fearGreed.available) {
    return {
      available: false,
      enabled: fearGreed.enabled !== false,
      error: fearGreed.error || fearGreed.reason || null
    };
  }

  return {
    available: true,
    enabled: true,
    value: fearGreed.value,
    classification: fearGreed.classification,
    score: fearGreed.score,
    timestamp: fearGreed.timestamp,
    source: fearGreed.source || 'alternative.me'
  };
}

function updatePaperState(decisions) {
  const statePath = path.join(config.dataDir, 'paper-state.json');
  const tradesPath = path.join(config.dataDir, 'paper-trades.jsonl');
  const now = new Date().toISOString();
  const state = readJsonFile(statePath) || {
    startedAt: now,
    cashUsd: config.paper.startBalanceUsd,
    startBalanceUsd: config.paper.startBalanceUsd,
    realizedPnlUsd: 0,
    positions: {},
    stats: {
      opened: 0,
      closed: 0,
      wins: 0,
      losses: 0
    },
    recentEvents: []
  };

  state.updatedAt = now;
  state.enabled = config.paper.enabled;
  state.config = {
    symbols: config.paper.symbols,
    maxPositionUsd: config.paper.maxPositionUsd,
    minConfidence: config.paper.minConfidence,
    feeRate: config.paper.feeRate,
    requireDeepSeekOk: config.paper.requireDeepSeekOk,
    requireCursorOk: config.paper.requireCursorOk,
    scalp: {
      enabled: config.scalp.paperEnabled,
      symbols: config.scalp.symbols,
      minConfidence: config.scalp.minConfidence,
      maxPositionUsd: config.scalp.maxPositionUsd,
      takeProfitPct: config.scalp.takeProfitPct,
      stopLossPct: config.scalp.stopLossPct
    }
  };

  const events = [];
  applyScalpPaperExits(state, decisions).forEach((event) => events.push(event));
  for (const decision of decisions) {
    const event = applyPaperDecision(state, decision);
    if (event) {
      events.push(event);
    }
    const scalpEvent = applyScalpPaperDecision(state, decision);
    if (scalpEvent) {
      events.push(scalpEvent);
    }
  }

  if (events.length) {
    appendJsonl(tradesPath, events);
    state.recentEvents = [...events, ...(state.recentEvents || [])].slice(0, 25);
  }

  const marks = markPaperPositions(state, decisions);
  state.openPositionCount = Object.keys(state.positions || {}).length;
  state.unrealizedPnlUsd = marks.unrealizedPnlUsd;
  state.openPositionValueUsd = marks.openPositionValueUsd;
  state.equityUsd = round((state.cashUsd || 0) + marks.openPositionValueUsd, 4);
  state.totalPnlUsd = round(state.equityUsd - state.startBalanceUsd, 4);
  state.totalPnlPct = round(percentChange(state.startBalanceUsd, state.equityUsd), 4);
  state.stats.winRatePct = state.stats.closed ? round((state.stats.wins / state.stats.closed) * 100, 2) : 0;

  writeJson(statePath, state);
  return summarizePaperState(state);
}

function applyPaperDecision(state, decision) {
  const symbol = decision.symbol;
  const price = Number(decision.market && decision.market.lastPrice);
  const action = decision.finalAction;
  const consensus = decision.consensus || {};
  const ai = decision.aiAnalyst || {};
  const cursor = decision.cursorAnalyst || {};
  const risk = decision.risk || {};
  const positions = state.positions || {};
  state.positions = positions;

  if (!config.paper.enabled || !price || !config.paper.symbols.includes(symbol)) {
    return null;
  }

  const position = positions[symbol];
  const paperMinConfidence = Math.max(
    config.paper.minConfidence,
    Number(decision.signal && decision.signal.effectiveMinConfidence) || 0
  );
  if (!position && action === 'BUY') {
    const blocks = [];
    if ((consensus.confidence || 0) < paperMinConfidence) {
      blocks.push(`paper confidence below minimum (${paperMinConfidence})`);
    }
    if (risk.blocks && risk.blocks.length) {
      blocks.push('risk manager has blocks');
    }
    if (config.paper.requireDeepSeekOk && ai.status !== 'ok') {
      blocks.push('DeepSeek not ok');
    }
    if (config.paper.requireCursorOk && cursor.status !== 'ok') {
      blocks.push('Cursor not ok');
    }
    if (config.paper.requireDeepSeekOk && ai.action !== 'BUY') {
      blocks.push('DeepSeek does not confirm BUY');
    }
    if (config.paper.requireCursorOk && cursor.action !== 'BUY') {
      blocks.push('Cursor does not confirm BUY');
    }
    if (blocks.length) {
      return paperEvent('SKIP_BUY', decision, price, { blocks });
    }

    const positionUsd = Math.min(config.paper.maxPositionUsd, state.cashUsd || 0);
    if (positionUsd <= 0) {
      return paperEvent('SKIP_BUY', decision, price, { blocks: ['no paper cash'] });
    }

    const feeUsd = positionUsd * config.paper.feeRate;
    const qty = positionUsd / price;
    positions[symbol] = {
      symbol,
      qty,
      entryPrice: price,
      entryTime: decision.timestamp,
      costUsd: positionUsd,
      openFeeUsd: feeUsd,
      confidence: consensus.confidence || 0
    };
    state.cashUsd = round((state.cashUsd || 0) - positionUsd - feeUsd, 4);
    state.stats.opened += 1;
    return paperEvent('OPEN', decision, price, { qty, positionUsd, feeUsd });
  }

  if (position && action === 'SELL') {
    const grossUsd = position.qty * price;
    const closeFeeUsd = grossUsd * config.paper.feeRate;
    const pnlUsd = grossUsd - closeFeeUsd - position.costUsd - position.openFeeUsd;
    state.cashUsd = round((state.cashUsd || 0) + grossUsd - closeFeeUsd, 4);
    state.realizedPnlUsd = round((state.realizedPnlUsd || 0) + pnlUsd, 4);
    state.stats.closed += 1;
    if (pnlUsd >= 0) {
      state.stats.wins += 1;
    } else {
      state.stats.losses += 1;
    }
    delete positions[symbol];
    return paperEvent('CLOSE', decision, price, {
      qty: position.qty,
      grossUsd,
      closeFeeUsd,
      pnlUsd,
      pnlPct: percentChange(position.costUsd + position.openFeeUsd, grossUsd - closeFeeUsd)
    });
  }

  return null;
}

function applyScalpPaperExits(state, decisions) {
  const events = [];
  if (!config.scalp.paperEnabled) {
    return events;
  }

  const latestPrices = {};
  const decisionBySymbol = {};
  for (const decision of decisions) {
    latestPrices[decision.symbol] = Number(decision.market && decision.market.lastPrice);
    decisionBySymbol[decision.symbol] = decision;
  }

  for (const [symbol, position] of Object.entries(state.positions || {})) {
    if (position.strategy !== 'scalp') {
      continue;
    }
    const price = latestPrices[symbol] || position.entryPrice;
    const grossUsd = position.qty * price;
    const closeFeeUsd = grossUsd * config.paper.feeRate;
    const pnlPct = percentChange(position.costUsd + position.openFeeUsd, grossUsd - closeFeeUsd);
    const decision = decisionBySymbol[symbol] || { symbol, market: { lastPrice: price } };
    const scalp = decision.scalpSignal || {};

    let exitReason = null;
    if (pnlPct >= config.scalp.takeProfitPct) {
      exitReason = `scalp take-profit ${config.scalp.takeProfitPct}%`;
    } else if (pnlPct <= -config.scalp.stopLossPct) {
      exitReason = `scalp stop-loss ${config.scalp.stopLossPct}%`;
    } else if (scalp.action === 'SELL') {
      exitReason = 'scalp signal exit';
    }

    if (!exitReason) {
      continue;
    }

    const pnlUsd = grossUsd - closeFeeUsd - position.costUsd - position.openFeeUsd;
    state.cashUsd = round((state.cashUsd || 0) + grossUsd - closeFeeUsd, 4);
    state.realizedPnlUsd = round((state.realizedPnlUsd || 0) + pnlUsd, 4);
    state.stats.closed += 1;
    if (pnlUsd >= 0) {
      state.stats.wins += 1;
    } else {
      state.stats.losses += 1;
    }
    delete state.positions[symbol];
    events.push(paperEvent('SCALP_CLOSE', decision, price, {
      qty: position.qty,
      grossUsd,
      closeFeeUsd,
      pnlUsd,
      pnlPct,
      reason: exitReason
    }));
  }

  return events;
}

function applyScalpPaperDecision(state, decision) {
  if (!config.scalp.paperEnabled) {
    return null;
  }

  const symbol = decision.symbol;
  const price = Number(decision.market && decision.market.lastPrice);
  const scalp = decision.scalpSignal || {};
  const positions = state.positions || {};
  state.positions = positions;

  if (!price || !config.scalp.symbols.includes(symbol) || positions[symbol]) {
    return null;
  }

  if (scalp.action !== 'BUY' || (scalp.confidence || 0) < config.scalp.minConfidence) {
    return null;
  }

  if (scalp.trend15m !== 'up') {
    return paperEvent('SCALP_SKIP_BUY', decision, price, { blocks: ['15m trend not up'] });
  }

  const orderBook = decision.market.orderBook || {};
  if (orderBook.available && orderBook.spreadPct > config.scalp.maxSpreadPct) {
    return paperEvent('SCALP_SKIP_BUY', decision, price, { blocks: ['spread too wide'] });
  }

  const positionUsd = Math.min(config.scalp.maxPositionUsd, state.cashUsd || 0);
  if (positionUsd <= 0) {
    return paperEvent('SCALP_SKIP_BUY', decision, price, { blocks: ['no paper cash'] });
  }

  const feeUsd = positionUsd * config.paper.feeRate;
  const qty = positionUsd / price;
  positions[symbol] = {
    symbol,
    strategy: 'scalp',
    qty,
    entryPrice: price,
    entryTime: decision.timestamp,
    costUsd: positionUsd,
    openFeeUsd: feeUsd,
    confidence: scalp.confidence || 0,
    takeProfitPct: config.scalp.takeProfitPct,
    stopLossPct: config.scalp.stopLossPct
  };
  state.cashUsd = round((state.cashUsd || 0) - positionUsd - feeUsd, 4);
  state.stats.opened += 1;
  return paperEvent('SCALP_OPEN', decision, price, { qty, positionUsd, feeUsd, scalp });
}

function paperEvent(type, decision, price, extra = {}) {
  return {
    timestamp: new Date().toISOString(),
    type,
    symbol: decision.symbol,
    action: decision.finalAction,
    price,
    confidence: decision.consensus ? decision.consensus.confidence : undefined,
    consensusSource: decision.consensus ? decision.consensus.source : undefined,
    deepSeek: decision.aiAnalyst ? decision.aiAnalyst.action : undefined,
    cursor: decision.cursorAnalyst ? decision.cursorAnalyst.action : undefined,
    ...extra
  };
}

function markPaperPositions(state, decisions) {
  const latestPrices = {};
  for (const decision of decisions) {
    latestPrices[decision.symbol] = Number(decision.market && decision.market.lastPrice);
  }

  let openPositionValueUsd = 0;
  let unrealizedPnlUsd = 0;
  for (const position of Object.values(state.positions || {})) {
    const price = latestPrices[position.symbol] || position.entryPrice;
    const valueUsd = position.qty * price;
    const closeFeeUsd = valueUsd * config.paper.feeRate;
    openPositionValueUsd += valueUsd;
    unrealizedPnlUsd += valueUsd - closeFeeUsd - position.costUsd - position.openFeeUsd;
    position.markPrice = price;
    position.unrealizedPnlUsd = round(valueUsd - closeFeeUsd - position.costUsd - position.openFeeUsd, 4);
    position.unrealizedPnlPct = round(percentChange(position.costUsd + position.openFeeUsd, valueUsd - closeFeeUsd), 4);
  }

  return {
    openPositionValueUsd: round(openPositionValueUsd, 4),
    unrealizedPnlUsd: round(unrealizedPnlUsd, 4)
  };
}

function summarizePaperState(state) {
  return {
    enabled: state.enabled,
    updatedAt: state.updatedAt,
    startBalanceUsd: state.startBalanceUsd,
    cashUsd: state.cashUsd,
    equityUsd: state.equityUsd,
    totalPnlUsd: state.totalPnlUsd,
    totalPnlPct: state.totalPnlPct,
    realizedPnlUsd: state.realizedPnlUsd,
    unrealizedPnlUsd: state.unrealizedPnlUsd,
    openPositionCount: state.openPositionCount,
    positions: state.positions,
    stats: state.stats,
    recentEvents: state.recentEvents || [],
    config: state.config
  };
}

function computeSignalQuality(rows) {
  const horizons = [
    { label: '15m', steps: 3 },
    { label: '1h', steps: 12 },
    { label: '4h', steps: 48 }
  ];
  const bySymbol = {};
  for (const row of rows) {
    bySymbol[row.symbol] = bySymbol[row.symbol] || [];
    bySymbol[row.symbol].push(row);
  }

  const symbols = {};
  for (const [symbol, items] of Object.entries(bySymbol)) {
    const actionCounts = {};
    for (const item of items) {
      actionCounts[item.finalAction] = (actionCounts[item.finalAction] || 0) + 1;
    }

    symbols[symbol] = {
      count: items.length,
      actionCounts,
      horizons: {}
    };

    for (const horizon of horizons) {
      const evaluated = [];
      for (let index = 0; index + horizon.steps < items.length; index += 1) {
        const current = items[index];
        const future = items[index + horizon.steps];
        if (!['BUY', 'SELL'].includes(current.finalAction)) {
          continue;
        }
        const currentPrice = Number(current.market && current.market.lastPrice);
        const futurePrice = Number(future.market && future.market.lastPrice);
        if (!currentPrice || !futurePrice) {
          continue;
        }
        const rawChangePct = percentChange(currentPrice, futurePrice);
        const edgePct = current.finalAction === 'BUY' ? rawChangePct : -rawChangePct;
        evaluated.push({
          hit: edgePct > 0,
          edgePct
        });
      }

      const hits = evaluated.filter((item) => item.hit).length;
      const edges = evaluated.map((item) => item.edgePct);
      symbols[symbol].horizons[horizon.label] = {
        evaluated: evaluated.length,
        hits,
        hitRatePct: evaluated.length ? round((hits / evaluated.length) * 100, 2) : 0,
        avgEdgePct: edges.length ? round(average(edges), 4) : 0,
        medianEdgePct: edges.length ? round(median(edges), 4) : 0
      };
    }
  }

  const quality = {
    updatedAt: new Date().toISOString(),
    totalDecisions: rows.length,
    symbols
  };
  writeJson(path.join(config.dataDir, 'quality.json'), quality);
  return quality;
}

function readAllDecisions() {
  const filePath = path.join(config.dataDir, 'decisions.jsonl');
  if (!fs.existsSync(filePath)) {
    return [];
  }
  return fs.readFileSync(filePath, 'utf8')
    .trim()
    .split('\n')
    .filter(Boolean)
    .map((line) => parseJson(line))
    .filter(Boolean);
}

function readLatestDecisions(limit) {
  const filePath = path.join(config.dataDir, 'decisions.jsonl');
  if (!fs.existsSync(filePath)) {
    return [];
  }
  return fs.readFileSync(filePath, 'utf8')
    .trim()
    .split('\n')
    .filter(Boolean)
    .slice(-limit)
    .map((line) => parseJson(line))
    .filter(Boolean);
}

function appendJsonl(filePath, rows) {
  const text = rows.map((row) => JSON.stringify(row)).join('\n') + '\n';
  fs.appendFileSync(filePath, text);
}

function writeJson(filePath, data) {
  fs.writeFileSync(filePath, JSON.stringify(data, null, 2));
}

function readJsonFile(filePath) {
  if (!fs.existsSync(filePath)) {
    return null;
  }
  return parseJson(fs.readFileSync(filePath, 'utf8'));
}

function loadDotEnv(filePath) {
  if (!fs.existsSync(filePath)) {
    return;
  }
  const lines = fs.readFileSync(filePath, 'utf8').split(/\r?\n/);
  for (const line of lines) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) {
      continue;
    }
    const separator = trimmed.indexOf('=');
    if (separator === -1) {
      continue;
    }
    const key = trimmed.slice(0, separator).trim();
    let value = trimmed.slice(separator + 1).trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }
    if (!Object.prototype.hasOwnProperty.call(process.env, key)) {
      process.env[key] = value;
    }
  }
}

function parseArgs(argv) {
  const parsed = {};
  for (let index = 0; index < argv.length; index += 1) {
    const item = argv[index];
    if (!item.startsWith('--')) {
      continue;
    }
    const [rawKey, inlineValue] = item.slice(2).split('=');
    const key = rawKey.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
    if (inlineValue !== undefined) {
      parsed[key] = inlineValue;
    } else if (argv[index + 1] && !argv[index + 1].startsWith('--')) {
      parsed[key] = argv[index + 1];
      index += 1;
    } else {
      parsed[key] = true;
    }
  }
  return parsed;
}

function pickTag(xml, tag) {
  const escaped = tag.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = xml.match(new RegExp(`<${escaped}\\b[^>]*>([\\s\\S]*?)<\\/${escaped}>`, 'i'));
  return match ? match[1].trim() : '';
}

function pickClass(html, className) {
  return stripTags(pickClassBlock(html, className));
}

function pickClassBlock(html, className) {
  const escaped = className.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = html.match(new RegExp(`<[^>]+class=["'][^"']*${escaped}[^"']*["'][^>]*>[\\s\\S]*?<\\/[^>]+>`, 'i'));
  return match ? match[0] : '';
}

function pickAttr(html, attr) {
  const escaped = attr.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = String(html || '').match(new RegExp(`${escaped}=["']([^"']+)["']`, 'i'));
  return match ? match[1] : '';
}

function pickLinkHref(xml) {
  const match = xml.match(/<link\b[^>]*href=["']([^"']+)["'][^>]*>/i);
  return match ? match[1] : '';
}

function absolutizeUrl(value, base) {
  try {
    return new URL(value, base).toString();
  } catch (error) {
    return value || base;
  }
}

function sourceLabel(source) {
  try {
    const url = new URL(source);
    if (url.hostname.includes('news.google.com')) {
      return 'Google News';
    }
    if (url.hostname.includes('forklog.com')) {
      return 'ForkLog';
    }
    if (url.hostname.includes('t.me')) {
      return 'Telegram';
    }
    return url.hostname.replace(/^www\./, '');
  } catch (error) {
    return source || 'unknown';
  }
}

function isUsefulNewsTitle(title) {
  const value = String(title || '').replace(/\s+/g, ' ').trim();
  if (value.length < 18 || value.length > 240) {
    return false;
  }
  const lower = value.toLowerCase();
  if (lower.includes('cookie') || lower.includes('privacy policy') || lower.includes('advertisement')) {
    return false;
  }
  return true;
}

function stripTags(value) {
  return String(value || '').replace(/<!\[CDATA\[([\s\S]*?)\]\]>/g, '$1').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
}

function decodeEntities(value) {
  return String(value || '')
    .replace(/<!\[CDATA\[([\s\S]*?)\]\]>/g, '$1')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'");
}

function containsTerm(text, term) {
  const escaped = term.trim().replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\s+/g, '\\s+');
  return new RegExp(`\\b${escaped}\\b`, 'i').test(text);
}

function rsi(values, period) {
  const slice = values.slice(-(period + 1));
  let gains = 0;
  let losses = 0;
  for (let index = 1; index < slice.length; index += 1) {
    const diff = slice[index] - slice[index - 1];
    if (diff >= 0) {
      gains += diff;
    } else {
      losses += Math.abs(diff);
    }
  }
  const averageGain = gains / period;
  const averageLoss = losses / period;
  if (averageLoss === 0) {
    return 100;
  }
  const rs = averageGain / averageLoss;
  return 100 - (100 / (1 + rs));
}

function averageTrueRangePercent(candles) {
  if (candles.length < 2) {
    return 0;
  }
  const ranges = [];
  for (let index = 1; index < candles.length; index += 1) {
    const candle = candles[index];
    const previous = candles[index - 1];
    ranges.push(Math.max(
      candle.high - candle.low,
      Math.abs(candle.high - previous.close),
      Math.abs(candle.low - previous.close)
    ));
  }
  return safeDivide(average(ranges), candles[candles.length - 1].close) * 100;
}

function adx(candles, period = 14) {
  if (!candles || candles.length < period + 2) {
    return 0;
  }

  const trueRanges = [];
  const plusDMs = [];
  const minusDMs = [];

  for (let index = 1; index < candles.length; index += 1) {
    const current = candles[index];
    const previous = candles[index - 1];
    const upMove = current.high - previous.high;
    const downMove = previous.low - current.low;
    plusDMs.push(upMove > downMove && upMove > 0 ? upMove : 0);
    minusDMs.push(downMove > upMove && downMove > 0 ? downMove : 0);
    trueRanges.push(Math.max(
      current.high - current.low,
      Math.abs(current.high - previous.close),
      Math.abs(current.low - previous.close)
    ));
  }

  if (trueRanges.length < period) {
    return 0;
  }

  let smoothedTr = average(trueRanges.slice(0, period));
  let smoothedPlus = average(plusDMs.slice(0, period));
  let smoothedMinus = average(minusDMs.slice(0, period));
  const dxValues = [];

  for (let index = period; index < trueRanges.length; index += 1) {
    smoothedTr = ((smoothedTr * (period - 1)) + trueRanges[index]) / period;
    smoothedPlus = ((smoothedPlus * (period - 1)) + plusDMs[index]) / period;
    smoothedMinus = ((smoothedMinus * (period - 1)) + minusDMs[index]) / period;
    const plusDI = smoothedTr ? (100 * smoothedPlus) / smoothedTr : 0;
    const minusDI = smoothedTr ? (100 * smoothedMinus) / smoothedTr : 0;
    const diSum = plusDI + minusDI;
    dxValues.push(diSum ? (100 * Math.abs(plusDI - minusDI)) / diSum : 0);
  }

  if (!dxValues.length) {
    return 0;
  }

  if (dxValues.length < period) {
    return round(average(dxValues), 2);
  }

  let adxValue = average(dxValues.slice(0, period));
  for (let index = period; index < dxValues.length; index += 1) {
    adxValue = ((adxValue * (period - 1)) + dxValues[index]) / period;
  }

  return round(adxValue, 2);
}

function average(values) {
  const filtered = values.filter((value) => Number.isFinite(value));
  return filtered.length ? filtered.reduce((sum, value) => sum + value, 0) / filtered.length : 0;
}

function emaSeries(values, period) {
  const multiplier = 2 / (period + 1);
  const result = [];
  let previous = values[0] || 0;
  for (const value of values) {
    previous = (value - previous) * multiplier + previous;
    result.push(previous);
  }
  return result;
}

function standardDeviation(values) {
  const avg = average(values);
  const variance = average(values.map((value) => (value - avg) ** 2));
  return Math.sqrt(variance);
}

function median(values) {
  const filtered = values.filter((value) => Number.isFinite(value)).sort((a, b) => a - b);
  if (!filtered.length) {
    return 0;
  }
  const middle = Math.floor(filtered.length / 2);
  return filtered.length % 2 ? filtered[middle] : (filtered[middle - 1] + filtered[middle]) / 2;
}

function percentChange(from, to) {
  return from ? ((to - from) / from) * 100 : 0;
}

function safeDivide(a, b) {
  return b ? a / b : 0;
}

function clamp(value, min, max) {
  return Math.max(min, Math.min(max, value));
}

function round(value, decimals) {
  const factor = 10 ** decimals;
  return Math.round(value * factor) / factor;
}

function splitList(value) {
  return String(value || '').split(',').map((item) => item.trim()).filter(Boolean);
}

function env(key, fallback) {
  return process.env[key] || fallback;
}

function numberEnv(key, fallback) {
  return Number(process.env[key] || fallback);
}

function numberArg(value, fallback) {
  const number = Number(value);
  return Number.isFinite(number) && number > 0 ? number : fallback;
}

function first(value) {
  return Array.isArray(value) ? value[0] : undefined;
}

function parseJson(text) {
  try {
    return JSON.parse(text);
  } catch (error) {
    return null;
  }
}

function parseJsonLoose(text) {
  const direct = parseJson(text);
  if (direct) {
    return direct;
  }
  const fence = String(text || '').match(/```(?:json)?\s*([\s\S]*?)```/i);
  if (fence) {
    const parsedFence = parseJson(fence[1].trim());
    if (parsedFence) {
      return parsedFence;
    }
  }
  const objectMatch = String(text || '').match(/\{[\s\S]*\}/);
  return objectMatch ? parseJson(objectMatch[0]) : null;
}

function ensureDir(dirPath) {
  fs.mkdirSync(dirPath, { recursive: true });
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function withTimeout(promise, ms, message) {
  return new Promise((resolve, reject) => {
    const timeout = setTimeout(() => reject(new Error(message)), ms);
    promise.then(
      (value) => {
        clearTimeout(timeout);
        resolve(value);
      },
      (error) => {
        clearTimeout(timeout);
        reject(error);
      }
    );
  });
}

function printHelp() {
  console.log(`Usage:
  npm run brain:once
  npm run brain:status
  node scripts/trading-brain.js loop

Environment:
  BRAIN_SYMBOLS=BTCUSDT,ETHUSDT
  BRAIN_DATA_DIR=/opt/trading-brain/data
  BRAIN_DRY_RUN=true
  AI_ANALYST_ENABLED=false
  AI_ANALYST_BASE_URL=https://api.openai.com/v1
  AI_ANALYST_API_KEY=...
  AI_ANALYST_MODEL=gpt-4o-mini
  CURSOR_ANALYST_ENABLED=false
  CURSOR_API_KEY=cursor_...
  CURSOR_ANALYST_MODEL=auto

AlgoVault analyst (crypto-quant-signal-mcp):
  ALGOVAULT_ENABLED=true
  ALGOVAULT_MCP_URL=https://api.algovault.com/mcp
  ALGOVAULT_EXCHANGE=BYBIT

Scalp strategy (Murphy + Solabuto, 5m):
  SCALP_ENABLED=true
  SCALP_INTERVAL=5
  SCALP_TAKE_PROFIT_PCT=0.35
  SCALP_STOP_LOSS_PCT=0.18

Market data also includes order book depth/imbalance and linear derivatives funding/OI.

Regime gate, ensemble scoring, and quality feedback:
  REGIME_GATE_ENABLED=true
  ENSEMBLE_SCORING_ENABLED=true
  QUALITY_FEEDBACK_ENABLED=true

This brain is read-only and writes decisions to JSONL. It does not place orders.
DeepSeek/OpenAI-compatible AI Analyst and Cursor Analyst are optional and never bypass the risk manager.
`);
}
