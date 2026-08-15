(function () {
  function dbg(msg) {}

  function safeToast(msg, type) {
    try {
      if (typeof toast === 'function') {
        toast(msg, type);
      }
    } catch (e) {}
  }
  function getYvoCfg() {
    var o = typeof window.yvoDealCabinet !== 'undefined' ? window.yvoDealCabinet : null;
    if (o && o.ajaxUrl) {
      return o;
    }
    var el = document.querySelector('.yvo-deal-cabinet-shell');
    if (el) {
      var ajax = el.getAttribute('data-yvo-ajax-url');
      if (ajax) {
        return {
          ajaxUrl: ajax,
          nonce: el.getAttribute('data-yvo-nonce') || '',
          contractsUrl: el.getAttribute('data-yvo-contracts-url') || '',
          build: el.getAttribute('data-yvo-build') || ''
        };
      }
    }
    return o || {};
  }

  function parseAjaxJson(r) {
    var ct = r.headers.get('content-type') || '';
    if (ct.indexOf('application/json') !== -1) {
      return r.json();
    }
    return r.text().then(function (t) {
      var trimmed = (t || '').trim();
      if (trimmed.charAt(0) === '{' || trimmed.charAt(0) === '[') {
        try {
          return JSON.parse(trimmed);
        } catch (e) {}
      }
      throw new Error(trimmed ? trimmed.slice(0, 200) : 'Ответ не JSON');
    });
  }

  (function showBuildTag() {
    try {
      var cfg = getYvoCfg();
      var tag = document.getElementById('yvoDealBuildTag');
      if (!tag) return;
      var b = (cfg && cfg.build) ? String(cfg.build) : '';
      tag.textContent = b ? ('build ' + b) : 'build —';
    } catch (e) {}
  })();

  var PERSON_SCHEMA = [
    { key: 'fio', label: 'ФИО полностью' },
    { key: 'role', label: 'Роль (продавец, даритель, супруг …)' },
    { key: 'birthDate', label: 'Дата рождения' },
    { key: 'birthPlace', label: 'Место рождения' },
    { key: 'passport', label: 'Серия и номер паспорта' },
    { key: 'passportIssued', label: 'Кем выдан паспорт', type: 'textarea' },
    { key: 'passportDate', label: 'Дата выдачи паспорта' },
    { key: 'snils', label: 'СНИЛС' },
    { key: 'inn', label: 'ИНН' },
    { key: 'address', label: 'Адрес регистрации', type: 'textarea' },
    { key: 'phone', label: 'Телефон' },
    { key: 'email', label: 'E-mail' },
    { key: 'notes', label: 'Заметки', type: 'textarea' }
  ];

  var CONTRACT_SCHEMA = [
    { key: 'number', label: 'Номер / наименование договора' },
    { key: 'date', label: 'Дата договора' },
    { key: 'type', label: 'Тип (КП, дарение, задаток …)' },
    { key: 'subject', label: 'Предмет (кратко)' },
    { key: 'registrationTerm', label: 'Срок государственной регистрации' },
    { key: 'notes', label: 'Особые условия, примечания', type: 'textarea' }
  ];

  var OBJECT_SCHEMA = [
    { key: 'kind', label: 'Тип объекта' },
    { key: 'address', label: 'Адрес объекта', type: 'textarea' },
    { key: 'cadastre', label: 'Кадастровый номер' },
    { key: 'area', label: 'Площадь' },
    { key: 'encumbrance', label: 'Обременения (ипотека, арест …)', type: 'textarea' },
    { key: 'notes', label: 'Заметки по объекту', type: 'textarea' }
  ];

  var PARAMS_SCHEMA = [
    { key: 'price', label: 'Цена / сумма сделки, ₽' },
    { key: 'pay', label: 'Способ расчёта (наличные, ячейка, аккредитив …)' },
    { key: 'mortgage', label: 'Ипотека (банк / нет)' },
    { key: 'downPayment', label: 'Первоначальный взнос, ₽' },
    { key: 'creditAmount', label: 'Сумма кредита, ₽' },
    { key: 'bank', label: 'Банк / реквизиты расчёта (кратко)' },
    { key: 'cellTerms', label: 'Условия ячейки / аккредитива', type: 'textarea' },
    { key: 'settlementDate', label: 'Дата / этап расчёта' },
    { key: 'notes', label: 'Прочие параметры сделки', type: 'textarea' }
  ];

  var nextPersonUid = 1;
  var nextDealId = 4;

  function emptyPerson(uid) {
    var o = { _uid: uid || ('p' + (++nextPersonUid)) };
    PERSON_SCHEMA.forEach(function (f) {
      o[f.key] = '';
    });
    return o;
  }

  function emptyContract() {
    var o = {};
    CONTRACT_SCHEMA.forEach(function (f) {
      o[f.key] = '';
    });
    return o;
  }

  function emptyObject() {
    var o = {};
    OBJECT_SCHEMA.forEach(function (f) {
      o[f.key] = '';
    });
    return o;
  }

  function emptyParams() {
    var o = {};
    PARAMS_SCHEMA.forEach(function (f) {
      o[f.key] = '';
    });
    return o;
  }

  var DEALS = [
    {
      id: 1,
      active: true,
      status: 'черновик',
      contract: {
        number: 'ДКП № 1140-УФА',
        date: '15.03.2026',
        type: 'Купля-продажа',
        subject: 'Квартира',
        registrationTerm: '30 рабочих дней',
        notes: ''
      },
      sellers: [
        Object.assign(emptyPerson('p1'), {
          fio: 'Монавар Муслафа Гуль',
          role: 'Продавец',
          birthDate: '10.12.1990',
          birthPlace: 'г. Хост',
          passport: '80 10 174176',
          passportIssued: 'УФМС по Республике Башкортостан',
          passportDate: '15.06.2010',
          address: 'г. Уфа, ул. Престижная, 21',
          phone: '+7 …',
          email: ''
        })
      ],
      buyers: [
        Object.assign(emptyPerson('p2'), {
          fio: 'Ильмухаметов Ильгиз Ринатович',
          role: 'Покупатель',
          birthDate: '16.09.1989',
          birthPlace: 'д. 1-е Тукатово',
          passport: '80 09 847078',
          passportIssued: 'УФМС',
          passportDate: '01.01.2015',
          address: 'Кугарчинский р-н, ул. Мира, 19',
          phone: '+7 …',
          email: ''
        })
      ],
      object: {
        kind: 'Квартира',
        address: 'Республика Башкортостан, г. Уфа, ул. Престижная, 21',
        cadastre: '02:55:040571:2374',
        area: '63 м²',
        encumbrance: 'нет',
        notes: ''
      },
      params: {
        price: '1 140 000',
        pay: 'Банковская ячейка',
        mortgage: 'Сбербанк',
        downPayment: '171 000',
        creditAmount: '969 000',
        bank: 'ПАО Сбербанк',
        cellTerms: 'Выдача после регистрации перехода права',
        settlementDate: 'В день сделки',
        notes: ''
      },
      attachments: []
    },
    {
      id: 2,
      active: true,
      status: 'сбор документов',
      contract: { number: '—', date: '', type: 'КП', subject: 'Земля', registrationTerm: '', notes: '' },
      sellers: [Object.assign(emptyPerson('p3'), { fio: 'Петров Пётр Петрович', role: 'Продавец', phone: '—' })],
      buyers: [Object.assign(emptyPerson('p4'), { fio: 'Сидорова Анна Викторовна', role: 'Покупатель', phone: '—' })],
      object: { kind: 'Земельный участок', address: 'уч. Елкибаево', cadastre: '', area: '', encumbrance: '', notes: '' },
      params: { price: '', pay: '', mortgage: 'нет', downPayment: '', creditAmount: '', bank: '', cellTerms: '', settlementDate: '', notes: '' },
      attachments: []
    },
    {
      id: 3,
      active: false,
      status: 'архив',
      contract: { number: 'Задаток № 3', date: '01.01.2025', type: 'Задаток', subject: 'Офис', registrationTerm: '', notes: '' },
      sellers: [Object.assign(emptyPerson('p5'), { fio: 'ООО «Ромашка»', role: 'Продавец', inn: '027…', phone: '—' })],
      buyers: [Object.assign(emptyPerson('p6'), { fio: 'ИП Иванов', role: 'Покупатель', inn: '…', phone: '—' })],
      object: { kind: 'Офис', address: 'г. Уфа', cadastre: '', area: '', encumbrance: '', notes: '' },
      params: { price: '500 000', pay: 'наличные', mortgage: 'нет', downPayment: '', creditAmount: '', bank: '', cellTerms: '', settlementDate: '', notes: 'задаток' },
      attachments: []
    }
  ];

  function saveDealsToServer() {
    try {
      var cfg = getYvoCfg();
      if (!cfg || !cfg.ajaxUrl) return;
      var p = new URLSearchParams();
      p.append('action', 'yvo_deal_crm_save');
      p.append('nonce', cfg.nonce || '');
      p.append('deals', JSON.stringify(DEALS || []));
      fetch(cfg.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: p.toString(),
        credentials: 'same-origin'
      }).catch(function () {});
    } catch (e) {}
  }

  function loadDealsFromServer(cb) {
    var cfg = getYvoCfg();
    if (!cfg || !cfg.ajaxUrl) {
      if (cb) cb(false);
      return;
    }
    var p = new URLSearchParams();
    p.append('action', 'yvo_deal_crm_list');
    p.append('nonce', cfg.nonce || '');
    fetch(cfg.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: p.toString(),
      credentials: 'same-origin'
    })
      .then(parseAjaxJson)
      .then(function (res) {
        if (res && res.success && res.data && Array.isArray(res.data.deals) && res.data.deals.length) {
          // replace in-place
          DEALS.length = 0;
          res.data.deals.forEach(function (d) { DEALS.push(d); });
          if (cb) cb(true);
          return;
        }
        if (cb) cb(false);
      })
      .catch(function () { if (cb) cb(false); });
  }

  (function syncPersonUid() {
    var m = 0;
    DEALS.forEach(function (d) {
      function scan(arr) {
        arr.forEach(function (p) {
          var u = String(p._uid || '');
          var n = parseInt(u.replace(/\D/g, ''), 10);
          if (!isNaN(n) && n > m) m = n;
        });
      }
      scan(d.sellers || []);
      scan(d.buyers || []);
    });
    nextPersonUid = m;
  })();

  var LIST_COLUMNS = [
    { key: 'id', label: '#' },
    { key: 'contract', label: 'Договор' },
    { key: 'seller', label: 'Продавец' },
    { key: 'buyer', label: 'Покупатель' },
    { key: 'object', label: 'Объект' },
    { key: 'status', label: 'Статус' },
    { key: 'active', label: 'Активна', type: 'toggle' },
    { key: 'open', label: '', type: 'open' },
    { key: 'actions', label: '', type: 'actions' }
  ];

  var selectedDealId = null;
  var page = 1;
  var pageSize = 25;
  var searchQuery = '';
  var hideInactive = false;
  var modalMode = 'view';
  var editContext = null;
  var uploadTargetDealId = null;

  var tableHead = document.getElementById('tableHead');
  var tableBody = document.getElementById('tableBody');
  var mobileCards = document.getElementById('mobileCards');
  var pageTitle = document.getElementById('pageTitle');
  var statsEl = document.getElementById('stats');
  var rangeInfo = document.getElementById('rangeInfo');
  var pagination = document.getElementById('pagination');
  var filterBar = document.getElementById('filterBar');
  var modalOverlay = document.getElementById('modalOverlay');
  var modalBox = document.getElementById('modalBox');
  var modalTitle = document.getElementById('modalTitle');
  var modalBody = document.getElementById('modalBody');
  var modalSave = document.getElementById('modalSave');
  var modalCancel = document.getElementById('modalCancel');
  var modalClose = document.getElementById('modalClose');
  var fileUpload = document.getElementById('fileUpload');
  var toastWrap = document.getElementById('toastWrap');
  var screenList = document.getElementById('screenList');
  var screenDetail = document.getElementById('screenDetail');
  var dealDetailRoot = document.getElementById('dealDetailRoot');
  var dealDetailHeading = document.getElementById('dealDetailHeading');

  function toast(msg, type) {
    if (!toastWrap) {
      try {
        alert(msg);
      } catch (e) {}
      return;
    }
    var el = document.createElement('div');
    el.className = 'toast' + (type === 'ok' ? ' ok' : type === 'err' ? ' err' : '');
    el.textContent = msg;
    toastWrap.appendChild(el);
    setTimeout(function () {
      el.remove();
    }, type === 'err' ? 9000 : 3200);
  }

  window.addEventListener('error', function (e) {
    try {
      var m = 'JS error: ' + (e && e.message ? e.message : 'unknown');
      dbg(m);
      toast(m, 'err');
    } catch (err) {}
  });
  window.addEventListener('unhandledrejection', function (e) {
    try {
      var m = e && e.reason && e.reason.message ? e.reason.message : (e && e.reason ? String(e.reason) : 'unknown');
      var mm = 'Promise error: ' + m;
      dbg(mm);
      toast(mm, 'err');
    } catch (err) {}
  });

  function dealToListRow(d) {
    var s0 = (d.sellers && d.sellers[0]) || {};
    var b0 = (d.buyers && d.buyers[0]) || {};
    return {
      id: d.id,
      contract: (d.contract && d.contract.number) || '—',
      seller: s0.fio || '—',
      buyer: b0.fio || '—',
      object: (d.object && (d.object.address || d.object.kind)) || '—',
      status: d.status,
      active: d.active
    };
  }

  function dealSearchBlob(d) {
    try {
      return JSON.stringify(d).toLowerCase();
    } catch (e) {
      return '';
    }
  }

  function filteredRows() {
    var rows = DEALS.slice();
    if (hideInactive) {
      rows = rows.filter(function (r) { return r.active; });
    }
    if (searchQuery.trim()) {
      var q = searchQuery.trim().toLowerCase();
      rows = rows.filter(function (d) { return dealSearchBlob(d).indexOf(q) !== -1; });
    }
    return rows;
  }

  function findDealIndex(id) {
    var n = Number(id);
    for (var i = 0; i < DEALS.length; i++) {
      if (DEALS[i].id === n) return i;
    }
    return -1;
  }

  function getDeal(id) {
    var i = findDealIndex(id);
    return i >= 0 ? DEALS[i] : null;
  }

  function statusBadge(status) {
    if (!status) return '';
    var cls = 'badge-muted';
    if (status.indexOf('чернов') !== -1 || status.indexOf('сбор') !== -1) cls = 'badge-warn';
    if (status.indexOf('актив') !== -1) cls = 'badge-ok';
    return '<span class="badge ' + cls + '">' + escapeHtml(status) + '</span>';
  }

  function escapeHtml(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function escapeAttr(s) {
    return escapeHtml(s).replace(/"/g, '&quot;');
  }

  function normalizeAttachment(a) {
    if (typeof a === 'string') {
      return { name: a, url: '', id: '' };
    }
    if (a && typeof a === 'object') {
      return { name: a.name || a.file || '', url: a.url || '', id: a.id || '' };
    }
    return { name: String(a), url: '', id: '' };
  }

  function renderAttachmentsBlock(deal, did) {
    var list = deal.attachments || [];
    if (!list.length) {
      return '<p class="yvo-deal-attachments-empty">Нет прикреплённых файлов</p>';
    }
    var cfg = getYvoCfg();
    var html = '<ul class="yvo-deal-attachments-list">';
    for (var i = 0; i < list.length; i++) {
      var a = normalizeAttachment(list[i]);
      var title = escapeHtml(a.name || 'файл');
      html += '<li><span>' + title + '</span>';
      if (a.id && cfg && cfg.ajaxUrl && cfg.nonce) {
        var base =
          cfg.ajaxUrl +
          '?action=yvo_deal_download&nonce=' +
          encodeURIComponent(cfg.nonce) +
          '&deal_id=' +
          encodeURIComponent(String(did)) +
          '&file_id=' +
          encodeURIComponent(String(a.id));
        var view = base + '&disposition=inline';
        var dl = base + '&disposition=attachment';
        html += ' <a class="btn btn-secondary btn-sm" href="' + escapeAttr(view) + '" target="_blank" rel="noopener noreferrer">Посмотреть</a>';
        html += ' <a class="btn btn-secondary btn-sm" href="' + escapeAttr(dl) + '">Скачать</a>';
        html +=
          ' <button type="button" class="btn btn-secondary btn-sm" data-dl-convert="pdf" data-file-id="' +
          escapeAttr(String(a.id)) +
          '" data-deal-id="' +
          did +
          '">Фото→PDF</button>';
        html +=
          ' <button type="button" class="btn btn-secondary btn-sm" data-dl-convert="jpg" data-file-id="' +
          escapeAttr(String(a.id)) +
          '" data-deal-id="' +
          did +
          '">PDF→JPG</button>';
        html +=
          ' <button type="button" class="btn btn-secondary btn-sm" data-dl-del-file="1" data-file-id="' +
          escapeAttr(String(a.id)) +
          '" data-deal-id="' +
          did +
          '">Удалить</button>';
      } else if (!a.id) {
        html +=
          ' <button type="button" class="btn btn-secondary btn-sm" data-dl-sync="1">Синхронизировать</button>' +
          ' <span style="color:var(--muted);font-size:12px">файл без связи с сервером</span>';
      } else if (!cfg || !cfg.ajaxUrl || !cfg.nonce) {
        html +=
          ' <span style="color:var(--muted);font-size:12px">нет настроек AJAX (build ' +
          escapeHtml(cfg && cfg.build ? cfg.build : '—') +
          ')</span>';
      }
      html += '</li>';
    }
    html += '</ul>';
    return html;
  }

  function convertDealFile(dealId, fileId, target, deal) {
    var yvo = getYvoCfg();
    if (!yvo.ajaxUrl) {
      toast('Конвертация недоступна', 'err');
      return;
    }
    var p = new URLSearchParams();
    p.append('action', 'yvo_deal_convert');
    p.append('nonce', yvo.nonce || '');
    p.append('deal_id', String(dealId));
    p.append('file_id', String(fileId));
    p.append('target', String(target));
    fetch(yvo.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: p.toString(),
      credentials: 'same-origin'
    })
      .then(function (r) {
        return parseAjaxJson(r);
      })
      .then(function (res) {
        if (res.success && res.data && res.data.file) {
          if (!deal.attachments) deal.attachments = [];
          deal.attachments.push({ name: res.data.file.name, url: res.data.file.url, id: res.data.file.id });
          toast('Конвертировано', 'ok');
          renderDealDetail();
          renderListOnly();
        } else {
          toast(res.data && res.data.message ? res.data.message : 'Ошибка конвертации', 'err');
        }
      })
      .catch(function (err) {
        toast(err && err.message ? err.message : 'Ошибка сети', 'err');
      });
  }

  function mergeServerFiles(files) {
    if (!files || !files.length) return;
    for (var i = 0; i < files.length; i++) {
      var f = files[i];
      var deal = getDeal(f.deal_id);
      if (!deal) continue;
      if (!deal.attachments) deal.attachments = [];
      var replaced = false;
      for (var j = 0; j < deal.attachments.length; j++) {
        var a = deal.attachments[j];
        if (typeof a === 'object') {
          if (a.id && f.id && String(a.id) === String(f.id)) {
            // already up-to-date
            replaced = true;
            break;
          }
          if (a.url && f.url && a.url === f.url) {
            // already up-to-date
            replaced = true;
            break;
          }
          // upgrade incomplete record by name match
          if ((a.name || a.file) && f.name && String(a.name || a.file) === String(f.name)) {
            deal.attachments[j] = { name: f.name, url: f.url, id: f.id };
            replaced = true;
            break;
          }
        }
        if (typeof a === 'string' && f.name && a === f.name) {
          // upgrade legacy string to object (so buttons appear)
          deal.attachments[j] = { name: f.name, url: f.url, id: f.id };
          replaced = true;
          break;
        }
      }
      if (!replaced) {
        deal.attachments.push({ name: f.name, url: f.url, id: f.id });
      }
    }
  }

  function loadServerFiles(cb) {
    var cfg = getYvoCfg();
    if (!cfg.ajaxUrl) {
      if (cb) cb();
      return;
    }
    var p = new URLSearchParams();
    p.append('action', 'yvo_deal_list');
    p.append('nonce', cfg.nonce || '');
    fetch(cfg.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: p.toString(),
      credentials: 'same-origin'
    })
      .then(function (r) {
        return parseAjaxJson(r);
      })
      .then(function (res) {
        if (!res.success) {
          toast(res.data && res.data.message ? res.data.message : 'Не удалось загрузить список файлов', 'err');
        } else if (res.data && res.data.files) {
          mergeServerFiles(res.data.files);
        }
        if (selectedDealId != null) renderDealDetail();
        renderListOnly();
        if (cb) cb();
      })
      .catch(function (err) {
        toast(err && err.message ? err.message : 'Ошибка сети (список файлов)', 'err');
        if (cb) cb();
      });
  }

  function uploadFilesSequential(dealNumericId, deal, files) {
    if (!deal) return;
    if (!deal.attachments) deal.attachments = [];
    var yvo = getYvoCfg();
    dbg('uploadFilesSequential deal_id=' + dealNumericId + ' files=' + (files ? files.length : 0));
    if (!yvo.ajaxUrl) {
      toast('Загрузка на сервер недоступна', 'err');
      return;
    }
    var done = 0;
    function next(i) {
      if (i >= files.length) {
        if (done) toast('Загружено файлов: ' + done, 'ok');
        if (selectedDealId != null) renderDealDetail();
        renderListOnly();
        return;
      }
      var fd = new FormData();
      fd.append('action', 'yvo_deal_upload');
      fd.append('nonce', yvo.nonce || '');
      fd.append('deal_id', String(dealNumericId));
      fd.append('file', files[i]);
      toast('Загрузка: ' + (files[i] && files[i].name ? files[i].name : 'файл'), '');
      dbg('fetch -> ' + yvo.ajaxUrl);
      fetch(yvo.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          if (!r.ok) {
            return r.text().then(function (t) {
              throw new Error('HTTP ' + r.status + ': ' + (t ? t.slice(0, 200) : ''));
            });
          }
          return parseAjaxJson(r);
        })
        .then(function (res) {
          dbg('upload response success=' + String(!!res.success));
          if (res.success && res.data) {
            done++;
            deal.attachments.push({
              name: res.data.name,
              url: res.data.url,
              id: res.data.id
            });
          } else {
            var msg = res.data && res.data.message ? res.data.message : 'Ошибка загрузки';
            toast(msg, 'err');
          }
          next(i + 1);
        })
        .catch(function (err) {
          dbg('upload error: ' + (err && err.message ? err.message : String(err)));
          toast(err && err.message ? err.message : 'Ошибка сети', 'err');
          next(i + 1);
        });
    }
    next(0);
  }

  function deleteDealFile(dealId, fileId, deal) {
    var yvo = getYvoCfg();
    if (!yvo.ajaxUrl) {
      toast('Удаление на сервере недоступно', 'err');
      return;
    }
    var p = new URLSearchParams();
    p.append('action', 'yvo_deal_delete');
    p.append('nonce', yvo.nonce || '');
    p.append('deal_id', String(dealId));
    p.append('file_id', String(fileId));
    fetch(yvo.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: p.toString(),
      credentials: 'same-origin'
    })
      .then(function (r) {
        return parseAjaxJson(r);
      })
      .then(function (res) {
        if (res.success) {
          if (deal.attachments) {
            for (var i = deal.attachments.length - 1; i >= 0; i--) {
              var a = deal.attachments[i];
              if (typeof a === 'object' && a.id && String(a.id) === String(fileId)) {
                deal.attachments.splice(i, 1);
              }
            }
          }
          toast('Файл удалён', 'ok');
          renderDealDetail();
          renderListOnly();
        } else {
          toast(res.data && res.data.message ? res.data.message : 'Ошибка', 'err');
        }
      })
      .catch(function (err) {
        toast(err && err.message ? err.message : 'Ошибка сети', 'err');
      });
  }

  function actionButtons(rowId) {
    var id = escapeHtml(String(rowId));
    return (
      '<span class="act-btns">' +
      '<button type="button" class="act" title="Открыть карточку" data-act="open" data-id="' + id + '"><svg><use href="#i-eye"/></svg></button>' +
      '<button type="button" class="act" title="Скачать JSON сделки" data-act="down" data-id="' + id + '"><svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg></button>' +
      '<button type="button" class="act" title="Копировать сделку" data-act="copy" data-id="' + id + '"><svg><use href="#i-copy"/></svg></button>' +
      '<button type="button" class="act act-danger" title="Удалить сделку" data-act="del" data-id="' + id + '"><svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg></button>' +
      '<button type="button" class="act" title="Файлы к сделке" data-act="up" data-id="' + id + '"><svg><use href="#i-up"/></svg></button>' +
      '</span>'
    );
  }

  var DEAL_META_SCHEMA = [
    { key: 'status', label: 'Статус сделки' },
    { key: 'active', label: 'Сделка активна', type: 'checkbox' }
  ];

  function buildFormFromSchema(schema, obj) {
    var html = '';
    var gridOpen = false;
    for (var i = 0; i < schema.length; i++) {
      var f = schema[i];
      var val = obj[f.key];
      var id = 'fld-' + f.key;
      var isTa = f.type === 'textarea';
      if (isTa && gridOpen) {
        html += '</div>';
        gridOpen = false;
      }
      if (!isTa && !gridOpen) {
        html += '<div class="form-grid-2">';
        gridOpen = true;
      }
      if (isTa && gridOpen) {
        html += '</div>';
        gridOpen = false;
      }
      html += '<div class="form-row">';
      html += '<label for="' + id + '">' + escapeHtml(f.label) + '</label>';
      if (f.type === 'checkbox') {
        html += '<input type="checkbox" id="' + id + '" data-field="' + escapeAttr(f.key) + '"' + (val ? ' checked' : '') + ' />';
      } else if (isTa) {
        html += '<textarea id="' + id + '" data-field="' + escapeAttr(f.key) + '">' + escapeHtml(val != null ? String(val) : '') + '</textarea>';
      } else {
        html += '<input type="text" id="' + id + '" data-field="' + escapeAttr(f.key) + '" value="' + escapeAttr(val != null ? String(val) : '') + '" />';
      }
      html += '</div>';
    }
    if (gridOpen) html += '</div>';
    return html;
  }

  function openModalEditContext(ctx, title, schema, obj) {
    editContext = ctx;
    modalMode = 'edit';
    modalTitle.textContent = title;
    modalSave.style.display = 'inline-block';
    modalCancel.textContent = 'Отмена';
    modalBox.classList.toggle('modal--wide', schema.length > 5);
    modalBody.innerHTML = buildFormFromSchema(schema, obj);
    modalOverlay.classList.add('is-open');
    modalOverlay.setAttribute('aria-hidden', 'false');
  }

  function openModalViewSchema(title, schema, obj) {
    editContext = null;
    modalMode = 'view';
    modalTitle.textContent = title;
    modalSave.style.display = 'none';
    modalCancel.textContent = 'Закрыть';
    modalBox.classList.toggle('modal--wide', schema.length > 5);
    var parts = [];
    for (var i = 0; i < schema.length; i++) {
      var f = schema[i];
      var v = obj[f.key];
      if (f.type === 'checkbox') v = v ? 'да' : 'нет';
      parts.push('<dt>' + escapeHtml(f.label) + '</dt><dd>' + escapeHtml(v != null && v !== '' ? String(v) : '—') + '</dd>');
    }
    modalBody.innerHTML = '<dl class="view-dl">' + parts.join('') + '</dl>';
    modalOverlay.classList.add('is-open');
    modalOverlay.setAttribute('aria-hidden', 'false');
  }

  function closeModal() {
    modalOverlay.classList.remove('is-open');
    modalOverlay.setAttribute('aria-hidden', 'true');
    editContext = null;
    modalBox.classList.remove('modal--wide');
  }

  function collectFormRow() {
    var inputs = modalBody.querySelectorAll('[data-field]');
    var o = {};
    for (var i = 0; i < inputs.length; i++) {
      var el = inputs[i];
      var key = el.getAttribute('data-field');
      if (el.type === 'checkbox') o[key] = el.checked;
      else o[key] = el.value.trim();
    }
    return o;
  }

  function findPersonInDeal(deal, role, uid) {
    var arr = role === 'seller' ? deal.sellers : deal.buyers;
    for (var i = 0; i < arr.length; i++) {
      if (String(arr[i]._uid) === String(uid)) return { person: arr[i], index: i, arr: arr };
    }
    return null;
  }

  function saveModal() {
    if (!editContext) {
      closeModal();
      return;
    }
    var data = collectFormRow();
    var deal = getDeal(editContext.dealId);
    if (!deal) {
      closeModal();
      return;
    }
    var t = editContext.type;
    if (t === 'dealMeta') {
      deal.status = data.status || '';
      deal.active = !!data.active;
      toast('Данные сделки сохранены', 'ok');
    } else if (t === 'contract') {
      Object.assign(deal.contract, data);
      toast('Договор сохранён', 'ok');
    } else if (t === 'object') {
      Object.assign(deal.object, data);
      toast('Объект сохранён', 'ok');
    } else if (t === 'params') {
      Object.assign(deal.params, data);
      toast('Параметры сделки сохранены', 'ok');
    } else if (t === 'seller' || t === 'buyer') {
      var found = findPersonInDeal(deal, t, editContext.uid);
      if (found) {
        var keepUid = found.person._uid;
        Object.assign(found.person, data);
        found.person._uid = keepUid;
        toast('Карточка сохранена', 'ok');
      }
    }
    closeModal();
    if (selectedDealId != null) renderDealDetail();
    renderListOnly();
    saveDealsToServer();
  }

  function createEmptyDeal() {
    return {
      id: nextDealId++,
      active: true,
      status: 'черновик',
      contract: emptyContract(),
      sellers: [emptyPerson()],
      buyers: [emptyPerson()],
      object: emptyObject(),
      params: emptyParams(),
      attachments: []
    };
  }

  function cloneDeal(deal) {
    var d = JSON.parse(JSON.stringify(deal));
    d.id = nextDealId++;
    if (d.contract && d.contract.number) d.contract.number = d.contract.number + ' (копия)';
    function remap(arr) {
      (arr || []).forEach(function (p) {
        p._uid = 'p' + (++nextPersonUid);
      });
    }
    remap(d.sellers);
    remap(d.buyers);
    return d;
  }

  function runListAction(act, id) {
    var deal = getDeal(id);
    if (act === 'open') {
      if (!deal) return;
      selectedDealId = deal.id;
      screenList.style.display = 'none';
      screenDetail.style.display = 'block';
      renderDealDetail();
      return;
    }
    if (!deal && act !== 'copy') {
      toast('Сделка не найдена', 'err');
      return;
    }
    if (act === 'copy') {
      if (!deal) return;
      DEALS.push(cloneDeal(deal));
      toast('Сделка скопирована · #' + DEALS[DEALS.length - 1].id, 'ok');
      renderListOnly();
      return;
    }
    if (act === 'del') {
      if (!confirm('Удалить сделку #' + id + '?')) return;
      var ix = findDealIndex(id);
      if (ix >= 0) {
        DEALS.splice(ix, 1);
        if (Number(selectedDealId) === Number(id)) {
          selectedDealId = null;
          screenDetail.style.display = 'none';
          screenList.style.display = 'block';
        }
        toast('Удалено', 'ok');
        renderListOnly();
      }
      return;
    }
    if (act === 'up') {
      uploadTargetDealId = id;
      fileUpload.value = '';
      fileUpload.click();
      return;
    }
    if (act === 'down') {
      var blob = new Blob([JSON.stringify(deal, null, 2)], { type: 'application/json;charset=utf-8' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'deal-' + id + '.json';
      a.click();
      URL.revokeObjectURL(a.href);
      toast('Файл скачан', 'ok');
    }
  }

  function renderHead() {
    var cols = LIST_COLUMNS;
    var html = '<tr>';
    html += '<th class="col-check"><input type="checkbox" id="checkAll" title="Выбрать все" /></th>';
    for (var i = 0; i < cols.length; i++) {
      var c = cols[i];
      if (c.type === 'actions') {
        html += '<th class="col-actions">' + (c.label || 'Действия') + '</th>';
      } else if (c.type === 'open') {
        html += '<th>Карточка</th>';
      } else {
        html += '<th>' + escapeHtml(c.label) + '</th>';
      }
    }
    html += '</tr>';
    tableHead.innerHTML = html;
  }

  function renderBody(slice) {
    var cols = LIST_COLUMNS;
    var html = '';
    for (var r = 0; r < slice.length; r++) {
      var deal = slice[r];
      var row = dealToListRow(deal);
      html += '<tr data-id="' + escapeHtml(String(deal.id)) + '">';
      html += '<td class="col-check"><input type="checkbox" class="row-check" data-id="' + escapeHtml(String(deal.id)) + '" /></td>';
      for (var c = 0; c < cols.length; c++) {
        var col = cols[c];
        var key = col.key;
        if (col.type === 'toggle') {
          var on = deal[key] ? ' on' : '';
          html += '<td><button type="button" class="toggle' + on + '" data-toggle="active" data-id="' + escapeHtml(String(deal.id)) + '" aria-label="Активна"></button></td>';
        } else if (col.type === 'open') {
          html += '<td><button type="button" class="btn btn-primary btn-sm" data-act="open" data-id="' + escapeHtml(String(deal.id)) + '">Открыть</button></td>';
        } else if (col.type === 'actions') {
          html += '<td class="col-actions">' + actionButtons(deal.id) + '</td>';
        } else if (key === 'status') {
          html += '<td>' + statusBadge(row[key]) + '</td>';
        } else {
          html += '<td title="' + escapeHtml(String(row[key] != null ? row[key] : '')) + '">' + escapeHtml(String(row[key] != null ? row[key] : '—')) + '</td>';
        }
      }
      html += '</tr>';
    }
    tableBody.innerHTML = html || '<tr><td colspan="' + (cols.length + 1) + '" style="padding:24px;text-align:center;color:#6b7280">Нет данных по фильтру</td></tr>';

    var checkAll = document.getElementById('checkAll');
    if (checkAll) {
      checkAll.addEventListener('change', function (e) {
        var boxes = document.querySelectorAll('.row-check');
        for (var i = 0; i < boxes.length; i++) boxes[i].checked = e.target.checked;
      });
    }
  }

  function renderMobile(slice) {
    var cols = LIST_COLUMNS.filter(function (c) {
      return c.type !== 'actions' && c.type !== 'open' && c.key !== 'id';
    });
    var html = '';
    for (var r = 0; r < slice.length; r++) {
      var deal = slice[r];
      var row = dealToListRow(deal);
      html += '<div class="m-card">';
      html += '<div class="m-row"><span class="m-k">#</span><span class="m-v">' + escapeHtml(String(deal.id)) + '</span></div>';
      for (var i = 0; i < cols.length; i++) {
        var col = cols[i];
        if (col.type === 'toggle') continue;
        html += '<div class="m-row"><span class="m-k">' + escapeHtml(col.label) + '</span><span class="m-v">' + escapeHtml(String(row[col.key] != null ? row[col.key] : '—')) + '</span></div>';
      }
      html += '<div class="m-row" style="margin-top:8px"><button type="button" class="btn btn-primary btn-sm" data-act="open" data-id="' + escapeHtml(String(deal.id)) + '">Открыть карточку</button></div>';
      html += '<div class="m-row" style="margin-top:8px">' + actionButtons(deal.id) + '</div>';
      html += '</div>';
    }
    mobileCards.innerHTML = html || '<div class="m-card" style="color:#6b7280;text-align:center">Нет данных</div>';
  }

  function computeStats() {
    var rows = DEALS;
    return [
      { num: rows.length, lbl: 'Всего сделок' },
      { num: rows.filter(function (r) { return r.active; }).length, lbl: 'Активные' },
      { num: rows.filter(function (r) { return !r.active; }).length, lbl: 'В архиве' }
    ];
  }

  function renderStats() {
    var s = computeStats();
    var html = '';
    for (var i = 0; i < s.length; i++) {
      html += '<div class="stat-card"><div class="num">' + s[i].num + '</div><div class="lbl">' + escapeHtml(s[i].lbl) + '</div></div>';
    }
    statsEl.innerHTML = html;
  }

  function renderPagination(total) {
    var pages = Math.max(1, Math.ceil(total / pageSize));
    if (page > pages) page = pages;
    var start = total === 0 ? 0 : (page - 1) * pageSize + 1;
    var end = Math.min(page * pageSize, total);
    rangeInfo.textContent = total ? 'Показано ' + start + '–' + end + ' из ' + total : 'Нет записей';

    var html = '';
    html += '<button type="button" ' + (page <= 1 ? 'disabled' : '') + ' data-p="prev">Назад</button>';
    html += '<button type="button" class="is-current">' + page + '</button>';
    html += '<button type="button" ' + (page >= pages ? 'disabled' : '') + ' data-p="next">Вперёд</button>';
    pagination.innerHTML = html;

    pagination.querySelector('[data-p="prev"]').addEventListener('click', function () {
      if (page > 1) { page--; renderListOnly(); }
    });
    pagination.querySelector('[data-p="next"]').addEventListener('click', function () {
      if (page < pages) { page++; renderListOnly(); }
    });
  }

  function renderListOnly() {
    pageTitle.textContent = 'Все сделки';
    renderStats();
    renderHead();
    var all = filteredRows();
    var total = all.length;
    var slice = all.slice((page - 1) * pageSize, page * pageSize);
    renderBody(slice);
    renderMobile(slice);
    renderPagination(total);
    filterBar.style.display = 'flex';
  }

  function dlFromObject(obj, keys) {
    var html = '<dl class="view-dl">';
    keys.forEach(function (k) {
      html += '<dt>' + escapeHtml(k.label) + '</dt><dd>' + escapeHtml(obj[k.key] != null && obj[k.key] !== '' ? String(obj[k.key]) : '—') + '</dd>';
    });
    html += '</dl>';
    return html;
  }

  function renderDealDetail() {
    var deal = getDeal(selectedDealId);
    if (!deal) {
      selectedDealId = null;
      screenDetail.style.display = 'none';
      screenList.style.display = 'block';
      renderListOnly();
      return;
    }
    dealDetailHeading.textContent = 'Сделка #' + deal.id + ' · ' + (deal.contract.number || 'без названия');

    var did = escapeHtml(String(deal.id));
    var h = '';

    h += '<div class="entity-panel">';
    h += '<div class="entity-panel__head"><h2>Сделка · общие данные</h2><div class="person-actions">';
    h += '<button type="button" class="btn btn-secondary btn-sm" data-dl-view="dealMeta" data-deal-id="' + did + '">Просмотр</button>';
    h += '<button type="button" class="btn btn-primary btn-sm" data-dl-edit="dealMeta" data-deal-id="' + did + '">Редактировать</button>';
    h += '</div></div>';
    h += '<div class="entity-panel__body"><p><strong>Статус:</strong> ' + statusBadge(deal.status) + ' &nbsp; <strong>Активна:</strong> ' + (deal.active ? 'да' : 'нет') + '</p></div></div>';

    h += '<div class="entity-panel">';
    h += '<div class="entity-panel__head"><h2>Договор</h2><div class="person-actions">';
    h += '<button type="button" class="btn btn-secondary btn-sm" data-dl-view="contract" data-deal-id="' + did + '">Просмотр</button>';
    h += '<button type="button" class="btn btn-primary btn-sm" data-dl-edit="contract" data-deal-id="' + did + '">Редактировать</button>';
    h += '</div></div>';
    h += '<div class="entity-panel__body">' + dlFromObject(deal.contract, [
      { key: 'number', label: 'Номер' }, { key: 'date', label: 'Дата' }, { key: 'type', label: 'Тип' },
      { key: 'subject', label: 'Предмет' }, { key: 'registrationTerm', label: 'Срок регистрации' }
    ]) + '</div></div>';

    h += '<div class="entity-panel">';
    h += '<div class="entity-panel__head"><h2>Продавцы и дарители</h2>';
    h += '<button type="button" class="btn btn-secondary btn-sm" data-dl-add-person="seller" data-deal-id="' + did + '">+ Добавить</button></div>';
    h += '<div class="entity-panel__body">';
    (deal.sellers || []).forEach(function (p, idx) {
      h += '<div class="person-block"><div class="person-block__top"><div><div class="person-block__name">' + escapeHtml(p.fio || 'Без ФИО') + '</div>';
      h += '<div style="color:var(--muted);font-size:11px">' + escapeHtml(p.role || '') + ' · паспорт ' + escapeHtml(p.passport || '—') + '</div></div>';
      h += '<div class="person-actions">';
      h += '<button type="button" class="btn btn-secondary btn-sm" data-dl-view-person="seller" data-deal-id="' + did + '" data-uid="' + escapeHtml(String(p._uid)) + '">Просмотр</button>';
      h += '<button type="button" class="btn btn-primary btn-sm" data-dl-edit-person="seller" data-deal-id="' + did + '" data-uid="' + escapeHtml(String(p._uid)) + '">Редактировать</button>';
      h += '<button type="button" class="btn btn-secondary btn-sm" data-dl-del-person="seller" data-deal-id="' + did + '" data-uid="' + escapeHtml(String(p._uid)) + '">Удалить</button>';
      h += '</div></div></div>';
    });
    h += '</div></div>';

    h += '<div class="entity-panel">';
    h += '<div class="entity-panel__head"><h2>Покупатели</h2>';
    h += '<button type="button" class="btn btn-secondary btn-sm" data-dl-add-person="buyer" data-deal-id="' + did + '">+ Добавить</button></div>';
    h += '<div class="entity-panel__body">';
    (deal.buyers || []).forEach(function (p) {
      h += '<div class="person-block"><div class="person-block__top"><div><div class="person-block__name">' + escapeHtml(p.fio || 'Без ФИО') + '</div>';
      h += '<div style="color:var(--muted);font-size:11px">' + escapeHtml(p.role || '') + ' · паспорт ' + escapeHtml(p.passport || '—') + '</div></div>';
      h += '<div class="person-actions">';
      h += '<button type="button" class="btn btn-secondary btn-sm" data-dl-view-person="buyer" data-deal-id="' + did + '" data-uid="' + escapeHtml(String(p._uid)) + '">Просмотр</button>';
      h += '<button type="button" class="btn btn-primary btn-sm" data-dl-edit-person="buyer" data-deal-id="' + did + '" data-uid="' + escapeHtml(String(p._uid)) + '">Редактировать</button>';
      h += '<button type="button" class="btn btn-secondary btn-sm" data-dl-del-person="buyer" data-deal-id="' + did + '" data-uid="' + escapeHtml(String(p._uid)) + '">Удалить</button>';
      h += '</div></div></div>';
    });
    h += '</div></div>';

    h += '<div class="entity-panel">';
    h += '<div class="entity-panel__head"><h2>Объект недвижимости</h2><div class="person-actions">';
    h += '<button type="button" class="btn btn-secondary btn-sm" data-dl-view="object" data-deal-id="' + did + '">Просмотр</button>';
    h += '<button type="button" class="btn btn-primary btn-sm" data-dl-edit="object" data-deal-id="' + did + '">Редактировать</button>';
    h += '</div></div>';
    h += '<div class="entity-panel__body">' + dlFromObject(deal.object, [
      { key: 'kind', label: 'Тип' }, { key: 'address', label: 'Адрес' }, { key: 'cadastre', label: 'Кадастр' }, { key: 'area', label: 'Площадь' }
    ]) + '</div></div>';

    h += '<div class="entity-panel">';
    h += '<div class="entity-panel__head"><h2>Параметры сделки</h2><div class="person-actions">';
    h += '<button type="button" class="btn btn-secondary btn-sm" data-dl-view="params" data-deal-id="' + did + '">Просмотр</button>';
    h += '<button type="button" class="btn btn-primary btn-sm" data-dl-edit="params" data-deal-id="' + did + '">Редактировать</button>';
    h += '</div></div>';
    h += '<div class="entity-panel__body">' + dlFromObject(deal.params, [
      { key: 'price', label: 'Цена, ₽' }, { key: 'pay', label: 'Расчёт' }, { key: 'mortgage', label: 'Ипотека' },
      { key: 'downPayment', label: 'Взнос' }, { key: 'creditAmount', label: 'Кредит' }, { key: 'bank', label: 'Банк' },
      { key: 'settlementDate', label: 'Срок расчёта' }
    ]) + '</div></div>';

    h += '<div class="entity-panel">';
    h += '<div class="entity-panel__head"><h2>Файлы сделки</h2>';
    h += '<button type="button" class="btn btn-primary btn-sm" data-dl-upload="' + did + '">Загрузить</button></div>';
    h += '<div class="entity-panel__body">' + renderAttachmentsBlock(deal, did) + '</div></div>';

    dealDetailRoot.innerHTML = h;
  }

  function handleTableAction(e) {
    var t = e.target.closest('[data-toggle]');
    if (t && t.classList.contains('toggle')) {
      e.preventDefault();
      var rid = t.getAttribute('data-id');
      var deal = getDeal(rid);
      if (deal) {
        deal.active = !deal.active;
        t.classList.toggle('on', deal.active);
        renderStats();
      }
      return;
    }
    var act = e.target.closest('[data-act]');
    if (act) {
      e.preventDefault();
      runListAction(act.getAttribute('data-act'), act.getAttribute('data-id'));
    }
  }

  function handleDealDetailClick(e) {
    var dealId = selectedDealId;
    var deal = getDeal(dealId);
    if (!deal) return;

    var uploadBtn = e.target.closest('[data-dl-upload]');
    if (uploadBtn) {
      uploadTargetDealId = uploadBtn.getAttribute('data-dl-upload');
      toast('Выберите файл для загрузки…', '');
      dbg('upload button click deal_id=' + uploadTargetDealId);
      fileUpload.value = '';
      fileUpload.click();
      return;
    }

    var delFile = e.target.closest('[data-dl-del-file]');
    if (delFile) {
      e.preventDefault();
      if (!confirm('Удалить файл с сервера?')) return;
      var fid = delFile.getAttribute('data-file-id');
      var dnumeric = Number(delFile.getAttribute('data-deal-id'));
      deleteDealFile(dnumeric, fid, deal);
      return;
    }

    var cvt = e.target.closest('[data-dl-convert]');
    if (cvt) {
      e.preventDefault();
      var target = cvt.getAttribute('data-dl-convert');
      var fid2 = cvt.getAttribute('data-file-id');
      var did2 = Number(cvt.getAttribute('data-deal-id'));
      convertDealFile(did2, fid2, target, deal);
      return;
    }

    var sync = e.target.closest('[data-dl-sync]');
    if (sync) {
      e.preventDefault();
      loadServerFiles(function () {
        toast('Синхронизировано', 'ok');
      });
      return;
    }

    var v = e.target.closest('[data-dl-view]');
    if (v) {
      var block = v.getAttribute('data-dl-view');
      if (block === 'dealMeta') {
        openModalViewSchema('Сделка · просмотр', DEAL_META_SCHEMA, { status: deal.status, active: deal.active });
      } else if (block === 'contract') {
        openModalViewSchema('Договор · просмотр', CONTRACT_SCHEMA, deal.contract);
      } else if (block === 'object') {
        openModalViewSchema('Объект · просмотр', OBJECT_SCHEMA, deal.object);
      } else if (block === 'params') {
        openModalViewSchema('Параметры сделки · просмотр', PARAMS_SCHEMA, deal.params);
      }
      return;
    }

    var ed = e.target.closest('[data-dl-edit]');
    if (ed) {
      var b = ed.getAttribute('data-dl-edit');
      if (b === 'dealMeta') {
        openModalEditContext({ type: 'dealMeta', dealId: deal.id }, 'Редактировать · общие данные сделки', DEAL_META_SCHEMA, { status: deal.status, active: deal.active });
      } else if (b === 'contract') {
        openModalEditContext({ type: 'contract', dealId: deal.id }, 'Редактировать · договор', CONTRACT_SCHEMA, deal.contract);
      } else if (b === 'object') {
        openModalEditContext({ type: 'object', dealId: deal.id }, 'Редактировать · объект', OBJECT_SCHEMA, deal.object);
      } else if (b === 'params') {
        openModalEditContext({ type: 'params', dealId: deal.id }, 'Редактировать · параметры сделки', PARAMS_SCHEMA, deal.params);
      }
      return;
    }

    var vp = e.target.closest('[data-dl-view-person]');
    if (vp) {
      var role = vp.getAttribute('data-dl-view-person');
      var uid = vp.getAttribute('data-uid');
      var found = findPersonInDeal(deal, role, uid);
      if (found) openModalViewSchema((role === 'seller' ? 'Продавец' : 'Покупатель') + ' · просмотр', PERSON_SCHEMA, found.person);
      return;
    }

    var ep = e.target.closest('[data-dl-edit-person]');
    if (ep) {
      var role2 = ep.getAttribute('data-dl-edit-person');
      var uid2 = ep.getAttribute('data-uid');
      var found2 = findPersonInDeal(deal, role2, uid2);
      if (found2) {
        openModalEditContext({ type: role2, dealId: deal.id, uid: uid2 }, 'Редактировать · ' + (role2 === 'seller' ? 'продавец' : 'покупатель'), PERSON_SCHEMA, found2.person);
      }
      return;
    }

    var add = e.target.closest('[data-dl-add-person]');
    if (add) {
      var role3 = add.getAttribute('data-dl-add-person');
      var np = emptyPerson();
      if (role3 === 'seller') {
        np.role = 'Продавец';
        deal.sellers.push(np);
      } else {
        np.role = 'Покупатель';
        deal.buyers.push(np);
      }
      toast('Добавлена новая карточка', 'ok');
      renderDealDetail();
      return;
    }

    var delp = e.target.closest('[data-dl-del-person]');
    if (delp) {
      var role4 = delp.getAttribute('data-dl-del-person');
      var uid4 = delp.getAttribute('data-uid');
      var arr = role4 === 'seller' ? deal.sellers : deal.buyers;
      if (arr.length <= 1) {
        toast('Нужна минимум одна карточка', 'err');
        return;
      }
      var fi = -1;
      for (var zi = 0; zi < arr.length; zi++) {
        if (String(arr[zi]._uid) === String(uid4)) { fi = zi; break; }
      }
      if (fi >= 0) {
        arr.splice(fi, 1);
        toast('Удалено', 'ok');
        renderDealDetail();
      }
    }
  }

  document.getElementById('navDealsList').addEventListener('click', function () {
    selectedDealId = null;
    screenDetail.style.display = 'none';
    screenList.style.display = 'block';
    renderListOnly();
  });

  document.getElementById('btnBackList').addEventListener('click', function () {
    selectedDealId = null;
    screenDetail.style.display = 'none';
    screenList.style.display = 'block';
    renderListOnly();
  });

  document.getElementById('tableSearch').addEventListener('input', function (e) {
    searchQuery = e.target.value;
    page = 1;
    renderListOnly();
  });

  document.getElementById('globalSearch').addEventListener('input', function (e) {
    searchQuery = e.target.value;
    document.getElementById('tableSearch').value = e.target.value;
    page = 1;
    renderListOnly();
  });

  document.getElementById('filterInactive').addEventListener('change', function (e) {
    hideInactive = e.target.checked;
    page = 1;
    renderListOnly();
  });

  document.getElementById('pageSize').addEventListener('change', function (e) {
    pageSize = parseInt(e.target.value, 10);
    page = 1;
    renderListOnly();
  });

  document.getElementById('btnRefresh').addEventListener('click', function () {
    loadServerFiles(function () {
      toast('Обновлено', 'ok');
    });
  });

  document.getElementById('btnNew').addEventListener('click', function () {
    var d = createEmptyDeal();
    DEALS.push(d);
    selectedDealId = d.id;
    screenList.style.display = 'none';
    screenDetail.style.display = 'block';
    renderDealDetail();
    renderListOnly();
    toast('Создана сделка #' + d.id, 'ok');
  });

  document.getElementById('btnImport').addEventListener('click', function () {
    toast('Импорт — подключите API или загрузку JSON позже', 'ok');
  });

  document.getElementById('btnContacts').addEventListener('click', function () {
    toast('Контакты — откройте карточку сделки', 'ok');
  });

  document.getElementById('btnExport').addEventListener('click', function () {
    var rows = filteredRows();
    var payload = { exportedAt: new Date().toISOString(), deals: rows };
    var blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'export-deals.json';
    a.click();
    URL.revokeObjectURL(a.href);
    toast('Экспорт: ' + rows.length + ' сделок', 'ok');
  });

  document.getElementById('btnBulk').addEventListener('click', function () {
    var checked = document.querySelectorAll('.row-check:checked');
    if (!checked.length) {
      toast('Отметьте строки галочкой', 'err');
      return;
    }
    if (!confirm('Удалить выбранные сделки (' + checked.length + ')?')) return;
    var ids = [];
    for (var i = 0; i < checked.length; i++) {
      ids.push(Number(checked[i].getAttribute('data-id')));
    }
    for (var j = DEALS.length - 1; j >= 0; j--) {
      if (ids.indexOf(DEALS[j].id) !== -1) DEALS.splice(j, 1);
    }
    if (selectedDealId != null && ids.indexOf(Number(selectedDealId)) !== -1) {
      selectedDealId = null;
      screenDetail.style.display = 'none';
      screenList.style.display = 'block';
    }
    toast('Удалено: ' + ids.length, 'ok');
    renderListOnly();
  });

  dealDetailRoot.addEventListener('click', handleDealDetailClick);

  tableBody.addEventListener('click', handleTableAction);
  mobileCards.addEventListener('click', handleTableAction);

  modalClose.addEventListener('click', closeModal);
  modalCancel.addEventListener('click', closeModal);
  modalSave.addEventListener('click', saveModal);
  modalOverlay.addEventListener('click', function (e) {
    if (e.target === modalOverlay) closeModal();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modalOverlay.classList.contains('is-open')) closeModal();
  });

  fileUpload.addEventListener('change', function () {
    var id = uploadTargetDealId;
    uploadTargetDealId = null;
    dbg('file input change deal_id=' + String(id));
    if (!id || !fileUpload.files || !fileUpload.files.length) return;
    var deal = getDeal(id);
    if (!deal) return;
    // FileList can be \"live\" in some browsers; copy before clearing input.
    var files = Array.prototype.slice.call(fileUpload.files || []);
    toast('Выбрано файлов: ' + files.length, '');
    dbg('selected files: ' + files.map(function (f) { return f.name; }).join(', '));
    fileUpload.value = '';
    try {
      uploadFilesSequential(Number(id), deal, files);
    } catch (err) {
      toast(err && err.message ? err.message : 'Ошибка запуска загрузки', 'err');
    }
  });

  var sidebar = document.getElementById('sidebar');
  var backdrop = document.getElementById('backdrop');
  document.getElementById('sidebarToggle').addEventListener('click', function () {
    sidebar.classList.toggle('is-open');
    backdrop.classList.toggle('is-on');
  });
  backdrop.addEventListener('click', function () {
    sidebar.classList.remove('is-open');
    backdrop.classList.remove('is-on');
  });

  loadDealsFromServer(function (loaded) {
    // Если на сервере пусто, но в клиенте есть дефолтные сделки — сохраняем их,
    // чтобы форма договора могла подтягивать «Сделки» из единого источника (user_meta).
    if (!loaded) {
      saveDealsToServer();
    }
    renderListOnly();
  });
  loadServerFiles();
})();