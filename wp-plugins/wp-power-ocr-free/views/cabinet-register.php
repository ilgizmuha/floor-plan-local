<?php
if (!defined('ABSPATH')) exit;
$roles = yvo_cabinet_get_roles();
$questions = yvo_cabinet_get_control_questions();
$error = isset($yvo_register_error) ? $yvo_register_error : '';
$success = isset($yvo_register_success) ? $yvo_register_success : '';
$yvo_yandex_url = isset($yvo_yandex_register_url) ? $yvo_yandex_register_url : '';
?>
<div class="yvo-cabinet-auth yvo-cabinet-register">
    <h2 class="yvo-cabinet-auth-title">Регистрация в личном кабинете</h2>
    <?php if ($error) : ?>
        <div class="yvo-cabinet-auth-message yvo-cabinet-error"><?php echo esc_html($error); ?></div>
    <?php endif; ?>
    <?php if ($success) : ?>
        <div class="yvo-cabinet-auth-message yvo-cabinet-success"><?php echo esc_html($success); ?></div>
        <p><a href="<?php echo esc_url(isset($yvo_login_url) ? $yvo_login_url : '#'); ?>" class="yvo-cabinet-link">Войти</a></p>
    <?php else : ?>
    <?php if (!empty($yvo_yandex_url)) : ?>
        <p class="yvo-cabinet-submit" style="margin-top: 0;">
            <a class="yvo-cabinet-btn yvo-cabinet-btn-yandex" href="<?php echo esc_url($yvo_yandex_url); ?>">Войти или зарегистрироваться через Яндекс</a>
        </p>
        <p class="yvo-cabinet-yandex-hint">Рекомендуем: один аккаунт Яндекс для входа и регистрации без пароля к сайту. После входа откроются договоры.</p>
        <div class="yvo-cabinet-divider">или регистрация по логину</div>
    <?php else : ?>
        <p class="yvo-cabinet-oauth-missing">Вход через Яндекс не настроен: укажите OAuth в <strong>Яндекс OCR Pro AI → Настройки</strong>.</p>
    <?php endif; ?>
    <form method="post" action="" class="yvo-cabinet-form">
        <?php wp_nonce_field('yvo_cabinet_register', 'yvo_cabinet_register_nonce'); ?>
        <p class="yvo-cabinet-field">
            <label for="yvo-reg-name">Имя *</label>
            <input type="text" id="yvo-reg-name" name="yvo_name" required minlength="2" autocomplete="name">
        </p>
        <p class="yvo-cabinet-field">
            <label for="yvo-reg-login">Логин *</label>
            <input type="text" id="yvo-reg-login" name="yvo_login" required minlength="3" autocomplete="username">
        </p>
        <p class="yvo-cabinet-field">
            <label for="yvo-reg-password">Пароль *</label>
            <input type="password" id="yvo-reg-password" name="yvo_password" required minlength="6" autocomplete="new-password">
        </p>
        <p class="yvo-cabinet-field">
            <label for="yvo-reg-password2">Пароль еще раз *</label>
            <input type="password" id="yvo-reg-password2" name="yvo_password2" required minlength="6" autocomplete="new-password">
        </p>
        <p class="yvo-cabinet-field">
            <label for="yvo-reg-role">Роль *</label>
            <select id="yvo-reg-role" name="yvo_role" required>
                <?php foreach ($roles as $key => $label) : ?>
                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="yvo-cabinet-field">
            <label for="yvo-reg-question">Контрольный вопрос (для восстановления пароля) *</label>
            <select id="yvo-reg-question" name="yvo_control_question" required>
                <?php foreach ($questions as $key => $label) : ?>
                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="yvo-cabinet-field">
            <label for="yvo-reg-answer">Ответ на контрольный вопрос *</label>
            <input type="text" id="yvo-reg-answer" name="yvo_control_answer" required minlength="2" autocomplete="off">
        </p>
        <?php
        $offer_url = function_exists('yvo_legal_get_url') ? yvo_legal_get_url('offer') : '#';
        $privacy_url = function_exists('yvo_legal_get_url') ? yvo_legal_get_url('privacy') : '#';
        ?>
        <div class="yvo-cabinet-legal-consent">
            <label>
                <input type="checkbox" name="yvo_accept_offer" value="1" required>
                <span>Я принимаю <a href="<?php echo esc_url($offer_url); ?>" target="_blank" rel="noopener">публичную оферту</a> *</span>
            </label>
            <label>
                <input type="checkbox" name="yvo_accept_privacy" value="1" required>
                <span>Я ознакомлен(а) с <a href="<?php echo esc_url($privacy_url); ?>" target="_blank" rel="noopener">Политикой конфиденциальности</a> *</span>
            </label>
        </div>
        <p class="yvo-cabinet-submit">
            <button type="submit" name="yvo_do_register" class="yvo-cabinet-btn yvo-cabinet-btn-primary">Зарегистрироваться</button>
        </p>
        <p class="yvo-cabinet-links">Уже есть аккаунт? <a href="<?php echo esc_url(isset($yvo_login_url) ? $yvo_login_url : '#'); ?>" class="yvo-cabinet-link">Войти</a></p>
    </form>
    <?php endif; ?>
</div>
