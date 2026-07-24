'use strict';

/**
 * Finam Trade API REST client (market data + accounts).
 * Docs: https://api.finam.ru / https://tradeapi.finam.ru/docs/rest/llms.txt
 * Symbols: TICKER@MIC (e.g. SBER@MISX, AAPL@XNGS, USD000UTSTOM@MISX)
 */

const DEFAULT_BASE = 'https://api.finam.ru';

function num(value) {
  if (value == null) {
    return null;
  }
  if (typeof value === 'number') {
    return Number.isFinite(value) ? value : null;
  }
  if (typeof value === 'object' && value.value != null) {
    const parsed = Number(value.value);
    return Number.isFinite(parsed) ? parsed : null;
  }
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : null;
}

function moneyToNumber(cashItem) {
  if (!cashItem) {
    return 0;
  }
  const units = Number(cashItem.units || 0);
  const nanos = Number(cashItem.nanos || 0);
  return units + (nanos / 1e9);
}

class FinamClient {
  constructor(options = {}) {
    this.baseUrl = String(options.baseUrl || DEFAULT_BASE).replace(/\/+$/, '');
    this.secret = options.secret || '';
    this.timeoutMs = Number(options.timeoutMs || 20000);
    this.token = null;
    this.tokenExpiresAt = 0;
  }

  async ensureToken(force = false) {
    const skewMs = 60_000;
    if (!force && this.token && Date.now() < this.tokenExpiresAt - skewMs) {
      return this.token;
    }
    if (!this.secret) {
      throw new Error('FINAM_SECRET_TOKEN is missing');
    }
    const data = await this.request('POST', '/v1/sessions', {
      body: { secret: this.secret },
      auth: false
    });
    this.token = data.token;
    // JWT ~15 min; refresh proactively
    this.tokenExpiresAt = Date.now() + (14 * 60 * 1000);
    return this.token;
  }

  async tokenDetails() {
    const token = await this.ensureToken();
    return this.request('POST', '/v1/sessions/details', {
      body: { token },
      auth: true
    });
  }

  async getAccount(accountId) {
    return this.request('GET', `/v1/accounts/${encodeURIComponent(accountId)}`);
  }

  async lastQuote(symbol) {
    return this.request('GET', `/v1/instruments/${encodeURIComponent(symbol)}/quotes/latest`);
  }

  async orderBook(symbol) {
    return this.request('GET', `/v1/instruments/${encodeURIComponent(symbol)}/orderbook`);
  }

  async bars(symbol, { timeframe = 'TIME_FRAME_M15', startTime, endTime } = {}) {
    const params = new URLSearchParams({ timeframe });
    if (startTime) {
      params.set('interval.start_time', startTime);
    }
    if (endTime) {
      params.set('interval.end_time', endTime);
    }
    return this.request('GET', `/v1/instruments/${encodeURIComponent(symbol)}/bars?${params}`);
  }

  async latestTrades(symbol) {
    return this.request('GET', `/v1/instruments/${encodeURIComponent(symbol)}/trades/latest`);
  }

  async getAsset(symbol, accountId) {
    const q = accountId ? `?account_id=${encodeURIComponent(accountId)}` : '';
    return this.request('GET', `/v1/assets/${encodeURIComponent(symbol)}${q}`);
  }

  async request(method, path, { body, auth = true } = {}) {
    const headers = { Accept: 'application/json' };
    if (auth) {
      const token = await this.ensureToken();
      headers.Authorization = `Bearer ${token}`;
    }
    if (body != null) {
      headers['Content-Type'] = 'application/json';
    }

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.timeoutMs);
    try {
      const response = await fetch(`${this.baseUrl}${path}`, {
        method,
        headers,
        body: body == null ? undefined : JSON.stringify(body),
        signal: controller.signal
      });
      const text = await response.text();
      let data = null;
      try {
        data = JSON.parse(text);
      } catch (error) {
        data = { raw: text };
      }
      if (!response.ok) {
        const message = (data && (data.message || data.error)) || `HTTP ${response.status}`;
        const err = new Error(`Finam ${method} ${path}: ${message}`);
        err.status = response.status;
        err.details = data;
        throw err;
      }
      return data;
    } finally {
      clearTimeout(timer);
    }
  }
}

function finamAssetClass(symbol) {
  const ticker = String(symbol || '').split('@')[0];
  if (/^(USD|EUR|CNY|GBP|JPY)/.test(ticker) || /RUB/.test(ticker) || /UTSTOM|TOD|_TOM/.test(ticker)) {
    return 'forex';
  }
  if (/^(GLD|GOLD|SLV|SILV|PLD|PLT)/i.test(ticker) || /GLDRUB|SLVRUB/.test(ticker)) {
    return 'metal';
  }
  if (/@(XNGS|XNYS|XNAS|ARCX)$/.test(symbol)) {
    return 'stock';
  }
  if (/@MISX$/.test(symbol)) {
    return 'stock';
  }
  return 'other';
}

function summarizeFinamOrderBook(payload) {
  const rows = payload && payload.orderbook && Array.isArray(payload.orderbook.rows)
    ? payload.orderbook.rows
    : [];
  const bids = [];
  const asks = [];
  for (const row of rows) {
    const price = num(row.price);
    const buy = num(row.buy_size);
    const sell = num(row.sell_size);
    if (price == null) {
      continue;
    }
    if (buy && buy > 0) {
      bids.push({ price, size: buy, usd: price * buy });
    }
    if (sell && sell > 0) {
      asks.push({ price, size: sell, usd: price * sell });
    }
  }
  bids.sort((a, b) => b.price - a.price);
  asks.sort((a, b) => a.price - b.price);
  const bidDepth = bids.slice(0, 10).reduce((sum, row) => sum + row.usd, 0);
  const askDepth = asks.slice(0, 10).reduce((sum, row) => sum + row.usd, 0);
  const bestBid = bids[0] ? bids[0].price : null;
  const bestAsk = asks[0] ? asks[0].price : null;
  const mid = bestBid && bestAsk ? (bestBid + bestAsk) / 2 : null;
  const spreadPct = mid ? ((bestAsk - bestBid) / mid) * 100 : null;
  const imbalance = (bidDepth + askDepth) ? (bidDepth - askDepth) / (bidDepth + askDepth) : 0;
  return {
    available: bids.length + asks.length > 0,
    spreadPct: spreadPct == null ? null : Math.round(spreadPct * 1000) / 1000,
    imbalance: Math.round(imbalance * 1000) / 1000,
    pressure: imbalance > 0.15 ? 'buy' : (imbalance < -0.15 ? 'sell' : 'neutral'),
    bidDepthUsd: Math.round(bidDepth),
    askDepthUsd: Math.round(askDepth),
    bidWall: bids[0] || null,
    askWall: asks[0] || null
  };
}

function barsToCandles(barsPayload) {
  const bars = (barsPayload && barsPayload.bars) || [];
  return bars
    .map((bar) => ({
      start: Date.parse(bar.timestamp),
      open: num(bar.open),
      high: num(bar.high),
      low: num(bar.low),
      close: num(bar.close),
      volume: num(bar.volume) || 0
    }))
    .filter((candle) => Number.isFinite(candle.start) && Number.isFinite(candle.close))
    .sort((a, b) => a.start - b.start);
}

function summarizeAccount(account) {
  const cash = Array.isArray(account.cash)
    ? account.cash.map((item) => ({
      currency: item.currency_code,
      amount: Math.round(moneyToNumber(item) * 100) / 100
    }))
    : [];
  const positions = Array.isArray(account.positions)
    ? account.positions.map((pos) => ({
      symbol: pos.symbol,
      qty: num(pos.quantity),
      averagePrice: num(pos.average_price),
      currentPrice: num(pos.current_price),
      dailyPnl: num(pos.daily_pnl),
      unrealizedPnl: num(pos.unrealized_pnl)
    }))
    : [];
  return {
    accountId: account.account_id || account.id,
    type: account.type,
    status: account.status,
    equity: num(account.equity),
    unrealizedProfit: num(account.unrealized_profit),
    cash,
    positions,
    portfolioMc: account.portfolio_mc || null
  };
}

module.exports = {
  FinamClient,
  num,
  moneyToNumber,
  finamAssetClass,
  summarizeFinamOrderBook,
  barsToCandles,
  summarizeAccount
};
