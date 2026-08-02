/**
 * Чат: JPG/PNG/PDF в браузере (pdf-lib + JSZip) + AJAX YVO.
 */
(function () {
  'use strict';

  var PAGE_FORMATS = {
    a4p: { w: 595.28, h: 841.89 },
    a4l: { w: 841.89, h: 595.28 },
    a5p: { w: 420.94, h: 595.28 },
    a5l: { w: 595.28, h: 420.94 },
    letterp: { w: 612, h: 792 },
    letterl: { w: 792, h: 612 },
  };

  function cfg() {
    return typeof window.yvo_jpg_pdf_chat_cfg === 'object' && window.yvo_jpg_pdf_chat_cfg
      ? window.yvo_jpg_pdf_chat_cfg
      : {};
  }

  function $(root, sel) {
    return root.querySelector(sel);
  }

  function scrollBottom(el) {
    el.scrollTop = el.scrollHeight;
  }

  function humanSize(n) {
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
  }

  function escHtml(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function addBubble(messagesEl, html, kind) {
    var b = document.createElement('div');
    b.className = 'yvo-jpg-pdf-chat__bubble yvo-jpg-pdf-chat__bubble--' + (kind || 'bot');
    b.innerHTML = html;
    messagesEl.appendChild(b);
    scrollBottom(messagesEl);
    return b;
  }

  function mmToPt(mm) {
    return (parseFloat(mm, 10) || 0) * 2.834645669;
  }

  function getPdfOptions(root) {
    var sel = $(root, '[data-yvo-page-format]');
    var key = sel && sel.value ? sel.value : 'a4p';
    var fmt = PAGE_FORMATS[key] || PAGE_FORMATS.a4p;
    var linkEl = $(root, '[data-yvo-margin-link]');
    var uniform = $(root, '[data-yvo-margin-uniform]');
    var u = parseFloat(uniform && uniform.value ? uniform.value : '10', 10);
    if (isNaN(u) || u < 0) u = 10;
    var mt, mr, mb, ml;
    if (linkEl && linkEl.checked) {
      mt = mr = mb = ml = u;
    } else {
      mt = parseFloat(($(root, '[data-yvo-m-t]') || {}).value || u, 10) || u;
      mr = parseFloat(($(root, '[data-yvo-m-r]') || {}).value || u, 10) || u;
      mb = parseFloat(($(root, '[data-yvo-m-b]') || {}).value || u, 10) || u;
      ml = parseFloat(($(root, '[data-yvo-m-l]') || {}).value || u, 10) || u;
    }
    return {
      pageW: fmt.w,
      pageH: fmt.h,
      margin: { top: mmToPt(mt), right: mmToPt(mr), bottom: mmToPt(mb), left: mmToPt(ml) },
    };
  }

  function fileKind(file) {
    if (!file || !file.name) return null;
    var t = (file.type || '').toLowerCase();
    var name = file.name.toLowerCase();
    if (t === 'application/pdf' || /\.pdf$/i.test(name)) return 'pdf';
    if (/^image\/(jpeg|jpg|png|pjpeg|x-png)$/i.test(t)) return 'image';
    if (/\.(jpe?g|png)$/i.test(name)) return 'image';
    return null;
  }

  function postForm(action, file) {
    var c = cfg();
    if (!(c.ajax_url || '').trim()) {
      return Promise.reject(new Error('Не задан URL admin-ajax.php (нужен сайт с плагином).'));
    }
    var fd = new FormData();
    fd.append('action', action);
    if (c.nonce) fd.append('nonce', c.nonce);
    fd.append('file', file, file.name);
    return fetch(c.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      return r.json();
    });
  }

  function postJson(action, data) {
    var c = cfg();
    if (!(c.ajax_url || '').trim()) {
      return Promise.reject(new Error('Не задан URL admin-ajax.php.'));
    }
    var body = new URLSearchParams();
    body.append('action', action);
    if (c.nonce) body.append('nonce', c.nonce);
    Object.keys(data || {}).forEach(function (k) {
      body.append(k, data[k]);
    });
    return fetch(c.ajax_url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
      credentials: 'same-origin',
    }).then(function (r) {
      return r.json();
    });
  }

  function formatPassport(d) {
    if (!d || typeof d !== 'object') return '';
    var lines = [];
    if (d.fio) lines.push('ФИО: ' + escHtml(d.fio));
    if (d.series_number) lines.push('Серия и номер: ' + escHtml(d.series_number));
    if (d.department_code) lines.push('Код подразделения: ' + escHtml(d.department_code));
    if (d.issued_by) lines.push('Кем выдан: ' + escHtml(d.issued_by));
    if (d.birth_date) lines.push('Дата рождения: ' + escHtml(d.birth_date));
    if (d.issue_date) lines.push('Дата выдачи: ' + escHtml(d.issue_date));
    return lines.length ? '<pre>' + lines.join('\n') + '</pre>' : '<pre>' + escHtml(JSON.stringify(d, null, 2)) + '</pre>';
  }

  function formatParsedDeepSeek(parsed) {
    if (!parsed || typeof parsed !== 'object') return '';
    return '<pre>' + escHtml(JSON.stringify(parsed, null, 2)) + '</pre>';
  }

  function formatPersons(list) {
    if (!list || !list.length) return '<p>Участники не найдены.</p>';
    var html = '<ol style="margin:6px 0;padding-left:18px;">';
    list.forEach(function (p) {
      html += '<li><strong>' + escHtml(p.full_name || '—') + '</strong>';
      if (p.passport_series || p.passport_number) {
        html += ' · паспорт ' + escHtml((p.passport_series || '') + ' ' + (p.passport_number || ''));
      }
      html += '</li>';
    });
    html += '</ol>';
    return html;
  }

  function embedImagePage(pdfDoc, file, opt) {
    return file.arrayBuffer().then(function (buf) {
      var isPng = /\.png$/i.test(file.name) || file.type === 'image/png';
      var p = isPng ? pdfDoc.embedPng(buf) : pdfDoc.embedJpg(buf);
      return Promise.resolve(p).then(function (image) {
        var pw = opt.pageW;
        var ph = opt.pageH;
        var m = opt.margin;
        var maxW = pw - m.left - m.right;
        var maxH = ph - m.top - m.bottom;
        if (maxW <= 1 || maxH <= 1) {
          throw new Error('Поля слишком большие для выбранного формата');
        }
        var page = pdfDoc.addPage([pw, ph]);
        var iw = image.width;
        var ih = image.height;
        var scale = Math.min(maxW / iw, maxH / ih, 1);
        var w = iw * scale;
        var h = ih * scale;
        var x = m.left + (maxW - w) / 2;
        var y = m.bottom + (maxH - h) / 2;
        page.drawImage(image, { x: x, y: y, width: w, height: h });
      });
    });
  }

  function mergeQueueToPdf(items, opt) {
    if (typeof PDFLib === 'undefined') {
      return Promise.reject(new Error('PDFLib не загрузился'));
    }
    var PDFDocument = PDFLib.PDFDocument;
    return PDFDocument.create().then(function (out) {
      var i = 0;
      function next() {
        if (i >= items.length) {
          return out.save();
        }
        var it = items[i++];
        if (it.kind === 'pdf') {
          return it.file.arrayBuffer().then(function (buf) {
            return PDFDocument.load(buf);
          }).then(function (src) {
            var n = src.getPageCount();
            var idx = [];
            var j;
            for (j = 0; j < n; j++) idx.push(j);
            return out.copyPages(src, idx).then(function (pages) {
              pages.forEach(function (p) {
                out.addPage(p);
              });
              return next();
            });
          });
        }
        return embedImagePage(out, it.file, opt).then(next);
      }
      return next();
    });
  }

  function splitPdfToZipBlob(file) {
    if (typeof JSZip === 'undefined') {
      return Promise.reject(new Error('JSZip не загрузился'));
    }
    var PDFDocument = PDFLib.PDFDocument;
    var zip = new JSZip();
    return file.arrayBuffer().then(function (buf) {
      return PDFDocument.load(buf);
    }).then(function (src) {
      var n = src.getPageCount();
      var i = 0;
      function onePage() {
        if (i >= n) {
          return zip.generateAsync({ type: 'blob', compression: 'DEFLATE' });
        }
        var idx = i++;
        return PDFDocument.create().then(function (one) {
          return one.copyPages(src, [idx]).then(function (pages) {
            one.addPage(pages[0]);
            return one.save();
          });
        }).then(function (bytes) {
          zip.file('page-' + (idx + 1) + '.pdf', bytes);
          return onePage();
        });
      }
      return onePage();
    });
  }

  function downloadBlob(bytes, name, mime) {
    var blob = bytes instanceof Blob ? bytes : new Blob([bytes], { type: mime || 'application/pdf' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    a.click();
    setTimeout(function () {
      URL.revokeObjectURL(a.href);
    }, 4000);
  }

  function initRoot(root) {
    var messagesEl = $(root, '[data-yvo-jpg-messages]');
    var queueEl = $(root, '[data-yvo-jpg-queue]');
    var queueHint = $(root, '[data-yvo-jpg-queue-hint]');
    var thumbsEl = $(root, '[data-yvo-jpg-thumbs]');
    var fileInput = $(root, '[data-yvo-jpg-file]');
    var dropzone = $(root, '[data-yvo-jpg-dropzone]');
    var btnMerge = $(root, '[data-yvo-jpg-merge]');
    var btnSplit = $(root, '[data-yvo-jpg-split]');
    var marginLink = $(root, '[data-yvo-margin-link]');
    var marginFour = $(root, '[data-yvo-margin-four]');
    var marginUniformWrap = $(root, '[data-yvo-margin-uniform-wrap]');
    var lastOcrText = '';

    if (!messagesEl || !thumbsEl || !fileInput) {
      if (window.console && console.error) {
        console.error('YVO JPG PDF Chat: не найдены обязательные элементы виджета. Обновите страницу (Ctrl+F5).');
      }
      return;
    }

    var items = [];

    if (typeof window.yvoJpgPdfChatRefreshApi !== 'function') {
      window.yvoJpgPdfChatRefreshApi = function () {
        var url = (cfg().ajax_url || '').trim();
        document.querySelectorAll('[data-yvo-jpg-api-row]').forEach(function (row) {
          row.hidden = !url;
        });
      };
    }
    window.yvoJpgPdfChatRefreshApi();

    function syncMarginUi() {
      if (!marginLink || !marginFour) return;
      var linked = marginLink.checked;
      marginFour.hidden = linked;
      if (marginUniformWrap) marginUniformWrap.hidden = !linked;
    }
    if (marginLink) {
      marginLink.addEventListener('change', function () {
        if (!marginLink.checked) {
          var uniform = $(root, '[data-yvo-margin-uniform]');
          var v = uniform && uniform.value ? uniform.value : '10';
          ['[data-yvo-m-t]', '[data-yvo-m-r]', '[data-yvo-m-b]', '[data-yvo-m-l]'].forEach(function (sel) {
            var el = $(root, sel);
            if (el) el.value = v;
          });
        }
        syncMarginUi();
      });
      syncMarginUi();
    }

    function syncQueueVisibility() {
      if (queueEl) queueEl.hidden = items.length === 0;
      if (queueHint) {
        queueHint.textContent = items.length ? '(' + items.length + ')' : '';
      }
    }

    function syncActionButtons() {
      if (btnMerge) btnMerge.disabled = items.length === 0;
      var onePdf = items.length === 1 && items[0].kind === 'pdf';
      if (btnSplit) btnSplit.disabled = !onePdf;
    }

    function moveItem(from, to) {
      if (to < 0 || to >= items.length) return;
      var x = items.splice(from, 1)[0];
      items.splice(to, 0, x);
      renderThumbs();
    }

    function renderThumbs() {
      thumbsEl.innerHTML = '';
      items.forEach(function (it, idx) {
        var wrap = document.createElement('div');
        wrap.className = 'yvo-jpg-pdf-chat__thumb';
        wrap.draggable = true;
        wrap.dataset.index = String(idx);

        var num = document.createElement('span');
        num.className = 'yvo-jpg-pdf-chat__thumb-num';
        num.textContent = String(idx + 1);
        wrap.appendChild(num);

        if (it.kind === 'image' && it.url) {
          var img = document.createElement('img');
          img.src = it.url;
          img.alt = it.file.name;
          wrap.appendChild(img);
        } else {
          var ph = document.createElement('div');
          ph.className = 'yvo-jpg-pdf-chat__thumb-pdf';
          ph.innerHTML = '<span class="yvo-jpg-pdf-chat__thumb-pdf-label">PDF</span><span class="yvo-jpg-pdf-chat__thumb-pdf-name">' + escHtml(it.file.name) + '</span>';
          wrap.appendChild(ph);
        }

        var tools = document.createElement('div');
        tools.className = 'yvo-jpg-pdf-chat__thumb-tools';
        var up = document.createElement('button');
        up.type = 'button';
        up.className = 'yvo-jpg-pdf-chat__thumb-tool';
        up.setAttribute('data-yvo-thumb-up', '1');
        up.setAttribute('aria-label', 'Выше');
        up.textContent = '↑';
        up.disabled = idx === 0;
        var down = document.createElement('button');
        down.type = 'button';
        down.className = 'yvo-jpg-pdf-chat__thumb-tool';
        down.setAttribute('data-yvo-thumb-down', '1');
        down.setAttribute('aria-label', 'Ниже');
        down.textContent = '↓';
        down.disabled = idx === items.length - 1;
        tools.appendChild(up);
        tools.appendChild(down);
        wrap.appendChild(tools);

        var rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'yvo-jpg-pdf-chat__thumb-remove';
        rm.setAttribute('aria-label', 'Удалить');
        rm.textContent = '×';
        rm.addEventListener('click', function (e) {
          e.stopPropagation();
          if (it.url) URL.revokeObjectURL(it.url);
          items.splice(idx, 1);
          renderThumbs();
          syncQueueVisibility();
        });
        wrap.appendChild(rm);

        thumbsEl.appendChild(wrap);
      });
      syncActionButtons();
    }

    thumbsEl.addEventListener('click', function (e) {
      var up = e.target.closest('[data-yvo-thumb-up]');
      var down = e.target.closest('[data-yvo-thumb-down]');
      if (!up && !down) return;
      e.preventDefault();
      e.stopPropagation();
      var wrap = e.target.closest('.yvo-jpg-pdf-chat__thumb');
      if (!wrap || !thumbsEl.contains(wrap)) return;
      var from = parseInt(wrap.dataset.index, 10);
      if (up) moveItem(from, from - 1);
      if (down) moveItem(from, from + 1);
    });

    var dragFrom = null;
    thumbsEl.addEventListener('dragstart', function (e) {
      if (e.target.closest('[data-yvo-thumb-up], [data-yvo-thumb-down], .yvo-jpg-pdf-chat__thumb-remove')) {
        e.preventDefault();
        return;
      }
      var t = e.target.closest('.yvo-jpg-pdf-chat__thumb');
      if (!t) return;
      dragFrom = parseInt(t.dataset.index, 10);
      t.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
    });
    thumbsEl.addEventListener('dragend', function (e) {
      var t = e.target.closest('.yvo-jpg-pdf-chat__thumb');
      if (t) t.classList.remove('is-dragging');
      dragFrom = null;
    });
    thumbsEl.addEventListener('dragover', function (e) {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
    });
    thumbsEl.addEventListener('drop', function (e) {
      e.preventDefault();
      var t = e.target.closest('.yvo-jpg-pdf-chat__thumb');
      if (!t || dragFrom === null) return;
      var to = parseInt(t.dataset.index, 10);
      if (dragFrom === to) return;
      var moved = items.splice(dragFrom, 1)[0];
      items.splice(to, 0, moved);
      renderThumbs();
    });

    function pushFiles(fileList) {
      var max = cfg().max_size || 20971520;
      Array.prototype.forEach.call(fileList, function (file) {
        var kind = fileKind(file);
        if (!kind) {
          addBubble(messagesEl, 'Пропуск: только JPG, PNG или PDF — <strong>' + escHtml(file.name || '') + '</strong>', 'err');
          return;
        }
        if (file.size > max) {
          addBubble(messagesEl, 'Файл слишком большой: ' + escHtml(file.name) + ' (' + humanSize(file.size) + ')', 'err');
          return;
        }
        var url = kind === 'image' ? URL.createObjectURL(file) : null;
        items.push({ file: file, kind: kind, url: url });
        addBubble(
          messagesEl,
          'Добавлено <strong>№' + items.length + '</strong>: ' + escHtml(file.name) + ' (' + humanSize(file.size) + ')',
          'user'
        );
      });
      renderThumbs();
      syncQueueVisibility();
    }

    fileInput.addEventListener('change', function () {
      if (fileInput.files && fileInput.files.length) pushFiles(fileInput.files);
      fileInput.value = '';
    });

    if (dropzone) {
      ['dragenter', 'dragover'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) {
          e.preventDefault();
          e.stopPropagation();
          dropzone.classList.add('is-dragover');
        });
      });
      ['dragleave', 'drop'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) {
          e.preventDefault();
          e.stopPropagation();
          dropzone.classList.remove('is-dragover');
        });
      });
      dropzone.addEventListener('drop', function (e) {
        var dt = e.dataTransfer;
        if (dt && dt.files && dt.files.length) pushFiles(dt.files);
      });
    }

    if (btnMerge) {
      btnMerge.addEventListener('click', function () {
        if (!items.length) return;
        btnMerge.disabled = true;
        addBubble(messagesEl, '<span class="yvo-jpg-pdf-chat__spinner"></span>Объединяю в PDF…', 'bot');
        var bubble = messagesEl.lastElementChild;
        var opt = getPdfOptions(root);
        mergeQueueToPdf(items, opt)
          .then(function (bytes) {
            downloadBlob(bytes, 'merged-' + Date.now() + '.pdf', 'application/pdf');
            bubble.innerHTML = 'Готово: один PDF, <strong>' + items.length + '</strong> элемент(ов) очереди.';
          })
          .catch(function (err) {
            bubble.className = 'yvo-jpg-pdf-chat__bubble yvo-jpg-pdf-chat__bubble--err';
            bubble.textContent = 'Ошибка: ' + (err && err.message ? err.message : String(err));
          })
          .finally(function () {
            syncActionButtons();
          });
      });
    }

    if (btnSplit) {
      btnSplit.addEventListener('click', function () {
        if (items.length !== 1 || items[0].kind !== 'pdf') return;
        btnSplit.disabled = true;
        addBubble(messagesEl, '<span class="yvo-jpg-pdf-chat__spinner"></span>Разделяю PDF на страницы…', 'bot');
        var bubble = messagesEl.lastElementChild;
        splitPdfToZipBlob(items[0].file)
          .then(function (blob) {
            downloadBlob(blob, 'split-' + Date.now() + '.zip', 'application/zip');
            bubble.innerHTML = 'Готово: ZIP с отдельными PDF по страницам.';
          })
          .catch(function (err) {
            bubble.className = 'yvo-jpg-pdf-chat__bubble yvo-jpg-pdf-chat__bubble--err';
            bubble.textContent = 'Ошибка: ' + (err && err.message ? err.message : String(err));
          })
          .finally(function () {
            syncActionButtons();
          });
      });
    }

    function withLoading(htmlLabel, work) {
      addBubble(messagesEl, '<span class="yvo-jpg-pdf-chat__spinner"></span>' + htmlLabel, 'bot');
      var bubble = messagesEl.lastElementChild;
      return work()
        .then(function (html) {
          bubble.innerHTML = html;
        })
        .catch(function (e) {
          bubble.className = 'yvo-jpg-pdf-chat__bubble yvo-jpg-pdf-chat__bubble--err';
          bubble.textContent = e && e.message ? e.message : String(e);
        });
    }

    function firstImageFile() {
      for (var i = 0; i < items.length; i++) {
        if (items[i].kind === 'image') return items[i].file;
      }
      return null;
    }

    function runAiReorder(documentKind) {
      if (!(cfg().ajax_url || '').trim()) {
        addBubble(messagesEl, 'Не задан URL API.', 'err');
        return;
      }
      if (items.some(function (x) { return x.kind === 'pdf'; })) {
        addBubble(messagesEl, 'Уберите PDF из очереди: ИИ сортирует только фото по распознанному тексту.', 'err');
        return;
      }
      if (!items.every(function (x) { return x.kind === 'image'; }) || items.length < 2) {
        addBubble(messagesEl, 'Нужно минимум 2 изображения JPG/PNG в очереди.', 'err');
        return;
      }
      addBubble(messagesEl, '<span class="yvo-jpg-pdf-chat__spinner"></span>Шаг 1/2: распознаю каждое фото (Яндекс Vision)…', 'bot');
      var bubble = messagesEl.lastElementChild;
      var chain = Promise.resolve();
      var payloads = [];
      items.forEach(function (it, idx) {
        chain = chain.then(function () {
          bubble.innerHTML =
            '<span class="yvo-jpg-pdf-chat__spinner"></span> OCR фото ' + (idx + 1) + ' / ' + items.length + '…';
          return postForm('yvo_frontend_upload', it.file).then(function (res) {
            if (!res.success) throw new Error((res.data && res.data.message) || 'Ошибка OCR');
            var t = res.data.text || '';
            payloads.push({ index: idx, name: it.file.name, text: t.slice(0, 1800) });
          });
        });
      });
      chain
        .then(function () {
          bubble.innerHTML = '<span class="yvo-jpg-pdf-chat__spinner"></span>Шаг 2/2: DeepSeek расставляет порядок страниц…';
          return postJson('yvo_jpg_pdf_chat_order_pages', {
            document_kind: documentKind,
            items: JSON.stringify(payloads),
          });
        })
        .then(function (res) {
          if (!res.success) throw new Error((res.data && res.data.message) || 'Ошибка ИИ');
          var order = res.data.order;
          var old = items.slice();
          items = order.map(function (j) {
            return old[j];
          });
          renderThumbs();
          syncQueueVisibility();
          var rs = res.data.reason_short ? escHtml(res.data.reason_short) : '';
          bubble.className = 'yvo-jpg-pdf-chat__bubble yvo-jpg-pdf-chat__bubble--bot';
          bubble.innerHTML =
            '<strong>Порядок обновлён (ИИ)</strong><p class="yvo-jpg-pdf-chat__hint">' +
            (rs || 'Готово.') +
            '</p><p class="yvo-jpg-pdf-chat__hint">Проверьте номера на миниатюрах и нажмите «Объединить в PDF».</p>';
        })
        .catch(function (err) {
          bubble.className = 'yvo-jpg-pdf-chat__bubble yvo-jpg-pdf-chat__bubble--err';
          bubble.textContent = err && err.message ? err.message : String(err);
        });
    }

    root.addEventListener('click', function (e) {
      var aiBtn = e.target.closest('[data-yvo-ai-order]');
      if (aiBtn && root.contains(aiBtn)) {
        e.preventDefault();
        runAiReorder(aiBtn.getAttribute('data-yvo-ai-order') || 'passport');
        return;
      }
      var btn = e.target.closest('[data-yvo-api]');
      if (!btn || !root.contains(btn)) return;
      var api = btn.getAttribute('data-yvo-api');
      var firstFile = firstImageFile();

      if (api === 'ocr_front') {
        if (!firstFile) {
          addBubble(messagesEl, 'Добавьте изображение (JPG/PNG) для OCR.', 'err');
          return;
        }
        withLoading('Распознавание (yvo_frontend_upload)…', function () {
          return postForm('yvo_frontend_upload', firstFile).then(function (res) {
            if (!res.success) throw new Error((res.data && res.data.message) || 'Ошибка OCR');
            lastOcrText = res.data.text || '';
            var esc = lastOcrText.length > 1200 ? lastOcrText.slice(0, 1200) + '…' : lastOcrText;
            return (
              '<strong>Текст (Vision)</strong><pre class="yvo-jpg-pdf-chat__ocr">' +
              escHtml(esc) +
              '</pre>' +
              '<p class="yvo-jpg-pdf-chat__hint">Дальше — другие действия API по этому тексту.</p>' +
              '<div class="yvo-jpg-pdf-chat__actions">' +
              '<button type="button" class="yvo-jpg-pdf-chat__btn" data-yvo-api="passport_regex">Паспорт (regex)</button>' +
              '<button type="button" class="yvo-jpg-pdf-chat__btn" data-yvo-api="passport_ds">Паспорт (парсер)</button>' +
              '<button type="button" class="yvo-jpg-pdf-chat__btn" data-yvo-api="persons">Участники</button>' +
              '<button type="button" class="yvo-jpg-pdf-chat__btn" data-yvo-api="tr_en">Перевод EN</button>' +
              '<button type="button" class="yvo-jpg-pdf-chat__btn" data-yvo-api="tr_ru">Перевод RU</button>' +
              '</div>'
            );
          });
        });
        return;
      }

      if (api === 'ocr_legacy') {
        if (!firstFile) {
          addBubble(messagesEl, 'Добавьте изображение (JPG/PNG).', 'err');
          return;
        }
        withLoading('Распознавание (yvo_process_upload)…', function () {
          return postForm('yvo_process_upload', firstFile).then(function (res) {
            if (!res.success) throw new Error((res.data && res.data.message) || 'Ошибка OCR');
            lastOcrText = res.data.text || '';
            var esc = lastOcrText.length > 1200 ? lastOcrText.slice(0, 1200) + '…' : lastOcrText;
            return '<strong>Текст (legacy)</strong><pre class="yvo-jpg-pdf-chat__ocr">' + escHtml(esc) + '</pre>';
          });
        });
        return;
      }

      if (!lastOcrText || lastOcrText.length < 5) {
        addBubble(messagesEl, 'Сначала выполните распознавание.', 'err');
        return;
      }

      if (api === 'passport_regex') {
        withLoading('yvo_parse_passport_data…', function () {
          return postJson('yvo_parse_passport_data', { text: lastOcrText }).then(function (res) {
            if (!res.success) throw new Error((res.data && res.data.message) || 'Ошибка');
            return '<strong>Паспорт (regex)</strong>' + formatPassport(res.data);
          });
        });
        return;
      }
      if (api === 'passport_ds') {
        withLoading('yvo_frontend_parse…', function () {
          return postJson('yvo_frontend_parse', { text: lastOcrText, document_type: 'passport' }).then(function (res) {
            if (!res.success) throw new Error((res.data && res.data.message) || 'Ошибка');
            return '<strong>Паспорт (парсер)</strong>' + formatParsedDeepSeek(res.data.parsed_data);
          });
        });
        return;
      }
      if (api === 'persons') {
        withLoading('yvo_frontend_parse_all_persons…', function () {
          return postJson('yvo_frontend_parse_all_persons', { text: lastOcrText }).then(function (res) {
            if (!res.success) throw new Error((res.data && res.data.message) || 'Ошибка');
            return '<strong>Участники</strong>' + formatPersons(res.data.persons || []);
          });
        });
        return;
      }
      if (api === 'tr_en' || api === 'tr_ru') {
        var target = api === 'tr_en' ? 'en' : 'ru';
        var slice = lastOcrText.slice(0, 8000);
        withLoading('yvo_translate_text → ' + target + '…', function () {
          return postJson('yvo_translate_text', { text: slice, target: target }).then(function (res) {
            if (!res.success) throw new Error((res.data && res.data.message) || 'Ошибка перевода');
            var t = res.data.text || '';
            return '<strong>Перевод (' + escHtml(target) + ')</strong><pre class="yvo-jpg-pdf-chat__ocr">' + escHtml(t) + '</pre>';
          });
        });
      }
    });
  }

  function bootAll() {
    document.querySelectorAll('.yvo-jpg-pdf-chat').forEach(function (root) {
      try {
        initRoot(root);
      } catch (err) {
        if (window.console && console.error) {
          console.error('YVO JPG PDF Chat init:', err);
        }
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootAll);
  } else {
    bootAll();
  }
})();
