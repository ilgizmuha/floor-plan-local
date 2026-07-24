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

  function buildRichAiPayload(market, news, fearGreed, ruleSignal, context = {}) {
    const profile = context.profile || {};
    const regime = context.regime || {};
    const quality = context.qualityFeedback || {};
    const calibration = context.calibration || {};
    return {
      role: 'confirm_only_judge',
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
        'You are a confirm-only judge. Do NOT invent new setups.',
        'If the rule setup is valid, respond verdict=confirm with the SAME action.',
        'If news/regime/risk is bad, respond verdict=veto.',
        'If uncertain, respond verdict=downgrade.',
        'JSON: {"verdict":"confirm|veto|downgrade","action":"BUY|SELL|HOLD|WAIT","confidence":0-100,"riskLevel":"low|medium|high","veto":boolean,"reasoning":"short","factors":["..."]}'
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
