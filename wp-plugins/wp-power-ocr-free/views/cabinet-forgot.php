<?php
if (!defined('ABSPATH')) exit;
$step = isset($yvo_forgot_step) ? (int) $yvo_forgot_step : 1;
$error = isset($yvo_forgot_error) ? $yvo_forgot_error : '';
$success = isset($yvo_forgot_success) ? $yvo_forgot_success : '';
$question_text = isset($yvo_forgot_question) ? $yvo_forgot_question : '';
$login_value = isset($yvo_forgot_login) ? $yvo_forgot_login : '';
?>
<div class="yvo-cabinet-auth yvo-cabinet-forgot">
    <h2 class="yvo-cabinet-auth-title">Восстановление пароля</h2>
    <?php if ($error) : ?>
        <div class="yvo-cabinet-auth-message yvo-cabinet-error"><?php echo esc_html($error); ?></div>
    <?php endif; ?>
    <?php if ($success) : ?>
        <div class="yvo-cabinet-auth-message yvo-cabinet-success"><?php echo esc_html($success); ?></div>
        <p><a href="<?php echo esc_url(isset($yvo_login_url) ? $yvo_login_url : '#'); ?>" class="yvo-cabinet-link">Войти</a></p>
    <?php elseif ($step === 2 && $question_text) : ?>
        <form method="post" action="" class="yvo-cabinet-form">
            <?php wp_nonce_field('yvo_cabinet_forgot', 'yvo_cabinet_forgot_nonce'); ?>
            <input type="hidden" name="yvo_forgot_login" value="<?php echo esc_attr($login_value); ?>">
            <p class="yvo-cabinet-field">
                <label>Контрольный вопрос</label>
                <strong><?php echo esc_html($question_text); ?></strong>
            </p>
            <p class="yvo-cabinet-field">
                <label for="yvo-forgot-answer">Ваш ответ *</label>
                <input type="text" id="yvo-forgot-answer" name="yvo_control_answer" required autocomplete="off">
            </p>
            <p class="yvo-cabinet-field">
                <label for="yvo-forgot-newpass">Новый пароль *</label>
                <input type="password" id="yvo-forgot-newpass" name="yvo_new_password" required minlength="6" autocomplete="new-password">
            </p>
            <p class="yvo-cabinet-field">
                <label for="yvo-forgot-newpass2">Повторите новый пароль *</label>
                <input type="password" id="yvo-forgot-newpass2" name="yvo_new_password2" required minlength="6" autocomplete="new-password">
            </p>
            <p class="yvo-cabinet-submit">
                <button type="submit" name="yvo_do_forgot_step2" class="yvo-cabinet-btn yvo-cabinet-btn-primary">Сохранить новый пароль</button>
            </p>
        </form>
    <?php else : ?>
        <form method="post" action="" class="yvo-cabinet-form">
            <?php wp_nonce_field('yvo_cabinet_forgot', 'yvo_cabinet_forgot_nonce'); ?>
            <p class="yvo-cabinet-field">
                <label for="yvo-forgot-login">Введите ваш логин *</label>
                <input type="text" id="yvo-forgot-login" name="yvo_forgot_login" value="<?php echo esc_attr($login_value); ?>" required autocomplete="username">
            </p>
            <p class="yvo-cabinet-submit">
                <button type="submit" name="yvo_do_forgot_step1" class="yvo-cabinet-btn yvo-cabinet-btn-primary">Далее</button>
            </p>
        </form>
    <?php endif; ?>
    <p class="yvo-cabinet-links">
        <a href="<?php echo esc_url(isset($yvo_login_url) ? $yvo_login_url : '#'); ?>" class="yvo-cabinet-link">Войти</a>
    </p>
</div>
