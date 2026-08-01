#!/usr/bin/env node

const crypto = require('crypto');
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
  apiKey: env('BYBIT_API_KEY', ''),
  apiSecret: env('BYBIT_API_SECRET', ''),
  recvWindow: env('BYBIT_RECV_WINDOW', '5000'),
  dryRun: env('BYBIT_DRY_RUN', 'true') !== 'false'
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

  if (command === 'health') {
    const time = await publicRequest('/v5/market/time');
    console.log('Bybit public API is reachable.');
    console.log(JSON.stringify(time, null, 2));
    return;
  }

  if (command === 'balance') {
    requireCredentials();
    const accountType = args.accountType || env('BYBIT_ACCOUNT_TYPE', 'UNIFIED');
    const balance = await privateRequest('GET', '/v5/account/wallet-balance', { accountType });
    console.log(JSON.stringify(balance, null, 2));
    return;
  }

  if (command === 'order') {
    const order = buildOrder(args);

    if (config.dryRun || !args.confirmLiveOrder) {
      console.log('DRY RUN: live order was not sent.');
      console.log('Set BYBIT_DRY_RUN=false and pass --confirm-live-order to send a real order.');
      console.log(JSON.stringify(order, null, 2));
      return;
    }

    requireCredentials();
    const result = await privateRequest('POST', '/v5/order/create', order);
    console.log(JSON.stringify(result, null, 2));
    return;
  }

  throw new Error(`Unknown command: ${command}`);
}

function buildOrder(input) {
  const category = input.category || env('BYBIT_CATEGORY', 'spot');
  const symbol = input.symbol || env('BYBIT_SYMBOL', 'BTCUSDT');
  const side = input.side || env('BYBIT_SIDE', 'Buy');
  const orderType = input.orderType || env('BYBIT_ORDER_TYPE', 'Limit');
  const qty = input.qty || env('BYBIT_QTY', '');
  const price = input.price || env('BYBIT_PRICE', '');
  const timeInForce = input.timeInForce || env('BYBIT_TIME_IN_FORCE', 'GTC');

  if (!qty) {
    throw new Error('Missing order quantity. Set BYBIT_QTY or pass --qty.');
  }

  const order = {
    category,
    symbol,
    side,
    orderType,
    qty
  };

  if (orderType === 'Limit') {
    if (!price) {
      throw new Error('Limit orders require BYBIT_PRICE or --price.');
    }
    order.price = price;
    order.timeInForce = timeInForce;
  }

  if (input.marketUnit) {
    order.marketUnit = input.marketUnit;
  }

  if (input.takeProfit) {
    order.takeProfit = input.takeProfit;
  }

  if (input.stopLoss) {
    order.stopLoss = input.stopLoss;
  }

  return order;
}

async function publicRequest(endpoint, query = {}) {
  const url = new URL(endpoint, config.baseUrl);
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== '') {
      url.searchParams.set(key, value);
    }
  }

  const response = await fetch(url, { method: 'GET' });
  const text = await response.text();
  const data = parseJson(text);

  if (!response.ok) {
    const error = new Error(`Public request failed with HTTP ${response.status}`);
    error.details = data || text;
    throw error;
  }

  return data;
}

async function privateRequest(method, endpoint, params = {}) {
  const timestamp = Date.now().toString();
  const body = method === 'POST' ? JSON.stringify(params) : '';
  const query = method === 'GET' ? toQueryString(params) : '';
  const payload = timestamp + config.apiKey + config.recvWindow + (method === 'GET' ? query : body);
  const signature = crypto.createHmac('sha256', config.apiSecret).update(payload).digest('hex');
  const pathWithQuery = query ? `${endpoint}?${query}` : endpoint;
  const url = new URL(pathWithQuery, config.baseUrl);

  const response = await fetch(url, {
    method,
    headers: {
      'Content-Type': 'application/json',
      'X-BAPI-API-KEY': config.apiKey,
      'X-BAPI-TIMESTAMP': timestamp,
      'X-BAPI-RECV-WINDOW': config.recvWindow,
      'X-BAPI-SIGN': signature
    },
    body: method === 'POST' ? body : undefined
  });
  const text = await response.text();
  const data = parseJson(text);

  if (!response.ok || !data || data.retCode !== 0) {
    const error = new Error(`Bybit request failed${data && data.retMsg ? `: ${data.retMsg}` : ''}`);
    error.details = data || text;
    throw error;
  }

  return data;
}

function requireCredentials() {
  if (!config.apiKey || !config.apiSecret) {
    throw new Error('Set BYBIT_API_KEY and BYBIT_API_SECRET in .env or environment variables.');
  }
}

function toQueryString(params) {
  return Object.entries(params)
    .filter(([, value]) => value !== undefined && value !== '')
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`)
    .join('&');
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

function env(key, fallback) {
  return process.env[key] || fallback;
}

function parseJson(text) {
  try {
    return JSON.parse(text);
  } catch (error) {
    return null;
  }
}

function printHelp() {
  console.log(`Usage:
  npm run bybit:health
  npm run bybit:balance
  npm run bybit:order -- --symbol BTCUSDT --side Buy --order-type Limit --qty 0.0001 --price 50000

Safety:
  Orders are dry-run by default.
  To send a live order, set BYBIT_DRY_RUN=false and pass --confirm-live-order.
`);
}
