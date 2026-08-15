<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var bool $yvo_pc_logged_in */
/** @var string $yvo_pc_login_url */
/** @var string $yvo_pc_register_url */
/** @var string $yvo_pc_pricing_url */
/** @var float $yvo_pc_price */
/** @var bool $yvo_pc_free_sub */
/** @var float $yvo_pc_wallet */
$price_fmt = number_format((float) $yvo_pc_price, 0, ',', ' ');
?>
<div class="yvo-pc-landing" id="yvoPropertyCheckLanding">
    <nav class="yvo-pc-breadcrumb" aria-label="Навигация">
        <a href="<?php echo esc_url(home_url('/')); ?>">Главная</a>
        <span aria-hidden="true">›</span>
        <span>Проверка квартиры с ИИ</span>
    </nav>

    <header class="yvo-pc-hero">
        <div class="yvo-pc-hero__text">
            <h1>Проверить квартиру с ИИ</h1>
            <p class="yvo-pc-hero__lead">Покажем риски и рекомендации за несколько минут</p>
            <ul class="yvo-pc-hero__bullets">
                <li>Выявим юридические риски по выписке и документам</li>
                <li>Проверим собственников и обременения</li>
                <li>Подсветим, что уточнить перед сделкой</li>
            </ul>
            <div class="yvo-pc-price-badge">
                <span class="yvo-pc-price-badge__main">Проверка — <strong><?php echo esc_html($price_fmt); ?> ₽</strong></span>
                <?php if ($yvo_pc_free_sub) : ?>
                    <span class="yvo-pc-price-badge__sub yvo-pc-price-badge__sub--ok">Бесплатно по вашей подписке</span>
                <?php else : ?>
                    <span class="yvo-pc-price-badge__sub">или бесплатно при подписке «Про» / «Бизнес»</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="yvo-pc-card yvo-pc-form-card">
            <div class="yvo-pc-drop" id="yvoPcDrop" tabindex="0" role="button" aria-label="Загрузить документы">
                <div class="yvo-pc-drop__title">Загрузить файл</div>
                <p class="yvo-pc-drop__hint">
                    <?php if ($yvo_pc_logged_in) : ?>
                        Выписка ЕГРН, договор — DOCX / PDF / JPEG / PNG / TXT
                    <?php else : ?>
                        <a href="<?php echo esc_url($yvo_pc_login_url); ?>">Войдите</a> или <a href="<?php echo esc_url($yvo_pc_register_url); ?>">зарегистрируйтесь</a>, чтобы загрузить файл
                    <?php endif; ?>
                </p>
                <p class="yvo-pc-drop__meta">Максимум <?php echo esc_html((string) get_option('yvo_max_size', 20)); ?> МБ · до ~18 страниц</p>
                <input type="file" id="yvoPcFile" accept=".pdf,.png,.jpg,.jpeg,.gif,.bmp,.txt,.docx,application/pdf,image/*" hidden />
                <div class="yvo-pc-file-list" id="yvoPcFileList" hidden></div>
            </div>

            <label class="yvo-pc-label" for="yvoPcCad">Кадастровый номер</label>
            <input class="yvo-pc-input" id="yvoPcCad" type="text" placeholder="77:08:0011001:1316" inputmode="numeric" autocomplete="off" />

            <label class="yvo-pc-label" for="yvoPcText">Текст выписки / договора (необязательно)</label>
            <textarea class="yvo-pc-textarea" id="yvoPcText" rows="5" placeholder="Вставьте текст выписки ЕГРН или договора, если не загружаете файл…"></textarea>

            <button type="button" class="yvo-pc-btn yvo-pc-btn--primary" id="yvoPcRunBtn">
                Проверить квартиру
            </button>
            <?php if ($yvo_pc_logged_in && !$yvo_pc_free_sub) : ?>
                <p class="yvo-pc-wallet-hint">Кошелёк: <strong id="yvoPcWallet"><?php echo esc_html(number_format((float) $yvo_pc_wallet, 0, ',', ' ')); ?></strong> ₽ · списание <?php echo esc_html($price_fmt); ?> ₽ за проверку</p>
            <?php endif; ?>
            <p class="yvo-pc-disclaimer">Анализ выполняется ИИ (DeepSeek) и не является юридической консультацией. Сверяйте данные с актуальной выпиской ЕГРН.</p>
        </div>
    </header>

    <section class="yvo-pc-stats" aria-label="Статистика">
        <div class="yvo-pc-stat"><strong>4.8 / 5</strong><span>Средняя оценка (демо)</span></div>
        <div class="yvo-pc-stat"><strong>1 200+</strong><span>Проверок объектов</span></div>
        <div class="yvo-pc-stat"><strong>от 5 000 ₽</strong><span>Экономия на ошибках</span></div>
    </section>

    <section class="yvo-pc-section">
        <h2>Как это работает?</h2>
        <ol class="yvo-pc-steps">
            <li><span>1</span>Загрузите выписку ЕГРН или договор (PDF, фото, TXT) и укажите кадастровый номер</li>
            <li><span>2</span>Подождите несколько минут — ИИ проанализирует объект, собственников и обременения</li>
            <li><span>3</span>Получите отчёт с рисками и рекомендациями перед сделкой</li>
        </ol>
    </section>

    <section class="yvo-pc-section yvo-pc-compare">
        <h2>Посмотрите, что скрыто в документах по квартире</h2>
        <div class="yvo-pc-compare__grid">
            <div class="yvo-pc-compare__col yvo-pc-compare__col--before">
                <h3>До проверки</h3>
                <ul>
                    <li>Сложный юридический язык</li>
                    <li>Скрытые обременения</li>
                    <li>Несоответствия в выписке</li>
                    <li>Риски, которые легко пропустить</li>
                </ul>
            </div>
            <div class="yvo-pc-compare__col yvo-pc-compare__col--after">
                <h3>После проверки ИИ</h3>
                <ul>
                    <li>Структурированный отчёт</li>
                    <li>Комментарии простым языком</li>
                    <li>Собственники и доли</li>
                    <li>Рекомендации перед сделкой</li>
                </ul>
            </div>
        </div>
    </section>
</div>

<div class="yvo-pc-modal" id="yvoPcLoadingModal" hidden aria-hidden="true">
    <div class="yvo-pc-modal__backdrop"></div>
    <div class="yvo-pc-modal__box" role="dialog" aria-labelledby="yvoPcLoadingTitle">
        <h3 id="yvoPcLoadingTitle">Проверяем квартиру…</h3>
        <p>Анализ может занять 2–5 минут. Не закрывайте страницу.</p>
        <div class="yvo-pc-spinner" aria-hidden="true"></div>
    </div>
</div>

<div class="yvo-pc-modal" id="yvoPcResultModal" hidden aria-hidden="true">
    <div class="yvo-pc-modal__backdrop" data-yvo-pc-close="1"></div>
    <div class="yvo-pc-modal__box yvo-pc-modal__box--wide" role="dialog" aria-labelledby="yvoPcResultTitle">
        <button type="button" class="yvo-pc-modal__close" data-yvo-pc-close="1" aria-label="Закрыть">&times;</button>
        <h3 id="yvoPcResultTitle">Отчёт о проверке квартиры</h3>
        <div class="yvo-pc-report" id="yvoPcReport"></div>
        <div class="yvo-pc-modal__actions">
            <button type="button" class="yvo-pc-btn yvo-pc-btn--secondary" data-yvo-pc-close="1">Закрыть</button>
        </div>
    </div>
</div>
