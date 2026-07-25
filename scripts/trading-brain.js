#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const {
  FinamClient,
  num: finamNum,
  finamAssetClass,
  summarizeFinamOrderBook,
  barsToCandles,
  summarizeAccount
} = require('./finam-client');
const { createStrategyEngine } = require('./strategy-engine');

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
    // Shiryaev book uses 10m; Bybit has no 10m kline — use 5m with same rule-set.
    interval: env('SCALP_INTERVAL', '5'),
    klineLimit: numberEnv('SCALP_KLINE_LIMIT', 200),
    higherTfInterval: env('SCALP_HIGHER_TF_INTERVAL', '240'),
    higherTfLimit: numberEnv('SCALP_HIGHER_TF_LIMIT', 120),
    minConfidence: numberEnv('SCALP_MIN_CONFIDENCE', 74),
    takeProfitPct: numberEnv('SCALP_TAKE_PROFIT_PCT', 0.45),
    stopLossPct: numberEnv('SCALP_STOP_LOSS_PCT', 0.28),
    maxSpreadPct: numberEnv('SCALP_MAX_SPREAD_PCT', 0.05),
    paperEnabled: env('SCALP_PAPER_ENABLED', 'true') === 'true',
    maxPositionUsd: numberEnv('SCALP_MAX_POSITION_USD', 12),
    maxOpenPositions: numberEnv('SCALP_MAX_OPEN_POSITIONS', 1),
    // 0 = unlimited number of scalp trades per day (still gated by setup/SL/TP/cooldown)
    maxDailyOpens: numberEnv('SCALP_MAX_DAILY_OPENS', 0),
    cooldownMinutes: numberEnv('SCALP_COOLDOWN_MINUTES', 45),
    lossCooldownMinutes: numberEnv('SCALP_LOSS_COOLDOWN_MINUTES', 90),
    maxConsecutiveLosses: numberEnv('SCALP_MAX_CONSECUTIVE_LOSSES', 2),
    consecutiveLossCooldownMinutes: numberEnv('SCALP_CONSECUTIVE_LOSS_COOLDOWN_MINUTES', 180),
    minVolumeRatio: numberEnv('SCALP_MIN_VOLUME_RATIO', 1.05),
    envelopePct: numberEnv('SCALP_ENVELOPE_PCT', 0.21),
    bounceTolPct: numberEnv('SCALP_BOUNCE_TOL_PCT', 0.12),
    minRewardRisk: numberEnv('SCALP_MIN_REWARD_RISK', 1.5),
    sessionGmtStartHour: numberEnv('SCALP_SESSION_GMT_START', 6),
    sessionGmtEndHour: numberEnv('SCALP_SESSION_GMT_END', 20),
    // Shiryaev session is for FX/futures; crypto (Bybit) is 24/7 unless listed here.
    sessionAssetClasses: splitList(env('SCALP_SESSION_ASSET_CLASSES', 'forex,futures')),
    forceFlatAtSessionEnd: env('SCALP_FORCE_FLAT_SESSION_END', 'true') === 'true',
    requireAiOk: env('SCALP_REQUIRE_AI_OK', 'true') === 'true',
    requireCursorOk: env('SCALP_REQUIRE_CURSOR_OK', 'true') === 'true',
    blockOnAiVeto: env('SCALP_BLOCK_ON_AI_VETO', 'true') === 'true',
    requireSwingNotBearish: env('SCALP_REQUIRE_SWING_NOT_BEARISH', 'true') === 'true',
    symbols: splitList(env('SCALP_SYMBOLS', 'BTCUSDT,ETHUSDT,SOLUSDT')),
    knowledgePath: env('SCALP_KNOWLEDGE_PATH', path.join(__dirname, 'knowledge', 'scalping-kb.json')),
    feePolicyPath: env('FEE_POLICY_PATH', path.join(__dirname, 'knowledge', 'fee-policy.json')),
    // Fee gate: metals/stocks/commodities/forex — только длинный горизонт (комиссия съедает скальп).
    allowedAssetClasses: splitList(env('SCALP_ALLOWED_ASSET_CLASSES', 'crypto'))
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
  },
  finam: {
    enabled: env('FINAM_ENABLED', 'false') === 'true',
    baseUrl: env('FINAM_BASE_URL', 'https://api.finam.ru').replace(/\/+$/, ''),
    secret: env('FINAM_SECRET_TOKEN', ''),
    // API numeric account ids (from token details). Trade codes are labels only.
    accountIds: splitList(env('FINAM_ACCOUNT_IDS', '1748987,2076665')),
    tradeCodes: splitList(env('FINAM_TRADE_CODES', '791750REXQ4,791750RM43P')),
    accountMap: {
      '1748987': env('FINAM_ACCOUNT_1748987_CODE', '791750REXQ4'),
      '2076665': env('FINAM_ACCOUNT_2076665_CODE', '791750RM43P')
    },
    // 791750REXQ4 — длинные/свинг; 791750RM43P — дневной тариф (intraday).
    accountRoles: {
      '1748987': {
        tradeCode: env('FINAM_ACCOUNT_1748987_CODE', '791750REXQ4'),
        role: env('FINAM_ACCOUNT_1748987_ROLE', 'long'),
        label: env('FINAM_ACCOUNT_1748987_LABEL', 'Длинные / свинг'),
        tariff: env('FINAM_ACCOUNT_1748987_TARIFF', 'long')
      },
      '2076665': {
        tradeCode: env('FINAM_ACCOUNT_2076665_CODE', '791750RM43P'),
        role: env('FINAM_ACCOUNT_2076665_ROLE', 'day'),
        label: env('FINAM_ACCOUNT_2076665_LABEL', 'Дневной тариф / intraday'),
        tariff: env('FINAM_ACCOUNT_2076665_TARIFF', 'daily')
      }
    },
    longAccountId: env('FINAM_LONG_ACCOUNT_ID', '1748987'),
    dayAccountId: env('FINAM_DAY_ACCOUNT_ID', '2076665'),
    // Long/swing symbols → REXQ4. Equities / metals / US stocks.
    symbols: splitList(env(
      'FINAM_SYMBOLS',
      'SBER@MISX,GAZP@MISX,LKOH@MISX,ROSN@MISX,GLDRUB_TOM@MISX,AAPL@XNGS,TSLA@XNGS'
    )),
    // Day/intraday (+ Finam scalp secondary) → RM43P. FX / futures / maker-friendly.
    daySymbols: splitList(env(
      'FINAM_DAY_SYMBOLS',
      'USD000UTSTOM@MISX,CNYRUB_TOM@MISX'
    )),
    // Explicit Finam scalp list (subset of daySymbols). Empty = use daySymbols + scalp_finam profile when eligible.
    scalpSymbols: splitList(env('FINAM_SCALP_SYMBOLS', 'USD000UTSTOM@MISX,CNYRUB_TOM@MISX')),
    timeframe: env('FINAM_TIMEFRAME', 'TIME_FRAME_M15'),
    dayTimeframe: env('FINAM_DAY_TIMEFRAME', 'TIME_FRAME_M5'),
    scalpTimeframe: env('FINAM_SCALP_TIMEFRAME', 'TIME_FRAME_M5'),
    scalpHigherTf: env('FINAM_SCALP_HIGHER_TF', 'TIME_FRAME_H4'),
    scalpBarLookbackHours: numberEnv('FINAM_SCALP_BAR_LOOKBACK_HOURS', 720),
    scalpHigherTfLookbackHours: numberEnv('FINAM_SCALP_HIGHER_TF_LOOKBACK_HOURS', 720),
    barLookbackHours: numberEnv('FINAM_BAR_LOOKBACK_HOURS', 48),
    timeoutMs: numberEnv('FINAM_TIMEOUT_MS', 20000),
    aiEnabled: env('FINAM_AI_ENABLED', 'false') === 'true',
    tradingEnabled: env('FINAM_TRADING_ENABLED', 'false') === 'true',
    // Legacy flat defaults (overridden by strategy.trading / horizon policies below).
    minConfidence: numberEnv('FINAM_MIN_CONFIDENCE', 65),
    minSellConfidence: numberEnv('FINAM_MIN_SELL_CONFIDENCE', 50),
    maxPositionRub: numberEnv('FINAM_MAX_POSITION_RUB', 500),
    maxOpenPositions: numberEnv('FINAM_MAX_OPEN_POSITIONS', 3),
    orderType: env('FINAM_ORDER_TYPE', 'LIMIT'), // LIMIT | MARKET
    allowSellToClose: env('FINAM_ALLOW_SELL_CLOSE', 'true') === 'true',
    strategies: {
      long: {
        minConfidence: numberEnv('FINAM_LONG_MIN_CONFIDENCE', 70),
        minSellConfidence: numberEnv('FINAM_LONG_MIN_SELL_CONFIDENCE', 50),
        maxPositionRub: numberEnv('FINAM_LONG_MAX_POSITION_RUB', 500),
        maxOpenPositions: numberEnv('FINAM_LONG_MAX_OPEN_POSITIONS', 3),
        orderType: env('FINAM_LONG_ORDER_TYPE', env('FINAM_ORDER_TYPE', 'LIMIT')),
        timeInForce: env('FINAM_LONG_TIME_IN_FORCE', 'TIME_IN_FORCE_DAY'),
        allowSellToClose: env('FINAM_LONG_ALLOW_SELL_CLOSE', 'true') === 'true',
        allowOpenShort: false
      },
      day: {
        minConfidence: numberEnv('FINAM_DAY_MIN_CONFIDENCE', 65),
        minSellConfidence: numberEnv('FINAM_DAY_MIN_SELL_CONFIDENCE', 48),
        maxPositionRub: numberEnv('FINAM_DAY_MAX_POSITION_RUB', 400),
        maxOpenPositions: numberEnv('FINAM_DAY_MAX_OPEN_POSITIONS', 2),
        orderType: env('FINAM_DAY_ORDER_TYPE', env('FINAM_ORDER_TYPE', 'LIMIT')),
        timeInForce: env('FINAM_DAY_TIME_IN_FORCE', 'TIME_IN_FORCE_DAY'),
        allowSellToClose: env('FINAM_DAY_ALLOW_SELL_CLOSE', 'true') === 'true',
        allowOpenShort: false
      },
      scalp: {
        enabled: env('FINAM_SCALP_ENABLED', 'true') === 'true',
        minConfidence: numberEnv('FINAM_SCALP_MIN_CONFIDENCE', 75),
        minSellConfidence: numberEnv('FINAM_SCALP_MIN_SELL_CONFIDENCE', 55),
        maxPositionRub: numberEnv('FINAM_SCALP_MAX_POSITION_RUB', 250),
        maxOpenPositions: numberEnv('FINAM_SCALP_MAX_OPEN_POSITIONS', 1),
        orderType: env('FINAM_SCALP_ORDER_TYPE', 'LIMIT'),
        timeInForce: env('FINAM_SCALP_TIME_IN_FORCE', 'TIME_IN_FORCE_DAY'),
        allowSellToClose: true,
        allowOpenShort: false,
        takeProfitPct: numberEnv('FINAM_SCALP_TAKE_PROFIT_PCT', 0.35),
        stopLossPct: numberEnv('FINAM_SCALP_STOP_LOSS_PCT', 0.22),
        maxSpreadPct: numberEnv('FINAM_SCALP_MAX_SPREAD_PCT', 0.05)
      }
    }
  },
  backtest: {
    // Rules-only historical replay (no DeepSeek/Cursor). Default symbols keep the run fast.
    symbols: splitList(env('BACKTEST_SYMBOLS', 'BTCUSDT,ETHUSDT,SOLUSDT')),
    interval: env('BACKTEST_INTERVAL', env('BRAIN_INTERVAL', '15')),
    candleLimit: numberEnv('BACKTEST_CANDLE_LIMIT', 500),
    warmupBars: numberEnv('BACKTEST_WARMUP_BARS', 60),
    windowBars: numberEnv('BACKTEST_WINDOW_BARS', numberEnv('BRAIN_KLINE_LIMIT', 96)),
    startBalanceUsd: numberEnv('BACKTEST_START_BALANCE_USD', numberEnv('PAPER_START_BALANCE_USD', 1000)),
    maxPositionUsd: numberEnv('BACKTEST_MAX_POSITION_USD', numberEnv('PAPER_MAX_POSITION_USD', 20)),
    minConfidence: numberEnv('BACKTEST_MIN_CONFIDENCE', numberEnv('PAPER_MIN_CONFIDENCE', 70)),
    feeRate: numberEnv('BACKTEST_FEE_RATE', numberEnv('PAPER_FEE_RATE', 0.001)),
    mode: env('BACKTEST_MODE', 'rules'), // rules | ai | both | all
    walkForwardFolds: numberEnv('BACKTEST_WALK_FORWARD_FOLDS', 5)
  },
  strategy: {
    profilesPath: env('STRATEGY_PROFILES_PATH', path.join(__dirname, 'knowledge', 'strategy-profiles.json')),
    calibrationPath: env('STRATEGY_CALIBRATION_PATH', ''),
    aiConfirmOnly: env('STRATEGY_AI_CONFIRM_ONLY', 'true') === 'true',
    blockWhenAiUnavailable: env('STRATEGY_BLOCK_WHEN_AI_UNAVAILABLE', 'false') === 'true',
    cursorRequired: env('STRATEGY_CURSOR_REQUIRED', 'false') === 'true',
    sentimentVetoOnly: env('STRATEGY_SENTIMENT_VETO_ONLY', 'true') === 'true'
  }
};

const strategyEngine = createStrategyEngine({
  profilesPath: config.strategy.profilesPath,
  calibrationPath: config.strategy.calibrationPath || path.join(config.dataDir, 'calibration.json'),
  dataDir: config.dataDir,
  knowledgeDir: path.join(__dirname, 'knowledge')
});

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

  if (command === 'finam') {
    const report = await finamStatusReport();
    console.log(JSON.stringify(report, null, 2));
    return;
  }

  if (command === 'stats') {
    const report = await tradingStatsReport();
    console.log(JSON.stringify(report, null, 2));
    return;
  }

  if (command === 'backtest') {
    const report = await runBacktestReport(args);
    console.log(JSON.stringify(report, null, 2));
    return;
  }

  if (command === 'calibrate') {
    const report = await runCalibrationReport(args);
    console.log(JSON.stringify(report, null, 2));
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
  const strategyProfiles = strategyEngine.loadProfiles();
  const calibration = strategyEngine.loadCalibration();
  const [marketsBybit, marketsFinam, news, fearGreed, finamAccounts] = await Promise.all([
    collectMarkets(),
    collectFinamMarkets().catch((error) => {
      console.error(new Date().toISOString(), 'Finam markets error:', error.message);
      return [];
    }),
    collectNews().catch((error) => ({
      sourceCount: 0,
      items: [],
      score: 0,
      error: error.message
    })),
    collectFearGreed().catch((error) => ({
      available: false,
      error: error.message
    })),
    collectFinamAccounts().catch((error) => ({
      enabled: config.finam.enabled,
      error: error.message,
      accounts: []
    }))
  ]);
  const markets = [...marketsBybit, ...marketsFinam];

  const decisions = await Promise.all(markets.map(async (market) => {
    const regime = detectMarketRegime(market);
    const profileBundle = strategyEngine.resolveProfilesForMarket(market, strategyProfiles);
    const profile = profileBundle.primary;
    const profileId = profileBundle.primaryId;
    const calibrationEntry = strategyEngine.getCalibratedThresholds(market.symbol, profileId, calibration);
    let signal = analyzeMarketWithProfile(market, news, fearGreed, qualityFeedback, profile, profileId, calibrationEntry);
    signal = applyRegimeToSignal(signal, regime, market);
    signal.regime = regime;
    const skipAi = market.provider === 'finam' && !config.finam.aiEnabled;
    const [aiAnalyst, algoVaultAnalyst] = await Promise.all([
      skipAi
        ? Promise.resolve({
          enabled: false,
          status: 'skipped',
          provider: 'finam',
          model: null,
          action: null,
          confidence: 0,
          riskLevel: 'unknown',
          veto: false,
          reasoning: 'AI skipped for Finam (set FINAM_AI_ENABLED=true to enable)',
          factors: []
        })
        : runAiAnalyst(market, news, signal, fearGreed, {
          profile,
          profileId,
          regime,
          qualityFeedback: getSymbolQualityFeedback(qualityFeedback, market.symbol),
          calibration: calibrationEntry
        }),
      runAlgoVaultAnalyst(market)
    ]);
    const cursorAnalyst = skipAi
      ? {
        enabled: false,
        status: 'skipped',
        provider: 'cursor',
        model: null,
        action: null,
        confidence: 0,
        riskLevel: 'unknown',
        veto: false,
        reasoning: 'Cursor skipped for Finam (set FINAM_AI_ENABLED=true to enable)',
        factors: []
      }
      : await runCursorAnalyst(market, news, signal, aiAnalyst, algoVaultAnalyst, fearGreed, {
        profile,
        profileId,
        regime,
        qualityFeedback: getSymbolQualityFeedback(qualityFeedback, market.symbol),
        calibration: calibrationEntry
      });
    const combineFn = config.strategy.aiConfirmOnly ? strategyEngine.combineConfirmOnly : combineSignals;
    const combineOpts = {
      blockWhenAiUnavailable: config.strategy.blockWhenAiUnavailable,
      cursorRequired: config.strategy.cursorRequired
    };
    const consensus = applyRegimeToConsensus(
      applyAlgoVaultConsensus(
        combineFn(signal, aiAnalyst, cursorAnalyst, combineOpts),
        algoVaultAnalyst
      ),
      regime,
      signal
    );
    const risk = applyRiskManager(consensus, market, aiAnalyst, cursorAnalyst, fearGreed, signal, regime);
    const scalpSignal = market.provider === 'finam'
      ? applyRegimeToScalp(
        analyzeFinamScalpStrategy(market, fearGreed, regime, profileBundle.secondary || {}),
        regime,
        market
      )
      : applyRegimeToScalp(analyzeScalpStrategy(market, fearGreed, regime), regime, market);
    const primaryStrategy = profileBundle.strategyType || 'swing';
    const strategyMeta = {
      primary: profileId,
      secondary: profileBundle.secondaryId,
      type: primaryStrategy,
      label: profile ? profile.label : null,
      accountRole: profileBundle.accountRole || (profile && profile.accountRole) || null,
      trading: (profile && profile.trading) || profileBundle.trading || {},
      calibration: calibrationEntry
    };
    return {
      timestamp: new Date().toISOString(),
      symbol: market.symbol,
      market,
      news: summarizeNewsForDecision(news),
      fearGreed,
      strategy: strategyMeta,
      signal,
      swingSignal: signal,
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
  const finamTrading = await executeFinamTrading(decisions, finamAccounts).catch((error) => ({
    enabled: config.finam.tradingEnabled,
    error: error.message,
    events: []
  }));
  const quality = computeSignalQuality(readAllDecisions());
  writeJson(path.join(config.dataDir, 'latest.json'), {
    timestamp: new Date().toISOString(),
    dryRun: config.dryRun,
    decisions,
    paper,
    quality,
    finam: {
      ...finamAccounts,
      trading: finamTrading
    }
  });

  return {
    timestamp: new Date().toISOString(),
    dryRun: config.dryRun,
    summary: decisions.map((item) => `${item.symbol}:${item.finalAction}:${item.consensus.confidence}`).join(' '),
    decisions,
    paper,
    quality,
    finam: {
      ...finamAccounts,
      trading: finamTrading
    }
  };
}

async function collectMarkets() {
  const results = [];
  for (const symbol of config.symbols) {
    const category = marketCategory(symbol);
    const assetClass = marketAssetClass(symbol);
    const scalpEnabledForSymbol = config.scalp.enabled && config.scalp.symbols.includes(symbol);
    const scalpQuery = scalpEnabledForSymbol ? bybitPublicOrNull('/v5/market/kline', {
      category,
      symbol,
      interval: config.scalp.interval,
      limit: String(config.scalp.klineLimit)
    }) : Promise.resolve(null);
    const higherTfQuery = scalpEnabledForSymbol ? bybitPublicOrNull('/v5/market/kline', {
      category,
      symbol,
      interval: config.scalp.higherTfInterval,
      limit: String(config.scalp.higherTfLimit)
    }) : Promise.resolve(null);

    const [tickerData, klineData, scalpKlineData, higherTfKlineData, orderBookData, derivativesTickerData, openInterestData] = await Promise.all([
      bybitPublic('/v5/market/tickers', { category, symbol }),
      bybitPublic('/v5/market/kline', {
        category,
        symbol,
        interval: config.interval,
        limit: String(config.klineLimit)
      }),
      scalpQuery,
      higherTfQuery,
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
    const scalpIndicators = buildScalpIndicators(parseKlineRows(scalpKlineData), {
      higherTfCandles: parseKlineRows(higherTfKlineData)
    });

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
      scalpIndicators,
      recentCandles: candles.slice(-24).map((candle) => ({
        start: candle.start,
        open: candle.open,
        high: candle.high,
        low: candle.low,
        close: candle.close,
        volume: candle.volume
      }))
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

function buildScalpIndicators(candles, options = {}) {
  // Prefer 150+ bars for EMA144; allow moderate history (80+) with softer EMA stack
  const minBars = Number(options.minBars) || 80;
  if (!candles || candles.length < minBars) {
    return { available: false, reason: `need >= ${minBars} bars, got ${candles ? candles.length : 0}` };
  }
  const fullEmaStack = candles.length >= 150;

  const closes = candles.map((candle) => candle.close);
  const volumes = candles.map((candle) => candle.volume);
  const lastCandle = candles[candles.length - 1];
  const prevCandle = candles[candles.length - 2];
  const last = closes[closes.length - 1];
  const envelopePct = config.scalp.envelopePct;
  const bounceTol = config.scalp.bounceTolPct;

  const ema9Values = emaSeries(closes, 9);
  const ema21Values = emaSeries(closes, 21);
  const ema34Values = emaSeries(closes, 34);
  const ema72Values = emaSeries(closes, Math.min(72, Math.max(34, Math.floor(closes.length / 2))));
  const ema144Values = fullEmaStack
    ? emaSeries(closes, 144)
    : emaSeries(closes, Math.min(96, Math.max(48, Math.floor(closes.length * 0.6))));
  const ema9 = ema9Values[ema9Values.length - 1];
  const ema21 = ema21Values[ema21Values.length - 1];
  const ema34 = ema34Values[ema34Values.length - 1];
  const ema72 = ema72Values[ema72Values.length - 1];
  const ema144 = ema144Values[ema144Values.length - 1];
  const prevEma34 = ema34Values[ema34Values.length - 2];
  const envelopeUpper = ema34 * (1 + envelopePct / 100);
  const envelopeLower = ema34 * (1 - envelopePct / 100);

  const sma50 = average(closes.slice(-50));
  const priorWindow = candles.slice(-21, -1);
  const support = Math.min(...priorWindow.map((candle) => candle.low));
  const resistance = Math.max(...priorWindow.map((candle) => candle.high));
  const last3 = closes.slice(-3);
  const impulseUp = last3.length === 3 && last3[2] > last3[1] && last3[1] > last3[0];
  const impulseDown = last3.length === 3 && last3[2] < last3[1] && last3[1] < last3[0];
  const volumeRatio = safeDivide(average(volumes.slice(-3)), average(volumes.slice(-20)));
  const distanceToEma21Pct = percentChange(ema21, last);
  const distanceToEma34Pct = percentChange(ema34, last);

  const stoch = stochasticSlow(candles, 13, 5, 3);
  const macdFast = emaSeries(closes, 21);
  const macdSlow = emaSeries(closes, 34);
  const macdLineSeries = macdFast.map((value, index) => value - macdSlow[index]);
  const macdSignalSeries = emaSeries(macdLineSeries, 5);
  const macdLine = macdLineSeries[macdLineSeries.length - 1];
  const macdSignal = macdSignalSeries[macdSignalSeries.length - 1];
  const macdHist = macdLine - macdSignal;
  const prevMacdHist = macdLineSeries[macdLineSeries.length - 2] - macdSignalSeries[macdSignalSeries.length - 2];
  const macdBullCross = macdLineSeries[macdLineSeries.length - 2] <= macdSignalSeries[macdSignalSeries.length - 2]
    && macdLine > macdSignal;
  const macdBearCross = macdLineSeries[macdLineSeries.length - 2] >= macdSignalSeries[macdSignalSeries.length - 2]
    && macdLine < macdSignal;

  const emaSlope34 = percentChange(prevEma34, ema34);
  const emaSpreadPct = Math.abs(percentChange(ema144, ema34));
  let segment = 'mixed';
  if (Math.abs(emaSlope34) < 0.03 && emaSpreadPct < 0.35) {
    segment = 'flat';
  } else if ((ema34 > ema72 && ema72 > ema144 && emaSlope34 > 0) || (ema34 < ema72 && ema72 < ema144 && emaSlope34 < 0)) {
    segment = 'trend';
  }

  const nearEma = (level) => Math.abs(percentChange(level, lastCandle.low)) <= bounceTol
    || Math.abs(percentChange(level, last)) <= bounceTol
    || (lastCandle.low <= level && lastCandle.close >= level);
  const bounceFromEma34 = nearEma(ema34) && lastCandle.close > lastCandle.open;
  const bounceFromEma72 = nearEma(ema72) && lastCandle.close > lastCandle.open;
  const bounceFromEma144 = nearEma(ema144) && lastCandle.close > lastCandle.open;
  const bounceFromLowerEnvelope = lastCandle.low <= envelopeLower * (1 + bounceTol / 100)
    && lastCandle.close > envelopeLower
    && lastCandle.close >= prevCandle.close;
  const bounceFromUpperEnvelope = lastCandle.high >= envelopeUpper * (1 - bounceTol / 100)
    && lastCandle.close < envelopeUpper
    && lastCandle.close <= prevCandle.close;

  let bounceLong = false;
  let bounceShort = false;
  let bounceType = null;
  if (segment === 'trend') {
    bounceLong = bounceFromEma34 || bounceFromEma72 || bounceFromEma144;
    bounceShort = false;
    bounceType = bounceLong ? 'ema_bounce' : null;
  } else if (segment === 'flat') {
    bounceLong = bounceFromLowerEnvelope;
    bounceShort = bounceFromUpperEnvelope;
    bounceType = bounceLong || bounceShort ? 'envelope_bounce' : null;
  } else {
    bounceLong = (bounceFromEma34 || bounceFromEma72 || bounceFromEma144) && bounceFromLowerEnvelope;
    bounceShort = bounceFromUpperEnvelope && (nearEma(ema34) || nearEma(ema72));
    bounceType = bounceLong || bounceShort ? 'combined_bounce' : null;
  }

  const stochBuy = Boolean(
    stoch.crossUp
    && (stoch.inOversoldZone || stoch.k <= 40 || stoch.prevK <= 25)
  );
  const stochSell = Boolean(
    stoch.crossDown
    && (stoch.inOverboughtZone || stoch.k >= 60 || stoch.prevK >= 75)
  );

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

  const higherTf = buildHigherTfTrend(options.higherTfCandles || []);

  return {
    available: true,
    method: 'shiryaev_conservative',
    fullEmaStack,
    barCount: candles.length,
    timeframe: `${config.scalp.interval}m`,
    ema9: round(ema9, 4),
    ema21: round(ema21, 4),
    ema34: round(ema34, 4),
    ema72: round(ema72, 4),
    ema144: round(ema144, 4),
    envelope: {
      mid: round(ema34, 4),
      upper: round(envelopeUpper, 4),
      lower: round(envelopeLower, 4),
      pct: envelopePct
    },
    sma50: round(sma50, 4),
    rsi7: round(rsi(closes, 7), 2),
    volumeRatio: round(volumeRatio, 3),
    impulseUp,
    impulseDown,
    priceAboveEma21: last > ema21,
    distanceToEma21Pct: round(distanceToEma21Pct, 3),
    distanceToEma34Pct: round(distanceToEma34Pct, 3),
    support: round(support, 4),
    resistance: round(resistance, 4),
    distanceToSupportPct: round(percentChange(support, last), 3),
    distanceToResistancePct: round(percentChange(last, resistance), 3),
    momentumPct: round(percentChange(closes[closes.length - 2], last), 3),
    segment,
    bounce: {
      long: bounceLong,
      short: bounceShort,
      type: bounceType,
      fromEma34: bounceFromEma34,
      fromEma72: bounceFromEma72,
      fromEma144: bounceFromEma144,
      fromLowerEnvelope: bounceFromLowerEnvelope,
      fromUpperEnvelope: bounceFromUpperEnvelope
    },
    stochastics: stoch,
    macd: {
      line: round(macdLine, 6),
      signal: round(macdSignal, 6),
      hist: round(macdHist, 6),
      histDelta: round(macdHist - prevMacdHist, 6),
      bullCross: macdBullCross,
      bearCross: macdBearCross,
      improving: macdHist > prevMacdHist
    },
    higherTf,
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

function stochasticSlow(candles, kPeriod = 13, kSmooth = 5, dPeriod = 3) {
  if (!candles || candles.length < kPeriod + kSmooth + dPeriod) {
    return {
      available: false,
      k: null,
      d: null,
      prevK: null,
      prevD: null,
      crossUp: false,
      crossDown: false,
      inOversoldZone: false,
      inOverboughtZone: false
    };
  }

  const rawK = [];
  for (let index = kPeriod - 1; index < candles.length; index += 1) {
    const window = candles.slice(index - kPeriod + 1, index + 1);
    const highest = Math.max(...window.map((candle) => candle.high));
    const lowest = Math.min(...window.map((candle) => candle.low));
    const close = candles[index].close;
    const denom = highest - lowest;
    rawK.push(denom > 0 ? ((close - lowest) / denom) * 100 : 50);
  }

  const slowK = [];
  for (let index = kSmooth - 1; index < rawK.length; index += 1) {
    slowK.push(average(rawK.slice(index - kSmooth + 1, index + 1)));
  }
  const slowD = [];
  for (let index = dPeriod - 1; index < slowK.length; index += 1) {
    slowD.push(average(slowK.slice(index - dPeriod + 1, index + 1)));
  }

  const k = slowK[slowK.length - 1];
  const d = slowD[slowD.length - 1];
  const prevK = slowK[slowK.length - 2];
  const prevD = slowD[slowD.length - 2];
  return {
    available: true,
    k: round(k, 2),
    d: round(d, 2),
    prevK: round(prevK, 2),
    prevD: round(prevD, 2),
    crossUp: prevK <= prevD && k > d,
    crossDown: prevK >= prevD && k < d,
    inOversoldZone: k <= 20 || d <= 20,
    inOverboughtZone: k >= 80 || d >= 80
  };
}

function buildHigherTfTrend(candles) {
  if (!candles || candles.length < 60) {
    return { available: false, trend: 'unknown', tenkan: null, kijun: null, cloud: null };
  }

  const last = candles[candles.length - 1];
  const tenkanWindow = candles.slice(-9);
  const kijunWindow = candles.slice(-26);
  const senkouBWindow = candles.slice(-52);
  const tenkan = (Math.max(...tenkanWindow.map((c) => c.high)) + Math.min(...tenkanWindow.map((c) => c.low))) / 2;
  const kijun = (Math.max(...kijunWindow.map((c) => c.high)) + Math.min(...kijunWindow.map((c) => c.low))) / 2;
  const senkouA = (tenkan + kijun) / 2;
  const senkouB = (Math.max(...senkouBWindow.map((c) => c.high)) + Math.min(...senkouBWindow.map((c) => c.low))) / 2;
  const cloudTop = Math.max(senkouA, senkouB);
  const cloudBottom = Math.min(senkouA, senkouB);
  const prev = candles[candles.length - 10] || candles[0];
  const prevTenkanWindow = candles.slice(-18, -9);
  const prevTenkan = prevTenkanWindow.length
    ? (Math.max(...prevTenkanWindow.map((c) => c.high)) + Math.min(...prevTenkanWindow.map((c) => c.low))) / 2
    : tenkan;
  const tenkanRising = tenkan > prevTenkan;
  const tenkanFalling = tenkan < prevTenkan;
  let trend = 'flat';
  if (last.close > cloudTop && tenkan > kijun && tenkanRising) {
    trend = 'up';
  } else if (last.close < cloudBottom && tenkan < kijun && tenkanFalling) {
    trend = 'down';
  } else if (tenkan > kijun && last.close >= cloudBottom) {
    trend = 'up';
  } else if (tenkan < kijun && last.close <= cloudTop) {
    trend = 'down';
  }

  return {
    available: true,
    timeframe: `${config.scalp.higherTfInterval}m`,
    trend,
    tenkan: round(tenkan, 4),
    kijun: round(kijun, 4),
    cloud: {
      top: round(cloudTop, 4),
      bottom: round(cloudBottom, 4),
      senkouA: round(senkouA, 4),
      senkouB: round(senkouB, 4)
    },
    goldenCross: tenkan > kijun,
    deadCross: tenkan < kijun,
    price: last.close
  };
}

function scalpSessionApplies(market = {}) {
  const classes = config.scalp.sessionAssetClasses || [];
  if (!classes.length) {
    return true;
  }
  const assetClass = market.assetClass || marketAssetClass(market.symbol) || finamAssetClass(market.symbol);
  return classes.includes(assetClass);
}

function isScalpSessionOpen(date = new Date(), market = null) {
  if (market && !scalpSessionApplies(market)) {
    return true;
  }
  const hour = date.getUTCHours() + date.getUTCMinutes() / 60;
  const start = config.scalp.sessionGmtStartHour;
  const end = config.scalp.sessionGmtEndHour;
  return hour >= start && hour < end;
}

function getUtcDayKey(date = new Date()) {
  return date.toISOString().slice(0, 10);
}

function ensureScalpDailyState(state) {
  const dayKey = getUtcDayKey();
  state.scalpDaily = state.scalpDaily || { date: null, opens: 0 };
  if (state.scalpDaily.date !== dayKey) {
    state.scalpDaily = { date: dayKey, opens: 0 };
  }
  return state.scalpDaily;
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

function loadFeePolicy() {
  try {
    return readJsonFile(config.scalp.feePolicyPath) || {};
  } catch (error) {
    return { error: error.message };
  }
}

function isScalpFeeEligible(market) {
  const assetClass = market.assetClass || marketAssetClass(market.symbol) || finamAssetClass(market.symbol);
  // Finam day-account FX/futures scalp is explicitly allowed on RM43P
  if (market.provider === 'finam') {
    const route = market.preferredAccount || resolveFinamAccountForSymbol(market.symbol);
    const listed = config.finam.scalpSymbols.includes(market.symbol);
    if (route.role === 'day' && (listed || assetClass === 'forex' || assetClass === 'futures')) {
      return {
        eligible: true,
        reason: 'Finam RM43P scalp fee gate ok (FX/futures day account)'
      };
    }
  }
  const policy = loadFeePolicy();
  const allowedClasses = config.scalp.allowedAssetClasses.length
    ? config.scalp.allowedAssetClasses
    : ((policy.scalp && policy.scalp.allowedAssetClasses) || ['crypto']);
  const allowedSymbols = (policy.scalp && policy.scalp.allowedSymbols) || config.scalp.symbols;
  const inSymbolList = config.scalp.symbols.includes(market.symbol)
    && (!allowedSymbols.length || allowedSymbols.includes(market.symbol));
  const classOk = allowedClasses.includes(assetClass);
  const longOnlyReason = policy.longOnly
    && policy.longOnly.reasonByClass
    && policy.longOnly.reasonByClass[assetClass];

  return {
    eligible: classOk && inSymbolList,
    assetClass,
    reason: classOk && inSymbolList
      ? 'fee gate ok for scalp'
      : (longOnlyReason || `Fee gate: ${assetClass} is long/swing only — commission eats short-trade edge`)
  };
}

function analyzeScalpStrategy(market, fearGreed = {}, regime = {}) {
  if (!config.scalp.enabled) {
    return {
      enabled: false,
      strategy: 'shiryaev_conservative_v1',
      action: null,
      confidence: 0,
      reasons: ['Scalp strategy disabled']
    };
  }

  const feeGate = isScalpFeeEligible(market);
  const trend15m = market.indicators && market.indicators.sma20 > market.indicators.sma50 ? 'up' : 'down';
  if (!feeGate.eligible) {
    return {
      enabled: true,
      strategy: 'shiryaev_conservative_v1',
      action: 'WAIT',
      confidence: 0,
      horizon: 'long_only',
      feeGate,
      trend15m,
      reasons: [feeGate.reason, 'Short trades disabled for this instrument']
    };
  }

  const knowledge = loadScalpingKnowledge();
  const bookSources = (knowledge.books || []).map((book) => `${book.author} — ${book.title}`);
  const scalp = market.scalpIndicators || {};
  const pivots = scalp.pivots || {};
  const sessionOpen = isScalpSessionOpen(new Date(), market);
  const sessionRequired = scalpSessionApplies(market);
  if (!scalp.available) {
    return {
      enabled: true,
      strategy: 'shiryaev_conservative_v1',
      action: 'WAIT',
      confidence: 0,
      horizon: 'scalp',
      feeGate,
      trend15m,
      sessionOpen,
      sessionRequired,
      knowledgeBooks: bookSources,
      reasons: [`Not enough ${config.scalp.interval}m data for Shiryaev scalp (need ~150 bars)`]
    };
  }

  let score = 0;
  const reasons = [];
  const appliedRules = [];
  const bounce = scalp.bounce || {};
  const stoch = scalp.stochastics || {};
  const macd = scalp.macd || {};
  const higherTf = scalp.higherTf || {};
  const higherTrend = higherTf.available ? higherTf.trend : (trend15m === 'up' ? 'up' : 'down');

  // Rule: session 06–20 GMT (FX/futures only by default)
  if (sessionRequired && !sessionOpen) {
    reasons.push(`Shiryaev: outside session ${config.scalp.sessionGmtStartHour}:00–${config.scalp.sessionGmtEndHour}:00 GMT`);
    appliedRules.push('session_window');
    return {
      enabled: true,
      strategy: 'shiryaev_conservative_v1',
      sources: bookSources,
      knowledgeVersion: knowledge.version || 2,
      appliedRules,
      timeframe: scalp.timeframe,
      trend15m,
      higherTfTrend: higherTrend,
      segment: scalp.segment,
      sessionOpen,
      sessionRequired,
      setupReady: false,
      pivots,
      bounce,
      stochastics: stoch,
      macd,
      horizon: 'scalp',
      feeGate,
      action: 'WAIT',
      confidence: 0,
      score: 0,
      takeProfitPct: config.scalp.takeProfitPct,
      stopLossPct: config.scalp.stopLossPct,
      reasons
    };
  }
  if (sessionRequired) {
    appliedRules.push('session_window');
  }

  // Rule №1: higher-TF trend (H4 Ichimoku-style)
  if (higherTrend === 'up') {
    score += 14;
    reasons.push('Shiryaev #1: higher-TF trend UP (Ichimoku/H4)');
    appliedRules.push('higher_tf_trend');
  } else if (higherTrend === 'down') {
    score -= 16;
    reasons.push('Shiryaev #1: higher-TF trend DOWN — long blocked');
    appliedRules.push('higher_tf_trend');
  } else {
    score += 2;
    reasons.push(`Shiryaev #1: higher-TF flat/mixed — work only current ${config.scalp.interval}m setup`);
    appliedRules.push('higher_tf_trend');
  }

  // Local M10 structure
  if (scalp.ema34 > scalp.ema72) {
    score += 6;
    reasons.push(`${config.scalp.interval}m EMA34 > EMA72`);
  } else {
    score -= 6;
    reasons.push(`${config.scalp.interval}m EMA34 < EMA72`);
  }

  // Rule №2: bounce-only (no breakout chase)
  if (bounce.long) {
    score += 16;
    reasons.push(`Shiryaev #2: bounce signal (${bounce.type || 'ema/envelope'}) on ${scalp.segment} segment`);
    appliedRules.push('bounce_only', 'segment_priority');
  } else {
    score -= 12;
    reasons.push('Shiryaev #2: no bounce signal — breakouts not traded');
    appliedRules.push('bounce_only');
  }

  // Rule №3: Stochastics entry after bounce
  if (stoch.available && stoch.crossUp && bounce.long) {
    score += 14;
    reasons.push(`Shiryaev #3: Stoch crossUp K=${stoch.k} D=${stoch.d}`);
    appliedRules.push('stoch_entry');
  } else if (stoch.available && stoch.inOversoldZone && bounce.long) {
    score += 8;
    reasons.push(`Shiryaev #3: Stoch in oversold zone K=${stoch.k}`);
    appliedRules.push('stoch_entry');
  } else if (stoch.available && stoch.crossDown) {
    score -= 8;
    reasons.push('Shiryaev #3: Stoch crossDown — no long');
    appliedRules.push('stoch_entry');
  } else {
    score -= 6;
    reasons.push('Shiryaev #3: Stoch entry not confirmed');
    appliedRules.push('stoch_entry');
  }

  // MACD confirm
  if (macd.bullCross || (macd.improving && macd.hist > 0)) {
    score += 8;
    reasons.push('Shiryaev: MACD confirms (cross/improving+)');
    appliedRules.push('macd_confirm');
  } else if (macd.bearCross || (macd.hist < 0 && !macd.improving)) {
    score -= 6;
    reasons.push('Shiryaev: MACD weak/bearish');
    appliedRules.push('macd_confirm');
  }

  // Pivot as context / targets only
  if (pivots.nearS1 && bounce.long) {
    score += 5;
    reasons.push('Shiryaev: Pivot S1 context supports long');
    appliedRules.push('pivot_sr');
  } else if (pivots.nearR1) {
    score -= 7;
    reasons.push('Shiryaev: near Pivot R1 — poor long location');
    appliedRules.push('pivot_sr');
  } else if (pivots.midRange && !bounce.long) {
    score -= 4;
    reasons.push('Shiryaev: mid Pivot range without bounce');
    appliedRules.push('pivot_sr');
  }

  if (scalp.distanceToResistancePct >= 0 && scalp.distanceToResistancePct < 0.2) {
    score -= 8;
    reasons.push('Too close to local resistance');
  }

  // Microstructure helpers (crypto)
  const orderBook = market.orderBook || {};
  if (orderBook.available) {
    if (orderBook.spreadPct > config.scalp.maxSpreadPct) {
      score -= 14;
      reasons.push('Borovkov: spread too wide');
      appliedRules.push('max_spread');
    }
    if (orderBook.pressure === 'sell' || Number(orderBook.imbalance) < -0.15) {
      score -= 6;
      reasons.push('CScalp: book sell pressure');
      appliedRules.push('orderbook_confirm');
    } else if (orderBook.pressure === 'buy' || Number(orderBook.imbalance) > 0.15) {
      score += 4;
      reasons.push('CScalp: book buy pressure');
      appliedRules.push('orderbook_confirm');
    }
  }

  if (fearGreed.available && fearGreed.value >= 80) {
    score -= 5;
    reasons.push('Extreme greed — reduce aggressive scalp long');
  }

  if (regime.regime === 'volatile') {
    score -= 10;
    reasons.push('Regime volatile — scalp blocked');
  }

  const rewardRisk = config.scalp.stopLossPct > 0
    ? config.scalp.takeProfitPct / config.scalp.stopLossPct
    : 0;
  if (rewardRisk + 1e-9 < config.scalp.minRewardRisk) {
    score -= 20;
    reasons.push(`Shiryaev: R:R ${round(rewardRisk, 2)} < min ${config.scalp.minRewardRisk}`);
    appliedRules.push('conservative_rr');
  } else {
    appliedRules.push('conservative_rr');
  }

  // Stoch confirm: crossUp, or oversold zone with MACD improving/cross
  const stochOk = Boolean(stoch.crossUp || (stoch.inOversoldZone && (macd.improving || macd.bullCross)));
  const setupReady = sessionOpen
    && higherTrend !== 'down'
    && Boolean(bounce.long)
    && stochOk
    && rewardRisk + 1e-9 >= config.scalp.minRewardRisk;

  if (!setupReady && score > 0) {
    score -= 10;
    reasons.push('Shiryaev: setup incomplete — WAIT');
  }

  const confidence = clamp(Math.round(50 + score), 0, 100);
  let action = 'WAIT';
  if (setupReady && confidence >= config.scalp.minConfidence) {
    action = 'BUY';
  } else if (bounce.short && stoch.crossDown && higherTrend === 'down' && confidence <= 40) {
    action = 'SELL';
  } else if (confidence >= 58) {
    action = 'HOLD';
  }

  return {
    enabled: true,
    strategy: 'shiryaev_conservative_v1',
    sources: bookSources,
    knowledgeVersion: knowledge.version || 2,
    appliedRules: [...new Set(appliedRules)],
    timeframe: scalp.timeframe,
    trend15m,
    higherTfTrend: higherTrend,
    higherTf,
    segment: scalp.segment,
    sessionOpen,
    sessionRequired,
    setupReady,
    bounce,
    stochastics: stoch,
    macd,
    pivots,
    horizon: 'scalp',
    feeGate,
    action,
    confidence,
    score: round(score, 2),
    takeProfitPct: config.scalp.takeProfitPct,
    stopLossPct: config.scalp.stopLossPct,
    minRewardRisk: config.scalp.minRewardRisk,
    rewardRisk: round(rewardRisk, 3),
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
  const profiles = strategyEngine.loadProfiles();
  const bundle = strategyEngine.resolveProfilesForMarket(market, profiles);
  const profile = bundle.primary || {
    strategyType: 'swing',
    minConfidence: config.minConfidence,
    sellThreshold: 35,
    ensembleWeights: config.ensemble.weights,
    sentimentMode: config.strategy.sentimentVetoOnly ? 'veto_only' : 'score',
    longOnly: marketAssetClass(market.symbol) !== 'crypto'
  };
  const calibration = strategyEngine.getCalibratedThresholds(
    market.symbol,
    bundle.primaryId || 'swing_crypto',
    strategyEngine.loadCalibration()
  );
  return analyzeMarketWithProfile(
    market,
    news,
    fearGreed,
    qualityFeedback,
    profile,
    bundle.primaryId || 'swing_crypto',
    calibration
  );
}

function analyzeMarketWithProfile(market, news, fearGreed = {}, qualityFeedback = { symbols: {} }, profile = {}, profileId = 'swing_crypto', calibrationEntry = {}) {
  const symbolFeedback = getSymbolQualityFeedback(qualityFeedback, market.symbol);
  const components = {
    technical: scoreTechnicalComponent(market),
    sentiment: strategyEngine.scoreSentimentForProfile(news, fearGreed, profile),
    microstructure: scoreMicrostructureComponent(market),
    derivatives: scoreDerivativesComponent(market)
  };
  const ensembleWeights = strategyEngine.resolveProfileWeights(profile, market, config.ensemble.weights);
  let   signal = strategyEngine.buildSignalFromComponents({
    market,
    profile,
    profileId,
    components,
    ensembleWeights,
    qualityFeedback: symbolFeedback,
    calibrationEntry,
    longOnlyAdjust: Boolean(profile.longOnly)
  });

  signal = strategyEngine.applySentimentVeto(signal, news, fearGreed, profile);
  return signal;
}

async function runAiAnalyst(market, news, signal, fearGreed = {}, strategyContext = {}) {
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
    const response = await aiRequest(buildAiMessages(market, news, signal, fearGreed, strategyContext));
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

function buildAiMessages(market, news, signal, fearGreed = {}, strategyContext = {}) {
  const payload = strategyEngine.buildRichAiPayload(market, news, fearGreed, signal, {
    profile: strategyContext.profile || {},
    profileId: strategyContext.profileId,
    regime: strategyContext.regime || signal.regime || {},
    qualityFeedback: strategyContext.qualityFeedback || signal.qualityFeedback || {},
    calibration: strategyContext.calibration || {}
  });

  return [
    {
      role: 'system',
      content: [
        'You are an indicator analyst and conservative confirm-only trading judge.',
        'First analyze indicatorAnalysis, recentCandles, order book and derivatives in the payload.',
        'Then confirm, veto, or downgrade the provided rule setup — do NOT invent new setups.',
        'Cite concrete metric values in indicatorSummary, reasoning and factors (e.g. RSI 72, ADX 18, MACD hist -0.4, book imbalance).',
        'If metrics contradict the setup, veto or downgrade. Prefer capital protection.',
        'Return only valid JSON.',
        'JSON schema: {"verdict":"confirm|veto|downgrade","action":"BUY|SELL|HOLD|WAIT|EXIT","confidence":0-100,"riskLevel":"low|medium|high","veto":boolean,"indicatorSummary":"short metric analysis","reasoning":"short reason","factors":["RSI ...","MACD ..."]}.'
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
  const verdict = ['confirm', 'veto', 'downgrade'].includes(String(raw.verdict || '').toLowerCase())
    ? String(raw.verdict).toLowerCase()
    : null;
  const confidence = clamp(Math.round(Number(raw.confidence) || 0), 0, 100);
  const riskLevel = ['low', 'medium', 'high'].includes(String(raw.riskLevel || '').toLowerCase())
    ? String(raw.riskLevel).toLowerCase()
    : 'unknown';
  const factors = Array.isArray(raw.factors) ? raw.factors.map((item) => String(item)).slice(0, 8) : [];
  const indicatorSummary = String(raw.indicatorSummary || raw.indicator_summary || '').slice(0, 600);

  return {
    enabled: true,
    status: 'ok',
    provider: config.ai.provider,
    model: config.ai.model,
    verdict,
    action,
    confidence,
    riskLevel,
    veto: Boolean(raw.veto) || verdict === 'veto',
    indicatorSummary,
    reasoning: String(raw.reasoning || '').slice(0, 500),
    factors
  };
}

async function runCursorAnalyst(market, news, signal, aiAnalyst, algoVaultAnalyst = {}, fearGreed = {}, strategyContext = {}) {
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
    const raw = await cursorRequest(buildCursorPrompt(market, news, signal, aiAnalyst, algoVaultAnalyst, fearGreed, strategyContext));
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

function buildCursorPrompt(market, news, signal, aiAnalyst, algoVaultAnalyst = {}, fearGreed = {}, strategyContext = {}) {
  const payload = strategyEngine.buildRichAiPayload(market, news, fearGreed, signal, {
    profile: strategyContext.profile || {},
    profileId: strategyContext.profileId,
    regime: strategyContext.regime || signal.regime || {},
    qualityFeedback: strategyContext.qualityFeedback || signal.qualityFeedback || {},
    calibration: strategyContext.calibration || {}
  });
  return [
    'You are Cursor Analyst — an indicator analyst and soft confirm-only veto layer.',
    'Do not inspect files. Use only this JSON payload.',
    'First analyze indicatorAnalysis, recentCandles, order book and derivatives.',
    'Then confirm, veto, or downgrade the rule setup. Do NOT invent new setups.',
    'Cite concrete metric values in indicatorSummary, reasoning and factors.',
    'If metrics contradict the setup, veto or downgrade.',
    'Return only valid JSON:',
    '{"verdict":"confirm|veto|downgrade","action":"BUY|SELL|HOLD|WAIT|EXIT","confidence":0-100,"riskLevel":"low|medium|high","veto":boolean,"indicatorSummary":"short metric analysis","reasoning":"short reason","factors":["RSI ...","MACD ..."]}',
    JSON.stringify({
      ...payload,
      deepSeekAnalyst: aiAnalyst,
      algoVaultAnalyst
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

  if (market.provider === 'finam' || String(market.symbol || '').includes('@')) {
    return {
      enabled: true,
      status: 'skipped',
      provider: 'algovault',
      model: 'crypto-quant-signal-mcp',
      action: null,
      confidence: 0,
      riskLevel: 'unknown',
      veto: false,
      reasoning: 'AlgoVault skipped for Finam / non-crypto symbols',
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
  if (String(symbol || '').includes('@')) {
    return finamAssetClass(symbol);
  }
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

function tradingStatsReport() {
  const paper = readJsonFile(path.join(config.dataDir, 'paper-state.json')) || {};
  const finamState = readJsonFile(path.join(config.dataDir, 'finam-state.json')) || {};
  const quality = readJsonFile(path.join(config.dataDir, 'quality.json')) || {};
  const decisionsPath = path.join(config.dataDir, 'decisions.jsonl');
  const recent = fs.existsSync(decisionsPath)
    ? fs.readFileSync(decisionsPath, 'utf8').trim().split('\n').filter(Boolean).slice(-300).map((line) => parseJson(line)).filter(Boolean)
    : [];
  const actionCounts = {};
  const finamActions = {};
  for (const row of recent) {
    actionCounts[row.finalAction] = (actionCounts[row.finalAction] || 0) + 1;
    if (row.market && row.market.provider === 'finam') {
      finamActions[row.finalAction] = (finamActions[row.finalAction] || 0) + 1;
    }
  }
  return {
    updatedAt: new Date().toISOString(),
    paper: {
      equityUsd: paper.equityUsd,
      cashUsd: paper.cashUsd,
      totalPnlUsd: paper.totalPnlUsd,
      totalPnlPct: paper.totalPnlPct,
      realizedPnlUsd: paper.realizedPnlUsd,
      stats: paper.stats,
      openPositions: Object.keys(paper.positions || {})
    },
    finam: {
      tradingEnabled: config.finam.tradingEnabled,
      stats: finamState.stats || {},
      recentOrders: (finamState.orders || []).slice(0, 10),
      accounts: finamState.accounts || []
    },
    recentDecisions: {
      sampleSize: recent.length,
      actions: actionCounts,
      finamActions
    },
    qualitySymbols: Object.fromEntries(Object.entries(quality.symbols || {}).map(([symbol, data]) => [
      symbol,
      {
        count: data.count,
        hitRate15m: data.horizons && data.horizons['15m'] ? data.horizons['15m'].hitRatePct : null,
        edge15m: data.horizons && data.horizons['15m'] ? data.horizons['15m'].avgEdgePct : null
      }
    ]))
  };
}

function getFinamClient() {
  if (!config.finam.enabled) {
    return null;
  }
  if (!config.finam.secret) {
    throw new Error('FINAM_ENABLED=true but FINAM_SECRET_TOKEN is empty');
  }
  return new FinamClient({
    baseUrl: config.finam.baseUrl,
    secret: config.finam.secret,
    timeoutMs: config.finam.timeoutMs
  });
}

function enrichFinamAccount(summary, accountId) {
  const role = config.finam.accountRoles[accountId] || {};
  summary.accountId = summary.accountId || accountId;
  summary.tradeCode = role.tradeCode || config.finam.accountMap[accountId] || summary.tradeCode || null;
  summary.role = role.role || 'unknown';
  summary.roleLabel = role.label || summary.role;
  summary.tariff = role.tariff || null;
  summary.horizon = role.role === 'day' ? 'intraday' : 'long';
  return summary;
}

function resolveFinamAccountForSymbol(symbol) {
  const assetClass = finamAssetClass(symbol);
  const isDay = config.finam.daySymbols.includes(symbol)
    || config.finam.scalpSymbols.includes(symbol)
    || assetClass === 'futures'
    || (assetClass === 'forex' && !config.finam.symbols.includes(symbol));

  if (isDay) {
    const accountId = config.finam.dayAccountId;
    const role = config.finam.accountRoles[accountId] || {};
    return {
      accountId,
      tradeCode: role.tradeCode || config.finam.accountMap[accountId],
      role: role.role || 'day',
      roleLabel: role.label || 'Дневной тариф / intraday',
      horizon: 'intraday',
      strategyHint: config.finam.scalpSymbols.includes(symbol) ? 'scalp' : 'day'
    };
  }
  const accountId = config.finam.longAccountId;
  const role = config.finam.accountRoles[accountId] || {};
  return {
    accountId,
    tradeCode: role.tradeCode || config.finam.accountMap[accountId],
    role: role.role || 'long',
    roleLabel: role.label || 'Длинные / свинг',
    horizon: 'long',
    strategyHint: 'long'
  };
}

function resolveFinamTradingPolicy(decision = {}) {
  const strategy = decision.strategy || {};
  const profileTrading = (strategy.trading && Object.keys(strategy.trading).length)
    ? strategy.trading
    : {};
  const type = strategy.type || strategy.primaryType || 'long';
  const horizonDefaults = config.finam.strategies[type]
    || config.finam.strategies[strategy.accountRole]
    || config.finam.strategies.long;
  const calibrated = (strategy.calibration && strategy.calibration.minConfidence)
    ? { minConfidence: strategy.calibration.minConfidence }
    : {};

  return {
    strategyType: type,
    accountRole: strategy.accountRole || (type === 'long' ? 'long' : 'day'),
    minConfidence: Number(profileTrading.minConfidence
      || calibrated.minConfidence
      || horizonDefaults.minConfidence
      || config.finam.minConfidence),
    minSellConfidence: Number(profileTrading.minSellConfidence
      || horizonDefaults.minSellConfidence
      || config.finam.minSellConfidence),
    maxPositionRub: Number(profileTrading.maxPositionRub
      || horizonDefaults.maxPositionRub
      || config.finam.maxPositionRub),
    maxOpenPositions: Number(profileTrading.maxOpenPositions
      || horizonDefaults.maxOpenPositions
      || config.finam.maxOpenPositions),
    orderType: profileTrading.orderType || horizonDefaults.orderType || config.finam.orderType,
    timeInForce: profileTrading.timeInForce || horizonDefaults.timeInForce || 'TIME_IN_FORCE_DAY',
    allowSellToClose: profileTrading.allowSellToClose != null
      ? Boolean(profileTrading.allowSellToClose)
      : (horizonDefaults.allowSellToClose != null
        ? Boolean(horizonDefaults.allowSellToClose)
        : config.finam.allowSellToClose),
    allowOpenShort: Boolean(profileTrading.allowOpenShort || horizonDefaults.allowOpenShort),
    takeProfitPct: Number(profileTrading.takeProfitPct || horizonDefaults.takeProfitPct || 0),
    stopLossPct: Number(profileTrading.stopLossPct || horizonDefaults.stopLossPct || 0),
    maxSpreadPct: Number(profileTrading.maxSpreadPct || horizonDefaults.maxSpreadPct || 0)
  };
}

function analyzeFinamScalpStrategy(market, fearGreed = {}, regime = {}, profile = {}) {
  if (!config.finam.strategies.scalp.enabled) {
    return {
      enabled: false,
      strategy: 'scalp_finam',
      action: null,
      confidence: 0,
      reasons: ['Finam scalp disabled']
    };
  }

  const route = market.preferredAccount || resolveFinamAccountForSymbol(market.symbol);
  if (route.role !== 'day') {
    return {
      enabled: true,
      strategy: 'scalp_finam',
      action: 'WAIT',
      confidence: 0,
      horizon: 'long_only',
      reasons: ['Finam scalp only on day account (RM43P)']
    };
  }

  const assetClass = market.assetClass || finamAssetClass(market.symbol);
  if (!['forex', 'futures'].includes(assetClass) && !config.finam.scalpSymbols.includes(market.symbol)) {
    return {
      enabled: true,
      strategy: 'scalp_finam',
      action: 'WAIT',
      confidence: 0,
      horizon: 'long_only',
      reasons: [`Fee gate: ${assetClass} is long/swing on Finam — scalp only forex/futures`]
    };
  }

  const trading = { ...config.finam.strategies.scalp, ...(profile.trading || {}) };
  // Reuse Shiryaev scalp engine when M5 indicators are available
  if (market.scalpIndicators && market.scalpIndicators.available) {
    const base = analyzeScalpStrategy(market, fearGreed, regime);
    const minConfidence = trading.minConfidence || config.finam.strategies.scalp.minConfidence || 75;
    let action = base.action;
    let confidence = base.confidence;
    // Finam scalp uses its own minConfidence gate
    if (action === 'BUY' && confidence < minConfidence) {
      action = 'WAIT';
      base.reasons = [...(base.reasons || []), `Finam scalp conf ${confidence} < ${minConfidence}`];
    }
    return {
      ...base,
      strategy: 'scalp_finam_shiryaev',
      accountRole: 'day',
      action,
      confidence,
      minConfidence,
      takeProfitPct: trading.takeProfitPct || base.takeProfitPct,
      stopLossPct: trading.stopLossPct || base.stopLossPct,
      maxSpreadPct: trading.maxSpreadPct || config.scalp.maxSpreadPct,
      reasons: [...(base.reasons || []), 'Finam RM43P scalp via Shiryaev rules']
    };
  }

  return {
    enabled: true,
    strategy: 'scalp_finam_shiryaev',
    action: 'WAIT',
    confidence: 0,
    horizon: 'scalp',
    accountRole: 'day',
    takeProfitPct: trading.takeProfitPct,
    stopLossPct: trading.stopLossPct,
    maxSpreadPct: trading.maxSpreadPct,
    minConfidence: trading.minConfidence || 75,
    reasons: ['Finam scalp waiting for Shiryaev M5 indicators']
  };
}

async function finamStatusReport() {
  const secret = config.finam.secret || process.env.FINAM_SECRET_TOKEN;
  if (!secret) {
    return { enabled: false, error: 'Finam not configured' };
  }
  const client = new FinamClient({
    baseUrl: config.finam.baseUrl,
    secret,
    timeoutMs: config.finam.timeoutMs
  });
  const details = await client.tokenDetails();
  const accounts = [];
  for (const accountId of (details.account_ids || config.finam.accountIds)) {
    try {
      const raw = await client.getAccount(accountId);
      accounts.push(enrichFinamAccount(summarizeAccount(raw), accountId));
    } catch (error) {
      accounts.push(enrichFinamAccount({ accountId, error: error.message }, accountId));
    }
  }
  return {
    enabled: true,
    expiresAt: details.expires_at,
    accountIds: details.account_ids || [],
    tradeCodes: config.finam.tradeCodes,
    roles: {
      long: resolveFinamAccountForSymbol('__long__'),
      day: {
        accountId: config.finam.dayAccountId,
        tradeCode: (config.finam.accountRoles[config.finam.dayAccountId] || {}).tradeCode,
        role: 'day',
        roleLabel: (config.finam.accountRoles[config.finam.dayAccountId] || {}).label,
        horizon: 'intraday'
      }
    },
    readonly: details.readonly,
    accounts
  };
}

async function collectFinamAccounts() {
  if (!config.finam.enabled) {
    return { enabled: false, accounts: [] };
  }
  const client = getFinamClient();
  const accounts = [];
  for (const accountId of config.finam.accountIds) {
    try {
      const raw = await client.getAccount(accountId);
      accounts.push(enrichFinamAccount(summarizeAccount(raw), accountId));
    } catch (error) {
      accounts.push(enrichFinamAccount({
        accountId,
        error: error.message
      }, accountId));
    }
  }
  return {
    enabled: true,
    updatedAt: new Date().toISOString(),
    roles: {
      long: {
        accountId: config.finam.longAccountId,
        tradeCode: (config.finam.accountRoles[config.finam.longAccountId] || {}).tradeCode,
        label: (config.finam.accountRoles[config.finam.longAccountId] || {}).label
      },
      day: {
        accountId: config.finam.dayAccountId,
        tradeCode: (config.finam.accountRoles[config.finam.dayAccountId] || {}).tradeCode,
        label: (config.finam.accountRoles[config.finam.dayAccountId] || {}).label
      }
    },
    accounts
  };
}

function finamDecimal(value) {
  const number = Number(value);
  if (!Number.isFinite(number)) {
    return { value: '0' };
  }
  return { value: String(round(number, 8)) };
}

function availableCashRub(accountSummary, rawAccount = null) {
  if (rawAccount && rawAccount.portfolio_mc && rawAccount.portfolio_mc.available_cash) {
    const fromMc = finamNum(rawAccount.portfolio_mc.available_cash);
    if (fromMc != null) {
      return fromMc;
    }
  }
  const rub = (accountSummary.cash || []).find((item) => item.currency === 'RUB' || item.currency_code === 'RUB');
  if (!rub) {
    return 0;
  }
  return Number(rub.amount != null ? rub.amount : 0);
}

function findFinamPosition(account, symbol) {
  return (account.positions || []).find((pos) => pos.symbol === symbol) || null;
}

function countFinamOpenPositions(account) {
  return (account.positions || []).filter((pos) => Number(pos.qty) > 0).length;
}

async function executeFinamTrading(decisions, finamAccountsSnapshot = {}) {
  const result = {
    enabled: config.finam.tradingEnabled,
    updatedAt: new Date().toISOString(),
    events: [],
    byStrategy: { long: 0, day: 0, scalp: 0 }
  };

  if (!config.finam.enabled || !config.finam.tradingEnabled) {
    result.reason = 'Finam trading disabled';
    return result;
  }

  const client = getFinamClient();
  const statePath = path.join(config.dataDir, 'finam-state.json');
  const tradesPath = path.join(config.dataDir, 'finam-trades.jsonl');
  const state = readJsonFile(statePath) || {
    startedAt: new Date().toISOString(),
    orders: [],
    stats: { submitted: 0, bought: 0, sold: 0, skipped: 0, errors: 0 },
    scalpMeta: {},
    scalpDaily: { date: null, opens: 0 }
  };
  state.scalpMeta = state.scalpMeta || {};
  ensureScalpDailyState(state);

  const accountsById = {};
  for (const accountId of config.finam.accountIds) {
    try {
      const raw = await client.getAccount(accountId);
      accountsById[accountId] = enrichFinamAccount(summarizeAccount(raw), accountId);
      accountsById[accountId]._raw = raw;
    } catch (error) {
      accountsById[accountId] = enrichFinamAccount({ accountId, error: error.message }, accountId);
    }
  }

  const finamDecisions = decisions.filter((item) => (item.market && item.market.provider === 'finam'));
  for (const decision of finamDecisions) {
    const symbol = decision.symbol;
    const route = (decision.market && decision.market.preferredAccount)
      || resolveFinamAccountForSymbol(symbol);
    const policy = resolveFinamTradingPolicy(decision);
    let tradeAction = decision.finalAction;
    let tradeConfidence = Number((decision.consensus && decision.consensus.confidence) || 0);
    let tradeSource = (decision.consensus && decision.consensus.source) || 'final';
    let activePolicy = { ...policy };
    const scalp = decision.scalpSignal || {};
    const scalpMinConf = scalp.minConfidence || config.finam.strategies.scalp.minConfidence || 75;
    const canPromoteScalpBuy = policy.strategyType === 'day'
      && config.finam.strategies.scalp.enabled
      && scalp.enabled
      && scalp.horizon === 'scalp'
      && scalp.action === 'BUY'
      && scalp.setupReady
      && (scalp.bounce && scalp.bounce.long)
      && (scalp.confidence || 0) >= scalpMinConf
      && decision.finalAction !== 'SELL'
      && decision.finalAction !== 'EXIT';
    if (canPromoteScalpBuy) {
      tradeAction = 'BUY';
      tradeConfidence = scalp.confidence;
      tradeSource = 'scalp_finam_shiryaev';
      activePolicy = resolveFinamTradingPolicy({
        ...decision,
        strategy: {
          ...(decision.strategy || {}),
          type: 'scalp',
          accountRole: 'day',
          trading: {
            ...(config.finam.strategies.scalp || {}),
            ...((decision.strategy && decision.strategy.trading) || {})
          }
        }
      });
    } else if (
      policy.strategyType === 'day'
      && config.finam.strategies.scalp.enabled
      && scalp.enabled
      && scalp.horizon === 'scalp'
      && scalp.action === 'SELL'
      && (scalp.confidence || 0) <= (scalp.minConfidence || 40)
    ) {
      tradeAction = 'SELL';
      tradeConfidence = scalp.confidence;
      tradeSource = 'scalp_finam_shiryaev_exit';
      activePolicy = resolveFinamTradingPolicy({
        ...decision,
        strategy: {
          ...(decision.strategy || {}),
          type: 'scalp',
          accountRole: 'day',
          trading: config.finam.strategies.scalp
        }
      });
    }

    const accountId = route.accountId;
    const account = accountsById[accountId];
    const price = Number(decision.market && decision.market.lastPrice);
    const eventBase = {
      timestamp: new Date().toISOString(),
      symbol,
      action: tradeAction,
      confidence: tradeConfidence,
      accountId,
      tradeCode: route.tradeCode,
      role: route.role,
      horizon: route.horizon,
      strategyType: activePolicy.strategyType,
      strategyProfile: decision.strategy && decision.strategy.primary,
      tradeSource,
      price
    };

    if (!account || account.error) {
      const event = { ...eventBase, type: 'SKIP', reason: account && account.error ? account.error : 'account unavailable' };
      result.events.push(event);
      state.stats.skipped += 1;
      continue;
    }

    if (activePolicy.accountRole && route.role && activePolicy.accountRole !== route.role) {
      const event = {
        ...eventBase,
        type: 'SKIP',
        reason: `strategy ${activePolicy.strategyType} expects account role ${activePolicy.accountRole}, got ${route.role}`
      };
      result.events.push(event);
      state.stats.skipped += 1;
      continue;
    }

    const position = findFinamPosition(account, symbol);
    const cashRub = availableCashRub(account, account._raw);

    if ((tradeAction === 'SELL' || tradeAction === 'EXIT') && activePolicy.allowSellToClose && position && Number(position.qty) > 0) {
      if (tradeConfidence < activePolicy.minSellConfidence) {
        const event = {
          ...eventBase,
          type: 'SKIP',
          reason: `sell confidence ${tradeConfidence} < ${activePolicy.minSellConfidence}`
        };
        result.events.push(event);
        state.stats.skipped += 1;
        continue;
      }
      try {
        const qty = Number(position.qty);
        const bid = decision.market.finam && decision.market.finam.bid;
        const limitPrice = bid || price;
        const orderBody = {
          symbol,
          quantity: finamDecimal(qty),
          side: 'SIDE_SELL',
          type: activePolicy.orderType === 'MARKET' ? 'ORDER_TYPE_MARKET' : 'ORDER_TYPE_LIMIT',
          time_in_force: activePolicy.timeInForce || 'TIME_IN_FORCE_DAY'
        };
        if (orderBody.type === 'ORDER_TYPE_LIMIT') {
          orderBody.limit_price = finamDecimal(limitPrice);
        }
        const orderResponse = await client.placeOrder(accountId, orderBody);
        const event = {
          ...eventBase,
          type: 'SELL_SUBMIT',
          qty,
          limitPrice: orderBody.limit_price ? limitPrice : null,
          order: orderResponse
        };
        result.events.push(event);
        appendJsonl(tradesPath, [event]);
        state.stats.submitted += 1;
        state.stats.sold += 1;
        result.byStrategy[activePolicy.strategyType] = (result.byStrategy[activePolicy.strategyType] || 0) + 1;
        state.orders = [event, ...(state.orders || [])].slice(0, 50);
        account.positions = (account.positions || []).filter((pos) => pos.symbol !== symbol);
      } catch (error) {
        const event = { ...eventBase, type: 'ERROR', reason: error.message, details: error.details || null };
        result.events.push(event);
        appendJsonl(tradesPath, [event]);
        state.stats.errors += 1;
      }
      continue;
    }

    if (tradeAction === 'BUY') {
      if (route.role !== 'long' && route.role !== 'day') {
        const event = { ...eventBase, type: 'SKIP', reason: 'unknown account role' };
        result.events.push(event);
        state.stats.skipped += 1;
        continue;
      }
      if (tradeConfidence < activePolicy.minConfidence) {
        const event = {
          ...eventBase,
          type: 'SKIP',
          reason: `confidence ${tradeConfidence} < ${activePolicy.minConfidence} (${activePolicy.strategyType})`
        };
        result.events.push(event);
        state.stats.skipped += 1;
        continue;
      }
      if (position && Number(position.qty) > 0) {
        const event = { ...eventBase, type: 'SKIP', reason: 'already in position' };
        result.events.push(event);
        state.stats.skipped += 1;
        continue;
      }
      if (countFinamOpenPositions(account) >= activePolicy.maxOpenPositions) {
        const event = {
          ...eventBase,
          type: 'SKIP',
          reason: `max open positions (${activePolicy.maxOpenPositions}) reached for ${activePolicy.strategyType}`
        };
        result.events.push(event);
        state.stats.skipped += 1;
        continue;
      }
      if (!price || price <= 0) {
        const event = { ...eventBase, type: 'SKIP', reason: 'no price' };
        result.events.push(event);
        state.stats.skipped += 1;
        continue;
      }

      const orderBook = decision.market.orderBook || {};
      if (activePolicy.strategyType === 'scalp') {
        const scalpBlocks = [];
        if (!isScalpSessionOpen(new Date(), decision.market)) {
          scalpBlocks.push(`outside session ${config.scalp.sessionGmtStartHour}:00–${config.scalp.sessionGmtEndHour}:00 GMT`);
        }
        if (!scalp.setupReady) {
          scalpBlocks.push('Shiryaev setup not ready');
        }
        if (!(scalp.bounce && scalp.bounce.long)) {
          scalpBlocks.push('no bounce signal');
        }
        const cooldownBlock = getScalpCooldownBlock(state, symbol);
        if (cooldownBlock) {
          scalpBlocks.push(cooldownBlock);
        }
        const daily = ensureScalpDailyState(state);
        if (config.scalp.maxDailyOpens > 0 && daily.opens >= config.scalp.maxDailyOpens) {
          scalpBlocks.push(`daily scalp cap ${config.scalp.maxDailyOpens}`);
        }
        if (orderBook.available && activePolicy.maxSpreadPct && orderBook.spreadPct > activePolicy.maxSpreadPct) {
          scalpBlocks.push(`spread ${orderBook.spreadPct}% > ${activePolicy.maxSpreadPct}%`);
        }
        if (scalpBlocks.length) {
          const event = {
            ...eventBase,
            type: 'SKIP',
            reason: `scalp limits: ${scalpBlocks.join('; ')}`
          };
          result.events.push(event);
          state.stats.skipped += 1;
          continue;
        }
      }

      let lotSize = 1;
      try {
        const asset = await client.getAsset(symbol, accountId);
        lotSize = finamNum(asset.lot_size) || 1;
      } catch (error) {
        // keep default lot
      }

      const budget = Math.min(activePolicy.maxPositionRub, cashRub);
      const maxQty = Math.floor(budget / price / lotSize) * lotSize;
      if (maxQty < lotSize) {
        const event = {
          ...eventBase,
          type: 'SKIP',
          reason: `insufficient cash: available ${round(cashRub, 2)} RUB, need ~${round(price * lotSize, 2)} for 1 lot`,
          cashRub: round(cashRub, 2),
          lotSize,
          maxPositionRub: activePolicy.maxPositionRub
        };
        result.events.push(event);
        state.stats.skipped += 1;
        continue;
      }

      try {
        const ask = decision.market.finam && decision.market.finam.ask;
        const limitPrice = ask || price;
        const orderBody = {
          symbol,
          quantity: finamDecimal(maxQty),
          side: 'SIDE_BUY',
          type: activePolicy.orderType === 'MARKET' ? 'ORDER_TYPE_MARKET' : 'ORDER_TYPE_LIMIT',
          time_in_force: activePolicy.timeInForce || 'TIME_IN_FORCE_DAY'
        };
        if (orderBody.type === 'ORDER_TYPE_LIMIT') {
          orderBody.limit_price = finamDecimal(limitPrice);
        }
        const orderResponse = await client.placeOrder(accountId, orderBody);
        const event = {
          ...eventBase,
          type: 'BUY_SUBMIT',
          qty: maxQty,
          limitPrice: orderBody.limit_price ? limitPrice : null,
          cashRub: round(cashRub, 2),
          maxPositionRub: activePolicy.maxPositionRub,
          order: orderResponse
        };
        result.events.push(event);
        appendJsonl(tradesPath, [event]);
        state.stats.submitted += 1;
        state.stats.bought += 1;
        result.byStrategy[activePolicy.strategyType] = (result.byStrategy[activePolicy.strategyType] || 0) + 1;
        state.orders = [event, ...(state.orders || [])].slice(0, 50);
        if (activePolicy.strategyType === 'scalp') {
          ensureScalpDailyState(state).opens += 1;
        }
      } catch (error) {
        const event = { ...eventBase, type: 'ERROR', reason: error.message, details: error.details || null };
        result.events.push(event);
        appendJsonl(tradesPath, [event]);
        state.stats.errors += 1;
      }
      continue;
    }
  }

  state.updatedAt = new Date().toISOString();
  state.tradingEnabled = true;
  state.accounts = Object.values(accountsById).map((account) => {
    const copy = { ...account };
    delete copy._raw;
    return copy;
  });
  state.lastEvents = result.events.slice(0, 20);
  state.byStrategy = result.byStrategy;
  writeJson(statePath, state);
  result.stats = state.stats;
  result.accounts = state.accounts;
  return result;
}

async function collectFinamMarkets() {
  if (!config.finam.enabled) {
    return [];
  }
  const symbols = [...new Set([
    ...config.finam.symbols,
    ...config.finam.daySymbols,
    ...config.finam.scalpSymbols
  ])];
  if (!symbols.length) {
    return [];
  }
  const client = getFinamClient();
  const end = new Date();
  const start = new Date(end.getTime() - config.finam.barLookbackHours * 3600 * 1000);
  const startTime = start.toISOString();
  const endTime = end.toISOString();
  const results = [];

  for (const symbol of symbols) {
    try {
      const accountRoute = resolveFinamAccountForSymbol(symbol);
      const wantsScalp = config.finam.strategies.scalp.enabled
        && (config.finam.scalpSymbols.includes(symbol) || accountRoute.strategyHint === 'scalp');
      const timeframe = accountRoute.horizon === 'intraday'
        ? (config.finam.dayTimeframe || config.finam.timeframe)
        : config.finam.timeframe;
      const scalpTf = config.finam.scalpTimeframe || 'TIME_FRAME_M5';
      const scalpStart = new Date(end.getTime() - (config.finam.scalpBarLookbackHours || 72) * 3600 * 1000).toISOString();
      const higherStart = new Date(end.getTime() - (config.finam.scalpHigherTfLookbackHours || 480) * 3600 * 1000).toISOString();

      const [quotePayload, barsPayload, orderBookPayload, scalpBarsPayload, higherBarsPayload] = await Promise.all([
        client.lastQuote(symbol),
        client.bars(symbol, {
          timeframe,
          startTime,
          endTime
        }),
        client.orderBook(symbol).catch(() => null),
        wantsScalp
          ? client.bars(symbol, { timeframe: scalpTf, startTime: scalpStart, endTime }).catch(() => null)
          : Promise.resolve(null),
        wantsScalp
          ? client.bars(symbol, {
            timeframe: config.finam.scalpHigherTf || 'TIME_FRAME_H4',
            startTime: higherStart,
            endTime
          }).catch(() => null)
          : Promise.resolve(null)
      ]);
      const candles = barsToCandles(barsPayload);
      if (candles.length < 30) {
        console.error(new Date().toISOString(), `Finam ${symbol}: not enough bars (${candles.length})`);
        continue;
      }
      let scalpCandles = wantsScalp ? barsToCandles(scalpBarsPayload) : [];
      if (wantsScalp && scalpCandles.length < 150 && timeframe === scalpTf) {
        scalpCandles = candles;
      }
      const higherTfCandles = wantsScalp ? barsToCandles(higherBarsPayload) : [];
      const quote = quotePayload.quote || quotePayload;
      const lastPrice = finamNum(quote.last) || candles[candles.length - 1].close;
      const firstClose = candles[Math.max(0, candles.length - 96)].close;
      const change24hPct = percentChange(firstClose, lastPrice);
      const orderBook = orderBookPayload
        ? summarizeFinamOrderBook(orderBookPayload)
        : { available: false };
      const market = assembleMarketFromCandles({
        symbol,
        provider: 'finam',
        category: 'finam',
        assetClass: finamAssetClass(symbol),
        lastPrice,
        change24hPct,
        turnover24h: 0,
        volume24h: candles.slice(-20).reduce((sum, candle) => sum + (candle.volume || 0), 0),
        candles,
        orderBook,
        derivatives: { available: false },
        scalpCandles,
        higherTfCandles,
        scalpMinBars: 50
      });
      market.finam = {
        bid: finamNum(quote.bid),
        ask: finamNum(quote.ask),
        quoteTimestamp: quote.timestamp || null,
        account: accountRoute,
        timeframe,
        scalpTimeframe: wantsScalp ? scalpTf : null,
        scalpBars: scalpCandles.length,
        higherTfBars: higherTfCandles.length
      };
      market.preferredAccount = accountRoute;
      market.strategyHint = accountRoute.strategyHint;
      results.push(market);
    } catch (error) {
      console.error(new Date().toISOString(), `Finam ${symbol}:`, error.message);
    }
  }
  return results;
}

function assembleMarketFromCandles({
  symbol,
  provider,
  category,
  assetClass,
  lastPrice,
  change24hPct,
  turnover24h,
  volume24h,
  candles,
  orderBook,
  derivatives,
  scalpCandles,
  higherTfCandles,
  scalpMinBars
}) {
  const closes = candles.map((candle) => candle.close);
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
  const rsi14 = rsi(closes, 14);
  const momentumPct = percentChange(previous, last);
  const trendPct = percentChange(sma50, sma20);
  const volatilityPct = averageTrueRangePercent(candles.slice(-14));
  const adx14 = adx(candles, 14);
  const volumeRatio = safeDivide(average(volumes.slice(-5)), average(volumes.slice(-30)));

  return {
    symbol,
    provider: provider || 'bybit',
    category,
    assetClass,
    lastPrice: Number(lastPrice || last),
    change24hPct: Number(change24hPct || 0),
    turnover24h: Number(turnover24h || 0),
    volume24h: Number(volume24h || 0),
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
      distanceToSupportPct: round(percentChange(support, last), 3),
      distanceToResistancePct: round(percentChange(last, resistance), 3),
      rsi14: round(rsi14, 2),
      momentumPct: round(momentumPct, 3),
      trendPct: round(trendPct, 3),
      volatilityPct: round(volatilityPct, 3),
      adx14: round(adx14, 2),
      volumeRatio: round(volumeRatio, 3)
    },
    orderBook: orderBook || { available: false },
    derivatives: derivatives || { available: false },
    scalpIndicators: buildScalpIndicators(scalpCandles || [], {
      higherTfCandles: higherTfCandles || [],
      minBars: scalpMinBars
    }),
    recentCandles: candles.slice(-24).map((candle) => ({
      start: candle.start,
      open: candle.open,
      high: candle.high,
      low: candle.low,
      close: candle.close,
      volume: candle.volume
    }))
  };
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
  return ['BUY', 'SELL', 'HOLD', 'WAIT', 'EXIT'].includes(value) ? value : null;
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
      method: 'shiryaev_conservative',
      interval: config.scalp.interval,
      symbols: config.scalp.symbols,
      minConfidence: config.scalp.minConfidence,
      maxPositionUsd: config.scalp.maxPositionUsd,
      maxOpenPositions: config.scalp.maxOpenPositions,
      maxDailyOpens: config.scalp.maxDailyOpens,
      takeProfitPct: config.scalp.takeProfitPct,
      stopLossPct: config.scalp.stopLossPct,
      minRewardRisk: config.scalp.minRewardRisk,
      sessionGmt: [config.scalp.sessionGmtStartHour, config.scalp.sessionGmtEndHour],
      cooldownMinutes: config.scalp.cooldownMinutes,
      lossCooldownMinutes: config.scalp.lossCooldownMinutes,
      maxConsecutiveLosses: config.scalp.maxConsecutiveLosses,
      minVolumeRatio: config.scalp.minVolumeRatio,
      requireAiOk: config.scalp.requireAiOk,
      requireCursorOk: config.scalp.requireCursorOk,
      blockOnAiVeto: config.scalp.blockOnAiVeto,
      requireSwingNotBearish: config.scalp.requireSwingNotBearish
    }
  };
  state.scalpMeta = state.scalpMeta || {};
  state.scalpDaily = state.scalpDaily || { date: null, opens: 0 };

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

  if (position && (action === 'SELL' || action === 'EXIT')) {
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
    } else if (
      config.scalp.forceFlatAtSessionEnd
      && scalpSessionApplies(decision.market || { symbol })
      && !isScalpSessionOpen(new Date(), decision.market || { symbol })
    ) {
      exitReason = `shiryaev session end ${config.scalp.sessionGmtEndHour}:00 GMT flat`;
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
    recordScalpCooldown(state, symbol, {
      closedAt: new Date().toISOString(),
      reason: exitReason,
      pnlUsd,
      loss: pnlUsd < 0
    });
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

function recordScalpCooldown(state, symbol, { closedAt, reason, pnlUsd, loss }) {
  state.scalpMeta = state.scalpMeta || {};
  const prev = state.scalpMeta[symbol] || {};
  const consecutiveLosses = loss ? (Number(prev.consecutiveLosses) || 0) + 1 : 0;
  let cooldownMinutes = config.scalp.cooldownMinutes;
  if (loss) {
    cooldownMinutes = Math.max(cooldownMinutes, config.scalp.lossCooldownMinutes);
  }
  if (consecutiveLosses >= config.scalp.maxConsecutiveLosses) {
    cooldownMinutes = Math.max(cooldownMinutes, config.scalp.consecutiveLossCooldownMinutes);
  }
  const closedMs = Date.parse(closedAt) || Date.now();
  state.scalpMeta[symbol] = {
    lastCloseAt: closedAt,
    lastCloseReason: reason,
    lastPnlUsd: round(pnlUsd, 4),
    consecutiveLosses,
    cooldownUntil: new Date(closedMs + cooldownMinutes * 60 * 1000).toISOString()
  };
}

function getScalpCooldownBlock(state, symbol) {
  const meta = (state.scalpMeta || {})[symbol];
  if (!meta || !meta.cooldownUntil) {
    return null;
  }
  const untilMs = Date.parse(meta.cooldownUntil);
  if (!Number.isFinite(untilMs) || untilMs <= Date.now()) {
    return null;
  }
  const minutesLeft = Math.ceil((untilMs - Date.now()) / 60000);
  return `scalp cooldown ${minutesLeft}m (after ${meta.lastCloseReason || 'close'}, losses=${meta.consecutiveLosses || 0})`;
}

function applyScalpPaperDecision(state, decision) {
  if (!config.scalp.paperEnabled) {
    return null;
  }

  const symbol = decision.symbol;
  const price = Number(decision.market && decision.market.lastPrice);
  const scalp = decision.scalpSignal || {};
  const ai = decision.aiAnalyst || {};
  const cursor = decision.cursorAnalyst || {};
  const positions = state.positions || {};
  state.positions = positions;
  state.scalpMeta = state.scalpMeta || {};

  if (!price || !config.scalp.symbols.includes(symbol) || positions[symbol]) {
    return null;
  }

  if (scalp.horizon === 'long_only' || (scalp.feeGate && scalp.feeGate.eligible === false)) {
    return paperEvent('SCALP_SKIP_BUY', decision, price, {
      blocks: [scalp.feeGate?.reason || 'fee gate: long/swing only']
    });
  }

  if (scalp.action !== 'BUY' || (scalp.confidence || 0) < config.scalp.minConfidence) {
    return null;
  }

  const blocks = [];
  if (!isScalpSessionOpen(new Date(), decision.market)) {
    blocks.push(`outside Shiryaev session ${config.scalp.sessionGmtStartHour}:00–${config.scalp.sessionGmtEndHour}:00 GMT`);
  }
  if (scalp.higherTfTrend === 'down') {
    blocks.push('higher-TF trend down');
  }
  if (!scalp.setupReady) {
    blocks.push('Shiryaev scalp setup not ready');
  }
  if (!(scalp.bounce && scalp.bounce.long)) {
    blocks.push('no bounce signal');
  }

  const daily = ensureScalpDailyState(state);
  if (config.scalp.maxDailyOpens > 0 && daily.opens >= config.scalp.maxDailyOpens) {
    blocks.push(`daily scalp cap reached (${config.scalp.maxDailyOpens})`);
  }

  const openScalps = Object.values(positions).filter((position) => position.strategy === 'scalp').length;
  if (openScalps >= config.scalp.maxOpenPositions) {
    blocks.push(`max open scalps reached (${config.scalp.maxOpenPositions})`);
  }

  const cooldownBlock = getScalpCooldownBlock(state, symbol);
  if (cooldownBlock) {
    blocks.push(cooldownBlock);
  }

  const orderBook = (decision.market && decision.market.orderBook) || {};
  if (orderBook.available && orderBook.spreadPct > config.scalp.maxSpreadPct) {
    blocks.push('spread too wide');
  }
  if (orderBook.available && Number(orderBook.imbalance) < -0.12) {
    blocks.push('order book sell pressure');
  }

  const scalpIndicators = (decision.market && decision.market.scalpIndicators) || {};
  if (scalpIndicators.available && Number(scalpIndicators.volumeRatio) < config.scalp.minVolumeRatio) {
    blocks.push(`volume ratio below ${config.scalp.minVolumeRatio}`);
  }

  if (config.scalp.requireSwingNotBearish) {
    const finalAction = decision.finalAction;
    if (finalAction === 'SELL' || finalAction === 'EXIT') {
      blocks.push(`swing action is ${finalAction}`);
    }
  }

  if (config.scalp.requireAiOk) {
    if (ai.status !== 'ok') {
      blocks.push(`DeepSeek not ok (${ai.status || 'missing'})`);
    } else if (ai.action === 'SELL' || ai.action === 'EXIT') {
      blocks.push(`DeepSeek action ${ai.action}`);
    }
  }
  if (config.scalp.requireCursorOk) {
    if (cursor.status !== 'ok') {
      blocks.push(`Cursor not ok (${cursor.status || 'missing'})`);
    } else if (cursor.action === 'SELL' || cursor.action === 'EXIT') {
      blocks.push(`Cursor action ${cursor.action}`);
    }
  }
  if (config.scalp.blockOnAiVeto) {
    if (ai.veto || ai.verdict === 'veto') {
      blocks.push('DeepSeek veto');
    }
    if (cursor.veto || cursor.verdict === 'veto') {
      blocks.push('Cursor veto');
    }
  }

  if (blocks.length) {
    return paperEvent('SCALP_SKIP_BUY', decision, price, { blocks, scalp });
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
  ensureScalpDailyState(state).opens += 1;
  return paperEvent('SCALP_OPEN', decision, price, {
    qty,
    positionUsd,
    feeUsd,
    scalp,
    method: 'shiryaev_conservative',
    dailyOpens: state.scalpDaily.opens
  });
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

async function runBacktestReport(cliArgs = {}) {
  ensureDir(config.dataDir);
  const mode = String(cliArgs.mode || config.backtest.mode || 'rules').toLowerCase();
  const symbols = splitList(cliArgs.symbols || config.backtest.symbols.join(','));
  const interval = String(cliArgs.interval || config.backtest.interval);
  const days = numberArg(cliArgs.days, 0);
  const warmupBars = numberArg(cliArgs.warmup, config.backtest.warmupBars);
  const windowBars = numberArg(cliArgs.window, config.backtest.windowBars);
  const startBalanceUsd = numberArg(cliArgs.startBalance, config.backtest.startBalanceUsd);
  const maxPositionUsd = numberArg(cliArgs.maxPosition, config.backtest.maxPositionUsd);
  const minConfidence = numberArg(cliArgs.minConfidence, config.backtest.minConfidence);
  const feeRate = numberArg(cliArgs.fee, config.backtest.feeRate);
  const intervalMinutes = Number(interval) || 15;
  const daysCandleLimit = days > 0
    ? Math.ceil((days * 24 * 60) / intervalMinutes) + Math.max(warmupBars, 60) + 20
    : 0;
  const candleLimit = numberArg(
    cliArgs.limit || cliArgs.candles,
    daysCandleLimit || config.backtest.candleLimit
  );
  const options = {
    interval,
    days: days || null,
    candleLimit,
    warmupBars,
    windowBars,
    startBalanceUsd,
    maxPositionUsd,
    minConfidence,
    feeRate
  };

  const report = {
    updatedAt: new Date().toISOString(),
    mode,
    options,
    venues: {
      bybit: symbols.filter((symbol) => !isFinamSymbol(symbol)),
      finam: symbols.filter((symbol) => isFinamSymbol(symbol))
    },
    note: modeNote(mode),
    runs: {}
  };

  if (mode === 'rules' || mode === 'both' || mode === 'all') {
    const fixturePath = cliArgs.fixture ? path.resolve(String(cliArgs.fixture)) : null;
    report.runs.rules = await backtestRulesMode(symbols, options, fixturePath);
  }
  if (mode === 'decisions' || mode === 'ai' || mode === 'both' || mode === 'all') {
    report.runs.ai = backtestAiDecisionsMode(symbols, options);
  }
  if (!Object.keys(report.runs).length) {
    throw new Error(`Unknown backtest mode: ${mode}. Use rules, ai/decisions, both, or all.`);
  }

  if (cliArgs.walkForward && report.runs.rules) {
    const fixturePath = cliArgs.fixture ? path.resolve(String(cliArgs.fixture)) : null;
    report.runs.walkForward = await backtestWalkForwardMode(symbols, options, fixturePath);
  }

  report.summary = summarizeBacktestReport(report);
  writeJson(path.join(config.dataDir, 'backtest-latest.json'), report);
  return report;
}

function modeNote(mode) {
  if (mode === 'ai' || mode === 'decisions') {
    return 'Replay of logged decisions.jsonl with DeepSeek/Cursor/rules breakdown. No new AI API calls. No live orders.';
  }
  if (mode === 'both' || mode === 'all') {
    return 'Rules kline replay + AI decisions.jsonl replay. No live orders.';
  }
  return 'Rules-only historical kline replay. AI analysts are skipped. No live orders.';
}

async function backtestRulesMode(symbols, options, fixturePath = null) {
  const bySymbol = {};
  const errors = {};
  const fixture = fixturePath ? readJsonFile(fixturePath) : null;
  for (const symbol of symbols) {
    try {
      let candles;
      if (fixture && Array.isArray(fixture[symbol])) {
        candles = fixture[symbol];
      } else if (fixture && Array.isArray(fixture.candles) && symbols.length === 1) {
        candles = fixture.candles;
      } else {
        candles = await fetchBacktestCandles(symbol, options.interval, options.candleLimit, options);
      }
      bySymbol[symbol] = backtestSymbolOnCandles(symbol, candles, options);
    } catch (error) {
      errors[symbol] = error.message;
      console.error(new Date().toISOString(), `Backtest ${symbol}:`, error.message);
    }
  }
  const bybitSymbols = Object.fromEntries(
    Object.entries(bySymbol).filter(([, item]) => item.provider !== 'finam')
  );
  const finamSymbols = Object.fromEntries(
    Object.entries(bySymbol).filter(([, item]) => item.provider === 'finam')
  );
  return {
    kind: 'rules',
    symbols: bySymbol,
    errors,
    portfolio: aggregateBacktestPortfolio(bySymbol, options.startBalanceUsd),
    portfolioByVenue: {
      bybit: aggregateBacktestPortfolio(bybitSymbols, options.startBalanceUsd),
      finam: aggregateBacktestPortfolio(finamSymbols, options.startBalanceUsd)
    },
    fixture: fixturePath || null
  };
}

function backtestAiDecisionsMode(symbols, options) {
  const rows = readAllDecisions().filter((row) => {
    if (!row || !row.symbol) {
      return false;
    }
    if (symbols.length && !symbols.includes(row.symbol)) {
      return false;
    }
    return true;
  });
  const bySymbol = {};
  const grouped = {};
  for (const row of rows) {
    grouped[row.symbol] = grouped[row.symbol] || [];
    grouped[row.symbol].push(row);
  }

  for (const [symbol, items] of Object.entries(grouped)) {
    items.sort((a, b) => String(a.timestamp || '').localeCompare(String(b.timestamp || '')));
    const enriched = items.map((item) => normalizeLoggedDecision(item));
    const strategies = {
      final: mapDecisionsToStrategy(enriched, 'final'),
      rules: mapDecisionsToStrategy(enriched, 'rules'),
      deepseek: mapDecisionsToStrategy(enriched, 'deepseek'),
      cursor: mapDecisionsToStrategy(enriched, 'cursor'),
      aiConfirm: mapDecisionsToStrategy(enriched, 'aiConfirm'),
      aiAgree: mapDecisionsToStrategy(enriched, 'aiAgree'),
      fullAgree: mapDecisionsToStrategy(enriched, 'fullAgree')
    };

    const strategyReports = {};
    for (const [name, decisions] of Object.entries(strategies)) {
      strategyReports[name] = {
        actionCounts: countActions(decisions),
        signalQuality: evaluateBacktestSignalQuality(decisions),
        paper: simulateBacktestPaper(decisions, {
          ...options,
          // For logged final/AI actions trust the recorded call; still require minConfidence when available.
          minConfidence: name === 'final' ? Math.min(options.minConfidence, 1) : options.minConfidence
        })
      };
    }

    bySymbol[symbol] = {
      symbol,
      source: 'decisions.jsonl',
      bars: enriched.length,
      from: enriched[0] ? enriched[0].timestamp : null,
      to: enriched.length ? enriched[enriched.length - 1].timestamp : null,
      aiCoverage: summarizeAiCoverage(enriched),
      strategies: strategyReports,
      // Keep top-level paper/quality as the AI-aware "final" path for panel compatibility.
      actionCounts: strategyReports.final.actionCounts,
      signalQuality: strategyReports.final.signalQuality,
      paper: strategyReports.final.paper,
      sampleDecisions: enriched
        .filter((d) => ['BUY', 'SELL'].includes(d.finalAction) || ['BUY', 'SELL'].includes(d.deepseekAction) || ['BUY', 'SELL'].includes(d.cursorAction))
        .slice(-10)
        .map((d) => ({
          timestamp: d.timestamp,
          finalAction: d.finalAction,
          rulesAction: d.rulesAction,
          deepseekAction: d.deepseekAction,
          cursorAction: d.cursorAction,
          confidence: d.confidence,
          price: d.market.lastPrice
        }))
    };
  }

  const portfolioByStrategy = {};
  for (const strategyName of ['final', 'rules', 'deepseek', 'cursor', 'aiConfirm', 'aiAgree', 'fullAgree']) {
    const fakeSymbols = {};
    for (const [symbol, item] of Object.entries(bySymbol)) {
      fakeSymbols[symbol] = {
        symbol,
        paper: item.strategies[strategyName].paper
      };
    }
    portfolioByStrategy[strategyName] = aggregateBacktestPortfolio(fakeSymbols, options.startBalanceUsd);
  }

  return {
    kind: 'ai_decisions',
    sampleSize: rows.length,
    symbols: bySymbol,
    portfolio: portfolioByStrategy.final,
    portfolioByStrategy,
    strategyLegend: {
      final: 'Logged finalAction after risk manager',
      rules: 'Rules/ensemble signal only',
      deepseek: 'DeepSeek action when status=ok',
      cursor: 'Cursor action when status=ok',
      aiConfirm: 'Rules setup + DeepSeek confirm-only + Cursor soft veto',
      aiAgree: 'Trade only when DeepSeek and Cursor agree BUY/SELL',
      fullAgree: 'Trade only when rules + DeepSeek + Cursor agree'
    }
  };
}

function normalizeLoggedDecision(item) {
  const signal = item.signal || {};
  const consensus = item.consensus || {};
  const ai = item.aiAnalyst || {};
  const cursor = item.cursorAnalyst || {};
  const confidence = Number(
    consensus.confidence
    ?? signal.confidence
    ?? ai.confidence
    ?? cursor.confidence
    ?? 0
  );
  return {
    timestamp: item.timestamp,
    symbol: item.symbol,
    finalAction: normalizeAction(item.finalAction) || 'WAIT',
    rulesAction: normalizeAction(signal.action) || 'HOLD',
    deepseekAction: ai.status === 'ok' ? (normalizeAction(ai.action) || 'HOLD') : null,
    deepseekConfidence: Number(ai.confidence) || 0,
    deepseekStatus: ai.status || 'missing',
    cursorAction: cursor.status === 'ok' ? (normalizeAction(cursor.action) || 'HOLD') : null,
    cursorConfidence: Number(cursor.confidence) || 0,
    cursorStatus: cursor.status || 'missing',
    confidence,
    consensusSource: consensus.source || signal.source || null,
    market: {
      lastPrice: Number(item.market && item.market.lastPrice),
      change24hPct: Number(item.market && item.market.change24hPct) || 0
    },
    riskAllowed: Boolean(item.risk && item.risk.allowed)
  };
}

function mapDecisionsToStrategy(enriched, strategyName) {
  return enriched.map((row) => {
    let action = 'HOLD';
    let confidence = row.confidence;

    if (strategyName === 'final') {
      action = row.finalAction;
      confidence = Math.max(row.confidence, 1);
    } else if (strategyName === 'rules') {
      action = row.rulesAction;
      confidence = row.confidence;
    } else if (strategyName === 'deepseek') {
      action = row.deepseekAction || 'WAIT';
      confidence = row.deepseekConfidence || 0;
    } else if (strategyName === 'cursor') {
      action = row.cursorAction || 'WAIT';
      confidence = row.cursorConfidence || 0;
    } else if (strategyName === 'aiConfirm') {
      const ruleSignal = {
        action: row.rulesAction,
        confidence: row.confidence,
        score: 0,
        reasons: []
      };
      const ai = {
        enabled: row.deepseekStatus === 'ok',
        status: row.deepseekStatus,
        action: row.deepseekAction,
        confidence: row.deepseekConfidence,
        veto: false,
        reasoning: ''
      };
      const cur = {
        enabled: row.cursorStatus === 'ok',
        status: row.cursorStatus,
        action: row.cursorAction,
        confidence: row.cursorConfidence,
        veto: false,
        reasoning: ''
      };
      const combined = strategyEngine.combineConfirmOnly(ruleSignal, ai, cur, {});
      action = combined.action;
      confidence = combined.confidence;
    } else if (strategyName === 'aiAgree') {
      if (row.deepseekAction && row.cursorAction && row.deepseekAction === row.cursorAction
        && ['BUY', 'SELL'].includes(row.deepseekAction)) {
        action = row.deepseekAction;
        confidence = average([row.deepseekConfidence, row.cursorConfidence]);
      } else {
        action = 'WAIT';
        confidence = 0;
      }
    } else if (strategyName === 'fullAgree') {
      if (row.rulesAction && row.deepseekAction && row.cursorAction
        && row.rulesAction === row.deepseekAction
        && row.rulesAction === row.cursorAction
        && ['BUY', 'SELL'].includes(row.rulesAction)) {
        action = row.rulesAction;
        confidence = average([row.confidence, row.deepseekConfidence, row.cursorConfidence]);
      } else {
        action = 'WAIT';
        confidence = 0;
      }
    }

    return {
      timestamp: row.timestamp,
      symbol: row.symbol,
      finalAction: action,
      consensus: { confidence: Number(confidence) || 0, source: strategyName },
      market: row.market
    };
  });
}

function summarizeAiCoverage(enriched) {
  const total = enriched.length || 1;
  const deepseekOk = enriched.filter((row) => row.deepseekStatus === 'ok').length;
  const cursorOk = enriched.filter((row) => row.cursorStatus === 'ok').length;
  const bothOk = enriched.filter((row) => row.deepseekStatus === 'ok' && row.cursorStatus === 'ok').length;
  const bothBuy = enriched.filter((row) => row.deepseekAction === 'BUY' && row.cursorAction === 'BUY').length;
  const bothSell = enriched.filter((row) => row.deepseekAction === 'SELL' && row.cursorAction === 'SELL').length;
  return {
    total: enriched.length,
    deepseekOk,
    cursorOk,
    bothOk,
    bothBuy,
    bothSell,
    deepseekOkPct: round((deepseekOk / total) * 100, 2),
    cursorOkPct: round((cursorOk / total) * 100, 2)
  };
}

function isFinamSymbol(symbol) {
  return String(symbol || '').includes('@');
}

function intervalToFinamTimeframe(interval) {
  const raw = String(interval || '15').toUpperCase();
  if (raw.startsWith('TIME_FRAME_')) {
    return raw;
  }
  const map = {
    1: 'TIME_FRAME_M1',
    5: 'TIME_FRAME_M5',
    15: 'TIME_FRAME_M15',
    30: 'TIME_FRAME_M30',
    60: 'TIME_FRAME_H1',
    240: 'TIME_FRAME_H4',
    D: 'TIME_FRAME_D',
    '1D': 'TIME_FRAME_D'
  };
  return map[raw] || map[Number(raw)] || 'TIME_FRAME_M15';
}

async function fetchBacktestCandles(symbol, interval, limit, options = {}) {
  if (isFinamSymbol(symbol)) {
    return fetchFinamBacktestCandles(symbol, interval, limit, options);
  }
  return fetchBybitBacktestCandles(symbol, interval, limit);
}

async function fetchBybitBacktestCandles(symbol, interval, limit) {
  const category = marketCategory(symbol);
  const needed = Math.min(Math.max(Number(limit) || 100, 50), 5000);
  const byStart = new Map();
  let endMs = null;

  while (byStart.size < needed) {
    const batchLimit = Math.min(1000, needed - byStart.size);
    const query = {
      category,
      symbol,
      interval: String(interval),
      limit: String(batchLimit)
    };
    if (endMs != null) {
      query.end = String(endMs);
    }
    const data = await bybitPublic('/v5/market/kline', query);
    const batch = parseKlineRows(data);
    if (!batch.length) {
      break;
    }
    for (const candle of batch) {
      byStart.set(candle.start, candle);
    }
    const oldest = batch[0].start;
    const nextEnd = oldest - 1;
    if (endMs != null && nextEnd >= endMs) {
      break;
    }
    endMs = nextEnd;
    if (batch.length < batchLimit) {
      break;
    }
  }

  const candles = [...byStart.values()].sort((a, b) => a.start - b.start);
  if (candles.length < 80) {
    throw new Error(`Not enough klines for backtest ${symbol}: got ${candles.length}`);
  }
  return candles.slice(-needed);
}

async function fetchFinamBacktestCandles(symbol, interval, limit, options = {}) {
  if (!config.finam.enabled) {
    throw new Error(`Finam disabled; cannot backtest ${symbol}`);
  }
  if (!config.finam.secret) {
    throw new Error('FINAM_SECRET_TOKEN is missing for Finam backtest');
  }
  const client = getFinamClient();
  const timeframe = intervalToFinamTimeframe(interval);
  const intervalMinutes = Number(interval) || 15;
  const days = Number(options.days) > 0
    ? Number(options.days)
    : Math.max(7, Math.ceil(((Number(limit) || 500) * intervalMinutes) / (24 * 60)));
  // Extra calendar days help MOEX/FX session gaps and warmup bars.
  const lookbackDays = Math.max(days + 3, 10);
  const end = new Date();
  const start = new Date(end.getTime() - lookbackDays * 24 * 3600 * 1000);
  const payload = await client.bars(symbol, {
    timeframe,
    startTime: start.toISOString(),
    endTime: end.toISOString()
  });
  const candles = barsToCandles(payload);
  const minBars = isFinamSymbol(symbol) ? 50 : 80;
  if (candles.length < minBars) {
    throw new Error(`Not enough Finam bars for backtest ${symbol}: got ${candles.length}`);
  }
  const needed = Math.min(Math.max(Number(limit) || candles.length, 50), candles.length);
  return candles.slice(-needed);
}

function backtestSymbolOnCandles(symbol, candles, options) {
  const requestedWarmup = Math.max(40, Number(options.warmupBars) || 60);
  const warmup = Math.min(requestedWarmup, Math.max(30, candles.length - 25));
  const windowBars = Math.min(Math.max(warmup, Number(options.windowBars) || 96), candles.length);
  const decisions = [];
  const provider = isFinamSymbol(symbol) ? 'finam' : 'bybit';
  const category = provider === 'finam' ? 'finam' : marketCategory(symbol);
  const assetClass = marketAssetClass(symbol);
  const profilesDoc = strategyEngine.loadProfiles();
  const calibrationDoc = strategyEngine.loadCalibration();
  const bundle = strategyEngine.resolveProfilesForMarket({ symbol, provider, assetClass }, profilesDoc);
  const profile = bundle.primary;
  const profileId = bundle.primaryId;
  const calibrationEntry = strategyEngine.getCalibratedThresholds(symbol, profileId, calibrationDoc);

  for (let index = warmup; index < candles.length; index += 1) {
    const end = index + 1;
    const start = Math.max(0, end - windowBars);
    const window = candles.slice(start, end);
    const last = window[window.length - 1];
    const lookback24h = Math.min(window.length, barsForApproxDay(options.interval));
    const price24hAgo = window[window.length - lookback24h].close;
    const market = assembleMarketFromCandles({
      symbol,
      provider,
      category,
      assetClass,
      lastPrice: last.close,
      change24hPct: percentChange(price24hAgo, last.close),
      turnover24h: 0,
      volume24h: average(window.slice(-lookback24h).map((c) => c.volume)),
      candles: window,
      orderBook: { available: false },
      derivatives: { available: false },
      scalpCandles: []
    });

    const regime = detectMarketRegime(market);
    let signal = analyzeMarketWithProfile(
      market,
      emptyBacktestNews(),
      emptyBacktestFearGreed(),
      { symbols: {} },
      profile,
      profileId,
      calibrationEntry
    );
    signal = applyRegimeToSignal(signal, regime, market);
    signal.regime = regime;
    const consensus = {
      ...signal,
      source: 'backtest_strategy_profile',
      strategyProfile: profileId,
      aiAgreement: 'skipped',
      cursorAgreement: 'skipped',
      reasons: [...(signal.reasons || []), 'Backtest: strategy profile replay']
    };
    const riskOpts = {
      ...options,
      minConfidence: calibrationEntry.minConfidence || signal.effectiveMinConfidence || options.minConfidence
    };
    const risk = applyBacktestRisk(consensus, market, signal, regime, riskOpts);
    decisions.push({
      timestamp: new Date(last.start).toISOString(),
      symbol,
      finalAction: risk.allowed ? consensus.action : (consensus.action === 'BUY' ? 'WAIT' : consensus.action),
      consensus: {
        action: consensus.action,
        confidence: consensus.confidence,
        source: consensus.source,
        score: consensus.score,
        strategyProfile: profileId
      },
      signal: {
        action: signal.action,
        confidence: signal.confidence,
        effectiveMinConfidence: signal.effectiveMinConfidence,
        strategyType: signal.strategyType,
        reasons: (signal.reasons || []).slice(0, 4)
      },
      risk: {
        allowed: risk.allowed,
        riskScore: risk.riskScore,
        blocks: risk.blocks
      },
      regime,
      market: {
        lastPrice: market.lastPrice,
        change24hPct: market.change24hPct,
        indicators: {
          rsi14: market.indicators.rsi14,
          trendPct: market.indicators.trendPct,
          volatilityPct: market.indicators.volatilityPct,
          adx14: market.indicators.adx14
        }
      }
    });
  }

  const paper = simulateBacktestPaper(decisions, {
    ...options,
    minConfidence: calibrationEntry.minConfidence || options.minConfidence
  });
  return {
    symbol,
    provider,
    source: provider === 'finam' ? 'finam_bars' : 'bybit_klines',
    interval: String(options.interval),
    strategyProfile: profileId,
    calibration: calibrationEntry,
    bars: candles.length,
    evaluatedBars: decisions.length,
    from: decisions[0] ? decisions[0].timestamp : null,
    to: decisions.length ? decisions[decisions.length - 1].timestamp : null,
    actionCounts: countActions(decisions),
    signalQuality: evaluateBacktestSignalQuality(decisions),
    paper,
    sampleDecisions: decisions.filter((d) => ['BUY', 'SELL', 'EXIT'].includes(d.finalAction)).slice(-8)
  };
}

function buildHistoricalSignalRows(symbol, candles, startIndex, endIndex, options = {}) {
  const warmup = Math.max(55, Number(options.warmupBars) || 60);
  const windowBars = Math.max(warmup, Number(options.windowBars) || 96);
  const rows = [];
  const provider = isFinamSymbol(symbol) ? 'finam' : 'bybit';
  const category = provider === 'finam' ? 'finam' : marketCategory(symbol);
  const assetClass = marketAssetClass(symbol);
  const profilesDoc = strategyEngine.loadProfiles();
  const bundle = strategyEngine.resolveProfilesForMarket({ symbol, provider, assetClass }, profilesDoc);
  const profile = bundle.primary;
  const profileId = bundle.primaryId;
  const from = Math.max(startIndex, warmup);
  const to = Math.min(endIndex, candles.length);

  for (let index = from; index < to; index += 1) {
    const end = index + 1;
    const start = Math.max(0, end - windowBars);
    const window = candles.slice(start, end);
    const last = window[window.length - 1];
    const lookback24h = Math.min(window.length, barsForApproxDay(options.interval));
    const price24hAgo = window[window.length - lookback24h].close;
    const market = assembleMarketFromCandles({
      symbol,
      provider,
      category,
      assetClass,
      lastPrice: last.close,
      change24hPct: percentChange(price24hAgo, last.close),
      turnover24h: 0,
      volume24h: average(window.slice(-lookback24h).map((c) => c.volume)),
      candles: window,
      orderBook: { available: false },
      derivatives: { available: false },
      scalpCandles: []
    });
    const regime = detectMarketRegime(market);
    let signal = analyzeMarketWithProfile(market, emptyBacktestNews(), emptyBacktestFearGreed(), { symbols: {} }, profile, profileId, {});
    signal = applyRegimeToSignal(signal, regime, market);
    rows.push({
      timestamp: new Date(last.start).toISOString(),
      rawAction: signal.action,
      confidence: signal.confidence,
      price: market.lastPrice,
      strategyProfile: profileId
    });
  }
  return { rows, profile, profileId };
}

async function backtestWalkForwardMode(symbols, options, fixturePath = null) {
  const folds = numberArg(options.walkForwardFolds, config.backtest.walkForwardFolds);
  const bySymbol = {};
  for (const symbol of symbols) {
    let candles;
    const fixture = fixturePath ? readJsonFile(fixturePath) : null;
    if (fixture && Array.isArray(fixture[symbol])) {
      candles = fixture[symbol];
    } else {
      candles = await fetchBacktestCandles(symbol, options.interval, options.candleLimit, options);
    }
    const segments = strategyEngine.splitWalkForwardIndices(candles.length, folds, options.warmupBars);
    const foldReports = [];
    let bestAggregate = null;

    for (const segment of segments) {
      const train = buildHistoricalSignalRows(symbol, candles, options.warmupBars, segment.trainEnd, options);
      const tuned = strategyEngine.calibrateThresholds(train.rows, train.profile);
      const testSlice = buildHistoricalSignalRows(symbol, candles, segment.testStart, segment.testEnd, options);
      const testMetrics = strategyEngine.evaluateThresholdOnSlice(
        testSlice.rows,
        tuned.minConfidence,
        tuned.sellThreshold,
        options.feeRate
      );
      foldReports.push({
        fold: segment.fold,
        trainBars: train.rows.length,
        testBars: testSlice.rows.length,
        tuned,
        test: testMetrics
      });
      if (!bestAggregate || (testMetrics.hitRatePct || 0) > (bestAggregate.test.hitRatePct || 0)) {
        bestAggregate = { fold: segment.fold, tuned, test: testMetrics };
      }
    }

    bySymbol[symbol] = {
      symbol,
      folds: foldReports,
      bestFold: bestAggregate,
      avgTestHitRatePct: foldReports.length
        ? round(average(foldReports.map((f) => f.test.hitRatePct || 0)), 2)
        : 0
    };
  }

  return {
    kind: 'walk_forward',
    folds,
    symbols: bySymbol
  };
}

async function runCalibrationReport(cliArgs = {}) {
  ensureDir(config.dataDir);
  const symbols = splitList(cliArgs.symbols || config.backtest.symbols.join(','));
  const folds = numberArg(cliArgs.folds, config.backtest.walkForwardFolds);
  const options = {
    interval: String(cliArgs.interval || config.backtest.interval),
    candleLimit: numberArg(cliArgs.limit || cliArgs.candles, config.backtest.candleLimit),
    warmupBars: numberArg(cliArgs.warmup, config.backtest.warmupBars),
    windowBars: numberArg(cliArgs.window, config.backtest.windowBars),
    walkForwardFolds: folds,
    feeRate: numberArg(cliArgs.fee, config.backtest.feeRate)
  };

  const calibration = strategyEngine.loadCalibration();
  calibration.updatedAt = new Date().toISOString();
  calibration.symbols = calibration.symbols || {};
  calibration.note = 'Walk-forward tuned thresholds per symbol/strategy profile';
  const report = { updatedAt: calibration.updatedAt, symbols: {} };

  for (const symbol of symbols) {
    const candles = await fetchBacktestCandles(symbol, options.interval, options.candleLimit);
    const wf = await backtestWalkForwardMode([symbol], options);
    const symbolWf = wf.symbols[symbol] || {};
    const best = symbolWf.bestFold && symbolWf.bestFold.tuned;
    const trainBundle = buildHistoricalSignalRows(symbol, candles, options.warmupBars, candles.length - 1, options);
    const fullTune = strategyEngine.calibrateThresholds(trainBundle.rows, trainBundle.profile);
    const chosen = best && best.evaluated >= 8 ? best : fullTune;

    calibration.symbols[symbol] = calibration.symbols[symbol] || { profiles: {} };
    calibration.symbols[symbol].profiles[trainBundle.profileId] = {
      minConfidence: chosen.minConfidence,
      sellThreshold: chosen.sellThreshold,
      hitRatePct: chosen.hitRatePct,
      evaluated: chosen.evaluated,
      walkForwardAvgHitRatePct: symbolWf.avgTestHitRatePct,
      updatedAt: calibration.updatedAt
    };
    report.symbols[symbol] = {
      profileId: trainBundle.profileId,
      thresholds: calibration.symbols[symbol].profiles[trainBundle.profileId],
      walkForward: symbolWf
    };
  }

  strategyEngine.saveCalibration(calibration);
  writeJson(path.join(config.dataDir, 'calibration-report.json'), report);
  return report;
}

function applyBacktestRisk(signal, market, ruleSignal = {}, regime = {}, options = {}) {
  const indicators = market.indicators || {};
  let riskScore = 0;
  const blocks = [];
  const minConfidence = Number(options.minConfidence) || Number(ruleSignal.effectiveMinConfidence) || config.minConfidence;

  if (signal.action === 'BUY' && (signal.confidence || 0) < minConfidence) {
    blocks.push(`confidence below backtest minimum (${minConfidence})`);
  }

  if (config.regime.enabled && regime.regime === 'volatile' && signal.action === 'BUY') {
    riskScore += 20;
    blocks.push('volatile regime blocks entries');
  }

  if (config.regime.enabled && regime.regime === 'trend_down' && signal.action === 'BUY') {
    riskScore += 15;
    blocks.push('downtrend regime blocks swing BUY');
  }

  if (indicators.volatilityPct > 3.5) {
    riskScore += 25;
    blocks.push('volatility too high');
  } else {
    riskScore += (indicators.volatilityPct || 0) * 4;
  }

  if (indicators.rsi14 > 76) {
    riskScore += 18;
    blocks.push('RSI extreme');
  }

  if (market.change24hPct < -8 || market.change24hPct > 12) {
    riskScore += 18;
    blocks.push('24h move outside safe range');
  }

  if (riskScore > config.maxRiskScore) {
    blocks.push('risk score above maximum');
  }

  return {
    allowed: blocks.length === 0,
    riskScore: round(riskScore, 2),
    blocks,
    minConfidence
  };
}

function simulateBacktestPaper(decisions, options = {}) {
  const startBalanceUsd = Number(options.startBalanceUsd) || config.backtest.startBalanceUsd;
  const maxPositionUsd = Number(options.maxPositionUsd) || config.backtest.maxPositionUsd;
  const feeRate = Number(options.feeRate) || config.backtest.feeRate;
  const minConfidence = Number(options.minConfidence) || config.backtest.minConfidence;
  let cashUsd = startBalanceUsd;
  let realizedPnlUsd = 0;
  let position = null;
  const trades = [];
  const equityCurve = [];
  let peakEquity = startBalanceUsd;
  let maxDrawdownPct = 0;

  for (const decision of decisions) {
    const price = Number(decision.market && decision.market.lastPrice);
    const confidence = Number(decision.consensus && decision.consensus.confidence) || 0;
    if (!price) {
      continue;
    }

    if (!position && decision.finalAction === 'BUY' && confidence >= minConfidence) {
      const positionUsd = Math.min(maxPositionUsd, cashUsd);
      if (positionUsd > 0) {
        const feeUsd = positionUsd * feeRate;
        const qty = positionUsd / price;
        position = {
          entryPrice: price,
          entryTime: decision.timestamp,
          qty,
          costUsd: positionUsd,
          openFeeUsd: feeUsd,
          confidence
        };
        cashUsd = round(cashUsd - positionUsd - feeUsd, 4);
        trades.push({
          type: 'OPEN',
          timestamp: decision.timestamp,
          price,
          qty,
          positionUsd,
          feeUsd,
          confidence
        });
      }
    } else if (position && (decision.finalAction === 'SELL' || decision.finalAction === 'EXIT')) {
      const grossUsd = position.qty * price;
      const closeFeeUsd = grossUsd * feeRate;
      const pnlUsd = grossUsd - closeFeeUsd - position.costUsd - position.openFeeUsd;
      cashUsd = round(cashUsd + grossUsd - closeFeeUsd, 4);
      realizedPnlUsd = round(realizedPnlUsd + pnlUsd, 4);
      trades.push({
        type: 'CLOSE',
        timestamp: decision.timestamp,
        price,
        qty: position.qty,
        pnlUsd: round(pnlUsd, 4),
        pnlPct: round(percentChange(position.costUsd + position.openFeeUsd, grossUsd - closeFeeUsd), 4),
        holdBars: null,
        confidence
      });
      position = null;
    }

    const openValue = position ? position.qty * price : 0;
    const equityUsd = round(cashUsd + openValue, 4);
    peakEquity = Math.max(peakEquity, equityUsd);
    const drawdownPct = peakEquity > 0 ? percentChange(peakEquity, equityUsd) : 0;
    if (drawdownPct < maxDrawdownPct) {
      maxDrawdownPct = drawdownPct;
    }
    equityCurve.push({
      timestamp: decision.timestamp,
      equityUsd,
      cashUsd: round(cashUsd, 4),
      open: Boolean(position)
    });
  }

  if (position) {
    const last = decisions[decisions.length - 1];
    const price = Number(last.market && last.market.lastPrice) || position.entryPrice;
    const grossUsd = position.qty * price;
    const closeFeeUsd = grossUsd * feeRate;
    const pnlUsd = grossUsd - closeFeeUsd - position.costUsd - position.openFeeUsd;
    cashUsd = round(cashUsd + grossUsd - closeFeeUsd, 4);
    realizedPnlUsd = round(realizedPnlUsd + pnlUsd, 4);
    trades.push({
      type: 'FORCE_CLOSE',
      timestamp: last.timestamp,
      price,
      qty: position.qty,
      pnlUsd: round(pnlUsd, 4),
      pnlPct: round(percentChange(position.costUsd + position.openFeeUsd, grossUsd - closeFeeUsd), 4),
      confidence: position.confidence
    });
    position = null;
  }

  const closed = trades.filter((trade) => trade.type === 'CLOSE' || trade.type === 'FORCE_CLOSE');
  const wins = closed.filter((trade) => trade.pnlUsd >= 0).length;
  const endEquity = equityCurve.length ? equityCurve[equityCurve.length - 1].equityUsd : startBalanceUsd;
  return {
    startBalanceUsd,
    endEquityUsd: round(cashUsd, 4),
    totalPnlUsd: round(cashUsd - startBalanceUsd, 4),
    totalPnlPct: round(percentChange(startBalanceUsd, cashUsd), 4),
    realizedPnlUsd,
    maxDrawdownPct: round(maxDrawdownPct, 4),
    trades: closed.length,
    opens: trades.filter((trade) => trade.type === 'OPEN').length,
    wins,
    losses: closed.length - wins,
    winRatePct: closed.length ? round((wins / closed.length) * 100, 2) : 0,
    avgTradePnlUsd: closed.length ? round(average(closed.map((trade) => trade.pnlUsd)), 4) : 0,
    recentTrades: trades.slice(-12),
    equityPoints: equityCurve.length,
    lastEquityUsd: round(endEquity, 4)
  };
}

function evaluateBacktestSignalQuality(decisions) {
  const horizons = [
    { label: '15m', steps: 1 },
    { label: '1h', steps: 4 },
    { label: '4h', steps: 16 }
  ];
  // decisions are sampled every brain bar (default 15m), so 1 step ≈ interval.
  const intervalMinutes = Number(config.backtest.interval) || 15;
  const scaled = horizons.map((horizon) => ({
    label: horizon.label,
    steps: Math.max(1, Math.round((horizon.steps * 15) / intervalMinutes))
  }));

  const result = {};
  for (const horizon of scaled) {
    const evaluated = [];
    for (let index = 0; index + horizon.steps < decisions.length; index += 1) {
      const current = decisions[index];
      const future = decisions[index + horizon.steps];
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
      evaluated.push({ hit: edgePct > 0, edgePct });
    }
    const hits = evaluated.filter((item) => item.hit).length;
    const edges = evaluated.map((item) => item.edgePct);
    result[horizon.label] = {
      evaluated: evaluated.length,
      hits,
      hitRatePct: evaluated.length ? round((hits / evaluated.length) * 100, 2) : 0,
      avgEdgePct: edges.length ? round(average(edges), 4) : 0
    };
  }
  return result;
}

function aggregateBacktestPortfolio(bySymbol, startBalanceUsd) {
  const symbols = Object.values(bySymbol || {});
  if (!symbols.length) {
    return {
      startBalanceUsd,
      endEquityUsd: startBalanceUsd,
      totalPnlUsd: 0,
      totalPnlPct: 0,
      trades: 0,
      winRatePct: 0,
      maxDrawdownPct: 0
    };
  }

  // Each symbol is simulated on its own virtual purse starting at startBalanceUsd.
  // Portfolio summary averages relative performance so multi-symbol runs stay comparable.
  const pnls = symbols.map((item) => item.paper.totalPnlPct);
  const trades = symbols.reduce((sum, item) => sum + item.paper.trades, 0);
  const wins = symbols.reduce((sum, item) => sum + item.paper.wins, 0);
  const maxDrawdownPct = Math.min(...symbols.map((item) => item.paper.maxDrawdownPct));
  const avgPnlPct = average(pnls);
  return {
    symbolCount: symbols.length,
    startBalanceUsdPerSymbol: startBalanceUsd,
    avgTotalPnlPct: round(avgPnlPct, 4),
    totalTrades: trades,
    winRatePct: trades ? round((wins / trades) * 100, 2) : 0,
    worstMaxDrawdownPct: round(maxDrawdownPct, 4),
    bySymbolPnlPct: Object.fromEntries(symbols.map((item) => [item.symbol, item.paper.totalPnlPct]))
  };
}

function summarizeBacktestReport(report) {
  const summary = {
    modes: Object.keys(report.runs || {}),
    days: report.options && report.options.days,
    venues: report.venues || null
  };
  for (const [name, run] of Object.entries(report.runs || {})) {
    summary[name] = {
      symbols: Object.keys(run.symbols || {}),
      errors: run.errors || {},
      portfolio: run.portfolio,
      portfolioByVenue: run.portfolioByVenue || null,
      bySymbol: Object.fromEntries(
        Object.entries(run.symbols || {}).map(([symbol, item]) => [symbol, {
          provider: item.provider || (isFinamSymbol(symbol) ? 'finam' : 'bybit'),
          from: item.from,
          to: item.to,
          bars: item.bars,
          trades: item.paper && item.paper.trades,
          winRatePct: item.paper && item.paper.winRatePct,
          totalPnlPct: item.paper && item.paper.totalPnlPct,
          maxDrawdownPct: item.paper && item.paper.maxDrawdownPct,
          strategyProfile: item.strategyProfile
        }])
      )
    };
  }
  return summary;
}

function countActions(decisions) {
  const counts = {};
  for (const decision of decisions) {
    const action = decision.finalAction || 'UNKNOWN';
    counts[action] = (counts[action] || 0) + 1;
  }
  return counts;
}

function barsForApproxDay(interval) {
  const minutes = Number(interval) || 15;
  return Math.max(1, Math.round((24 * 60) / minutes));
}

function emptyBacktestNews() {
  return {
    score: 0,
    itemCount: 0,
    sourceCount: 0,
    top: [],
    sourceCounts: {}
  };
}

function emptyBacktestFearGreed() {
  return {
    available: false,
    value: null,
    classification: 'unavailable',
    score: 0
  };
}

function printHelp() {
  console.log(`Usage:
  npm run brain:once
  npm run brain:status
  npm run brain:backtest
  node scripts/trading-brain.js backtest --mode rules --symbols BTCUSDT,ETHUSDT --limit 500
  node scripts/trading-brain.js backtest --mode rules --days 7 --interval 15 --symbols BTCUSDT,ETHUSDT,SBER@MISX,ROSN@MISX
  node scripts/trading-brain.js backtest --mode ai --symbols BTCUSDT,ETHUSDT,SOLUSDT
  node scripts/trading-brain.js backtest --mode all
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

Backtest:
  BACKTEST_SYMBOLS=BTCUSDT,ETHUSDT,SOLUSDT
  BACKTEST_INTERVAL=15
  BACKTEST_CANDLE_LIMIT=500
  BACKTEST_MIN_CONFIDENCE=70
  BACKTEST_MODE=ai
  # modes: rules | ai/decisions | both | all
  # ai mode replays decisions.jsonl and compares rules / DeepSeek / Cursor / agreement

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
