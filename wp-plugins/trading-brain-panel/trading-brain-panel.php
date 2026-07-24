<?php
/**
 * Plugin Name: Trading Brain Panel
 * Description: Admin-only control panel for the read-only hybrid trading brain service.
 * Version: 0.1.0
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
		<div class="wrap trading-brain-panel">
			<h1>Trading Brain</h1>
			<p>Панель только для управления и просмотра. Bybit-ключи и торговая логика находятся вне WordPress.</p>

			<div class="tbp-grid">
				<div class="tbp-card">
					<h2>Сервис</h2>
					<p><strong>Статус:</strong> <span id="tbp-service-status">loading...</span></p>
					<p><strong>Dry-run:</strong> <span id="tbp-dry-run">loading...</span></p>
					<p><strong>Обновлено:</strong> <span id="tbp-updated">loading...</span></p>
					<div class="tbp-actions">
						<button class="button button-primary" data-tbp-action="refresh">Обновить</button>
						<button class="button" data-tbp-action="restart">Перезапустить мозг</button>
						<button class="button" data-tbp-action="start">Включить</button>
						<button class="button" data-tbp-action="stop">Выключить</button>
					</div>
				</div>

				<div class="tbp-card">
					<h2>Сигналы</h2>
					<div id="tbp-decisions">loading...</div>
				</div>

				<div class="tbp-card">
					<h2>Paper trading</h2>
					<div id="tbp-paper">loading...</div>
				</div>

				<div class="tbp-card">
					<h2>Finam</h2>
					<div id="tbp-finam">loading...</div>
				</div>

				<div class="tbp-card tbp-wide">
					<h2>Новости и причины</h2>
					<div id="tbp-news">loading...</div>
				</div>

				<div class="tbp-card tbp-wide">
					<h2>Качество сигналов</h2>
					<div id="tbp-quality">loading...</div>
				</div>
			</div>

			<pre id="tbp-log" class="tbp-log"></pre>
		</div>

		<style>
			.trading-brain-panel .tbp-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
				gap: 16px;
				margin-top: 16px;
			}
			.trading-brain-panel .tbp-card {
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 8px;
				padding: 16px;
			}
			.trading-brain-panel .tbp-wide {
				grid-column: 1 / -1;
			}
			.trading-brain-panel .tbp-actions {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				margin-top: 12px;
			}
			.trading-brain-panel .tbp-signal {
				border-left: 4px solid #8c8f94;
				padding: 10px 12px;
				margin: 10px 0;
				background: #f6f7f7;
			}
			.trading-brain-panel .tbp-signal-buy {
				border-left-color: #00a32a;
			}
			.trading-brain-panel .tbp-signal-sell {
				border-left-color: #d63638;
			}
			.trading-brain-panel .tbp-signal-wait,
			.trading-brain-panel .tbp-signal-hold {
				border-left-color: #dba617;
			}
			.trading-brain-panel .tbp-muted {
				color: #646970;
			}
			.trading-brain-panel .tbp-log {
				background: #1d2327;
				color: #f0f0f1;
				padding: 12px;
				border-radius: 6px;
				max-height: 240px;
				overflow: auto;
			}
		</style>

		<script>
			(function () {
				const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				const nonce = <?php echo wp_json_encode( $nonce ); ?>;
				const log = document.getElementById('tbp-log');

				function escapeHtml(value) {
					return String(value ?? '').replace(/[&<>"']/g, function (char) {
						return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
					});
				}

				function writeLog(message) {
					log.textContent = new Date().toISOString() + ' ' + message + "\n" + log.textContent;
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

				function render(data) {
					const latest = data.latest || {};
					document.getElementById('tbp-service-status').textContent = data.service_status || 'unknown';
					document.getElementById('tbp-dry-run').textContent = typeof latest.dryRun !== 'undefined' ? String(latest.dryRun) : 'нет данных';
					document.getElementById('tbp-updated').textContent = latest.timestamp ? latest.timestamp : 'нет данных';

					const decisions = latest.decisions || [];
					document.getElementById('tbp-decisions').innerHTML = decisions.length ? decisions.map((item) => {
						const action = item.finalAction || 'WAIT';
						const signal = item.signal || {};
						const consensus = item.consensus || signal;
						const ai = item.aiAnalyst || {};
						const algoVault = item.algoVaultAnalyst || {};
						const cursor = item.cursorAnalyst || {};
						const risk = item.risk || {};
						const market = item.market || {};
						const indicators = market.indicators || {};
						const regime = (item.signal && item.signal.regime) || {};
						const components = (item.signal && item.signal.components) || {};
						const qualityFeedback = item.qualityFeedback || (item.signal && item.signal.qualityFeedback) || {};
						const orderBook = market.orderBook || {};
						const derivatives = market.derivatives || {};
						return '<div class="tbp-signal tbp-signal-' + escapeHtml(action.toLowerCase()) + '">' +
							'<h3>' + escapeHtml(item.symbol) + (market.assetClass ? ' <span class="tbp-muted">(' + escapeHtml(market.assetClass) + (market.provider ? '/' + escapeHtml(market.provider) : (market.category ? '/' + escapeHtml(market.category) : '')) + ')</span>' : '') + ' - ' + escapeHtml(action) + '</h3>' +
							'<p><strong>Consensus:</strong> ' + escapeHtml(consensus.source || 'rules') + ' | <strong>Confidence:</strong> ' + escapeHtml(consensus.confidence) + ' | <strong>Risk:</strong> ' + escapeHtml(risk.riskScore) + (risk.effectiveMinConfidence ? ' | <strong>Min conf:</strong> ' + escapeHtml(risk.effectiveMinConfidence) : '') + '</p>' +
							(regime.regime ? '<p><strong>Regime:</strong> ' + escapeHtml(regime.regime) + ' / ' + escapeHtml(regime.preferredStrategy || '') + ' | ADX ' + escapeHtml(regime.adx ?? indicators.adx14 ?? '') + ' | ' + escapeHtml((regime.reasons || []).join(', ')) + '</p>' : '') +
							(Object.keys(components).length ? '<p><strong>Ensemble:</strong> ' + escapeHtml(Object.keys(components).map((name) => name + ' ' + escapeHtml((components[name] && components[name].weightedScore) ?? 0)).join(' | ')) + '</p>' : '') +
							(qualityFeedback.reason && qualityFeedback.reason !== 'no data' ? '<p class="tbp-muted"><strong>Quality feedback:</strong> ' + escapeHtml(qualityFeedback.reason) + (qualityFeedback.hitRatePct !== undefined ? ' | hit-rate ' + escapeHtml(qualityFeedback.hitRatePct) + '%' : '') + '</p>' : '') +
							'<p><strong>Price:</strong> ' + escapeHtml(market.lastPrice) + ' | <strong>24h:</strong> ' + escapeHtml(market.change24hPct) + '%</p>' +
							'<p><strong>RSI:</strong> ' + escapeHtml(indicators.rsi14) + ' | <strong>SMA20/SMA50:</strong> ' + escapeHtml(indicators.sma20) + ' / ' + escapeHtml(indicators.sma50) + ' | <strong>ADX:</strong> ' + escapeHtml(indicators.adx14) + '</p>' +
							'<p><strong>MACD:</strong> ' + escapeHtml(indicators.macdLine) + ' / ' + escapeHtml(indicators.macdSignal) + ' | <strong>Hist:</strong> ' + escapeHtml(indicators.macdHistogram) + ' (' + escapeHtml(indicators.macdHistogramDelta) + ')</p>' +
							(function () {
								const scalp = item.scalpSignal || {};
								if (!scalp.enabled) {
									return '';
								}
								return '<p><strong>Scalp (KB):</strong> ' + escapeHtml(scalp.action || 'WAIT') + ' / ' + escapeHtml(scalp.confidence) + (scalp.horizon ? ' | <strong>horizon:</strong> ' + escapeHtml(scalp.horizon) : '') + ' | 15m trend ' + escapeHtml(scalp.trend15m) + (scalp.setupReady !== undefined ? ' | setup ' + escapeHtml(scalp.setupReady) : '') + ' | TP ' + escapeHtml(scalp.takeProfitPct) + '% SL ' + escapeHtml(scalp.stopLossPct) + '%</p>' +
									(scalp.horizon === 'long_only' ? '<p class="tbp-muted"><strong>Fee gate:</strong> ' + escapeHtml((scalp.feeGate && scalp.feeGate.reason) || 'long/swing only — commission eats short trades') + '</p>' : '') +
									(scalp.pivots ? '<p class="tbp-muted"><strong>Pivots (Shiryaev):</strong> PP ' + escapeHtml(scalp.pivots.pp) + ' S1 ' + escapeHtml(scalp.pivots.s1) + ' R1 ' + escapeHtml(scalp.pivots.r1) + '</p>' : '') +
									(scalp.appliedRules && scalp.appliedRules.length ? '<p class="tbp-muted"><strong>KB rules:</strong> ' + escapeHtml(scalp.appliedRules.join(', ')) + '</p>' : '') +
									(scalp.reasons && scalp.reasons.length ? '<p class="tbp-muted"><strong>Scalp:</strong> ' + escapeHtml(scalp.reasons.slice(0, 5).join(', ')) + '</p>' : '');
							})() +
							'<p><strong>Bollinger:</strong> pos ' + escapeHtml(indicators.bollingerPosition) + ', width ' + escapeHtml(indicators.bollingerWidthPct) + '% | <strong>S/R:</strong> ' + escapeHtml(indicators.support) + ' / ' + escapeHtml(indicators.resistance) + '</p>' +
							(orderBook.available ? '<p><strong>Order book:</strong> spread ' + escapeHtml(orderBook.spreadPct) + '%, imbalance ' + escapeHtml(orderBook.imbalance) + ', pressure ' + escapeHtml(orderBook.pressure) + ' | depth bid/ask ' + escapeHtml(orderBook.bidDepthUsd) + ' / ' + escapeHtml(orderBook.askDepthUsd) + '</p>' +
							'<p class="tbp-muted"><strong>Walls:</strong> bid ' + escapeHtml(orderBook.bidWall && orderBook.bidWall.price) + ' (' + escapeHtml(orderBook.bidWall && orderBook.bidWall.usd) + ' USD) | ask ' + escapeHtml(orderBook.askWall && orderBook.askWall.price) + ' (' + escapeHtml(orderBook.askWall && orderBook.askWall.usd) + ' USD)</p>' : '<p class="tbp-muted"><strong>Order book:</strong> unavailable</p>') +
							(derivatives.available ? '<p><strong>Derivatives:</strong> funding ' + escapeHtml(derivatives.fundingRatePct) + '%, OI change ' + escapeHtml(derivatives.openInterestChangePct) + '%, basis ' + escapeHtml(derivatives.basisPct) + '% | OI value ' + escapeHtml(derivatives.openInterestValue) + '</p>' : '<p class="tbp-muted"><strong>Derivatives:</strong> unavailable</p>') +
							'<p><strong>DeepSeek Analyst:</strong> ' + escapeHtml(ai.status || 'unknown') + ' ' + escapeHtml(ai.model || '') + (ai.action ? ' → ' + escapeHtml(ai.action) + ' / ' + escapeHtml(ai.confidence) : '') + '</p>' +
							(ai.reasoning ? '<p class="tbp-muted"><strong>AI:</strong> ' + escapeHtml(ai.reasoning) + '</p>' : '') +
							'<p><strong>AlgoVault:</strong> ' + escapeHtml(algoVault.status || 'unknown') + ' ' + escapeHtml(algoVault.model || '') + (algoVault.action ? ' → ' + escapeHtml(algoVault.action) + ' / ' + escapeHtml(algoVault.confidence) : '') + (algoVault.regime ? ' | regime ' + escapeHtml(algoVault.regime) : '') + '</p>' +
							(algoVault.reasoning ? '<p class="tbp-muted"><strong>AlgoVault:</strong> ' + escapeHtml(algoVault.reasoning) + '</p>' : '') +
							'<p><strong>Cursor Analyst:</strong> ' + escapeHtml(cursor.status || 'unknown') + ' ' + escapeHtml(cursor.model || '') + (cursor.action ? ' → ' + escapeHtml(cursor.action) + ' / ' + escapeHtml(cursor.confidence) : '') + '</p>' +
							(cursor.reasoning ? '<p class="tbp-muted"><strong>Cursor:</strong> ' + escapeHtml(cursor.reasoning) + '</p>' : '') +
							'<p class="tbp-muted">' + escapeHtml((consensus.reasons || signal.reasons || []).join(', ')) + '</p>' +
							(risk.blocks && risk.blocks.length ? '<p><strong>Блокировки:</strong> ' + escapeHtml(risk.blocks.join(', ')) + '</p>' : '') +
						'</div>';
					}).join('') : '<p>Пока нет решений.</p>';

					const firstNews = decisions[0] && decisions[0].news ? decisions[0].news : {};
					const firstFearGreed = decisions[0] && decisions[0].fearGreed ? decisions[0].fearGreed : {};
					const topNews = firstNews.top || [];
					const sourceCounts = firstNews.sourceCounts || {};
					const sourceErrors = firstNews.sourceErrors || {};
					document.getElementById('tbp-news').innerHTML =
						'<p><strong>Fear & Greed:</strong> ' + (firstFearGreed.available ? escapeHtml(firstFearGreed.value) + ' (' + escapeHtml(firstFearGreed.classification) + ')' : 'unavailable') + '</p>' +
						'<p><strong>News score:</strong> ' + escapeHtml(firstNews.score ?? 0) + ' | <strong>Items:</strong> ' + escapeHtml(firstNews.itemCount ?? 0) + '</p>' +
						(Object.keys(sourceCounts).length ? '<p><strong>Sources:</strong> ' + escapeHtml(JSON.stringify(sourceCounts)) + '</p>' : '') +
						(Object.keys(sourceErrors).length ? '<p><strong>Source errors:</strong> ' + escapeHtml(JSON.stringify(sourceErrors)) + '</p>' : '') +
						(topNews.length ? '<ul>' + topNews.map((item) =>
							'<li><a href="' + escapeHtml(item.link) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(item.title) + '</a> <span class="tbp-muted">' + escapeHtml(item.source || 'source') + ', score ' + escapeHtml(item.score) + '</span></li>'
						).join('') + '</ul>' : '<p>Новостей пока нет.</p>');

					const paper = data.paper || latest.paper || {};
					const paperStats = paper.stats || {};
					const positions = paper.positions || {};
					document.getElementById('tbp-paper').innerHTML =
						'<p><strong>Enabled:</strong> ' + escapeHtml(paper.enabled ?? false) + '</p>' +
						'<p><strong>Equity:</strong> ' + escapeHtml(paper.equityUsd ?? 0) + ' USDT | <strong>PnL:</strong> ' + escapeHtml(paper.totalPnlUsd ?? 0) + ' (' + escapeHtml(paper.totalPnlPct ?? 0) + '%)</p>' +
						'<p><strong>Cash:</strong> ' + escapeHtml(paper.cashUsd ?? 0) + ' | <strong>Open positions:</strong> ' + escapeHtml(paper.openPositionCount ?? 0) + '</p>' +
						'<p><strong>Trades:</strong> opened ' + escapeHtml(paperStats.opened ?? 0) + ', closed ' + escapeHtml(paperStats.closed ?? 0) + ', winrate ' + escapeHtml(paperStats.winRatePct ?? 0) + '%</p>' +
						(Object.keys(positions).length ? '<ul>' + Object.keys(positions).map((symbol) => {
							const pos = positions[symbol] || {};
							return '<li>' + escapeHtml(symbol) + ': qty ' + escapeHtml(pos.qty) + ', entry ' + escapeHtml(pos.entryPrice) + ', uPnL ' + escapeHtml(pos.unrealizedPnlUsd) + '</li>';
						}).join('') + '</ul>' : '<p class="tbp-muted">Открытых paper-позиций нет.</p>');

					const finam = data.finam || latest.finam || {};
					const finamAccounts = finam.accounts || [];
					document.getElementById('tbp-finam').innerHTML =
						'<p><strong>Enabled:</strong> ' + escapeHtml(finam.enabled ?? false) + (finam.error ? ' | <strong>Error:</strong> ' + escapeHtml(finam.error) : '') + '</p>' +
						(finamAccounts.length ? finamAccounts.map((acc) => {
							const cash = (acc.cash || []).map((c) => escapeHtml(c.amount) + ' ' + escapeHtml(c.currency)).join(', ');
							const positions = acc.positions || [];
							return '<div class="tbp-signal">' +
								'<p><strong>' + escapeHtml(acc.tradeCode || acc.accountId) + '</strong> <span class="tbp-muted">(' + escapeHtml(acc.accountId) + ')</span> — ' + escapeHtml(acc.status || acc.error || '') + '</p>' +
								'<p>Equity: ' + escapeHtml(acc.equity ?? '—') + ' | Cash: ' + (cash || '—') + ' | uPnL: ' + escapeHtml(acc.unrealizedProfit ?? 0) + '</p>' +
								(positions.length ? '<ul>' + positions.map((pos) =>
									'<li>' + escapeHtml(pos.symbol) + ': qty ' + escapeHtml(pos.qty) + ', avg ' + escapeHtml(pos.averagePrice) + ', now ' + escapeHtml(pos.currentPrice) + ', uPnL ' + escapeHtml(pos.unrealizedPnl) + '</li>'
								).join('') + '</ul>' : '<p class="tbp-muted">Позиций нет.</p>') +
							'</div>';
						}).join('') : '<p class="tbp-muted">Нет данных по счетам Finam.</p>');

					const quality = data.quality || latest.quality || {};
					const qualitySymbols = quality.symbols || {};
					document.getElementById('tbp-quality').innerHTML = Object.keys(qualitySymbols).length ? Object.keys(qualitySymbols).map((symbol) => {
						const item = qualitySymbols[symbol] || {};
						const horizons = item.horizons || {};
						return '<h3>' + escapeHtml(symbol) + '</h3>' +
							'<p><strong>Decisions:</strong> ' + escapeHtml(item.count ?? 0) + ' | <strong>Actions:</strong> ' + escapeHtml(JSON.stringify(item.actionCounts || {})) + '</p>' +
							'<table class="widefat striped"><thead><tr><th>Горизонт</th><th>Hit-rate</th><th>Hits</th><th>Avg edge</th><th>Median edge</th></tr></thead><tbody>' +
							['15m', '1h', '4h'].map((label) => {
								const h = horizons[label] || {};
								return '<tr><td>' + escapeHtml(label) + '</td><td>' + escapeHtml(h.hitRatePct ?? 0) + '%</td><td>' + escapeHtml(h.hits ?? 0) + '/' + escapeHtml(h.evaluated ?? 0) + '</td><td>' + escapeHtml(h.avgEdgePct ?? 0) + '%</td><td>' + escapeHtml(h.medianEdgePct ?? 0) + '%</td></tr>';
							}).join('') +
							'</tbody></table>';
					}).join('') : '<p>Качество ещё не рассчитано.</p>';
				}

				function refresh() {
					writeLog('refresh');
					request('trading_brain_panel_status').then(render).catch((error) => writeLog(error.message));
				}

				document.querySelectorAll('[data-tbp-action]').forEach((button) => {
					button.addEventListener('click', function () {
						const action = button.getAttribute('data-tbp-action');
						if (action === 'refresh') {
							refresh();
							return;
						}
						if (!window.confirm('Выполнить действие: ' + action + '?')) {
							return;
						}
						writeLog(action);
						request('trading_brain_panel_control', { service_action: action })
							.then(render)
							.catch((error) => writeLog(error.message));
					});
				});

				refresh();
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
		$decoded_latest = array();
		$decoded_paper = array();
		$decoded_quality = array();

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

		return array(
			'service_status' => trim( $status['output'] ) ?: 'unknown',
			'latest'         => $decoded_latest,
			'paper'          => $decoded_paper,
			'quality'        => $decoded_quality,
		);
	}

	private static function run_helper( string $command ): array {
		$allowed = array( 'latest', 'status', 'paper', 'quality', 'start', 'stop', 'restart' );
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
