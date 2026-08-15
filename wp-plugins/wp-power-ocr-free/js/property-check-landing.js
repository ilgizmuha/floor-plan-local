(function () {
  var cfg = window.yvoPropertyCheck || {};
  var drop = document.getElementById('yvoPcDrop');
  var fileInput = document.getElementById('yvoPcFile');
  var fileList = document.getElementById('yvoPcFileList');
  var cadInput = document.getElementById('yvoPcCad');
  var textArea = document.getElementById('yvoPcText');
  var runBtn = document.getElementById('yvoPcRunBtn');
  var loadingModal = document.getElementById('yvoPcLoadingModal');
  var resultModal = document.getElementById('yvoPcResultModal');
  var reportEl = document.getElementById('yvoPcReport');
  var walletEl = document.getElementById('yvoPcWallet');
  var docText = '';
  var fileName = '';

  function toast(msg) {
    var el = document.createElement('div');
    el.className = 'yvo-pc-toast';
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(function () { el.remove(); }, 3200);
  }

  function showModal(el) {
    if (!el) return;
    el.hidden = false;
    el.setAttribute('aria-hidden', 'false');
  }
  function hideModal(el) {
    if (!el) return;
    el.hidden = true;
    el.setAttribute('aria-hidden', 'true');
  }

  document.querySelectorAll('[data-yvo-pc-close]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      hideModal(resultModal);
      hideModal(loadingModal);
    });
  });

  function renderFile() {
    if (!fileList) return;
    if (!fileName) {
      fileList.hidden = true;
      fileList.textContent = '';
      return;
    }
    fileList.hidden = false;
    fileList.textContent = 'Файл: ' + fileName + (docText ? ' (' + docText.length + ' симв.)' : '');
  }

  function readTxtFile(file) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onload = function (e) { resolve(e.target && e.target.result ? String(e.target.result) : ''); };
      reader.onerror = reject;
      reader.readAsText(file, 'UTF-8');
    });
  }

  function uploadOcr(file) {
    var fd = new FormData();
    fd.append('action', 'yvo_frontend_upload');
    fd.append('yvo_pd_consent', '1');
    if (cfg.nonce) fd.append('nonce', cfg.nonce);
    fd.append('file', file);
    return fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  async function handleFile(file) {
    if (!file) return;
    fileName = file.name || 'файл';
    var ext = (fileName.split('.').pop() || '').toLowerCase();
    try {
      if (ext === 'txt') {
        docText = await readTxtFile(file);
      } else {
        runBtn.disabled = true;
        runBtn.textContent = 'Распознаём документ…';
        var res = await uploadOcr(file);
        runBtn.disabled = false;
        runBtn.textContent = 'Проверить квартиру';
        if (!res || !res.success || !res.data || !res.data.text) {
          throw new Error((res && res.data && res.data.message) ? res.data.message : 'Не удалось распознать файл');
        }
        docText = res.data.text;
        if (textArea && !textArea.value) textArea.value = docText.slice(0, 8000);
      }
      renderFile();
      toast('Документ загружен');
    } catch (err) {
      toast(err && err.message ? err.message : 'Ошибка загрузки');
      fileName = '';
      docText = '';
      renderFile();
    }
  }

  if (drop && fileInput) {
    drop.addEventListener('click', function (e) {
      if (!cfg.loggedIn) {
        if (cfg.loginUrl) window.location.href = cfg.loginUrl;
        return;
      }
      if (e.target === fileInput) return;
      fileInput.click();
    });
    fileInput.addEventListener('change', function () {
      if (fileInput.files && fileInput.files[0]) handleFile(fileInput.files[0]);
      fileInput.value = '';
    });
    ['dragenter', 'dragover'].forEach(function (evt) {
      drop.addEventListener(evt, function (e) { e.preventDefault(); drop.classList.add('is-drag'); });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
      drop.addEventListener(evt, function (e) {
        e.preventDefault();
        drop.classList.remove('is-drag');
        if (evt === 'drop' && e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
          if (!cfg.loggedIn) {
            if (cfg.loginUrl) window.location.href = cfg.loginUrl;
            return;
          }
          handleFile(e.dataTransfer.files[0]);
        }
      });
    });
  }

  var params = new URLSearchParams(window.location.search);
  var cadParam = params.get('cad');
  if (cadParam && cadInput) cadInput.value = cadParam.replace(/\s+/g, '');

  if (runBtn) {
    runBtn.addEventListener('click', function () {
      if (!cfg.loggedIn) {
        if (cfg.loginUrl) window.location.href = cfg.loginUrl;
        else toast('Войдите в аккаунт');
        return;
      }
      var cad = cadInput ? cadInput.value.replace(/\s+/g, '') : '';
      var text = (textArea && textArea.value ? textArea.value.trim() : '') || docText;
      if (!cad && text.length < 80) {
        toast('Укажите кадастровый номер или загрузите / вставьте текст выписки (от 80 символов)');
        return;
      }
      var confirmMsg = cfg.freeSub
        ? 'Запустить проверку квартиры? (бесплатно по подписке)'
        : 'Списать ' + (cfg.price || 119) + ' ₽ с кошелька за проверку?';
      if (!cfg.freeSub && cfg.billingEnforced && !window.confirm(confirmMsg)) return;

      runBtn.disabled = true;
      showModal(loadingModal);
      var body = new URLSearchParams();
      body.append('action', 'yvo_check_property');
      body.append('nonce', cfg.nonce || '');
      body.append('cadastral_number', cad);
      body.append('document_text', text);

      fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString(),
        credentials: 'same-origin'
      })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          hideModal(loadingModal);
          runBtn.disabled = false;
          if (res && res.success && res.data && res.data.report) {
            if (reportEl) reportEl.textContent = res.data.report;
            if (walletEl && typeof res.data.wallet_balance === 'number') {
              walletEl.textContent = String(Math.round(res.data.wallet_balance)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
            }
            showModal(resultModal);
            if (res.data.charged_price > 0) {
              toast('Списано ' + res.data.charged_price + ' ₽');
            }
          } else {
            var msg = (res && res.data && res.data.message) ? res.data.message : 'Ошибка проверки';
            if (res && res.data && res.data.login_required && res.data.login_url) {
              window.location.href = res.data.login_url;
              return;
            }
            if (res && res.data && res.data.pricing_url && window.confirm(msg + '\n\nПерейти на страницу тарифов?')) {
              window.location.href = res.data.pricing_url;
              return;
            }
            toast(msg);
          }
        })
        .catch(function () {
          hideModal(loadingModal);
          runBtn.disabled = false;
          toast('Ошибка сервера');
        });
    });
  }
})();
