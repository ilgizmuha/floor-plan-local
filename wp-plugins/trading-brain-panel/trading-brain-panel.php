<?php
/**
 * Plugin Name: Trading Brain Panel
 * Description: Admin trading terminal for the hybrid trading brain (Bybit + Finam).
 * Version: 0.2.1
 * Author: Cursor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Trading_Brain_Panel {
	private const MENU_SLUG = 'trading-brain-panel';
	private const NONCE_ACTION = 'trading_brain_panel_action';
	private const HELPER = '/usr/local/bin/trading-brain-panel';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'wp_ajax_trading_brain_panel_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_trading_brain_panel_control', array( __CLASS__, 'ajax_control' ) );
	}

	public static function add_menu(): void {
		add_menu_page(
			'Trading Brain',
			'Trading Brain',
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-chart-line',
			58
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'trading-brain-panel' ) );
		}

		$nonce = wp_create_nonce( self::NONCE_ACTION );
		?>
		<div class="wrap trading-brain-panel tbp-terminal">
			<header class="tbp-topbar">
				<div class="tbp-brand">
					<span class="tbp-logo">TB</span>
					<div>
						<strong>Trading Brain</strong>
						<div class="tbp-sub" id="tbp-updated">—</div>
					</div>
				</div>
				<div class="tbp-kpis">
					<div class="tbp-kpi"><span>Service</span><strong id="tbp-service-status">…</strong></div>
					<div class="tbp-kpi"><span>Dry-run</span><strong id="tbp-dry-run">…</strong></div>
					<div class="tbp-kpi"><span>Paper PnL</span><strong id="tbp-kpi-paper">…</strong></div>
					<div class="tbp-kpi"><span>Finam</span><strong id="tbp-kpi-finam">…</strong></div>
					<div class="tbp-kpi"><span>Signals</span><strong id="tbp-kpi-signals">…</strong></div>
				</div>
				<div class="tbp-actions">
					<button class="button button-primary" data-tbp-action="refresh">Обновить</button>
					<button class="button" data-tbp-action="restart">Restart</button>
					<button class="button" data-tbp-action="start">Start</button>
					<button class="button" data-tbp-action="stop">Stop</button>
					<label class="tbp-autoref"><input type="checkbox" id="tbp-autorefresh" checked> 30с</label>
				</div>
			</header>

			<nav class="tbp-tabs" role="tablist">
				<button type="button" class="tbp-tab is-active" data-tab="markets">Рынки</button>
				<button type="button" class="tbp-tab" data-tab="finam">Finam</button>
				<button type="button" class="tbp-tab" data-tab="paper">Paper</button>
				<button type="button" class="tbp-tab" data-tab="backtest">Бэктест</button>
				<button type="button" class="tbp-tab" data-tab="news">Новости</button>
				<button type="button" class="tbp-tab" data-tab="quality">Качество</button>
				<button type="button" class="tbp-tab" data-tab="log">Лог</button>
			</nav>

			<div class="tbp-workspace">
				<section class="tbp-pane is-active" data-pane="markets">
					<div class="tbp-toolbar">
						<div class="tbp-filters">
							<button type="button" class="tbp-chip is-active" data-filter="all">Все</button>
							<button type="button" class="tbp-chip" data-filter="BUY">BUY</button>
							<button type="button" class="tbp-chip" data-filter="SELL">SELL</button>
							<button type="button" class="tbp-chip" data-filter="HOLD">HOLD</button>
							<button type="button" class="tbp-chip" data-filter="bybit">Bybit</button>
							<button type="button" class="tbp-chip" data-filter="finam">Finam</button>
						</div>
						<input type="search" id="tbp-search" class="tbp-search" placeholder="Поиск символа…">
					</div>
					<div class="tbp-split">
						<div class="tbp-table-wrap">
							<table class="tbp-table" id="tbp-markets-table">
								<thead>
									<tr>
										<th>Symbol</th>
										<th>Action</th>
										<th>Conf</th>
										<th>Price</th>
										<th>24h</th>
										<th>Regime</th>
										<th>Scalp</th>
										<th>Provider</th>
									</tr>
								</thead>
								<tbody id="tbp-markets-body"><tr><td colspan="8">loading…</td></tr></tbody>
							</table>
						</div>
						<aside class="tbp-detail" id="tbp-detail">
							<div class="tbp-detail-empty">Выберите инструмент в таблице</div>
						</aside>
					</div>
				</section>

				<section class="tbp-pane" data-pane="finam">
					<div id="tbp-finam" class="tbp-scroll">loading…</div>
				</section>
				<section class="tbp-pane" data-pane="paper">
					<div id="tbp-paper" class="tbp-scroll">loading…</div>
				</section>
				<section class="tbp-pane" data-pane="backtest">
					<div id="tbp-backtest" class="tbp-scroll">loading…</div>
				</section>
				<section class="tbp-pane" data-pane="news">
					<div id="tbp-news" class="tbp-scroll">loading…</div>
				</section>
				<section class="tbp-pane" data-pane="quality">
					<div id="tbp-quality" class="tbp-scroll">loading…</div>
				</section>
				<section class="tbp-pane" data-pane="log">
					<pre id="tbp-log" class="tbp-log"></pre>
				</section>
			</div>
		</div>

		<style>
			#wpcontent { padding-left: 0 !important; }
			.trading-brain-panel.tbp-terminal {
				margin: 0 0 0 -20px;
				background: #0f1419;
				color: #e7ecf3;
				min-height: calc(100vh - 32px);
				font-family: "Segoe UI", "IBM Plex Sans", system-ui, sans-serif;
			}
			.tbp-terminal .tbp-topbar {
				position: sticky;
				top: 32px;
				z-index: 20;
				display: grid;
				grid-template-columns: minmax(180px, 220px) 1fr auto;
				gap: 12px;
				align-items: center;
				padding: 10px 16px;
				background: #121821;
				border-bottom: 1px solid #243041;
			}
			.tbp-brand { display: flex; gap: 10px; align-items: center; }
			.tbp-logo {
				width: 36px; height: 36px; border-radius: 8px;
				display: grid; place-items: center;
				background: linear-gradient(135deg, #1f6feb, #2ea043);
				font-weight: 700; color: #fff;
			}
			.tbp-sub { color: #8b9bb4; font-size: 12px; }
			.tbp-kpis { display: flex; flex-wrap: wrap; gap: 8px; }
			.tbp-kpi {
				background: #1a2330;
				border: 1px solid #2a384c;
				border-radius: 8px;
				padding: 6px 10px;
				min-width: 90px;
			}
			.tbp-kpi span { display: block; color: #8b9bb4; font-size: 11px; text-transform: uppercase; }
			.tbp-kpi strong { font-size: 14px; }
			.tbp-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
			.tbp-actions .button { margin: 0; }
			.tbp-autoref { color: #8b9bb4; font-size: 12px; display: flex; gap: 4px; align-items: center; }
			.tbp-tabs {
				display: flex; gap: 4px; padding: 8px 16px 0;
				background: #121821; border-bottom: 1px solid #243041;
				position: sticky; top: 90px; z-index: 19;
			}
			.tbp-tab {
				background: transparent; border: 0; color: #8b9bb4;
				padding: 10px 14px; cursor: pointer; border-bottom: 2px solid transparent;
				font-weight: 600;
			}
			.tbp-tab.is-active { color: #fff; border-bottom-color: #1f6feb; }
			.tbp-workspace { padding: 12px 16px 20px; height: calc(100vh - 170px); }
			.tbp-pane { display: none; height: 100%; }
			.tbp-pane.is-active { display: block; }
			.tbp-toolbar { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 10px; flex-wrap: wrap; }
			.tbp-filters { display: flex; gap: 6px; flex-wrap: wrap; }
			.tbp-chip {
				border: 1px solid #2a384c; background: #1a2330; color: #c9d4e3;
				border-radius: 999px; padding: 4px 10px; cursor: pointer; font-size: 12px;
			}
			.tbp-chip.is-active { background: #1f6feb; border-color: #1f6feb; color: #fff; }
			.tbp-search {
				background: #1a2330; border: 1px solid #2a384c; color: #fff;
				border-radius: 8px; padding: 6px 10px; min-width: 180px;
			}
			.tbp-split {
				display: grid;
				grid-template-columns: minmax(0, 1.4fr) minmax(280px, 0.9fr);
				gap: 12px;
				height: calc(100% - 42px);
			}
			.tbp-table-wrap, .tbp-detail, .tbp-scroll {
				background: #151c27;
				border: 1px solid #243041;
				border-radius: 10px;
				overflow: auto;
				height: 100%;
			}
			.tbp-table { width: 100%; border-collapse: collapse; font-size: 13px; }
			.tbp-table th {
				position: sticky; top: 0; background: #1a2330; color: #8b9bb4;
				text-align: left; padding: 10px 12px; border-bottom: 1px solid #243041;
				font-size: 11px; text-transform: uppercase; letter-spacing: .04em;
			}
			.tbp-table td { padding: 10px 12px; border-bottom: 1px solid #1e2836; cursor: pointer; white-space: nowrap; }
			.tbp-table tr:hover td { background: #1a2330; }
			.tbp-table tr.is-selected td { background: #1e2d45; }
			.tbp-badge {
				display: inline-block; min-width: 52px; text-align: center;
				border-radius: 6px; padding: 2px 8px; font-weight: 700; font-size: 12px;
			}
			.tbp-badge-buy { background: rgba(46,160,67,.2); color: #3fb950; }
			.tbp-badge-sell { background: rgba(248,81,73,.2); color: #f85149; }
			.tbp-badge-hold, .tbp-badge-wait { background: rgba(210,153,34,.18); color: #d29922; }
			.tbp-pos { color: #3fb950; }
			.tbp-neg { color: #f85149; }
			.tbp-detail { padding: 14px; }
			.tbp-detail-empty { color: #8b9bb4; padding: 24px 8px; text-align: center; }
			.tbp-detail h3 { margin: 0 0 8px; font-size: 18px; }
			.tbp-detail .tbp-meta { color: #8b9bb4; font-size: 12px; margin-bottom: 12px; }
			.tbp-detail p { margin: 0 0 8px; line-height: 1.45; font-size: 13px; }
			.tbp-muted { color: #8b9bb4; }
			.tbp-scroll { padding: 14px; }
			.tbp-log {
				background: #0b0f14; color: #9fefc0; margin: 0; height: 100%;
				border-radius: 10px; padding: 12px; overflow: auto; font-size: 12px;
			}
			.tbp-account {
				border: 1px solid #243041; border-radius: 8px; padding: 12px; margin-bottom: 10px; background: #1a2330;
			}
			@media (max-width: 1100px) {
				.tbp-terminal .tbp-topbar { grid-template-columns: 1fr; top: 46px; }
				.tbp-tabs { top: auto; position: relative; overflow-x: auto; }
				.tbp-split { grid-template-columns: 1fr; height: auto; }
				.tbp-table-wrap { max-height: 45vh; }
				.tbp-detail { min-height: 280px; }
				.tbp-workspace { height: auto; }
			}
		</style>

		<script>
			(function () {
				const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				const nonce = <?php echo wp_json_encode( $nonce ); ?>;
				const logEl = document.getElementById('tbp-log');
				let state = { decisions: [], selected: null, filter: 'all', query: '' };
				let timer = null;

				function escapeHtml(value) {
					return String(value ?? '').replace(/[&<>"']/g, (char) => ({
						'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
					})[char]);
				}

				function writeLog(message) {
					logEl.textContent = new Date().toISOString() + ' ' + message + '\n' + logEl.textContent;
				}

				function request(action, extra) {
					const body = new URLSearchParams(Object.assign({
						action: action,
						_ajax_nonce: nonce
					}, extra || {}));
					return fetch(ajaxUrl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: body.toString()
					}).then((response) => response.json()).then((json) => {
						if (!json.success) {
							throw new Error((json.data && json.data.message) || 'Request failed');
						}
						return json.data;
					});
				}

				function pctClass(value) {
					const n = Number(value);
					if (!Number.isFinite(n) || n === 0) return '';
					return n > 0 ? 'tbp-pos' : 'tbp-neg';
				}

				function switchTab(name) {
					document.querySelectorAll('.tbp-tab').forEach((el) => el.classList.toggle('is-active', el.dataset.tab === name));
					document.querySelectorAll('.tbp-pane').forEach((el) => el.classList.toggle('is-active', el.dataset.pane === name));
				}

				function filteredDecisions() {
					return state.decisions.filter((item) => {
						const action = item.finalAction || 'WAIT';
						const provider = (item.market && item.market.provider) || 'bybit';
						const symbol = String(item.symbol || '').toUpperCase();
						if (state.query && !symbol.includes(state.query.toUpperCase())) return false;
						if (state.filter === 'all') return true;
						if (state.filter === 'bybit' || state.filter === 'finam') return provider === state.filter;
						return action === state.filter;
					});
				}

				function renderMarkets() {
					const rows = filteredDecisions();
					const body = document.getElementById('tbp-markets-body');
					if (!rows.length) {
						body.innerHTML = '<tr><td colspan="8">Нет строк по фильтру</td></tr>';
						return;
					}
					body.innerHTML = rows.map((item) => {
						const action = item.finalAction || 'WAIT';
						const market = item.market || {};
						const consensus = item.consensus || item.signal || {};
						const regime = (item.signal && item.signal.regime) || {};
						const scalp = item.scalpSignal || {};
						const provider = market.provider || 'bybit';
						const selected = state.selected === item.symbol ? ' is-selected' : '';
						return '<tr class="' + selected + '" data-symbol="' + escapeHtml(item.symbol) + '">' +
							'<td><strong>' + escapeHtml(item.symbol) + '</strong><div class="tbp-muted">' + escapeHtml(market.assetClass || '') + '</div></td>' +
							'<td><span class="tbp-badge tbp-badge-' + escapeHtml(action.toLowerCase()) + '">' + escapeHtml(action) + '</span></td>' +
							'<td>' + escapeHtml(consensus.confidence ?? '—') + '</td>' +
							'<td>' + escapeHtml(market.lastPrice ?? '—') + '</td>' +
							'<td class="' + pctClass(market.change24hPct) + '">' + escapeHtml(market.change24hPct ?? '—') + '%</td>' +
							'<td>' + escapeHtml(regime.regime || '—') + '</td>' +
							'<td>' + escapeHtml(scalp.horizon === 'long_only' ? 'long' : (scalp.action || '—')) + '</td>' +
							'<td>' + escapeHtml(provider) + '</td>' +
						'</tr>';
					}).join('');

					body.querySelectorAll('tr[data-symbol]').forEach((row) => {
						row.addEventListener('click', () => {
							state.selected = row.getAttribute('data-symbol');
							renderMarkets();
							renderDetail();
						});
					});

					if (!state.selected && rows[0]) {
						state.selected = rows[0].symbol;
						renderDetail();
						renderMarkets();
					} else if (state.selected) {
						renderDetail();
					}
				}

				function renderDetail() {
					const item = state.decisions.find((d) => d.symbol === state.selected);
					const box = document.getElementById('tbp-detail');
					if (!item) {
						box.innerHTML = '<div class="tbp-detail-empty">Выберите инструмент в таблице</div>';
						return;
					}
					const action = item.finalAction || 'WAIT';
					const signal = item.signal || {};
					const consensus = item.consensus || signal;
					const ai = item.aiAnalyst || {};
					const algoVault = item.algoVaultAnalyst || {};
					const cursor = item.cursorAnalyst || {};
					const risk = item.risk || {};
					const market = item.market || {};
					const indicators = market.indicators || {};
					const regime = signal.regime || {};
					const scalp = item.scalpSignal || {};
					const orderBook = market.orderBook || {};
					const derivatives = market.derivatives || {};
					const account = market.preferredAccount || {};

					box.innerHTML =
						'<h3>' + escapeHtml(item.symbol) + ' <span class="tbp-badge tbp-badge-' + escapeHtml(action.toLowerCase()) + '">' + escapeHtml(action) + '</span></h3>' +
						'<div class="tbp-meta">' + escapeHtml(market.assetClass || '') + ' / ' + escapeHtml(market.provider || market.category || '') +
						(account.tradeCode ? ' · счёт ' + escapeHtml(account.tradeCode) + ' (' + escapeHtml(account.roleLabel || account.role || '') + ')' : '') + '</div>' +
						'<p><strong>Conf</strong> ' + escapeHtml(consensus.confidence) + ' · <strong>Risk</strong> ' + escapeHtml(risk.riskScore) +
						(risk.effectiveMinConfidence ? ' · min ' + escapeHtml(risk.effectiveMinConfidence) : '') + '</p>' +
						'<p><strong>Price</strong> ' + escapeHtml(market.lastPrice) + ' · <strong>24h</strong> <span class="' + pctClass(market.change24hPct) + '">' + escapeHtml(market.change24hPct) + '%</span></p>' +
						'<p><strong>RSI</strong> ' + escapeHtml(indicators.rsi14) + ' · <strong>ADX</strong> ' + escapeHtml(indicators.adx14) + ' · <strong>SMA</strong> ' + escapeHtml(indicators.sma20) + '/' + escapeHtml(indicators.sma50) + '</p>' +
						(regime.regime ? '<p><strong>Regime</strong> ' + escapeHtml(regime.regime) + ' / ' + escapeHtml(regime.preferredStrategy || '') + '</p>' : '') +
						(scalp.enabled ? '<p><strong>Scalp</strong> ' + escapeHtml(scalp.action || 'WAIT') + ' / ' + escapeHtml(scalp.confidence) + ' · ' + escapeHtml(scalp.horizon || '') + '</p>' : '') +
						(orderBook.available ? '<p><strong>Book</strong> spread ' + escapeHtml(orderBook.spreadPct) + '% · imb ' + escapeHtml(orderBook.imbalance) + ' · ' + escapeHtml(orderBook.pressure) + '</p>' : '') +
						(derivatives.available ? '<p><strong>Deriv</strong> fund ' + escapeHtml(derivatives.fundingRatePct) + '% · OIΔ ' + escapeHtml(derivatives.openInterestChangePct) + '%</p>' : '') +
						'<p><strong>AI</strong> ' + escapeHtml(ai.status || '—') + (ai.action ? ' → ' + escapeHtml(ai.action) + '/' + escapeHtml(ai.confidence) : '') + '</p>' +
						(ai.reasoning ? '<p class="tbp-muted">' + escapeHtml(ai.reasoning) + '</p>' : '') +
						'<p><strong>Cursor</strong> ' + escapeHtml(cursor.status || '—') + (cursor.action ? ' → ' + escapeHtml(cursor.action) + '/' + escapeHtml(cursor.confidence) : '') + '</p>' +
						'<p><strong>AlgoVault</strong> ' + escapeHtml(algoVault.status || '—') + '</p>' +
						'<p class="tbp-muted">' + escapeHtml((consensus.reasons || signal.reasons || []).slice(0, 8).join(' · ')) + '</p>' +
						(risk.blocks && risk.blocks.length ? '<p><strong>Blocks</strong> ' + escapeHtml(risk.blocks.join(', ')) + '</p>' : '');
				}

				function render(data) {
					const latest = data.latest || {};
					state.decisions = latest.decisions || [];
					document.getElementById('tbp-service-status').textContent = data.service_status || 'unknown';
					document.getElementById('tbp-dry-run').textContent = typeof latest.dryRun !== 'undefined' ? String(latest.dryRun) : '—';
					document.getElementById('tbp-updated').textContent = latest.timestamp ? ('Updated ' + latest.timestamp) : 'нет данных';

					const paper = data.paper || latest.paper || {};
					const paperStats = paper.stats || {};
					document.getElementById('tbp-kpi-paper').textContent =
						(paper.totalPnlUsd ?? 0) + ' (' + (paper.totalPnlPct ?? 0) + '%)';
					document.getElementById('tbp-kpi-paper').className = pctClass(paper.totalPnlUsd);

					const finam = data.finam || latest.finam || {};
					const longAcc = (finam.accounts || []).find((a) => a.role === 'long') || (finam.accounts || [])[0] || {};
					document.getElementById('tbp-kpi-finam').textContent = longAcc.equity != null ? (longAcc.equity + ' ₽') : ((finam.trading && finam.trading.enabled) ? 'on' : 'off');

					const counts = { BUY: 0, SELL: 0, HOLD: 0, WAIT: 0 };
					state.decisions.forEach((d) => { counts[d.finalAction || 'WAIT'] = (counts[d.finalAction || 'WAIT'] || 0) + 1; });
					document.getElementById('tbp-kpi-signals').textContent = 'B' + counts.BUY + ' / S' + counts.SELL + ' / H' + counts.HOLD;

					renderMarkets();

					const positions = paper.positions || {};
					document.getElementById('tbp-paper').innerHTML =
						'<div class="tbp-account">' +
						'<p><strong>Equity</strong> ' + escapeHtml(paper.equityUsd ?? 0) + ' USDT · <strong>PnL</strong> <span class="' + pctClass(paper.totalPnlUsd) + '">' + escapeHtml(paper.totalPnlUsd ?? 0) + ' (' + escapeHtml(paper.totalPnlPct ?? 0) + '%)</span></p>' +
						'<p><strong>Cash</strong> ' + escapeHtml(paper.cashUsd ?? 0) + ' · opened ' + escapeHtml(paperStats.opened ?? 0) + ' / closed ' + escapeHtml(paperStats.closed ?? 0) + ' · WR ' + escapeHtml(paperStats.winRatePct ?? 0) + '%</p>' +
						(Object.keys(positions).length ? '<ul>' + Object.keys(positions).map((symbol) => {
							const pos = positions[symbol] || {};
							return '<li>' + escapeHtml(symbol) + ': qty ' + escapeHtml(pos.qty) + ', entry ' + escapeHtml(pos.entryPrice) + ', uPnL ' + escapeHtml(pos.unrealizedPnlUsd) + '</li>';
						}).join('') + '</ul>' : '<p class="tbp-muted">Нет открытых paper-позиций</p>') +
						'</div>';

					const backtest = data.backtest || {};
					const backtestRuns = backtest.runs || {};
					document.getElementById('tbp-backtest').innerHTML =
						'<p><strong>Updated</strong> ' + escapeHtml(backtest.updatedAt || '—') +
						' · <strong>Mode</strong> ' + escapeHtml(backtest.mode || '—') + '</p>' +
						(backtest.note ? '<p class="tbp-muted">' + escapeHtml(backtest.note) + '</p>' : '') +
						(Object.keys(backtestRuns).length ? Object.keys(backtestRuns).map((runName) => {
							const run = backtestRuns[runName] || {};
							const portfolio = run.portfolio || {};
							const symbolsMap = run.symbols || {};
							return '<div class="tbp-account">' +
								'<p><strong>' + escapeHtml(runName) + '</strong> · trades ' + escapeHtml(portfolio.totalTrades ?? 0) +
								' · WR ' + escapeHtml(portfolio.winRatePct ?? 0) + '%' +
								' · avg PnL ' + escapeHtml(portfolio.avgTotalPnlPct ?? 0) + '%' +
								' · max DD ' + escapeHtml(portfolio.worstMaxDrawdownPct ?? 0) + '%</p>' +
								Object.keys(symbolsMap).map((symbol) => {
									const item = symbolsMap[symbol] || {};
									const paperBt = item.paper || {};
									const q15 = (item.signalQuality && item.signalQuality['15m']) || {};
									return '<p><strong>' + escapeHtml(symbol) + '</strong>: PnL ' + escapeHtml(paperBt.totalPnlUsd ?? 0) +
										' (' + escapeHtml(paperBt.totalPnlPct ?? 0) + '%) · trades ' + escapeHtml(paperBt.trades ?? 0) +
										' · WR ' + escapeHtml(paperBt.winRatePct ?? 0) + '%' +
										' · hit15m ' + escapeHtml(q15.hitRatePct ?? 0) + '%' +
										' · bars ' + escapeHtml(item.evaluatedBars ?? item.bars ?? 0) + '</p>';
								}).join('') +
							'</div>';
						}).join('') : '<p class="tbp-muted">Бэктест ещё не запускался. На VPS: <code>npm run brain:backtest</code></p>');

					const finamAccounts = finam.accounts || [];
					document.getElementById('tbp-finam').innerHTML =
						'<p><strong>Trading</strong> ' + escapeHtml((finam.trading && finam.trading.enabled) ?? false) +
						(finam.trading && finam.trading.stats ? ' · submitted ' + escapeHtml(finam.trading.stats.submitted ?? 0) + ', bought ' + escapeHtml(finam.trading.stats.bought ?? 0) + ', sold ' + escapeHtml(finam.trading.stats.sold ?? 0) + ', skipped ' + escapeHtml(finam.trading.stats.skipped ?? 0) : '') + '</p>' +
						(finam.trading && finam.trading.events && finam.trading.events.length ? '<ul>' + finam.trading.events.slice(0, 10).map((ev) =>
							'<li>' + escapeHtml(ev.type) + ' ' + escapeHtml(ev.symbol) + (ev.reason ? ' — ' + escapeHtml(ev.reason) : '') + (ev.qty ? ' qty ' + escapeHtml(ev.qty) : '') + '</li>'
						).join('') + '</ul>' : '') +
						(finamAccounts.length ? finamAccounts.map((acc) => {
							const cash = (acc.cash || []).map((c) => escapeHtml(c.amount) + ' ' + escapeHtml(c.currency)).join(', ');
							const pos = (acc.positions || []).filter((p) => Number(p.qty) > 0);
							return '<div class="tbp-account">' +
								'<p><strong>' + escapeHtml(acc.tradeCode || acc.accountId) + '</strong> · ' + escapeHtml(acc.roleLabel || acc.role || '') + ' · ' + escapeHtml(acc.status || acc.error || '') + '</p>' +
								'<p>Equity ' + escapeHtml(acc.equity ?? '—') + ' · Cash ' + (cash || '—') + ' · uPnL ' + escapeHtml(acc.unrealizedProfit ?? 0) + '</p>' +
								(pos.length ? '<ul>' + pos.map((p) => '<li>' + escapeHtml(p.symbol) + ': ' + escapeHtml(p.qty) + ' @ ' + escapeHtml(p.averagePrice) + ' → ' + escapeHtml(p.currentPrice) + ' (uPnL ' + escapeHtml(p.unrealizedPnl) + ')</li>').join('') + '</ul>' : '<p class="tbp-muted">Позиций нет</p>') +
							'</div>';
						}).join('') : '<p class="tbp-muted">Нет данных Finam</p>');

					const decisions = state.decisions;
					const firstNews = decisions[0] && decisions[0].news ? decisions[0].news : {};
					const firstFearGreed = decisions[0] && decisions[0].fearGreed ? decisions[0].fearGreed : {};
					const topNews = firstNews.top || [];
					document.getElementById('tbp-news').innerHTML =
						'<p><strong>Fear & Greed:</strong> ' + (firstFearGreed.available ? escapeHtml(firstFearGreed.value) + ' (' + escapeHtml(firstFearGreed.classification) + ')' : 'unavailable') + '</p>' +
						'<p><strong>News score:</strong> ' + escapeHtml(firstNews.score ?? 0) + '</p>' +
						(topNews.length ? '<ul>' + topNews.map((item) =>
							'<li><a href="' + escapeHtml(item.link) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(item.title) + '</a> <span class="tbp-muted">' + escapeHtml(item.source || '') + '</span></li>'
						).join('') + '</ul>' : '<p class="tbp-muted">Новостей нет</p>');

					const quality = data.quality || latest.quality || {};
					const qualitySymbols = quality.symbols || {};
					document.getElementById('tbp-quality').innerHTML = Object.keys(qualitySymbols).length ? Object.keys(qualitySymbols).map((symbol) => {
						const item = qualitySymbols[symbol] || {};
						const horizons = item.horizons || {};
						return '<div class="tbp-account"><strong>' + escapeHtml(symbol) + '</strong>' +
							'<table class="tbp-table"><thead><tr><th>TF</th><th>Hit%</th><th>Hits</th><th>Edge</th></tr></thead><tbody>' +
							['15m', '1h', '4h'].map((label) => {
								const h = horizons[label] || {};
								return '<tr><td>' + escapeHtml(label) + '</td><td>' + escapeHtml(h.hitRatePct ?? 0) + '%</td><td>' + escapeHtml(h.hits ?? 0) + '/' + escapeHtml(h.evaluated ?? 0) + '</td><td>' + escapeHtml(h.avgEdgePct ?? 0) + '%</td></tr>';
							}).join('') + '</tbody></table></div>';
					}).join('') : '<p class="tbp-muted">Качество ещё не рассчитано</p>';
				}

				function refresh() {
					writeLog('refresh');
					return request('trading_brain_panel_status').then(render).catch((error) => writeLog(error.message));
				}

				function setupAutoRefresh() {
					if (timer) clearInterval(timer);
					const enabled = document.getElementById('tbp-autorefresh').checked;
					if (enabled) {
						timer = setInterval(refresh, 30000);
					}
				}

				document.querySelectorAll('.tbp-tab').forEach((tab) => {
					tab.addEventListener('click', () => switchTab(tab.dataset.tab));
				});
				document.querySelectorAll('.tbp-chip').forEach((chip) => {
					chip.addEventListener('click', () => {
						document.querySelectorAll('.tbp-chip').forEach((el) => el.classList.remove('is-active'));
						chip.classList.add('is-active');
						state.filter = chip.dataset.filter;
						renderMarkets();
					});
				});
				document.getElementById('tbp-search').addEventListener('input', (event) => {
					state.query = event.target.value || '';
					renderMarkets();
				});
				document.getElementById('tbp-autorefresh').addEventListener('change', setupAutoRefresh);

				document.querySelectorAll('[data-tbp-action]').forEach((button) => {
					button.addEventListener('click', function () {
						const action = button.getAttribute('data-tbp-action');
						if (action === 'refresh') {
							refresh();
							return;
						}
						if (!window.confirm('Выполнить действие: ' + action + '?')) return;
						writeLog(action);
						request('trading_brain_panel_control', { service_action: action })
							.then(render)
							.catch((error) => writeLog(error.message));
					});
				});

				refresh();
				setupAutoRefresh();
			})();
		</script>
		<?php
	}

	public static function ajax_status(): void {
		self::assert_admin_ajax();
		wp_send_json_success( self::get_panel_data() );
	}

	public static function ajax_control(): void {
		self::assert_admin_ajax();

		$action = isset( $_POST['service_action'] ) ? sanitize_key( wp_unslash( $_POST['service_action'] ) ) : '';
		if ( ! in_array( $action, array( 'start', 'stop', 'restart' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid service action.' ), 400 );
		}

		$result = self::run_helper( $action );
		if ( 0 !== $result['code'] ) {
			wp_send_json_error( array( 'message' => 'Service action failed: ' . $result['output'] ), 500 );
		}

		wp_send_json_success( self::get_panel_data() );
	}

	private static function assert_admin_ajax(): void {
		check_ajax_referer( self::NONCE_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Access denied.' ), 403 );
		}
	}

	private static function get_panel_data(): array {
		$status = self::run_helper( 'status' );
		$latest = self::run_helper( 'latest' );
		$paper = self::run_helper( 'paper' );
		$quality = self::run_helper( 'quality' );
		$backtest = self::run_helper( 'backtest' );
		$decoded_latest = array();
		$decoded_paper = array();
		$decoded_quality = array();
		$decoded_backtest = array();

		if ( 0 === $latest['code'] && '' !== $latest['output'] ) {
			$decoded = json_decode( $latest['output'], true );
			if ( is_array( $decoded ) ) {
				$decoded_latest = $decoded;
			}
		}
		if ( 0 === $paper['code'] && '' !== $paper['output'] ) {
			$decoded = json_decode( $paper['output'], true );
			if ( is_array( $decoded ) ) {
				$decoded_paper = $decoded;
			}
		}
		if ( 0 === $quality['code'] && '' !== $quality['output'] ) {
			$decoded = json_decode( $quality['output'], true );
			if ( is_array( $decoded ) ) {
				$decoded_quality = $decoded;
			}
		}
		if ( 0 === $backtest['code'] && '' !== $backtest['output'] ) {
			$decoded = json_decode( $backtest['output'], true );
			if ( is_array( $decoded ) ) {
				$decoded_backtest = $decoded;
			}
		}

		return array(
			'service_status' => trim( $status['output'] ) ?: 'unknown',
			'latest'         => $decoded_latest,
			'paper'          => $decoded_paper,
			'quality'        => $decoded_quality,
			'backtest'       => $decoded_backtest,
			'finam'          => isset( $decoded_latest['finam'] ) && is_array( $decoded_latest['finam'] ) ? $decoded_latest['finam'] : array(),
		);
	}

	private static function run_helper( string $command ): array {
		$allowed = array( 'latest', 'status', 'paper', 'quality', 'backtest', 'start', 'stop', 'restart' );
		if ( ! in_array( $command, $allowed, true ) ) {
			return array(
				'code'   => 1,
				'output' => 'Command is not allowed.',
			);
		}

		$cmd = 'sudo ' . escapeshellarg( self::HELPER ) . ' ' . escapeshellarg( $command ) . ' 2>&1';
		$output = array();
		$code = 0;
		exec( $cmd, $output, $code );

		return array(
			'code'   => (int) $code,
			'output' => trim( implode( "\n", $output ) ),
		);
	}
}

Trading_Brain_Panel::init();
