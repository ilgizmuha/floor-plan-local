'use strict';

const fs = require('fs');
const path = require('path');

function clamp(value, min, max) {
  return Math.max(min, Math.min(max, value));
}

function round(value, decimals) {
  const factor = 10 ** decimals;
  return Math.round(value * factor) / factor;
}

function average(values) {
  if (!values.length) {
    return 0;
  }
  return values.reduce((sum, value) => sum + value, 0) / values.length;
}

function loadJson(filePath, fallback) {
  try {
    if (!fs.existsSync(filePath)) {
      return fallback;
    }
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch (error) {
    return fallback;
  }
}

function createStrategyEngine(options = {}) {
  const knowledgeDir = options.knowledgeDir || path.join(__dirname, 'knowledge');
  const dataDir = options.dataDir || path.join(process.cwd(), 'data');
  const profilesPath = options.profilesPath || path.join(knowledgeDir, 'strategy-profiles.json');
  const calibrationPath = options.calibrationPath || path.join(dataDir, 'calibration.json');
  const defaultCalibrationPath = path.join(knowledgeDir, 'calibration.json');

  function loadProfiles() {
    return loadJson(profilesPath, { version: 1, profiles: {}, symbolPrimary: {}, symbolSecondary: {} });
  }

  function loadCalibration() {
    const fromData = loadJson(calibrationPath, null);
    if (fromData && fromData.symbols) {
      return fromData;
    }
    return loadJson(defaultCalibrationPath, { version: 1, symbols: {} });
  }

  function saveCalibration(data) {
    fs.mkdirSync(path.dirname(calibrationPath), { recursive: true });
    fs.writeFileSync(calibrationPath, JSON.stringify(data, null, 2));
  }

  function getProfile(profilesDoc, profileId) {
    return (profilesDoc.profiles && profilesDoc.profiles[profileId]) || null;
  }

  function resolveProfilesForMarket(market, profilesDoc = loadProfiles()) {
    const provider = market.provider || 'bybit';
    const assetClass = market.assetClass || 'crypto';
    const symbol = market.symbol;

    if (provider === 'finam') {
      return resolveFinamProfiles(market, profilesDoc);
    }

    const primaryId = (profilesDoc.symbolPrimary && profilesDoc.symbolPrimary[symbol])
      || (assetClass === 'crypto' ? 'swing_crypto' : 'swing_tradfi');
    const secondaryId = profilesDoc.symbolSecondary && profilesDoc.symbolSecondary[symbol];
    const primary = getProfile(profilesDoc, primaryId);
    const secondary = secondaryId ? getProfile(profilesDoc, secondaryId) : null;

    return {
      primary,
      primaryId,
      secondary,
      secondaryId,
      strategyType: primary ? primary.strategyType : 'swing'
    };
  }

  function resolveFinamProfiles(market, profilesDoc = loadProfiles()) {
    const finamMeta = profilesDoc.finam || {};
    const symbol = market.symbol;
    const horizon = (market.preferredAccount && market.preferredAccount.horizon)
      || (market.finam && market.finam.account && market.finam.account.horizon)
      || null;
    const role = (market.preferredAccount && market.preferredAccount.role)
      || (market.finam && market.finam.account && market.finam.account.role)
      || null;
    const mapped = profilesDoc.symbolPrimary && profilesDoc.symbolPrimary[symbol];
    const assetClass = market.assetClass || 'other';

    let primaryId = mapped || finamMeta.longProfile || 'long_finam';
    if (!mapped) {
      if (role === 'day' || horizon === 'intraday' || (finamMeta.dayAssetClasses || []).includes(assetClass)) {
        primaryId = finamMeta.dayProfile || 'day_finam';
      } else {
        primaryId = finamMeta.longProfile || 'long_finam';
      }
    }

    const secondaryId = (profilesDoc.symbolSecondary && profilesDoc.symbolSecondary[symbol])
      || ((primaryId === (finamMeta.dayProfile || 'day_finam')
        && (finamMeta.scalpAssetClasses || []).includes(assetClass))
        ? (finamMeta.scalpProfile || 'scalp_finam')
        : null);

    const primary = getProfile(profilesDoc, primaryId);
    const secondary = secondaryId ? getProfile(profilesDoc, secondaryId) : null;
    return {
      primary,
      primaryId,
      secondary,
      secondaryId,
      strategyType: primary ? primary.strategyType : 'long',
      accountRole: (primary && primary.accountRole) || role || 'long',
      trading: (primary && primary.trading) || {}
    };
  }

  function getCalibratedThresholds(symbol, profileId, calibration = loadCalibration()) {
    const entry = calibration.symbols && calibration.symbols[symbol];
    const byProfile = entry && entry.profiles && entry.profiles[profileId];
    return byProfile || {};
  }

  function applySentimentVeto(signal, news = {}, fearGreed = {}, profile = {}) {
    if ((profile.sentimentMode || 'veto_only') !== 'veto_only') {
      return signal;
    }
    const vetoes = [...(signal.vetoBlocks || [])];
    let action = signal.action;

    if (fearGreed.available && fearGreed.value >= 80 && action === 'BUY') {
      vetoes.push(`Fear & Greed extreme greed (${fearGreed.value}) blocks BUY`);
      action = 'WAIT';
    }
    if (fearGreed.available && fearGreed.value <= 12 && action === 'SELL' && !profile.longOnly) {
      vetoes.push(`Fear & Greed extreme fear (${fearGreed.value}) blocks SELL`);
      action = 'HOLD';
    }
    if ((news.score || 0) <= -8 && action === 'BUY') {
      vetoes.push(`Strong negative news (${news.score}) blocks BUY`);
      action = 'WAIT';
    }
    if ((news.score || 0) >= 10 && action === 'SELL' && !profile.longOnly) {
      vetoes.push(`Strong positive news (${news.score}) blocks SELL`);
      action = 'HOLD';
    }

    if (vetoes.length === (signal.vetoBlocks || []).length) {
      return signal;
    }
    return {
      ...signal,
      action,
      confidence: Math.min(signal.confidence || 0, 45),
      vetoBlocks: vetoes,
      reasons: [...(signal.reasons || []), ...vetoes]
    };
  }

  function mapAiVerdictToAction(raw, setupAction) {
    const verdict = String(raw.verdict || '').toLowerCase();
    if (verdict === 'confirm') {
      return setupAction;
    }
    if (verdict === 'veto') {
      return 'WAIT';
    }
    if (verdict === 'downgrade') {
      return 'HOLD';
    }
    const action = String(raw.action || '').toUpperCase();
    if (action === setupAction) {
      return setupAction;
    }
    if (action === 'WAIT' || raw.veto) {
      return 'WAIT';
    }
    if (action === 'HOLD') {
      return 'HOLD';
    }
    if (['BUY', 'SELL'].includes(action) && action !== setupAction) {
      return 'HOLD';
    }
    return action || 'HOLD';
  }

  function combineConfirmOnly(ruleSignal, aiAnalyst = {}, cursorAnalyst = {}, opts = {}) {
    const setupAction = ruleSignal.action;
    const baseReasons = [...(ruleSignal.reasons || [])];

    if (!['BUY', 'SELL'].includes(setupAction)) {
      return {
        ...ruleSignal,
        source: ruleSignal.source || 'rules_no_setup',
        aiAgreement: 'not_needed',
        cursorAgreement: cursorAnalyst.status || 'not_checked'
      };
    }

    const aiDisabled = !aiAnalyst.enabled || aiAnalyst.status === 'disabled' || aiAnalyst.status === 'skipped';
    if (aiDisabled) {
      return applyCursorSoftVeto(ruleSignal, cursorAnalyst, baseReasons);
    }

    if (aiAnalyst.status !== 'ok') {
      if (opts.blockWhenAiUnavailable) {
        return {
          action: 'WAIT',
          confidence: Math.min(ruleSignal.confidence, 35),
          score: ruleSignal.score,
          source: 'ai_unavailable_block',
          aiAgreement: aiAnalyst.status,
          cursorAgreement: 'not_checked',
          reasons: [...baseReasons, `AI unavailable (${aiAnalyst.status}) — setup blocked`]
        };
      }
      return applyCursorSoftVeto({
        ...ruleSignal,
        source: 'rules_ai_unavailable',
        reasons: [...baseReasons, `AI unavailable (${aiAnalyst.status}) — rules-only fallback`]
      }, cursorAnalyst, baseReasons);
    }

    const aiMapped = mapAiVerdictToAction(aiAnalyst, setupAction);
    if (aiMapped === 'WAIT' || aiAnalyst.veto) {
      return {
        action: 'WAIT',
        confidence: Math.min(ruleSignal.confidence, aiAnalyst.confidence || 40, 45),
        score: ruleSignal.score,
        source: 'ai_veto',
        aiAgreement: 'veto',
        cursorAgreement: cursorAnalyst.status || 'not_checked',
        reasons: [...baseReasons, aiAnalyst.reasoning || 'AI vetoed the setup']
      };
    }
    if (aiMapped === 'HOLD') {
      return {
        action: 'HOLD',
        confidence: Math.min(ruleSignal.confidence, aiAnalyst.confidence || 50),
        score: ruleSignal.score,
        source: 'ai_downgrade',
        aiAgreement: 'downgrade',
        cursorAgreement: cursorAnalyst.status || 'not_checked',
        reasons: [...baseReasons, aiAnalyst.reasoning || 'AI downgraded setup to HOLD']
      };
    }

    let action = setupAction;
    let source = 'rules_ai_confirm';
    let cursorAgreement = cursorAnalyst.status || 'not_checked';
    const confidences = [ruleSignal.confidence, aiAnalyst.confidence || ruleSignal.confidence];

    if (cursorAnalyst.enabled && cursorAnalyst.status !== 'disabled' && cursorAnalyst.status !== 'skipped') {
      if (cursorAnalyst.status !== 'ok') {
        if (opts.cursorRequired) {
          return {
            action: 'WAIT',
            confidence: Math.min(ruleSignal.confidence, 40),
            score: ruleSignal.score,
            source: 'cursor_unavailable_block',
            aiAgreement: 'confirm',
            cursorAgreement: cursorAnalyst.status,
            reasons: [...baseReasons, 'Cursor unavailable — setup blocked']
          };
        }
      } else if (cursorAnalyst.veto) {
        return {
          action: 'WAIT',
          confidence: Math.min(ruleSignal.confidence, cursorAnalyst.confidence || 40, 45),
          score: ruleSignal.score,
          source: 'cursor_veto',
          aiAgreement: 'confirm',
          cursorAgreement: 'veto',
          reasons: [...baseReasons, cursorAnalyst.reasoning || 'Cursor soft-veto']
        };
      } else {
        const cursorMapped = mapAiVerdictToAction(cursorAnalyst, setupAction);
        if (cursorMapped === 'WAIT') {
          return {
            action: 'WAIT',
            confidence: Math.min(ruleSignal.confidence, 42),
            score: ruleSignal.score,
            source: 'cursor_veto',
            aiAgreement: 'confirm',
            cursorAgreement: 'veto',
            reasons: [...baseReasons, cursorAnalyst.reasoning || 'Cursor veto']
          };
        }
        if (cursorMapped === 'HOLD') {
          action = 'HOLD';
          source = 'cursor_downgrade';
          cursorAgreement = 'downgrade';
        } else {
          confidences.push(cursorAnalyst.confidence || ruleSignal.confidence);
          cursorAgreement = 'confirm';
          source = 'rules_ai_cursor_confirm';
        }
      }
    }

    return {
      action,
      confidence: clamp(Math.round(average(confidences)) + 4, 0, 100),
      score: ruleSignal.score,
      source,
      aiAgreement: 'confirm',
      cursorAgreement,
      reasons: [...baseReasons, `AI confirmed ${setupAction}: ${aiAnalyst.reasoning || 'ok'}`]
    };
  }

  function applyCursorSoftVeto(ruleSignal, cursorAnalyst, baseReasons = ruleSignal.reasons || []) {
    if (!cursorAnalyst.enabled || cursorAnalyst.status === 'disabled' || cursorAnalyst.status === 'skipped') {
      return {
        ...ruleSignal,
        source: ruleSignal.source || 'rules_only',
        aiAgreement: 'not_enabled',
        cursorAgreement: 'not_enabled'
      };
    }
    if (cursorAnalyst.status === 'ok' && cursorAnalyst.veto) {
      return {
        action: 'WAIT',
        confidence: Math.min(ruleSignal.confidence, 40),
        score: ruleSignal.score,
        source: 'cursor_veto',
        aiAgreement: 'not_enabled',
        cursorAgreement: 'veto',
        reasons: [...baseReasons, cursorAnalyst.reasoning || 'Cursor veto']
      };
    }
    return {
      ...ruleSignal,
      source: ruleSignal.source || 'rules_only',
      aiAgreement: 'not_enabled',
      cursorAgreement: cursorAnalyst.status || 'not_checked'
    };
  }

  function summarizeIndicatorsForAi(market = {}) {
    const i = market.indicators || {};
    const orderBook = market.orderBook || {};
    const derivatives = market.derivatives || {};
    const scalp = market.scalpIndicators || {};
    const readings = [];

    if (i.sma20 != null && i.sma50 != null) {
      readings.push({
        name: 'trend_sma',
        value: `${i.sma20}/${i.sma50}`,
        bias: i.sma20 > i.sma50 ? 'bullish' : 'bearish',
        note: i.sma20 > i.sma50 ? 'SMA20 above SMA50' : 'SMA20 below SMA50'
      });
    }
    if (i.ema12 != null && i.ema26 != null) {
      readings.push({
        name: 'trend_ema',
        value: `${i.ema12}/${i.ema26}`,
        bias: i.ema12 > i.ema26 ? 'bullish' : 'bearish',
        note: i.ema12 > i.ema26 ? 'EMA12 above EMA26' : 'EMA12 below EMA26'
      });
    }
    if (i.rsi14 != null) {
      let bias = 'neutral';
      let note = 'RSI mid-range';
      if (i.rsi14 >= 70) {
        bias = 'overbought';
        note = 'RSI overbought risk';
      } else if (i.rsi14 <= 30) {
        bias = 'oversold';
        note = 'RSI oversold bounce candidate';
      } else if (i.rsi14 >= 55) {
        bias = 'bullish';
        note = 'RSI bullish zone';
      } else if (i.rsi14 <= 45) {
        bias = 'bearish';
        note = 'RSI bearish zone';
      }
      readings.push({ name: 'rsi14', value: i.rsi14, bias, note });
    }
    if (i.macdHistogram != null) {
      readings.push({
        name: 'macd',
        value: {
          line: i.macdLine,
          signal: i.macdSignal,
          hist: i.macdHistogram,
          delta: i.macdHistogramDelta
        },
        bias: i.macdHistogram > 0 ? 'bullish' : 'bearish',
        note: i.macdHistogramDelta > 0 ? 'MACD histogram improving' : 'MACD histogram weakening'
      });
    }
    if (i.bollingerPosition != null) {
      let bias = 'neutral';
      if (i.bollingerPosition >= 0.85) {
        bias = 'overbought';
      } else if (i.bollingerPosition <= 0.15) {
        bias = 'oversold';
      }
      readings.push({
        name: 'bollinger',
        value: {
          position: i.bollingerPosition,
          widthPct: i.bollingerWidthPct,
          upper: i.bollingerUpper,
          lower: i.bollingerLower
        },
        bias,
        note: `price at ${round((i.bollingerPosition || 0) * 100, 1)}% of BB range`
      });
    }
    if (i.adx14 != null) {
      readings.push({
        name: 'adx14',
        value: i.adx14,
        bias: i.adx14 >= 22 ? 'trending' : 'ranging',
        note: i.adx14 >= 22 ? 'ADX shows trend strength' : 'ADX weak / range'
      });
    }
    if (i.momentumPct != null) {
      readings.push({
        name: 'momentum',
        value: i.momentumPct,
        bias: i.momentumPct > 0 ? 'bullish' : 'bearish',
        note: `short momentum ${i.momentumPct}%`
      });
    }
    if (i.volatilityPct != null) {
      readings.push({
        name: 'volatility',
        value: i.volatilityPct,
        bias: i.volatilityPct >= 3.2 ? 'high' : 'normal',
        note: `ATR% ${i.volatilityPct}`
      });
    }
    if (i.volumeRatio != null) {
      readings.push({
        name: 'volume',
        value: i.volumeRatio,
        bias: i.volumeRatio >= 1.15 ? 'expanding' : (i.volumeRatio <= 0.85 ? 'dry' : 'normal'),
        note: `volume ratio ${i.volumeRatio}`
      });
    }
    if (i.distanceToSupportPct != null || i.distanceToResistancePct != null) {
      readings.push({
        name: 'levels',
        value: {
          support: i.support,
          resistance: i.resistance,
          distanceToSupportPct: i.distanceToSupportPct,
          distanceToResistancePct: i.distanceToResistancePct
        },
        bias: 'context',
        note: `support ${i.distanceToSupportPct}% / resistance ${i.distanceToResistancePct}%`
      });
    }
    if (orderBook.available) {
      readings.push({
        name: 'orderbook',
        value: {
          spreadPct: orderBook.spreadPct,
          imbalance: orderBook.imbalance,
          pressure: orderBook.pressure,
          bidDepthUsd: orderBook.bidDepthUsd,
          askDepthUsd: orderBook.askDepthUsd
        },
        bias: orderBook.pressure || 'neutral',
        note: `book ${orderBook.pressure}, spread ${orderBook.spreadPct}%`
      });
    }
    if (derivatives.available) {
      readings.push({
        name: 'derivatives',
        value: {
          fundingRatePct: derivatives.fundingRatePct,
          basisPct: derivatives.basisPct,
          openInterestChangePct: derivatives.openInterestChangePct
        },
        bias: derivatives.fundingRatePct > 0.03 ? 'crowded_long'
          : (derivatives.fundingRatePct < -0.03 ? 'crowded_short' : 'neutral'),
        note: `funding ${derivatives.fundingRatePct}%, OIΔ ${derivatives.openInterestChangePct}%`
      });
    }
    if (scalp.available) {
      readings.push({
        name: 'scalp_5m',
        value: {
          rsi: scalp.rsi,
          momentumPct: scalp.momentumPct,
          volumeRatio: scalp.volumeRatio,
          pivots: scalp.pivots || null
        },
        bias: (scalp.momentumPct || 0) > 0 ? 'bullish' : 'bearish',
        note: '5m scalp indicators available'
      });
    }

    const bullish = readings.filter((item) => ['bullish', 'oversold', 'expanding'].includes(item.bias)).length;
    const bearish = readings.filter((item) => ['bearish', 'overbought', 'dry', 'crowded_long', 'high'].includes(item.bias)).length;
    return {
      readings,
      tally: { bullish, bearish, total: readings.length },
      netBias: bullish > bearish + 1 ? 'bullish' : (bearish > bullish + 1 ? 'bearish' : 'mixed')
    };
  }

  function summarizeRecentCandles(market = {}, limit = 12) {
    const candles = market.recentCandles || market.candles || [];
    if (!Array.isArray(candles) || !candles.length) {
      return { available: false, count: 0, bars: [] };
    }
    const slice = candles.slice(-limit).map((candle) => ({
      t: candle.start || candle.timestamp || null,
      o: round(Number(candle.open), 6),
      h: round(Number(candle.high), 6),
      l: round(Number(candle.low), 6),
      c: round(Number(candle.close), 6),
      v: round(Number(candle.volume) || 0, 4)
    }));
    const closes = slice.map((bar) => bar.c).filter((value) => Number.isFinite(value));
    const first = closes[0];
    const last = closes[closes.length - 1];
    return {
      available: true,
      count: slice.length,
      rangePct: first ? round(((last - first) / first) * 100, 4) : 0,
      higherHighs: slice.length > 2 ? slice[slice.length - 1].h >= Math.max(...slice.slice(0, -1).map((b) => b.h)) : false,
      lowerLows: slice.length > 2 ? slice[slice.length - 1].l <= Math.min(...slice.slice(0, -1).map((b) => b.l)) : false,
      bars: slice
    };
  }

  function buildRichAiPayload(market, news, fearGreed, ruleSignal, context = {}) {
    const profile = context.profile || {};
    const regime = context.regime || {};
    const quality = context.qualityFeedback || {};
    const calibration = context.calibration || {};
    const indicatorAnalysis = summarizeIndicatorsForAi(market);
    const recentCandles = summarizeRecentCandles(market, 12);
    return {
      role: 'indicator_analyst_and_confirm_judge',
      symbol: market.symbol,
      strategy: {
        profileId: context.profileId,
        label: profile.label,
        type: profile.strategyType,
        timeframe: profile.timeframe,
        minConfidence: calibration.minConfidence || profile.minConfidence,
        longOnly: Boolean(profile.longOnly)
      },
      setup: {
        action: ruleSignal.action,
        confidence: ruleSignal.confidence,
        score: ruleSignal.score,
        reasons: (ruleSignal.reasons || []).slice(0, 10),
        components: ruleSignal.components || {},
        vetoBlocks: ruleSignal.vetoBlocks || []
      },
      regime: {
        name: regime.regime,
        preferredStrategy: regime.preferredStrategy,
        allowSwingBuy: regime.allowSwingBuy,
        allowSwingSell: regime.allowSwingSell,
        reasons: (regime.reasons || []).slice(0, 4)
      },
      qualityFeedback: quality,
      analysisRequired: {
        mustReview: [
          'trend (SMA/EMA/ADX)',
          'momentum (RSI/MACD/momentumPct)',
          'volatility and Bollinger position',
          'volume ratio',
          'order book pressure/spread if available',
          'derivatives funding/OI if available',
          'recent candle structure',
          'news score and Fear & Greed as veto context only'
        ],
        outputMustInclude: [
          'indicatorSummary citing concrete values',
          'factors[] with specific metric names',
          'verdict confirm|veto|downgrade based on indicator agreement with setup'
        ]
      },
      indicatorAnalysis,
      recentCandles,
      market: {
        lastPrice: market.lastPrice,
        change24hPct: market.change24hPct,
        assetClass: market.assetClass,
        provider: market.provider,
        indicators: market.indicators,
        orderBook: market.orderBook || { available: false },
        derivatives: market.derivatives || { available: false },
        scalpIndicators: market.scalpIndicators || { available: false }
      },
      news: {
        score: news.score,
        itemCount: news.itemCount,
        topTitles: (news.top || []).slice(0, 5).map((item) => item.title)
      },
      fearGreed: {
        available: fearGreed.available,
        value: fearGreed.value,
        classification: fearGreed.classification
      },
      instructions: [
        'First ANALYZE indicators, order book, derivatives and recent candles.',
        'Then judge the rule setup: confirm only if metrics support it.',
        'Do NOT invent a new unrelated setup.',
        'If indicators contradict the setup, veto or downgrade.',
        'In reasoning and factors cite concrete numbers (e.g. RSI 72, ADX 18, spread 0.12%).',
        'JSON: {"verdict":"confirm|veto|downgrade","action":"BUY|SELL|HOLD|WAIT|EXIT","confidence":0-100,"riskLevel":"low|medium|high","veto":boolean,"indicatorSummary":"short analysis of metrics","reasoning":"short","factors":["RSI ...","MACD ...","book ..."]}'
      ]
    };
  }

  function scoreSentimentForProfile(news, fearGreed, profile) {
    if ((profile.sentimentMode || 'veto_only') === 'veto_only') {
      return { score: 0, reasons: ['sentiment: veto-only (not scored)'] };
    }
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
      if (fearGreed.value >= 65) {
        score -= 4;
        reasons.push('Fear & Greed elevated');
      } else if (fearGreed.value <= 25) {
        score += 2;
        reasons.push('Fear & Greed fear zone');
      }
    }
    return { score: round(score, 2), reasons };
  }

  function resolveProfileWeights(profile, market, fallbackWeights) {
    const weights = { ...(profile.ensembleWeights || fallbackWeights) };
    const derivatives = market.derivatives || {};
    const orderBook = market.orderBook || {};
    if (!derivatives.available) {
      weights.technical += (weights.derivatives || 0) * 0.65;
      weights.sentiment += (weights.derivatives || 0) * 0.35;
      weights.derivatives = 0;
    }
    if (!orderBook.available) {
      weights.technical += weights.microstructure || 0;
      weights.microstructure = 0;
    }
    const total = Object.values(weights).reduce((sum, value) => sum + value, 0) || 1;
    return Object.fromEntries(Object.entries(weights).map(([key, value]) => [key, round(value / total, 4)]));
  }

  function buildSignalFromComponents({
    market,
    profile,
    profileId,
    components,
    ensembleWeights,
    qualityFeedback,
    calibrationEntry,
    scoreTechnicalComponent,
    longOnlyAdjust
  }) {
    let score = 0;
    const reasons = [];
    for (const [name, component] of Object.entries(components)) {
      const weight = ensembleWeights[name] || 0;
      score += component.score * weight;
      if (component.reasons.length) {
        reasons.push(`${name}: ${component.reasons.slice(0, 2).join('; ')}`);
      }
    }
    score = round(score, 2);

    const symbolFeedback = qualityFeedback || { confidenceDelta: 0, minConfidenceDelta: 0, reason: 'no data' };
    let confidence = clamp(Math.round(50 + score + (symbolFeedback.confidenceDelta || 0)), 0, 100);
    const baseMin = calibrationEntry.minConfidence || profile.minConfidence || 65;
    const effectiveMinConfidence = clamp(baseMin + (symbolFeedback.minConfidenceDelta || 0), 55, 92);
    const sellThreshold = calibrationEntry.sellThreshold || profile.sellThreshold || 35;

    let action = 'HOLD';
    if (confidence >= effectiveMinConfidence) {
      action = 'BUY';
    } else if (confidence <= sellThreshold) {
      action = profile.longOnly ? 'EXIT' : 'SELL';
    }

    if (profile.longOnly && action === 'SELL') {
      action = 'EXIT';
    }
    if (longOnlyAdjust && action === 'SELL') {
      action = 'EXIT';
    }

    if (symbolFeedback.reason && !['no data', 'neutral', 'insufficient samples'].includes(symbolFeedback.reason)) {
      reasons.push(`Quality feedback: ${symbolFeedback.reason}`);
    }

    return {
      action,
      confidence,
      score,
      strategyType: profile.strategyType,
      strategyProfile: profileId,
      strategyLabel: profile.label,
      components: Object.fromEntries(Object.entries(components).map(([name, component]) => [name, {
        score: component.score,
        weightedScore: round(component.score * (ensembleWeights[name] || 0), 2),
        reasons: component.reasons
      }])),
      ensembleWeights,
      effectiveMinConfidence,
      sellThreshold,
      qualityFeedback: symbolFeedback,
      source: 'strategy_profile',
      reasons,
      vetoBlocks: []
    };
  }

  function splitWalkForwardIndices(total, folds = 5, warmup = 60) {
    const usable = total - warmup;
    if (usable <= folds * 10) {
      return [{ trainEnd: warmup + Math.floor(usable * 0.7), testStart: warmup + Math.floor(usable * 0.7), testEnd: total }];
    }
    const foldSize = Math.floor(usable / folds);
    const segments = [];
    for (let fold = 0; fold < folds; fold += 1) {
      const testStart = warmup + fold * foldSize;
      const testEnd = fold === folds - 1 ? total : testStart + foldSize;
      segments.push({
        fold: fold + 1,
        trainEnd: testStart,
        testStart,
        testEnd
      });
    }
    return segments;
  }

  function evaluateThresholdOnSlice(decisions, minConfidence, sellThreshold, feeRate = 0.001) {
    let hits = 0;
    let evaluated = 0;
    for (let index = 0; index + 4 < decisions.length; index += 1) {
      const current = decisions[index];
      const future = decisions[index + 4];
      let action = current.rawAction;
      if (action === 'BUY' && (current.confidence || 0) < minConfidence) {
        continue;
      }
      if ((action === 'SELL' || action === 'EXIT') && current.confidence > sellThreshold) {
        continue;
      }
      if (!['BUY', 'SELL', 'EXIT'].includes(action)) {
        continue;
      }
      const p0 = current.price;
      const p1 = future.price;
      if (!p0 || !p1) {
        continue;
      }
      const edge = action === 'BUY' ? ((p1 - p0) / p0) * 100 : ((p0 - p1) / p0) * 100;
      evaluated += 1;
      if (edge - (feeRate * 200) > 0) {
        hits += 1;
      }
    }
    const hitRate = evaluated ? (hits / evaluated) * 100 : 0;
    return { minConfidence, sellThreshold, evaluated, hits, hitRatePct: round(hitRate, 2) };
  }

  function calibrateThresholds(decisions, profile, grid = {}) {
    const minCandidates = grid.minConfidence || [58, 62, 65, 68, 70, 72, 75, 78];
    const sellCandidates = grid.sellThreshold || [profile.sellThreshold || 32, 35, 38, 40];
    let best = null;
    for (const minConfidence of minCandidates) {
      for (const sellThreshold of sellCandidates) {
        if (sellThreshold >= minConfidence - 10) {
          continue;
        }
        const result = evaluateThresholdOnSlice(decisions, minConfidence, sellThreshold);
        if (result.evaluated < 8) {
          continue;
        }
        const score = result.hitRatePct - Math.max(0, 55 - result.hitRatePct) * 0.5;
        if (!best || score > best.score) {
          best = { ...result, score: round(score, 3) };
        }
      }
    }
    return best || {
      minConfidence: profile.minConfidence,
      sellThreshold: profile.sellThreshold,
      evaluated: 0,
      hitRatePct: 0,
      score: 0
    };
  }

  return {
    loadProfiles,
    loadCalibration,
    saveCalibration,
    getProfile,
    resolveProfilesForMarket,
    resolveFinamProfiles,
    getCalibratedThresholds,
    applySentimentVeto,
    combineConfirmOnly,
    summarizeIndicatorsForAi,
    summarizeRecentCandles,
    buildRichAiPayload,
    scoreSentimentForProfile,
    resolveProfileWeights,
    buildSignalFromComponents,
    splitWalkForwardIndices,
    evaluateThresholdOnSlice,
    calibrateThresholds,
    mapAiVerdictToAction
  };
}

module.exports = { createStrategyEngine };
