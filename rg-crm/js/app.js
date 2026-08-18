const ADMIN_PIN = "1111";
const ADMIN_KEY = "rg_crm_admin";
const EXCLUDE_RANK_RE = /куватов|гиндуллин|гареев|алимбаев/i;

const money = (n) =>
  n == null || Number.isNaN(n)
    ? "—"
    : new Intl.NumberFormat("ru-RU", { maximumFractionDigits: 0 }).format(Math.round(n));

const moneyK = (n) =>
  n == null || Number.isNaN(n)
    ? "—"
    : new Intl.NumberFormat("ru-RU", {
        minimumFractionDigits: 0,
        maximumFractionDigits: 3,
      }).format(n);

const int = (n) =>
  n == null || Number.isNaN(n)
    ? "—"
    : new Intl.NumberFormat("ru-RU", { maximumFractionDigits: 0 }).format(n);

const pctTxt = (n) => (n == null || Number.isNaN(n) ? "—" : `${n}%`);

const MONTH_ORDER = [
  "Январь", "Февраль", "Март", "Апрель", "Май", "Июнь",
  "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь",
];

const PLAN_INTERN = {
  ad: 2,
  meetings: 30,
  dealsPrimary: 1,
  dealsSecondary: 0,
  rastleyka: 4000,
  rassylka: 1000,
};

const PLAN_AGENT = {
  ad: 4,
  meetings: 15,
  dealsPrimary: 2,
  dealsSecondary: 2,
  rastleyka: 4000,
  rassylka: 1000,
};

const DAILY_METRICS = [
  ["hz", "ХЗ"],
  ["incoming", "Вх. звонки"],
  ["rastleyka", "Расклейка"],
  ["rassylka", "Рассылка"],
  ["crm", "CRM"],
  ["meetings", "Встречи"],
  ["showings", "Показы"],
  ["podbor", "Подбор"],
  ["consults", "Консультации"],
  ["bron", "Бронь"],
  ["zadatok", "Задаток"],
  ["deals", "Сд. перв."],
  ["deals_secondary", "Сд. втор."],
  ["ad", "А.Д"],
  ["touches", "Касания"],
];

const RU_MONTHS_GEN = [
  "января", "февраля", "марта", "апреля", "мая", "июня",
  "июля", "августа", "сентября", "октября", "ноября", "декабря",
];

let DATA = null;
let dailyIndex = null;
let dailyReport = null;
let selectedDailyDate = null;
let selectedMonthId = null;
let yearMetric = "dealsTotal";
let monthMetric = "dealsTotal";
let detailsOpen = true;
let route = { view: "group", agentKey: null };
let isAdmin = sessionStorage.getItem(ADMIN_KEY) === "1";

const els = {
  nav: document.getElementById("monthNav"),
  mobMonthBar: document.getElementById("mobMonthBar"),
  agentNav: document.getElementById("agentNav"),
  source: document.getElementById("sourceMeta"),
  roleLabel: document.getElementById("roleLabel"),
  eyebrow: document.getElementById("pageEyebrow"),
  title: document.getElementById("pageTitle"),
  groupHint: document.getElementById("groupHint"),
  agent: document.getElementById("filterAgent"),
  search: document.getElementById("filterSearch"),
  sort: document.getElementById("filterSort"),
  kpi: document.getElementById("kpiRow"),
  funnel: document.getElementById("funnel"),
  boards: document.getElementById("boards"),
  boardsHint: document.getElementById("boardsHint"),
  monthChips: document.getElementById("monthChips"),
  monthGrid: document.getElementById("monthGrid"),
  monthRankHint: document.getElementById("monthRankHint"),
  yearChips: document.getElementById("yearChips"),
  yearGrid: document.getElementById("yearGrid"),
  hint: document.getElementById("tableHint"),
  toggle: document.getElementById("toggleDetails"),
  details: document.getElementById("detailsWrap"),
  tbody: document.querySelector("#agentsTable tbody"),
  attestCard: document.getElementById("attestCard"),
  attestHead: document.querySelector("#attestTable thead"),
  attestBody: document.querySelector("#attestTable tbody"),
  count: document.getElementById("rowCount"),
  viewGroup: document.getElementById("viewGroup"),
  viewDaily: document.getElementById("viewDaily"),
  viewAgent: document.getElementById("viewAgent"),
  sideDailyLink: document.getElementById("sideDailyLink"),
  dailyTitle: document.getElementById("dailyTitle"),
  dailySub: document.getElementById("dailySub"),
  dailySearch: document.getElementById("dailySearch"),
  dailySort: document.getElementById("dailySort"),
  dailyDateHint: document.getElementById("dailyDateHint"),
  dailyDateChips: document.getElementById("dailyDateChips"),
  dailyKpi: document.getElementById("dailyKpi"),
  dailyTableHint: document.getElementById("dailyTableHint"),
  dailyTableHead: document.getElementById("dailyTableHead"),
  dailyTableBody: document.getElementById("dailyTableBody"),
  dailyTableFoot: document.getElementById("dailyTableFoot"),
  agentTitle: document.getElementById("agentTitle"),
  agentSub: document.getElementById("agentSub"),
  agentMonth: document.getElementById("agentMonthSelect"),
  agentKpi: document.getElementById("agentKpi"),
  agentPlans: document.getElementById("agentPlans"),
  planHint: document.getElementById("planHint"),
  agentFunnel: document.getElementById("agentFunnel"),
  agentConv: document.getElementById("agentConv"),
  agentHistory: document.querySelector("#agentHistory tbody"),
  loginBtn: document.getElementById("loginBtn"),
  logoutBtn: document.getElementById("logoutBtn"),
  loginDialog: document.getElementById("loginDialog"),
  loginForm: document.getElementById("loginForm"),
  pinInput: document.getElementById("pinInput"),
  loginError: document.getElementById("loginError"),
  loginCancel: document.getElementById("loginCancel"),
  downloadAgentCsv: document.getElementById("downloadAgentCsv"),
  mobMenuBtn: document.getElementById("mobMenuBtn"),
  mobCloseBtn: document.getElementById("mobCloseBtn"),
  sidebarBackdrop: document.getElementById("sidebarBackdrop"),
  mobPeriodLabel: document.getElementById("mobPeriodLabel"),
  mobLoginBtn: document.getElementById("mobLoginBtn"),
  mobBottom: document.getElementById("mobBottom"),
  mobBottomLogin: document.getElementById("mobBottomLogin"),
  mobBottomLoginLabel: document.getElementById("mobBottomLoginLabel"),
  agentSearch: document.getElementById("agentSearch"),
};

const MOBILE_MQ = window.matchMedia("(max-width: 900px)");

function isMobileLayout() {
  return MOBILE_MQ.matches;
}

function openSidebar() {
  if (!isMobileLayout()) return;
  document.body.classList.add("sidebar-open");
  if (els.sidebarBackdrop) els.sidebarBackdrop.hidden = false;
}

function closeSidebar() {
  document.body.classList.remove("sidebar-open");
  if (els.sidebarBackdrop) els.sidebarBackdrop.hidden = true;
}

function syncMobPeriodLabel() {
  if (!els.mobPeriodLabel) return;
  if (route.view === "daily" && selectedDailyDate) {
    els.mobPeriodLabel.textContent = formatDailyDate(selectedDailyDate);
    return;
  }
  const month = currentMonth();
  els.mobPeriodLabel.textContent = month?.id || "—";
}

function syncMobBottomNav() {
  if (!els.mobBottom) return;
  const isAgent = route.view === "agent";
  const isDaily = route.view === "daily";
  els.mobBottom.querySelectorAll("[data-mob-nav]").forEach((btn) => {
    const kind = btn.dataset.mobNav;
    btn.classList.toggle(
      "is-active",
      kind === "group" ? !isAgent && !isDaily : kind === "daily" ? isDaily : kind === "agents" ? isAgent : false
    );
  });
  if (els.mobBottomLoginLabel) {
    els.mobBottomLoginLabel.textContent = isAdmin ? "Выйти" : "Вход";
  }
  if (els.mobLoginBtn) {
    els.mobLoginBtn.classList.toggle("is-admin", isAdmin);
    els.mobLoginBtn.textContent = isAdmin ? "✓" : "РГ";
  }
}

function showLoginDialog() {
  els.loginError.classList.add("is-hidden");
  els.pinInput.value = "";
  els.loginDialog.showModal();
  els.pinInput.focus();
}

function filterAgentNav() {
  if (!els.agentSearch || !els.agentNav) return;
  const q = els.agentSearch.value.trim().toLowerCase();
  els.agentNav.querySelectorAll("a").forEach((link) => {
    const name = (link.textContent || "").toLowerCase();
    link.classList.toggle("is-hidden-agent", Boolean(q) && !name.includes(q));
  });
}

function agentNavLabel(name) {
  const intern = isIntern(name);
  const clean = (name || "").replace(/\s*·\s*стаж[её]р/gi, "").trim();
  return intern ? `${clean} · стажёр` : clean;
}

function isIntern(name) {
  return /стаж/i.test(name || "");
}

function planFor(name) {
  return isIntern(name) ? PLAN_INTERN : PLAN_AGENT;
}

/** Первичка = ! Первичка; вторичка = ! Вторичка. Бронь/задаток — текущие, не сделки. */
function dealParts(metrics = {}) {
  const primary = metrics.deals || 0;
  const secondary = metrics.deals_secondary || metrics.dealsSecondary || 0;
  return { primary, secondary, total: primary + secondary };
}

function ruDeal(n) {
  const x = Math.round(n || 0);
  const m10 = x % 10;
  const m100 = x % 100;
  if (m10 === 1 && m100 !== 11) return `${x} сделка`;
  if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return `${x} сделки`;
  return `${x} сделок`;
}

function ruBron(n) {
  const x = Math.round(n || 0);
  const m10 = x % 10;
  const m100 = x % 100;
  if (m10 === 1 && m100 !== 11) return `${x} бронь`;
  if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return `${x} брони`;
  return `${x} броней`;
}

function ruZad(n) {
  const x = Math.round(n || 0);
  const m10 = x % 10;
  const m100 = x % 100;
  if (m10 === 1 && m100 !== 11) return `${x} задаток`;
  if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return `${x} задатка`;
  return `${x} задатков`;
}

function dealsLabel(metrics = {}) {
  const { primary, secondary, total } = dealParts(metrics);
  if (!total) return "0";
  return `${int(total)} <small>(${int(primary)} перв. + ${int(secondary)} втор.)</small>`;
}

function excludedFromRank(row) {
  return EXCLUDE_RANK_RE.test(row?.name || "") || EXCLUDE_RANK_RE.test(row?.key || "");
}

function attestMap() {
  const map = new Map();
  for (const a of DATA.attestation?.agents || []) map.set(a.key, a);
  return map;
}

function agentHref(key) {
  return `#/agent/${encodeURIComponent(key)}`;
}

function parseRoute() {
  const raw = (location.hash || "#/").replace(/^#/, "");
  const parts = raw.split("/").filter(Boolean);
  if (parts[0] === "agent" && parts[1]) {
    return { view: "agent", agentKey: decodeURIComponent(parts.slice(1).join("/")), dailyDate: null };
  }
  if (parts[0] === "daily") {
    return {
      view: "daily",
      agentKey: null,
      dailyDate: parts[1] ? decodeURIComponent(parts[1]) : null,
    };
  }
  return { view: "group", agentKey: null, dailyDate: null };
}

function formatDailyDate(iso) {
  if (!iso) return "—";
  const [y, m, d] = iso.split("-").map(Number);
  if (!y || !m || !d) return iso;
  return `${d} ${RU_MONTHS_GEN[m - 1]} ${y}`;
}

function dailyHref(date) {
  return date ? `#/daily/${encodeURIComponent(date)}` : "#/daily";
}

async function loadDailyIndex() {
  if (!dailyIndex) {
    const res = await fetch("data/daily/index.json");
    if (!res.ok) throw new Error("daily index");
    dailyIndex = await res.json();
  }
  return dailyIndex;
}

async function loadDailyReport(date) {
  if (dailyReport?.date === date && selectedDailyDate === date) return dailyReport;
  const res = await fetch(`data/daily/${date}.json`);
  if (!res.ok) throw new Error(`daily ${date}`);
  dailyReport = await res.json();
  selectedDailyDate = date;
  return dailyReport;
}

function dailyTouches(metrics = {}) {
  return (
    (metrics.meetings || 0) +
    (metrics.showings || 0) +
    (metrics.podbor || 0) +
    (metrics.consults || 0)
  );
}

function normalizeDailyMetrics(raw = {}) {
  const m = { ...raw };
  m.touches = dailyTouches(m);
  return m;
}

function dailyActivityScore(metrics = {}) {
  return DAILY_METRICS.reduce((s, [key]) => {
    if (key === "touches") return s;
    return s + (metrics[key] || 0);
  }, 0);
}

function buildDailyRows(report) {
  const month = DATA.months.find((m) => m.id === report.monthId);
  const byKey = new Map();

  for (const ag of enrich(month || { agents: [] })) {
    if (excludedFromRank(ag)) continue;
    byKey.set(ag.key, {
      name: ag.name,
      key: ag.key,
      intern: ag.intern,
      metrics: normalizeDailyMetrics(),
    });
  }

  for (const [key, raw] of Object.entries(report.agents || {})) {
    const metrics = normalizeDailyMetrics(raw);
    if (byKey.has(key)) {
      const row = byKey.get(key);
      row.metrics = metrics;
      if (raw?.name) row.name = raw.name;
    } else if (!excludedFromRank({ key, name: raw?.name || key })) {
      byKey.set(key, {
        name: raw?.name || key,
        key,
        intern: isIntern(raw?.name || ""),
        metrics,
      });
    }
  }

  return [...byKey.values()];
}

function filterDailyRows(rows) {
  const q = els.dailySearch?.value.trim().toLowerCase() || "";
  if (!q) return rows;
  return rows.filter((r) => r.name.toLowerCase().includes(q));
}

function sortDailyRows(rows) {
  const mode = els.dailySort?.value || "activity-desc";
  return [...rows].sort((a, b) => {
    if (mode === "name-asc") return a.name.localeCompare(b.name, "ru");
    if (mode === "meetings-desc") return (b.metrics.meetings || 0) - (a.metrics.meetings || 0);
    if (mode === "touches-desc") return (b.metrics.touches || 0) - (a.metrics.touches || 0);
    return dailyActivityScore(b.metrics) - dailyActivityScore(a.metrics);
  });
}

function sumDaily(rows, key) {
  if (key === "touches") return rows.reduce((s, r) => s + dailyTouches(r.metrics), 0);
  return rows.reduce((s, r) => s + (r.metrics?.[key] || 0), 0);
}

function renderDailyKpis(rows) {
  const items = [
    { label: "Встречи", value: int(sumDaily(rows, "meetings")), sub: `А.Д ${int(sumDaily(rows, "ad"))}` },
    { label: "Показы", value: int(sumDaily(rows, "showings")), sub: `задаток ${int(sumDaily(rows, "zadatok"))}` },
    {
      label: "Сделки",
      value: int(sumDaily(rows, "deals") + sumDaily(rows, "deals_secondary")),
      sub: `${int(sumDaily(rows, "deals"))} перв. + ${int(sumDaily(rows, "deals_secondary"))} втор.`,
    },
    { label: "Касания", value: int(sumDaily(rows, "touches")), sub: "встр+показ+подбор+конс" },
    { label: "Расклейка", value: int(sumDaily(rows, "rastleyka")), sub: `рассылка ${int(sumDaily(rows, "rassylka"))}` },
    { label: "Вх. звонки", value: int(sumDaily(rows, "incoming")), sub: `ХЗ ${int(sumDaily(rows, "hz"))} · CRM ${int(sumDaily(rows, "crm"))}` },
  ];

  els.dailyKpi.innerHTML = items
    .map(
      (item) => `<article class="kpi">
        <p class="kpi-label">${item.label}</p>
        <p class="kpi-value">${item.value}</p>
        <p class="kpi-delta flat">${item.sub}</p>
      </article>`
    )
    .join("");
}

function renderDailyTable(rows) {
  els.dailyTableHead.innerHTML = `<tr>
    <th>#</th>
    <th>Агент</th>
    ${DAILY_METRICS.map(([, label]) => `<th class="num">${label}</th>`).join("")}
  </tr>`;

  if (!rows.length) {
    els.dailyTableBody.innerHTML = `<tr><td colspan="${DAILY_METRICS.length + 2}" class="daily-empty">Нет агентов для отображения</td></tr>`;
    els.dailyTableFoot.innerHTML = "";
    return;
  }

  els.dailyTableBody.innerHTML = rows
    .map((r, idx) => {
      const active = dailyActivityScore(r.metrics) > 0;
      return `<tr class="${active ? "" : "is-muted"}">
        <td>${idx + 1}</td>
        <td class="agent-name"><a class="linkish" href="${agentHref(r.key)}">${r.name}${r.intern ? " · стажёр" : ""}</a></td>
        ${DAILY_METRICS.map(([key]) => {
          const val = key === "touches" ? r.metrics.touches : r.metrics[key] || 0;
          return `<td class="num ${val ? "has-val" : ""}">${int(val)}</td>`;
        }).join("")}
      </tr>`;
    })
    .join("");

  els.dailyTableFoot.innerHTML = `<tr>
    <td colspan="2">Итого за день</td>
    ${DAILY_METRICS.map(([key]) => `<td class="num">${int(sumDaily(rows, key))}</td>`).join("")}
  </tr>`;
}

async function renderDaily() {
  document.title = "Ежедневный отчёт · РГ CRM";
  els.sideDailyLink?.classList.add("is-active");

  let index;
  try {
    index = await loadDailyIndex();
  } catch {
    els.dailySub.textContent = "Не удалось загрузить список дат";
    els.dailyDateChips.innerHTML = "";
    els.dailyKpi.innerHTML = "";
    els.dailyTableBody.innerHTML = `<tr><td colspan="${DAILY_METRICS.length + 2}" class="daily-empty">Нет файла data/daily/index.json</td></tr>`;
    return;
  }

  const dates = index.dates || [];
  if (!dates.length) {
    els.dailySub.textContent = "Пока нет загруженных дневных отчётов";
    els.dailyDateChips.innerHTML = "";
    els.dailyKpi.innerHTML = "";
    els.dailyTableBody.innerHTML = `<tr><td colspan="${DAILY_METRICS.length + 2}" class="daily-empty">Добавьте файл в data/daily/</td></tr>`;
    return;
  }

  const date = route.dailyDate || selectedDailyDate || index.latest || dates[0];
  if (!dates.includes(date)) {
    location.hash = dailyHref(index.latest || dates[0]);
    return;
  }

  if (!dailyReport || dailyReport.date !== date) {
    try {
      await loadDailyReport(date);
    } catch {
      els.dailySub.textContent = `Не найден отчёт за ${formatDailyDate(date)}`;
      return;
    }
  }

  const rows = sortDailyRows(filterDailyRows(buildDailyRows(dailyReport)));
  const activeCount = rows.filter((r) => dailyActivityScore(r.metrics) > 0).length;

  els.dailyTitle.textContent = `Ежедневный отчёт · ${formatDailyDate(date)}`;
  els.dailySub.textContent = `${dailyReport.monthId} · ${activeCount} агентов с активностью из ${rows.length}`;
  els.dailyDateHint.textContent = reportDatesHint(dates, date);
  els.dailyTableHint.textContent = `Показатели за ${formatDailyDate(date)} · серые строки без активности за день`;

  els.dailyDateChips.innerHTML = dates
    .map(
      (d) =>
        `<a class="chip ${d === date ? "is-active" : ""}" href="${dailyHref(d)}">${formatDailyDate(d)}</a>`
    )
    .join("");

  renderDailyKpis(rows);
  renderDailyTable(rows);
  els.count.textContent = `День · ${formatDailyDate(date)} · ${rows.length} агентов`;
  syncMobPeriodLabel();
}

function reportDatesHint(dates, current) {
  const idx = dates.indexOf(current);
  if (idx === -1) return `${dates.length} дн. в архиве`;
  return `${idx + 1} из ${dates.length} · новее ${idx > 0 ? formatDailyDate(dates[idx - 1]) : "—"}`;
}

function currentMonth() {
  return DATA.months.find((m) => m.id === selectedMonthId) || DATA.months.at(-1);
}

function prevMonth(month) {
  const idx = DATA.months.findIndex((m) => m.id === month.id);
  return idx > 0 ? DATA.months[idx - 1] : null;
}

function pct(num, den) {
  if (!den) return null;
  return Math.round((1000 * num) / den) / 10;
}

function deltaPct(cur, prev) {
  if (prev == null || prev === 0) {
    if (!cur) return { text: "нет базы", cls: "flat" };
    return { text: "новый период", cls: "flat" };
  }
  const d = Math.round((((cur - prev) / Math.abs(prev)) * 1000)) / 10;
  if (d > 0) return { text: `▲ ${d}% к пред.`, cls: "up" };
  if (d < 0) return { text: `▼ ${Math.abs(d)}% к пред.`, cls: "down" };
  return { text: "без изменений", cls: "flat" };
}

function enrich(month) {
  const amap = attestMap();
  return (month?.agents || []).map((ag) => {
    const att = amap.get(ag.key);
    const plan = planFor(ag.name);
    return {
      ...ag,
      attRevenue: att?.revenueByMonth?.[month.label] ?? null,
      plan,
      intern: isIntern(ag.name),
    };
  });
}

function allAgentDirectory() {
  const byKey = new Map();
  for (const m of DATA.months) {
    for (const a of m.agents || []) {
      if (!byKey.has(a.key)) byKey.set(a.key, { key: a.key, name: a.name });
    }
  }
  for (const a of DATA.attestation?.agents || []) {
    if (!byKey.has(a.key)) byKey.set(a.key, { key: a.key, name: a.name });
  }
  return [...byKey.values()]
    .filter((a) => !excludedFromRank(a))
    .sort((a, b) => a.name.localeCompare(b.name, "ru"));
}

function findAgentRow(month, key) {
  return enrich(month).find((a) => a.key === key) || null;
}

function applyFilters(rows) {
  const agent = els.agent.value;
  const q = els.search.value.trim().toLowerCase();
  return rows.filter((r) => {
    if (agent && r.name !== agent) return false;
    if (q && !r.name.toLowerCase().includes(q)) return false;
    return true;
  });
}

function sortRows(rows) {
  const mode = els.sort.value;
  const m = (r, k) => r.metrics?.[k] ?? 0;
  const deals = (r) => dealParts(r.metrics).total;
  return [...rows].sort((a, b) => {
    if (mode === "meetings-desc") return m(b, "meetings") - m(a, "meetings");
    if (mode === "deals-desc") return deals(b) - deals(a);
    if (mode === "ad-desc") return m(b, "ad") - m(a, "ad");
    if (mode === "touches-desc") return m(b, "touches") - m(a, "touches");
    if (mode === "name-asc") return a.name.localeCompare(b.name, "ru");
    if (mode === "prihod-desc" && isAdmin) return m(b, "prihod") - m(a, "prihod");
    return m(b, "meetings") - m(a, "meetings");
  });
}

function sum(rows, k) {
  return rows.reduce((s, r) => s + (r.metrics?.[k] || 0), 0);
}

function factPlan(fact, plan) {
  const f = fact || 0;
  const ok = f >= plan;
  return `<span class="fp ${ok ? "ok" : "bad"}">${int(f)}<small>/${plan}</small></span>`;
}

function setAdminUI() {
  document.body.classList.toggle("is-admin", isAdmin);
  els.roleLabel.textContent = isAdmin ? "Режим РГ / админ" : "Режим агента";
  els.loginBtn.classList.toggle("is-hidden", isAdmin);
  els.logoutBtn.classList.toggle("is-hidden", !isAdmin);
  els.groupHint.textContent = isAdmin
    ? "Общие приходы и аттестация открыты"
    : "Общие приходы скрыты · в ЛК агент видит свой приход";
  const prihodOpt = els.sort.querySelector('option[value="prihod-desc"]');
  if (prihodOpt) prihodOpt.hidden = !isAdmin;
  if (!isAdmin && els.sort.value === "prihod-desc") els.sort.value = "meetings-desc";
  syncMobBottomNav();
}

function renderNav() {
  const monthButtons = DATA.months
    .map(
      (m) =>
        `<button type="button" data-id="${m.id}" class="${m.id === selectedMonthId ? "is-active" : ""}">${m.label} 2026</button>`
    )
    .join("");
  const monthPills = DATA.months
    .map(
      (m) =>
        `<button type="button" data-id="${m.id}" class="${m.id === selectedMonthId ? "is-active" : ""}">${m.label}</button>`
    )
    .join("");
  els.nav.innerHTML = monthButtons;
  if (els.mobMonthBar) els.mobMonthBar.innerHTML = monthPills;
  syncMobPeriodLabel();
}

function onMonthPick(btn) {
  if (!btn) return;
  selectedMonthId = btn.dataset.id;
  if (route.view === "agent") {
    els.agentMonth.value = selectedMonthId;
    renderAgentCabinet();
  } else {
    renderGroup();
  }
  closeSidebar();
}

function renderAgentNav() {
  const list = allAgentDirectory();
  els.agentNav.innerHTML = list
    .map((a) => {
      const active = route.view === "agent" && route.agentKey === a.key ? "is-active" : "";
      return `<a class="${active}" href="${agentHref(a.key)}">${agentNavLabel(a.name)}</a>`;
    })
    .join("");
  els.sideDailyLink?.classList.toggle("is-active", route.view === "daily");
  filterAgentNav();
}

function renderKpis(month, rows, target = els.kpi, { personal = false } = {}) {
  const prev = prevMonth(month);
  const cur = {
    prihod: sum(rows, "prihod"),
    meetings: sum(rows, "meetings"),
    ad: sum(rows, "ad"),
    showings: sum(rows, "showings"),
    consults: sum(rows, "consults"),
    deals: sum(rows, "deals"),
    bron: sum(rows, "bron"),
    zadatok: sum(rows, "zadatok"),
    touches: sum(rows, "touches"),
    rastleyka: sum(rows, "rastleyka"),
    rassylka: sum(rows, "rassylka"),
  };
  const p = prev
    ? {
        prihod: prev.totals?.prihod || 0,
        meetings: prev.totals?.meetings || 0,
        ad: prev.totals?.ad || 0,
        showings: prev.totals?.showings || 0,
        consults: prev.totals?.consults || 0,
        deals: prev.totals?.deals || 0,
      }
    : null;

  const items = [];
  if (personal || isAdmin) {
    items.push({
      label: "Приход",
      value: `${money(cur.prihod)} ₽`,
      d: deltaPct(cur.prihod, p?.prihod),
    });
  }
  items.push(
    {
      label: "Встречи",
      value: int(cur.meetings),
      d: deltaPct(cur.meetings, p?.meetings),
      sub: `А.Д ${int(cur.ad)} · ${pctTxt(pct(cur.ad, cur.meetings))}`,
    },
    {
      label: "Показы",
      value: int(cur.showings),
      d: deltaPct(cur.showings, p?.showings),
      sub: `задаток ${int(cur.zadatok)}`,
    },
    {
      label: "Сделки",
      value: int(dealParts({ deals: cur.deals, deals_secondary: sum(rows, "deals_secondary") }).total),
      d: deltaPct(
        dealParts({ deals: cur.deals, deals_secondary: sum(rows, "deals_secondary") }).total,
        p
          ? dealParts({
              deals: p.deals,
              deals_secondary: prev?.totals?.deals_secondary || 0,
            }).total
          : null
      ),
      sub: `${int(cur.deals)} перв. + ${int(sum(rows, "deals_secondary"))} втор. · бронь ${int(cur.bron)}`,
    },
    {
      label: "Касания",
      value: int(cur.touches),
      d: { text: "встр+показ+подбор+конс", cls: "flat" },
    },
    {
      label: "Расклейка",
      value: int(cur.rastleyka),
      d: { text: personal ? `план ${rows[0]?.plan?.rastleyka || 4000}` : "сумма группы", cls: "flat" },
    }
  );

  target.innerHTML = items
    .map(
      (it) => `<article class="kpi">
        <p class="label">${it.label}</p>
        <p class="value">${it.value}</p>
        <p class="delta ${it.d.cls}">${it.d.text}</p>
        ${it.sub ? `<p class="delta flat">${it.sub}</p>` : ""}
      </article>`
    )
    .join("");
}

function renderFunnel(rows, target = els.funnel) {
  const primary = sum(rows, "deals");
  const secondary = sum(rows, "deals_secondary");
  const stages = [
    { key: "meetings", label: "Встречи", value: sum(rows, "meetings") },
    { key: "ad", label: "А.Д", value: sum(rows, "ad") },
    { key: "showings", label: "Показы", value: sum(rows, "showings") },
    { key: "bron", label: "Бронь", value: sum(rows, "bron") },
    { key: "deals", label: "Сд. первичка", value: primary },
    { key: "zadatok", label: "Задаток", value: sum(rows, "zadatok") },
    { key: "secondary", label: "Сд. вторичка", value: secondary },
  ];

  const max = Math.max(...stages.map((s) => s.value), 1);

  target.innerHTML = stages
    .map((s, i) => {
      const prev = i > 0 ? stages[i - 1].value : null;
      const conv = i > 0 ? pct(s.value, prev) : null;
      const width = Math.max(8, Math.round((s.value / max) * 100));
      let note = i === 0 ? "старт воронки" : `конв. ${pctTxt(conv)}`;
      if (s.key === "deals") note = "новостройки";
      if (s.key === "secondary") note = "вторичка";
      return `<div class="funnel-step">
        <div class="name">${s.label}</div>
        <div class="funnel-bar"><span style="width:${width}%"></span></div>
        <div class="funnel-meta">
          <strong>${int(s.value)}</strong>
          <small>${note}</small>
        </div>
      </div>`;
    })
    .join("");
}

function rankedMonthAgents(monthId, metricKey) {
  const month = DATA.months.find((m) => m.id === monthId);
  if (!month) return [];
  return enrich(month)
    .filter((a) => !excludedFromRank(a))
    .map((a) => {
      const parts = dealParts(a.metrics);
      let value = 0;
      if (metricKey === "dealsTotal") value = parts.total;
      else if (metricKey === "dealsPrimary") value = parts.primary;
      else if (metricKey === "dealsSecondary") value = parts.secondary;
      else if (metricKey === "deals_secondary") value = parts.secondary;
      else value = a.metrics?.[metricKey] || 0;
      return {
        name: a.name,
        key: a.key,
        value,
        primary: parts.primary,
        secondary: parts.secondary,
        metrics: a.metrics,
      };
    })
    .filter((a) => a.value > 0)
    .sort((a, b) => b.value - a.value);
}

function rankedYearAgents(metricKey) {
  const map = new Map();
  for (const month of DATA.months) {
    for (const a of enrich(month)) {
      if (excludedFromRank(a)) continue;
      const parts = dealParts(a.metrics);
      const row = map.get(a.key) || {
        name: a.name,
        key: a.key,
        dealsTotal: 0,
        dealsPrimary: 0,
        dealsSecondary: 0,
        meetings: 0,
        touches: 0,
        ad: 0,
        bron: 0,
        consults: 0,
        showings: 0,
        objects: 0,
      };
      row.dealsTotal += parts.total;
      row.dealsPrimary += parts.primary;
      row.dealsSecondary += parts.secondary;
      row.meetings += a.metrics?.meetings || 0;
      row.touches += a.metrics?.touches || 0;
      row.ad += a.metrics?.ad || 0;
      row.bron += a.metrics?.bron || 0;
      row.consults += a.metrics?.consults || 0;
      row.showings += a.metrics?.showings || 0;
      row.objects += a.metrics?.objects || 0;
      map.set(a.key, row);
    }
  }
  return [...map.values()]
    .map((a) => ({
      ...a,
      value: a[metricKey] || 0,
      primary: a.dealsPrimary,
      secondary: a.dealsSecondary,
    }))
    .filter((a) => a.value > 0)
    .sort((a, b) => b.value - a.value);
}

function formatRankValue(row, metricKey) {
  if (metricKey === "dealsTotal") {
    return `${int(row.value)} <small>(${int(row.primary)} перв. + ${int(row.secondary)} втор.)</small>`;
  }
  return int(row.value);
}

function renderRankList(target, list, metricKey, title) {
  target.innerHTML = `<div class="year-col is-focus">
    <h3>${title}</h3>
    <ol>
      ${
        list
          .slice(0, 12)
          .map(
            (r, i) =>
              `<li><a class="who linkish" href="${agentHref(r.key || "")}">${i + 1}. ${r.name}</a><span class="val">${formatRankValue(r, metricKey)}</span></li>`
          )
          .join("") || `<li><span class="who">нет данных</span></li>`
      }
    </ol>
  </div>`;
}

function renderBoards(monthId) {
  const month = DATA.months.find((m) => m.id === monthId);
  // Блоки сделок/броней — все агенты (в т.ч. Куватов); общий рейтинг сделок — без исключённых
  const agents = enrich(month || {});

  const dealLeaders = rankedMonthAgents(monthId, "dealsTotal").slice(0, 8);

  // Первичка: сделки + брони (показываем у кого есть бронь или сделка перв.)
  const primaryBoardRows = agents
    .map((a) => {
      const parts = dealParts(a.metrics);
      const bron = a.metrics?.bron || 0;
      return {
        name: a.name,
        key: a.key,
        primary: parts.primary,
        bron,
        sort: bron * 1000 + parts.primary,
      };
    })
    .filter((a) => a.primary > 0 || a.bron > 0)
    .sort((a, b) => b.sort - a.sort || a.name.localeCompare(b.name, "ru"));

  // Вторичка: сделки + задаток
  const secondaryBoardRows = agents
    .map((a) => {
      const parts = dealParts(a.metrics);
      const zad = a.metrics?.zadatok || 0;
      return {
        name: a.name,
        key: a.key,
        secondary: parts.secondary,
        zadatok: zad,
        sort: parts.secondary * 1000 + zad,
      };
    })
    .filter((a) => a.secondary > 0 || a.zadatok > 0)
    .sort((a, b) => b.sort - a.sort || a.name.localeCompare(b.name, "ru"));

  els.boardsHint.textContent = isAdmin
    ? "первичка: сделки (брони) · вторичка: сделки (задаток) · приход для РГ"
    : "первичка: сделки (брони) · вторичка: сделки (задаток)";

  const dealBoard = `<div class="board">
    <h3>Сделки · всего</h3>
    <ol>
      ${
        dealLeaders
          .map(
            (r, i) => `<li>
              <span class="place ${i === 0 ? "gold" : ""}">${i + 1}</span>
              <a class="who linkish" href="${agentHref(r.key)}">${r.name}</a>
              <span class="val">${formatRankValue(r, "dealsTotal")}</span>
            </li>`
          )
          .join("") || `<li><span class="who">нет сделок</span></li>`
      }
    </ol>
  </div>`;

  const primaryBoard = `<div class="board">
    <h3>Первичка · сделки и брони</h3>
    <ol>
      ${
        primaryBoardRows
          .map(
            (r, i) => `<li>
              <span class="place ${i === 0 ? "gold" : ""}">${i + 1}</span>
              <a class="who linkish" href="${agentHref(r.key)}">${r.name}</a>
              <span class="val">${ruDeal(r.primary)} <small>(${ruBron(r.bron)})</small></span>
            </li>`
          )
          .join("") || `<li><span class="who">нет данных</span></li>`
      }
    </ol>
  </div>`;

  const secondaryBoard = `<div class="board">
    <h3>Вторичка · сделки и задатки</h3>
    <ol>
      ${
        secondaryBoardRows
          .map(
            (r, i) => `<li>
              <span class="place ${i === 0 ? "gold" : ""}">${i + 1}</span>
              <a class="who linkish" href="${agentHref(r.key)}">${r.name}</a>
              <span class="val">${ruDeal(r.secondary)} <small>(${ruZad(r.zadatok)})</small></span>
            </li>`
          )
          .join("") || `<li><span class="who">нет данных</span></li>`
      }
    </ol>
  </div>`;

  let prihodBoard = "";
  if (isAdmin) {
    const top = rankedMonthAgents(monthId, "prihod").slice(0, 3);
    prihodBoard = `<div class="board">
      <h3>Приход · РГ</h3>
      <ol>
        ${top
          .map(
            (r, i) => `<li>
              <span class="place ${i === 0 ? "gold" : ""}">${i + 1}</span>
              <a class="who linkish" href="${agentHref(r.key)}">${r.name}</a>
              <span class="val">${money(r.value)} ₽</span>
            </li>`
          )
          .join("") || `<li><span class="who">—</span></li>`}
      </ol>
    </div>`;
  }

  els.boards.innerHTML = dealBoard + primaryBoard + secondaryBoard + prihodBoard;
}

const RANK_METRICS = [
  ["dealsTotal", "Сделки"],
  ["dealsPrimary", "Первичка"],
  ["dealsSecondary", "Вторичка"],
  ["meetings", "Встречи"],
  ["consults", "Консультации"],
  ["showings", "Показы"],
  ["objects", "Объекты"],
  ["touches", "Касания"],
  ["ad", "А.Д"],
];

function renderMonthRank() {
  const month = currentMonth();
  els.monthRankHint.textContent = `${month.id} · без исключённых`;
  if (!RANK_METRICS.some(([k]) => k === monthMetric)) monthMetric = "dealsTotal";

  els.monthChips.innerHTML = RANK_METRICS.map(
    ([key, label]) =>
      `<button type="button" class="chip ${monthMetric === key ? "is-active" : ""}" data-month-metric="${key}">${label}</button>`
  ).join("");

  const label = RANK_METRICS.find(([k]) => k === monthMetric)?.[1] || "Сделки";
  const list = rankedMonthAgents(month.id, monthMetric);
  els.monthGrid.innerHTML = `<div class="year-col is-focus">
    <h3>${label} · ${month.label}</h3>
    <ol>
      ${
        list
          .slice(0, 12)
          .map(
            (r, i) =>
              `<li><a class="who linkish" href="${agentHref(r.key || "")}">${i + 1}. ${r.name}</a><span class="val">${formatRankValue(r, monthMetric)}</span></li>`
          )
          .join("") || `<li><span class="who">нет данных</span></li>`
      }
    </ol>
  </div>`;
}

function renderYear() {
  if (!RANK_METRICS.some(([k]) => k === yearMetric)) yearMetric = "dealsTotal";

  els.yearChips.innerHTML = RANK_METRICS.map(
    ([key, label]) =>
      `<button type="button" class="chip ${yearMetric === key ? "is-active" : ""}" data-year-metric="${key}">${label}</button>`
  ).join("");

  const label = RANK_METRICS.find(([k]) => k === yearMetric)?.[1] || "Сделки";
  const list = rankedYearAgents(yearMetric);
  renderRankList(els.yearGrid, list, yearMetric, `${label} · год`);
}

function renderAgents(rows) {
  els.tbody.innerHTML = rows
    .map((r, idx) => {
      const m = r.metrics || {};
      const p = r.plan;
      const parts = dealParts(m);
      const mute = !(parts.total || m.meetings || m.touches || m.ad);
      const prihodCell = isAdmin
        ? `<td class="num money admin-col">${m.prihod ? money(m.prihod) : "—"}</td>`
        : `<td class="num admin-col">·</td>`;
      const attCell = isAdmin
        ? `<td class="num admin-col">${r.attRevenue != null ? moneyK(r.attRevenue) : "—"}</td>`
        : `<td class="num admin-col">·</td>`;
      return `<tr class="${mute ? "is-muted" : ""}">
        <td>${idx + 1}</td>
        <td class="agent-name"><a class="linkish" href="${agentHref(r.key)}">${r.name}${r.intern ? " · стажёр" : ""}</a></td>
        ${prihodCell}
        <td class="num">${factPlan(parts.primary, p.dealsPrimary)}</td>
        <td class="num">${factPlan(parts.secondary, p.dealsSecondary)}</td>
        <td class="num">${factPlan(m.meetings, p.meetings)}</td>
        <td class="num">${factPlan(m.ad, p.ad)}</td>
        <td class="num">${factPlan(m.rastleyka, p.rastleyka)}</td>
        <td class="num">${factPlan(m.rassylka, p.rassylka)}</td>
        <td class="num">${int(m.showings)}</td>
        <td class="num">${int(m.bron)}</td>
        <td class="num">${int(m.touches)}</td>
        ${attCell}
      </tr>`;
    })
    .join("");
  els.hint.textContent = `${currentMonth().id} · ${rows.length} агентов · перв./втор.`;
  els.count.textContent = `Группа · ${rows.length} · ${isAdmin ? "РГ" : "агент"}`;
}

function renderAttest() {
  if (!isAdmin) {
    els.attestCard.classList.add("is-hidden");
    return;
  }
  els.attestCard.classList.remove("is-hidden");
  // Все агенты с приходами из аттестации — без исключений рейтинга
  const agents = [...(DATA.attestation?.agents || [])];
  const months = MONTH_ORDER.filter((mo) =>
    agents.some((a) => a.revenueByMonth?.[mo] != null)
  );
  els.attestHead.innerHTML = `<tr>
    <th>Агент</th>
    ${months.map((m) => `<th class="num">${m}</th>`).join("")}
    <th class="num">Итого</th>
  </tr>`;

  const nameFilter = els.agent.value;
  const q = els.search.value.trim().toLowerCase();
  const rows = agents
    .filter((a) => {
      if (q && !a.name.toLowerCase().includes(q)) return false;
      if (nameFilter) {
        const parts = nameFilter.toLowerCase().split(/\s+/);
        if (!parts.every((p) => a.name.toLowerCase().includes(p) || a.key.includes(p))) return false;
      }
      return true;
    })
    .map((a) => {
      const vals = months.map((m) => a.revenueByMonth?.[m]);
      const total = vals.reduce((s, v) => s + (v || 0), 0);
      return { a, vals, total };
    })
    .sort((x, y) => y.total - x.total || x.a.name.localeCompare(y.a.name, "ru"));

  els.attestBody.innerHTML = rows
    .map(({ a, vals, total }) => {
      return `<tr>
        <td class="agent-name"><a class="linkish" href="${agentHref(a.key)}">${a.name}</a></td>
        ${vals.map((v) => `<td class="num">${v != null ? moneyK(v) : "—"}</td>`).join("")}
        <td class="num money">${moneyK(total)}</td>
      </tr>`;
    })
    .join("");
}

function renderPlans(row) {
  const m = row?.metrics || {};
  const p = row?.plan || PLAN_AGENT;
  const parts = dealParts(m);
  const items = [
    ["Встречи", m.meetings, p.meetings],
    ["А.Д", m.ad, p.ad],
    ["Сделка перв.", parts.primary, p.dealsPrimary],
    ["Сделка втор.", parts.secondary, p.dealsSecondary],
    ["Расклейка", m.rastleyka, p.rastleyka],
    ["Рассылка", m.rassylka, p.rassylka],
    ["Бронь (текущие)", m.bron, null],
  ];
  els.planHint.textContent = row?.intern
    ? "Стажёр: 2 А.Д · 30 встреч · 1 перв. · бронь→перв. · задаток→втор."
    : "Агент: 4 А.Д · 15 встреч · 2 перв. · 2 втор. · бронь→перв. · задаток→втор.";
  els.agentPlans.innerHTML = items
    .map(([label, fact, plan]) => {
      if (plan == null) {
        return `<article class="plan-card">
          <p class="label">${label}</p>
          <p class="value">${int(fact)}</p>
          <div class="plan-bar"><span style="width:${fact ? 100 : 0}%"></span></div>
        </article>`;
      }
      const f = fact == null ? null : fact || 0;
      const ratio = f == null || !plan ? 0 : Math.min(100, Math.round((f / plan) * 100));
      const ok = f != null && f >= plan;
      return `<article class="plan-card ${ok ? "ok" : ""}">
        <p class="label">${label}</p>
        <p class="value">${f == null ? "—" : int(f)} <small>/ ${plan}</small></p>
        <div class="plan-bar"><span style="width:${f == null ? 0 : ratio}%"></span></div>
      </article>`;
    })
    .join("");
}

function fillFilters() {
  const names = new Set();
  for (const m of DATA.months) {
    for (const a of m.agents) {
      if (!excludedFromRank(a)) names.add(a.name);
    }
  }
  els.agent.innerHTML =
    `<option value="">Все агенты</option>` +
    [...names]
      .sort((a, b) => a.localeCompare(b, "ru"))
      .map((n) => `<option value="${n}">${n}</option>`)
      .join("");

  els.agentMonth.innerHTML = DATA.months
    .map((m) => `<option value="${m.id}">${m.id}</option>`)
    .join("");

  els.source.innerHTML = `
    <div><strong>Источники</strong></div>
    ${DATA.source?.report || "—"}<br/>
    ${DATA.source?.attestation || "—"}<br/>
    ${DATA.generatedAt || ""}
  `;
}

function downloadBlob(filename, text, mime = "text/csv;charset=utf-8") {
  const bom = "\uFEFF";
  const blob = new Blob([bom + text], { type: mime });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

function csvEscape(v) {
  const s = v == null ? "" : String(v);
  if (/[;"\n]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
  return s;
}

function downloadAgentTable(key) {
  const dir = allAgentDirectory().find((a) => a.key === key);
  const name = findAgentRow(currentMonth(), key)?.name || dir?.name || key;
  const rows = [
    [
      "Месяц",
      "Приход",
      "Сделка перв.",
      "Сделка втор.",
      "Встречи",
      "А.Д",
      "Расклейка",
      "Рассылка",
      "Показы",
      "Задаток",
      "Бронь",
      "Касания",
    ],
  ];
  for (const m of DATA.months) {
    const r = findAgentRow(m, key);
    const met = r?.metrics || {};
    const parts = dealParts(met);
    rows.push([
      m.id,
      met.prihod || 0,
      parts.primary,
      parts.secondary,
      met.meetings || 0,
      met.ad || 0,
      met.rastleyka || 0,
      met.rassylka || 0,
      met.showings || 0,
      met.zadatok || 0,
      met.bron || 0,
      met.touches || 0,
    ]);
  }
  const csv = rows.map((row) => row.map(csvEscape).join(";")).join("\n");
  const safe = name.replace(/[\\/:*?"<>|]+/g, "_");
  downloadBlob(`${safe}_CRM.csv`, csv);
}

function renderAgentCabinet() {
  const key = route.agentKey;
  const dir = allAgentDirectory().find((a) => a.key === key);
  const month =
    DATA.months.find((m) => m.id === els.agentMonth.value) || currentMonth();
  selectedMonthId = month.id;
  els.agentMonth.value = month.id;

  let row = findAgentRow(month, key);
  if (!row && dir) {
    row = { name: dir.name, key, metrics: {}, conversions: {}, plan: planFor(dir.name), intern: isIntern(dir.name) };
  }
  const name = row?.name || key;

  els.agentTitle.textContent = name;
  els.agentSub.textContent = `${row?.intern ? "Стажёр" : "Агент"} · личный кабинет · свой приход виден`;
  document.title = `${name} · РГ CRM`;

  const rows = [row];
  renderKpis(month, rows, els.agentKpi, { personal: true });
  renderPlans(row);
  renderFunnel(rows, els.agentFunnel);

  const c = row?.conversions || {};
  els.agentConv.innerHTML = [
    ["Встречи → А.Д", c.meet_to_ad],
    ["Показы → задаток", c.show_to_zad],
    ["Конс. → бронь", c.consult_to_bron],
    ["Бронь → сделки", pct(row?.metrics?.deals || 0, row?.metrics?.bron || 0)],
  ]
    .map(
      ([label, v]) => `<div class="conv-item">
        <span>${label}</span><strong>${pctTxt(v)}</strong>
      </div>`
    )
    .join("");

  els.agentHistory.innerHTML = DATA.months
    .map((m) => {
      const r = findAgentRow(m, key);
      const met = r?.metrics || {};
      const parts = dealParts(met);
      return `<tr>
        <td>${m.id}</td>
        <td class="num money">${met.prihod ? money(met.prihod) : "—"}</td>
        <td class="num">${int(parts.primary)}</td>
        <td class="num">${int(parts.secondary)}</td>
        <td class="num">${int(met.meetings)}</td>
        <td class="num">${int(met.ad)}</td>
        <td class="num">${int(met.rastleyka)}</td>
        <td class="num">${int(met.rassylka)}</td>
        <td class="num">${int(met.showings)}</td>
        <td class="num">${int(met.bron)}</td>
        <td class="num">${int(met.touches)}</td>
      </tr>`;
    })
    .join("");

  els.count.textContent = `ЛК · ${name}`;
  renderNav();
  renderAgentNav();
}

function renderGroup() {
  document.title = "РГ CRM";
  setAdminUI();
  renderNav();
  renderAgentNav();
  const month = currentMonth();
  els.eyebrow.textContent = "Отчётный период";
  els.title.textContent = month.id;

  let rows = enrich(month).filter((r) => !excludedFromRank(r));
  rows = applyFilters(rows);
  rows = sortRows(rows);

  renderKpis(month, rows);
  renderFunnel(rows);
  renderBoards(month.id);
  renderMonthRank();
  renderAgents(rows);
  renderAttest();
  renderYear();

  els.details.classList.toggle("is-collapsed", !detailsOpen);
  els.toggle.textContent = detailsOpen ? "Свернуть таблицу" : "Показать таблицу";
}

function applyViews() {
  route = parseRoute();
  setAdminUI();
  const isAgent = route.view === "agent";
  const isDaily = route.view === "daily";
  els.viewGroup.classList.toggle("is-hidden", isAgent || isDaily);
  els.viewDaily.classList.toggle("is-hidden", !isDaily);
  els.viewAgent.classList.toggle("is-hidden", !isAgent);
  if (els.mobMonthBar) els.mobMonthBar.classList.toggle("is-hidden", isAgent || isDaily);
  if (isAgent) {
    if (!els.agentMonth.value) els.agentMonth.value = selectedMonthId;
    renderAgentCabinet();
  } else if (isDaily) {
    renderDaily().catch((err) => {
      console.error(err);
      els.dailySub.textContent = "Ошибка загрузки дневного отчёта";
    });
  } else {
    renderGroup();
  }
  renderAgentNav();
  syncMobBottomNav();
  closeSidebar();
}

function bind() {
  els.nav.addEventListener("click", (e) => {
    const btn = e.target.closest("button[data-id]");
    if (!btn) return;
    onMonthPick(btn);
  });

  els.mobMonthBar?.addEventListener("click", (e) => {
    const btn = e.target.closest("button[data-id]");
    if (!btn) return;
    onMonthPick(btn);
  });

  els.agentNav?.addEventListener("click", (e) => {
    if (e.target.closest("a")) closeSidebar();
  });

  els.yearChips.addEventListener("click", (e) => {
    const btn = e.target.closest("button[data-year-metric]");
    if (!btn) return;
    e.preventDefault();
    yearMetric = btn.dataset.yearMetric;
    renderYear();
  });

  els.monthChips.addEventListener("click", (e) => {
    const btn = e.target.closest("button[data-month-metric]");
    if (!btn) return;
    e.preventDefault();
    monthMetric = btn.dataset.monthMetric;
    renderMonthRank();
  });

  for (const el of [els.agent, els.sort]) {
    el.addEventListener("change", () => {
      if (route.view === "group") renderGroup();
    });
  }
  els.search.addEventListener("input", () => {
    if (route.view === "group") renderGroup();
  });
  els.toggle.addEventListener("click", () => {
    detailsOpen = !detailsOpen;
    renderGroup();
  });
  els.dailySearch?.addEventListener("input", () => {
    if (route.view === "daily") renderDaily();
  });
  els.dailySort?.addEventListener("change", () => {
    if (route.view === "daily") renderDaily();
  });
  els.agentMonth.addEventListener("change", () => {
    selectedMonthId = els.agentMonth.value;
    renderAgentCabinet();
  });

  els.downloadAgentCsv?.addEventListener("click", () => {
    if (route.agentKey) downloadAgentTable(route.agentKey);
  });

  els.loginBtn.addEventListener("click", showLoginDialog);
  els.mobLoginBtn?.addEventListener("click", () => {
    if (isAdmin) {
      isAdmin = false;
      sessionStorage.removeItem(ADMIN_KEY);
      applyViews();
      return;
    }
    showLoginDialog();
  });
  els.mobBottomLogin?.addEventListener("click", () => {
    if (isAdmin) {
      isAdmin = false;
      sessionStorage.removeItem(ADMIN_KEY);
      applyViews();
      return;
    }
    showLoginDialog();
  });
  els.mobMenuBtn?.addEventListener("click", openSidebar);
  els.mobCloseBtn?.addEventListener("click", closeSidebar);
  els.sidebarBackdrop?.addEventListener("click", closeSidebar);
  els.agentSearch?.addEventListener("input", filterAgentNav);
  els.mobBottom?.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-mob-nav]");
    if (!btn) return;
    const kind = btn.dataset.mobNav;
    if (kind === "group") {
      location.hash = "#/";
      return;
    }
    if (kind === "daily") {
      location.hash = "#/daily";
      return;
    }
    if (kind === "agents") {
      openSidebar();
      els.agentSearch?.focus();
    }
  });
  MOBILE_MQ.addEventListener("change", () => {
    if (!isMobileLayout()) closeSidebar();
  });
  els.loginCancel.addEventListener("click", () => els.loginDialog.close());
  els.logoutBtn.addEventListener("click", () => {
    isAdmin = false;
    sessionStorage.removeItem(ADMIN_KEY);
    applyViews();
  });
  els.loginForm.addEventListener("submit", (e) => {
    e.preventDefault();
    if (els.pinInput.value.trim() === ADMIN_PIN) {
      isAdmin = true;
      sessionStorage.setItem(ADMIN_KEY, "1");
      els.loginDialog.close();
      applyViews();
    } else {
      els.loginError.classList.remove("is-hidden");
    }
  });

  window.addEventListener("hashchange", applyViews);
}

async function boot() {
  DATA = await (await fetch("data/metrics.json")).json();
  selectedMonthId = DATA.months.at(-1)?.id;
  if (isMobileLayout()) detailsOpen = false;
  fillFilters();
  bind();
  applyViews();
}

boot().catch((err) => {
  document.body.insertAdjacentHTML(
    "beforeend",
    `<p style="padding:1.5rem;color:#8a2f1d">Не загрузился metrics.json. Нужен локальный сервер. ${err}</p>`
  );
});
