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
  newsLookbackHours: numberEnv('BRAIN_NEWS_LOOKBACK_HOURS', 12),
  loopIntervalSeconds: numberEnv('BRAIN_LOOP_INTERVAL_SECONDS', 300),
  dryRun: env('BRAIN_DRY_RUN', 'true') !== 'false'
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

  const decisions = markets.map((market) => {
    const signal = analyzeMarket(market, news);
    const risk = applyRiskManager(signal, market);
    return {
      timestamp: new Date().toISOString(),
      symbol: market.symbol,
      market,
      news: summarizeNewsForDecision(news),
      signal,
      risk,
      finalAction: risk.allowed ? signal.action : 'WAIT',
      dryRun: config.dryRun
    };
  });

  appendJsonl(path.join(config.dataDir, 'decisions.jsonl'), decisions);
  writeJson(path.join(config.dataDir, 'latest.json'), {
    timestamp: new Date().toISOString(),
    dryRun: config.dryRun,
    decisions
  });

  return {
    timestamp: new Date().toISOString(),
    dryRun: config.dryRun,
    summary: decisions.map((item) => `${item.symbol}:${item.finalAction}:${item.signal.confidence}`).join(' '),
    decisions
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
  const cutoff = Date.now() - config.newsLookbackHours * 60 * 60 * 1000;

  for (const source of config.newsSources) {
    const response = await fetch(source, { headers: { 'User-Agent': 'TradingBrain/1.0' } });
    if (!response.ok) {
      continue;
    }
    const xml = await response.text();
    const items = parseRss(xml)
      .filter((item) => !item.timestamp || item.timestamp >= cutoff)
      .slice(0, 20)
      .map((item) => ({ ...item, source }));
    allItems.push(...items);
  }

  const scored = allItems.map((item) => ({
    ...item,
    sentiment: scoreText(`${item.title} ${item.description}`)
  }));
  const score = scored.length ? round(average(scored.map((item) => item.sentiment.score)), 2) : 0;

  return {
    sourceCount: config.newsSources.length,
    itemCount: scored.length,
    score,
    items: scored
      .sort((a, b) => Math.abs(b.sentiment.score) - Math.abs(a.sentiment.score))
      .slice(0, 8)
      .map((item) => ({
        title: item.title,
        link: item.link,
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

function applyRiskManager(signal, market) {
  const i = market.indicators;
  let riskScore = 0;
  const blocks = [];

  if (!config.dryRun) {
    blocks.push('live mode disabled for this brain stage');
  }

  if (signal.confidence < config.minConfidence && signal.action === 'BUY') {
    blocks.push('confidence below minimum');
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
    error: news.error,
    top: (news.items || []).slice(0, 3)
  };
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

function pickLinkHref(xml) {
  const match = xml.match(/<link\b[^>]*href=["']([^"']+)["'][^>]*>/i);
  return match ? match[1] : '';
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

function ensureDir(dirPath) {
  fs.mkdirSync(dirPath, { recursive: true });
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
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

This brain is read-only and writes decisions to JSONL. It does not place orders.
`);
}
