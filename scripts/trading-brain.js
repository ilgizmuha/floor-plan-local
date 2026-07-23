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
  symbols: splitList(env('BRAIN_SYMBOLS', 'BTCUSDT,ETHUSDT')),
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
  paper: {
    enabled: env('PAPER_TRADING_ENABLED', 'true') === 'true',
    startBalanceUsd: numberEnv('PAPER_START_BALANCE_USD', 1000),
    maxPositionUsd: numberEnv('PAPER_MAX_POSITION_USD', 20),
    minConfidence: numberEnv('PAPER_MIN_CONFIDENCE', 70),
    feeRate: numberEnv('PAPER_FEE_RATE', 0.001),
    symbols: splitList(env('PAPER_SYMBOLS', 'BTCUSDT')),
    requireDeepSeekOk: env('PAPER_REQUIRE_DEEPSEEK_OK', 'true') === 'true',
    requireCursorOk: env('PAPER_REQUIRE_CURSOR_OK', 'true') === 'true'
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
  const [markets, news] = await Promise.all([
    collectMarkets(),
    collectNews().catch((error) => ({
      sourceCount: 0,
      items: [],
      score: 0,
      error: error.message
    }))
  ]);

  const decisions = await Promise.all(markets.map(async (market) => {
    const signal = analyzeMarket(market, news);
    const aiAnalyst = await runAiAnalyst(market, news, signal);
    const cursorAnalyst = await runCursorAnalyst(market, news, signal, aiAnalyst);
    const consensus = combineSignals(signal, aiAnalyst, cursorAnalyst);
    const risk = applyRiskManager(consensus, market, aiAnalyst, cursorAnalyst);
    return {
      timestamp: new Date().toISOString(),
      symbol: market.symbol,
      market,
      news: summarizeNewsForDecision(news),
      signal,
      aiAnalyst,
      cursorAnalyst,
      consensus,
      risk,
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
    const [tickerData, klineData] = await Promise.all([
      bybitPublic('/v5/market/tickers', { category: config.category, symbol }),
      bybitPublic('/v5/market/kline', {
        category: config.category,
        symbol,
        interval: config.interval,
        limit: String(config.klineLimit)
      })
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
    const volumes = candles.map((candle) => candle.volume);
    const last = closes[closes.length - 1];
    const previous = closes[closes.length - 2];
    const sma20 = average(closes.slice(-20));
    const sma50 = average(closes.slice(-50));
    const rsi14 = rsi(closes, 14);
    const momentumPct = percentChange(previous, last);
    const trendPct = percentChange(sma50, sma20);
    const volatilityPct = averageTrueRangePercent(candles.slice(-14));
    const volumeRatio = safeDivide(average(volumes.slice(-5)), average(volumes.slice(-30)));

    results.push({
      symbol,
      lastPrice: Number(ticker.lastPrice || last),
      change24hPct: Number(ticker.price24hPcnt || 0) * 100,
      turnover24h: Number(ticker.turnover24h || 0),
      volume24h: Number(ticker.volume24h || 0),
      indicators: {
        sma20: round(sma20, 4),
        sma50: round(sma50, 4),
        rsi14: round(rsi14, 2),
        momentumPct: round(momentumPct, 3),
        trendPct: round(trendPct, 3),
        volatilityPct: round(volatilityPct, 3),
        volumeRatio: round(volumeRatio, 3)
      }
    });
  }
  return results;
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

function analyzeMarket(market, news) {
  const i = market.indicators;
  let score = 0;
  const reasons = [];

  if (i.sma20 > i.sma50) {
    score += 18;
    reasons.push('short trend above long trend');
  } else {
    score -= 18;
    reasons.push('short trend below long trend');
  }

  if (i.rsi14 >= 45 && i.rsi14 <= 62) {
    score += 12;
    reasons.push('RSI in constructive range');
  } else if (i.rsi14 > 72) {
    score -= 16;
    reasons.push('RSI overheated');
  } else if (i.rsi14 < 32) {
    score -= 8;
    reasons.push('RSI weak/oversold');
  }

  if (i.momentumPct > 0) {
    score += Math.min(12, i.momentumPct * 8);
    reasons.push('positive short momentum');
  } else {
    score += Math.max(-12, i.momentumPct * 8);
    reasons.push('negative short momentum');
  }

  if (i.volumeRatio > 1.15) {
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

  if (news.score > 0) {
    score += Math.min(10, news.score);
    reasons.push('news sentiment positive');
  } else if (news.score < 0) {
    score += Math.max(-14, news.score);
    reasons.push('news sentiment negative');
  }

  const confidence = clamp(Math.round(50 + score), 0, 100);
  let action = 'HOLD';
  if (confidence >= config.minConfidence) {
    action = 'BUY';
  } else if (confidence <= 35) {
    action = 'SELL';
  }

  return {
    action,
    confidence,
    score: round(score, 2),
    reasons
  };
}

async function runAiAnalyst(market, news, signal) {
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
    const response = await aiRequest(buildAiMessages(market, news, signal));
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

function buildAiMessages(market, news, signal) {
  const payload = {
    symbol: market.symbol,
    market: {
      lastPrice: market.lastPrice,
      change24hPct: market.change24hPct,
      turnover24h: market.turnover24h,
      volume24h: market.volume24h,
      indicators: market.indicators
    },
    news: summarizeNewsForDecision(news),
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

async function runCursorAnalyst(market, news, signal, aiAnalyst) {
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
    const raw = await cursorRequest(buildCursorPrompt(market, news, signal, aiAnalyst));
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

function buildCursorPrompt(market, news, signal, aiAnalyst) {
  return [
    'You are Cursor Analyst, an independent conservative reviewer for a crypto trading brain.',
    'Do not inspect or modify files. Use only the JSON payload in this prompt.',
    'Return only valid JSON with this schema:',
    '{"action":"BUY|SELL|HOLD|WAIT","confidence":0-100,"riskLevel":"low|medium|high","veto":boolean,"reasoning":"short reason","factors":["factor"]}',
    'Prefer HOLD or WAIT when signal quality is weak, AI providers disagree, news risk is high, or edge is unclear.',
    JSON.stringify({
      symbol: market.symbol,
      market: {
        lastPrice: market.lastPrice,
        change24hPct: market.change24hPct,
        turnover24h: market.turnover24h,
        volume24h: market.volume24h,
        indicators: market.indicators
      },
      news: summarizeNewsForDecision(news),
      ruleSignal: signal,
      deepSeekAnalyst: aiAnalyst,
      constraints: {
        mode: 'dry-run analysis only',
        allowedActions: ['BUY', 'SELL', 'HOLD', 'WAIT'],
        noLeverage: true,
        preferCapitalProtection: true
      }
    })
  ].join('\n\n');
}

async function cursorRequest(prompt) {
  const { Agent } = await import('@cursor/sdk');
  const request = Agent.prompt(prompt, {
    apiKey: config.cursor.apiKey,
    model: { id: config.cursor.model },
    local: { cwd: config.cursor.cwd, settingSources: [] }
  });

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

function applyRiskManager(signal, market, aiAnalyst = {}, cursorAnalyst = {}) {
  const i = market.indicators;
  let riskScore = 0;
  const blocks = [];

  if (!config.dryRun) {
    blocks.push('live mode disabled for this brain stage');
  }

  if (signal.confidence < config.minConfidence && signal.action === 'BUY') {
    blocks.push('confidence below minimum');
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
    requireCursorOk: config.paper.requireCursorOk
  };

  const events = [];
  for (const decision of decisions) {
    const event = applyPaperDecision(state, decision);
    if (event) {
      events.push(event);
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
  if (!position && action === 'BUY') {
    const blocks = [];
    if ((consensus.confidence || 0) < config.paper.minConfidence) {
      blocks.push('paper confidence below minimum');
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

function average(values) {
  const filtered = values.filter((value) => Number.isFinite(value));
  return filtered.length ? filtered.reduce((sum, value) => sum + value, 0) / filtered.length : 0;
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

This brain is read-only and writes decisions to JSONL. It does not place orders.
DeepSeek/OpenAI-compatible AI Analyst and Cursor Analyst are optional and never bypass the risk manager.
`);
}
