/**
 * Резерв: привязка «Добавить участника» после yvo-frontend-js.
 * Если основной $(function(){}) в frontend.js оборвался ошибкой выше по коду,
 * window.yvoFpHandleTabAddClick всё равно появится после успешного init; иначе ждём и повторяем.
 */
(function($) {
    'use strict';

    function bindWhenReady() {
        if (typeof window.yvoFpHandleTabAddClick !== 'function' || !window.yvoFpParticipantTabAddReady) {
            if (bindWhenReady.attempts === undefined) bindWhenReady.attempts = 0;
            if (bindWhenReady.attempts < 200) {
                bindWhenReady.attempts++;
                setTimeout(bindWhenReady, 40);
            }
            return;
        }
        var btn = document.getElementById('yvo-fp-tab-add');
        if (!btn) {
            return;
        }
        if (btn.getAttribute('data-yvo-participant-tab-add') === '1') {
            return;
        }
        btn.setAttribute('data-yvo-participant-tab-add', '1');
        btn.addEventListener('click', window.yvoFpHandleTabAddClick, true);
    }

    $(function() {
        bindWhenReady();
    });
})(jQuery);
