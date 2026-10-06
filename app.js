/* =========================================================
   ADS COMMAND v0.5 — LIVE Meta Marketing API
   Token & akun: config.js
   Logika angka = script sheet CPAS:
     - Purchase/value/ATC: catalog_segment_* ("with shared items"), fallback actions
     - Atribusi 7d_click + 1d_view
     - Total per akun: spend dari level campaign (ACTIVE+PAUSED),
       konversi dari level akun (sudah di-dedup Meta → sama dengan Ads Manager)
   ========================================================= */

const $ = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];
const store = {
  get(k, d) { try { return JSON.parse(localStorage.getItem(k)) ?? d; } catch { return d; } },
  set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch {} }
};
const CFG = window.ADS_CONFIG;
let TARGET = Object.assign({ roas: 1.5, cpa: 45000, ctr: 1.2 }, store.get("ac_target", {}));

// ---------- TANGGAL ----------
const pad = n => String(n).padStart(2, "0");
const ymd = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const parseD = s => { const [y, m, d] = s.split("-").map(Number); return new Date(y, m - 1, d); };
const addDays = (s, n) => { const d = parseD(s); d.setDate(d.getDate() + n); return ymd(d); };
const daysIn = (a, b) => { const o = []; for (let s = a; s <= b; s = addDays(s, 1)) o.push(s); return o; };
const fmtD = s => parseD(s).toLocaleDateString("id-ID", { day: "numeric", month: "short", year: "numeric" });
const fmtNum = s => { const d = parseD(s); return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`; };
const fmtDay = s => parseD(s).toLocaleDateString("id-ID", { weekday: "short", day: "numeric", month: "short" });
const hhmm = d => d.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });
const NOW = new Date();
let TODAY = ymd(NOW);
const PRESET = {
  today: ["Hari Ini", () => [TODAY, TODAY]],
  yesterday: ["Kemarin", () => { const y = addDays(TODAY, -1); return [y, y]; }],
  last7: ["7 Hari Terakhir", () => [addDays(TODAY, -6), TODAY]],
  last30: ["30 Hari Terakhir", () => [addDays(TODAY, -29), TODAY]],
  month: ["Bulan Ini", () => [TODAY.slice(0, 8) + "01", TODAY]],
  lastmonth: ["Bulan Lalu", () => [ymd(new Date(NOW.getFullYear(), NOW.getMonth() - 1, 1)), ymd(new Date(NOW.getFullYear(), NOW.getMonth(), 0))]]
};

// ---------- STATE ----------
const S = {
  preset: "today", from: TODAY, to: TODAY,
  level: "campaign", view: "all", pf: "all", acc: null, q: "", accQ: "",
  aiFilter: null, sort: { k: "spend", dir: -1 }, sel: new Set(), lastSync: null,
  panel: null, loading: "", busy: false
};
let RANGE = [TODAY];

// ---------- DATA ----------
const ACCOUNTS = CFG.accounts.map(a => ({
  id: a.id.replace(/^act_/, ""), act: a.id.startsWith("act_") ? a.id : "act_" + a.id, name: a.name, pf: a.group, tokenKey: a.token,
  currency: "IDR", campaigns: [], ads: [], days: {}, daysAll: {}, daysCamp: {}, err: null, loaded: false, maps: null, raw: null, structAt: 0,
  spendSrc: (CFG.spendSource || { prepare: "account", skinlyfe: "campaign" })[a.token] || "account"
}));
let CAMPAIGNS = [], ADSETS = [], ADS = [], BY_ID = new Map();
function rebuild() {
  CAMPAIGNS = ACCOUNTS.flatMap(a => a.campaigns.filter(c => c.ads.length));
  ADSETS = CAMPAIGNS.flatMap(c => c.adsets.filter(s => s.ads.length));
  ADS = ACCOUNTS.flatMap(a => a.ads);
  BY_ID = new Map([...CAMPAIGNS, ...ADSETS, ...ADS].map(e => [e.id, e]));
}

// =========================================================
// META API
// =========================================================
const ZERO_DEC = ["IDR", "JPY", "KRW", "VND", "CLP", "COP", "CRC", "HUF", "ISK", "PYG", "TWD", "UGX"];
const AD_ST = ["ACTIVE", "PAUSED", "CAMPAIGN_PAUSED", "ADSET_PAUSED", "PENDING_REVIEW", "DISAPPROVED", "WITH_ISSUES", "IN_PROCESS", "PREAPPROVED"];
const LIVE_AD_ST = ["ACTIVE", "PENDING_REVIEW", "DISAPPROVED", "WITH_ISSUES", "IN_PROCESS", "PREAPPROVED"];
const CONV_FIELDS = "actions,action_values,catalog_segment_actions,catalog_segment_value,video_thruplay_watched_actions";
const CAMP_FILTER = [{ field: "campaign.effective_status", operator: "IN", value: ["ACTIVE", "PAUSED"] }];

const sleep = ms => new Promise(r => setTimeout(r, ms));
async function api(action, body) {   // semua request ke Meta & AI lewat api.php — token/API key tidak pernah sampai ke browser
  const r = await fetch(`${CFG.api || "api.php"}?action=${action}`, { method: "POST", headers: { "content-type": "application/json", "x-csrf": CFG.csrf || "" }, body: JSON.stringify(body) });
  if (r.status === 401) throw new Error("Sesi habis — refresh halaman & login lagi.");
  const txt = await r.text();
  try { return JSON.parse(txt); } catch {}
  const i = txt.indexOf('{"'); if (i > 0) { try { return JSON.parse(txt.slice(i)); } catch {} }
  throw new Error(`Server PHP error (HTTP ${r.status}): ${txt.replace(/<[^>]+>/g, " ").trim().slice(0, 160)}`);
}
async function gget(path, params, acc) {
  let limit = params.limit || 100, lastMsg = "";
  for (let attempt = 0; attempt < 6; attempt++) {
    let out = [], after = null, err = null, guard = 0;
    do {
      const j = await api("graph", { acc: acc.id, path, params: { ...params, ...(params.limit ? { limit } : {}), ...(after ? { after } : {}) } });
      if (j.error) { err = j.error; break; }
      if (!Array.isArray(j.data)) return j;
      out.push(...j.data); after = j.next_after || null;
    } while (after && guard++ < 100);
    if (!err) return out;
    lastMsg = err.message || "";
    if (/reduce the amount of data/i.test(lastMsg) || err.code === 1) { limit = Math.max(10, Math.floor(limit / 2)); continue; }
    if ([4, 17, 32, 613, 80000, 80003, 80004].includes(err.code) || /limit reached|too many calls/i.test(lastMsg)) { await sleep(3000 * (attempt + 1)); continue; }
    throw new Error(lastMsg);
  }
  throw new Error(`Meta API menolak setelah 6× percobaan: ${lastMsg}`);
}
const getAct = (arr, types) => { for (const t of types) { const f = (arr || []).find(a => a.action_type === t); if (f) return parseFloat(f.value) || 0; } return 0; };
function convOf(r) {  // sama persis dengan script sheet CPAS
  return {
    purch: getAct(r.catalog_segment_actions, ["omni_purchase", "purchase"]) || getAct(r.actions, ["omni_purchase", "onsite_conversion.purchase", "purchase"]),
    value: getAct(r.catalog_segment_value, ["omni_purchase", "purchase"]) || getAct(r.action_values, ["omni_purchase", "onsite_conversion.purchase", "purchase"]),
    atc: getAct(r.catalog_segment_actions, ["omni_add_to_cart", "add_to_cart"]) || getAct(r.actions, ["omni_add_to_cart", "add_to_cart"]),
    vc: getAct(r.catalog_segment_actions, ["omni_view_content", "view_content"]) || getAct(r.actions, ["omni_view_content", "view_content"])
  };
}
// [0 spend, 1 impr, 2 link clicks, 3 ATC, 4 purchase, 5 value, 6 freq(iklan), 7 content view, 8 video 3 detik, 9 thruplay]
const rowArr = r => { const c = convOf(r); return [+r.spend || 0, +r.impressions || 0, +r.inline_link_clicks || 0, c.atc, c.purch, c.value, 0, c.vc, getAct(r.actions, ["video_view"]), getAct(r.video_thruplay_watched_actions, ["video_view"])]; };
const normName = s => String(s || "").replace(/\s*-\s*(copy|salinan)(\s*\d+)?$/i, "").trim().toLowerCase();

const structReqs = acc => [
  { path: acc.act, params: { fields: "name,currency" } },
  { path: `${acc.act}/campaigns`, params: { fields: "id,name,status,effective_status,daily_budget,lifetime_budget,start_time", effective_status: ["ACTIVE", "IN_PROCESS", "WITH_ISSUES"], limit: 500 } },
  { path: `${acc.act}/adsets`, params: { fields: "id,name,campaign_id,status,daily_budget,lifetime_budget,start_time", effective_status: ["ACTIVE", "IN_PROCESS", "WITH_ISSUES"], limit: 500 } },
  { path: `${acc.act}/ads`, params: { fields: "id,name,status,effective_status,adset_id,campaign_id,created_time,creative{body,title,thumbnail_url}", effective_status: LIVE_AD_ST, limit: 200 } }
];
function applyStructure(acc, [info, camps, sets, ads]) {
  ads.forEach(o => { if (o.creative?.body) o.creative.body = o.creative.body.slice(0, 400); });
  acc.raw = { info, camps, sets, ads }; acc.structAt = Date.now();
  buildStructure(acc);
}
async function loadStructure(acc) {   // jalur cadangan per akun (dengan retry & limit dikecilkan)
  const r = structReqs(acc);
  const res = await Promise.all(r.map((x, i) => i === 3
    ? gget(x.path, x.params, acc).catch(() => gget(x.path, { ...x.params, fields: "id,name,status,effective_status,adset_id,campaign_id,created_time" }, acc))
    : gget(x.path, x.params, acc)));
  applyStructure(acc, res);
}
function buildStructure(acc) {
  const { info, camps, sets, ads } = acc.raw;
  acc.currency = info.currency || "IDR";
  const off = ZERO_DEC.includes(acc.currency) ? 1 : 100; acc.off = off;
  const bud = o => o?.daily_budget ? [+o.daily_budget / off, "Daily"] : o?.lifetime_budget ? [+o.lifetime_budget / off, "Lifetime"] : [null, null];
  const cMap = new Map(), sMap = new Map(), aMap = new Map();
  const campObj = new Map(camps.map(o => [o.id, o])), setObj = new Map(sets.map(o => [o.id, o])), adObj = new Map(ads.map(o => [o.id, o]));
  acc.campaigns = []; acc.ads = [];
  const mkCamp = (id, name) => {
    if (cMap.has(id)) return cMap.get(id);
    const o = campObj.get(id), [b, bt] = bud(o);
    const c = { id, level: "campaign", acc, name: o?.name || name || id, budget: b, budgetType: bt, start: (o?.start_time || "").slice(0, 10) || null, on: o?.status === "ACTIVE", eff: o?.effective_status, adsets: [], ads: [], days: {} };
    cMap.set(id, c); acc.campaigns.push(c); return c;
  };
  const mkSet = (id, name, campId, campName) => {
    if (sMap.has(id)) return sMap.get(id);
    const o = setObj.get(id), camp = mkCamp(o?.campaign_id || campId, campName), [b, bt] = bud(o);
    const s = { id, level: "adset", acc, camp, name: o?.name || name || id, budget: b, budgetType: bt, start: (o?.start_time || "").slice(0, 10) || camp.start, on: o?.status === "ACTIVE", ads: [] };
    camp.adsets.push(s); sMap.set(id, s); return s;
  };
  const mkAd = (id, name, setId, setName, campId, campName) => {
    if (aMap.has(id)) return aMap.get(id);
    const o = adObj.get(id), adset = mkSet(o?.adset_id || setId, setName, o?.campaign_id || campId, campName);
    const cr = o?.creative || {};
    const text = cr.body || cr.title || "";
    const ad = { id, level: "ad", acc, camp: adset.camp, adset, name: o?.name || name || id, ck: normName(o?.name || name),
      desc: text ? text.replace(/\s+/g, " ").slice(0, 400) : "(Tidak ada primary text)", thumb: cr.thumbnail_url || null,
      start: (o?.created_time || "").slice(0, 10) || adset.start, on: o?.status === "ACTIVE", eff: o?.effective_status || null, days: {}, freqR: null };
    adset.ads.push(ad); adset.camp.ads.push(ad); aMap.set(id, ad); acc.ads.push(ad); return ad;
  };
  ads.forEach(o => mkAd(o.id, o.name));
  acc.maps = { mkAd, cMap };
}

const insightReqs = acc => {
  const base = { time_range: { since: S.from, until: S.to }, action_attribution_windows: CFG.attribution, limit: 500 };
  return [
    { path: `${acc.act}/insights`, params: { ...base, level: "ad", time_increment: 1, filtering: CAMP_FILTER, fields: `ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,spend,impressions,inline_link_clicks,frequency,${CONV_FIELDS}` } },
    { path: `${acc.act}/insights`, params: { ...base, level: "campaign", time_increment: 1, filtering: CAMP_FILTER, fields: `campaign_id,spend,impressions,inline_link_clicks,${CONV_FIELDS}` } },
    { path: `${acc.act}/insights`, params: { ...base, level: "account", time_increment: 1, fields: `spend,impressions,inline_link_clicks,${CONV_FIELDS}` } }
  ];
};
async function loadInsights(acc) { applyInsights(acc, await Promise.all(insightReqs(acc).map(x => gget(x.path, x.params, acc)))); }
function applyInsights(acc, [adI, campI, accI]) {
  acc.ads.forEach(a => { a.days = {}; a.freqR = null; });
  acc.campaigns.forEach(c => c.days = {});
  adI.forEach(r => { const ad = acc.maps.mkAd(r.ad_id, r.ad_name, r.adset_id, r.adset_name, r.campaign_id, r.campaign_name); const x = rowArr(r); x[6] = +r.frequency || 0; ad.days[r.date_start] = x; });
  campI.forEach(r => { const c = acc.maps.cMap.get(r.campaign_id); if (c) c.days[r.date_start] = rowArr(r); });
  // total akun: konversi SELALU level akun (dedup Meta). Spend: sesuai spendSource di config.js
  //   "account"  = spend level akun (semua campaign, termasuk yang sudah dihapus) → sama dengan script sheet Prepare
  //   "campaign" = jumlah campaign ACTIVE+PAUSED                                → sama dengan script sheet Skinlyfe
  acc.daysAll = {}; acc.daysCamp = {}; acc.days = {};
  accI.forEach(r => { acc.daysAll[r.date_start] = rowArr(r); });
  campI.forEach(r => { const d = acc.daysCamp[r.date_start] ||= [0, 0, 0, 0, 0, 0]; d[0] += +r.spend || 0; d[1] += +r.impressions || 0; d[2] += +r.inline_link_clicks || 0; });
  const src = acc.spendSrc === "campaign" ? acc.daysCamp : acc.daysAll;
  new Set([...Object.keys(acc.daysAll), ...Object.keys(acc.daysCamp)]).forEach(d => {
    const sp = src[d] || [0, 0, 0], cv = acc.daysAll[d] || [0, 0, 0, 0, 0, 0];
    acc.days[d] = [sp[0], sp[1], sp[2], cv[3], cv[4], cv[5], 0, cv[7] || 0, cv[8] || 0, cv[9] || 0];
  });
  acc.range = `${S.from}|${S.to}`;
}


// ---------- CACHE: dashboard langsung tampil saat dibuka, lalu diperbarui dari Meta ----------
function saveCache() {
  try {
    const accs = {};
    ACCOUNTS.filter(a => a.loaded && a.raw).forEach(a => {
      const adDays = {}, campDays = {};
      a.ads.forEach(x => { if (Object.keys(x.days).length) adDays[x.id] = x.days; });
      a.campaigns.forEach(c => { if (Object.keys(c.days).length) campDays[c.id] = c.days; });
      accs[a.id] = { raw: a.raw, structAt: a.structAt, adDays, campDays, days: a.days, daysAll: a.daysAll, daysCamp: a.daysCamp };
    });
    localStorage.setItem("ac_cache", JSON.stringify({ v: 2, from: S.from, to: S.to, t: Date.now(), accs }));
  } catch { try { localStorage.removeItem("ac_cache"); } catch {} }
}
function hydrateCache() {
  let c; try { c = JSON.parse(localStorage.getItem("ac_cache")); } catch { return false; }
  if (!c || c.v !== 2) return false;
  const same = c.from === S.from && c.to === S.to;
  let n = 0;
  ACCOUNTS.forEach(acc => {
    const x = c.accs[acc.id]; if (!x) return;
    acc.raw = x.raw; acc.structAt = x.structAt; buildStructure(acc); n++;
    if (!same) return;
    acc.days = x.days; acc.daysAll = x.daysAll || {}; acc.daysCamp = x.daysCamp || {};
    acc.ads.forEach(a => a.days = x.adDays[a.id] || {});
    acc.campaigns.forEach(k => k.days = x.campDays[k.id] || {});
    acc.loaded = true;
  });
  if (!n) return false;
  rebuild();
  if (same) { S.lastSync = new Date(c.t); S.fromCache = true; }
  return same;
}

async function pool(items, n, fn) { const q = [...items]; await Promise.all(Array.from({ length: n }, async () => { while (q.length) await fn(q.shift()); })); }

async function gmulti(reqs) {   // server (api.php) menjalankan semua request ke Meta secara paralel + ambil semua halaman
  const j = await api("multi", { requests: reqs });
  if (j.error || !Array.isArray(j.results)) throw new Error(j.error?.message || "multi gagal");
  return j.results;
}
const unwrap = x => x.data ?? x.obj;
async function loadAll(withStructure) {
  if (S.busy) return;
  S.busy = true;
  const t0 = Date.now(), sec = () => Math.round((Date.now() - t0) / 1000);
  const failed = new Set();
  const stale = a => withStructure || !a.raw || Date.now() - a.structAt > 30 * 60000;
  try {
    const needS = ACCOUNTS.filter(stale);
    ACCOUNTS.filter(a => !stale(a) && !a.maps).forEach(buildStructure);
    if (needS.length) {
      setLoading(`Sync Meta API… struktur ${needS.length} akun sekaligus`);
      const res = await gmulti(needS.flatMap(a => structReqs(a).map(r => ({ acc: a.id, ...r }))));
      needS.forEach((a, i) => { const r = res.slice(i * 4, i * 4 + 4); if (r.some(x => !x || x.error)) failed.add(a); else applyStructure(a, r.map(unwrap)); });
    }
    const ok = ACCOUNTS.filter(a => a.raw && !failed.has(a));
    setLoading(`Sync Meta API… angka ${ok.length} akun sekaligus (${sec()} dtk)`);
    const res = await gmulti(ok.flatMap(a => insightReqs(a).map(r => ({ acc: a.id, ...r }))));
    ok.forEach((a, i) => {
      const r = res.slice(i * 3, i * 3 + 3);
      if (r.some(x => !x || x.error)) { failed.add(a); return; }
      try { applyInsights(a, r.map(unwrap)); a.err = null; a.loaded = true; } catch (e) { failed.add(a); }
    });
  } catch (e) { ACCOUNTS.forEach(a => failed.add(a)); }   // api.php lama / server error → semua lewat jalur cadangan
  if (failed.size) {
    let done = 0;
    rebuild(); S.lastSync ||= new Date(); render();
    setLoading(`Mengulang ${failed.size} akun yang besar/gagal… (${sec()} dtk)`);
    await pool([...failed], 4, async acc => {
      try {
        if (stale(acc) || !acc.raw) await loadStructure(acc); else if (!acc.maps) buildStructure(acc);
        await loadInsights(acc); acc.err = null; acc.loaded = true;
      } catch (e) { acc.err = e.message; }
      setLoading(`Mengulang akun… ${++done}/${failed.size} (${sec()} dtk)`);
    });
  }
  rebuild();
  S.lastSync = new Date(); S.busy = false; S.fromCache = false; S.syncSec = sec();
  setLoading(""); render(); saveCache();
}

async function setStatusLive(e, on) {
  const j = await api("post", { acc: e.acc.id, id: e.id, status: on ? "ACTIVE" : "PAUSED" });
  if (j.error) throw new Error(j.error.message || "Gagal");
}

// =========================================================
// METRIK
// =========================================================
const rp = n => n == null || !isFinite(n) ? "—" : "Rp" + Math.round(n).toLocaleString("id-ID");
const rpS = n => n == null || !isFinite(n) ? "—" : n >= 1e6 ? "Rp" + (n / 1e6).toFixed(1).replace(".", ",") + " jt" : n >= 1e3 ? "Rp" + Math.round(n / 1e3) + " rb" : rp(n);
const num = n => Math.round(n).toLocaleString("id-ID");
const f2 = n => n == null || !isFinite(n) ? "—" : n.toFixed(2);
const esc = s => String(s ?? "").replace(/[&<>"]/g, m => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[m]));
const cap = s => s.charAt(0).toUpperCase() + s.slice(1);
const LVL = { campaign: ["campaign", "campaigns", "Campaign"], adset: ["ad set", "ad sets", "Ad set"], ad: ["iklan", "ads", "Ad"] };

const zero = () => ({ spend: 0, impr: 0, clicks: 0, atc: 0, purch: 0, value: 0, vc: 0, v3: 0, thru: 0, fImpr: 0, n: 0 });
const addX = (m, x) => { m.spend += x[0]; m.impr += x[1]; m.clicks += x[2]; m.atc += x[3]; m.purch += x[4]; m.value += x[5]; m.vc += x[7] || 0; m.v3 += x[8] || 0; m.thru += x[9] || 0; };
function derive(m) {
  m.roas = m.spend ? m.value / m.spend : 0;
  m.cpa = m.purch ? m.spend / m.purch : null;
  m.cpatc = m.atc ? m.spend / m.atc : null;
  m.ctr = m.impr ? m.clicks / m.impr * 100 : 0;
  m.cvr = m.clicks ? m.purch / m.clicks * 100 : 0;
  m.freq = m.impr && m.fImpr ? m.fImpr / m.impr : 0;
  m.cpvc = m.vc ? m.spend / m.vc : null;
  m.cpc = m.clicks ? m.spend / m.clicks : null;
  m.hook = m.impr ? m.v3 / m.impr * 100 : 0;      // hook rate = video 3 detik / impressions
  m.hold = m.impr ? m.thru / m.impr * 100 : 0;    // hold rate = ThruPlay / impressions
  return m;
}
function daysM(days) { const m = zero(); RANGE.forEach(d => { const x = days[d]; if (x) addX(m, x); }); return m; }
function adMetrics(ad) {  // frequency = rata-rata harian tertimbang impression (perkiraan untuk rentang > 1 hari)
  const m = daysM(ad.days); RANGE.forEach(d => { const x = ad.days[d]; if (x && x[6]) m.fImpr += x[1] * x[6]; }); m.n = 1; return derive(m);
}
function sumM(list) { const m = zero(); list.forEach(x => { for (const k of ["spend", "impr", "clicks", "atc", "purch", "value", "vc", "v3", "thru", "fImpr", "n"]) m[k] += x[k] || 0; }); return derive(m); }

const adsOf = e => e.level === "ad" ? [e] : e.ads;
const pfOk = acc => S.pf === "all" || acc.pf === S.pf;
const adHay = a => [a.name, a.desc, a.camp.name, a.adset.name, a.acc.name, a.acc.id, a.id, a.camp.id, a.adset.id].join(" ").toLowerCase();
let QSET = null;
const qOk = a => !QSET || QSET.has(a.id);
const scopeOk = a => pfOk(a.acc) && (!S.acc || a.acc.id === S.acc);
const adsIn = e => adsOf(e).filter(a => scopeOk(a) && qOk(a));
let M = new Map(), AM = new Map();

function compute() {
  TODAY = ymd(new Date());
  RANGE = daysIn(S.from, S.to);
  QSET = S.q ? new Set(ADS.filter(a => adHay(a).includes(S.q)).map(a => a.id)) : null;
  AM = new Map(ADS.map(a => [a.id, adMetrics(a)]));
  M = new Map();
  ADS.forEach(a => M.set(a.id, scopeOk(a) && qOk(a) ? AM.get(a.id) : zero()));
  ADSETS.forEach(s => M.set(s.id, sumM(adsIn(s).map(a => AM.get(a.id)))));
  CAMPAIGNS.forEach(c => {
    const ads = adsIn(c), sum = sumM(ads.map(a => AM.get(a.id)));
    if (!QSET && ads.length && Object.keys(c.days).length) {   // tanpa pencarian → angka level campaign (dedup Meta)
      const m = daysM(c.days); m.fImpr = sum.fImpr * (sum.impr ? m.impr / sum.impr : 0); m.n = ads.length; M.set(c.id, derive(m));
    } else M.set(c.id, sum);
  });
  computeAI();
}
// total akun = level akun (dedup) kalau tanpa pencarian; kalau ada pencarian = jumlah iklan yang cocok
const accMetrics = acc => QSET ? sumM(acc.ads.filter(qOk).map(a => AM.get(a.id))) : derive(Object.assign(daysM(acc.days), { n: acc.ads.length }));
const scopedAccounts = () => ACCOUNTS.filter(a => pfOk(a) && (!S.acc || a.id === S.acc));

// status
const isOn = e => e.level === "campaign" ? e.on : e.level === "adset" ? e.camp.on && e.on : e.camp.on && e.adset.on && e.on;
function delivery(e) {
  if (e.level === "ad" && ["DISAPPROVED", "WITH_ISSUES"].includes(e.eff)) return { t: e.eff === "DISAPPROVED" ? "Rejected" : "Error", c: "err" };
  if (e.level === "ad" && ["PENDING_REVIEW", "IN_PROCESS"].includes(e.eff)) return { t: "In review", c: "learn" };
  if (e.level === "ad" && e.eff === "ARCHIVED") return { t: "Archived", c: "off" };
  const live = e.level === "ad" ? isOn(e) : isOn(e) && adsOf(e).some(isOn);
  if (!live) return { t: "Off", c: "off" };
  if (e.start && e.start >= addDays(TODAY, -2)) return { t: "Learning", c: "learn" };
  return { t: "Active", c: "on" };
}

// =========================================================
// AI ANALYST — pakai angka yang sama dengan yang tampil
// =========================================================
const V = {
  scale: { label: "Naikkan budget", icon: "▲", cls: "v-scale" },
  potential: { label: "Potensi winning", icon: "↗", cls: "v-pot" },
  optimize: { label: "Optimasi", icon: "◐", cls: "v-opt" },
  kill: { label: "Matikan", icon: "■", cls: "v-kill" },
  watch: { label: "Pantau", icon: "●", cls: "v-watch" }
};
let AI = new Map();
const minSpend = () => TARGET.cpa * 1.5;
function bestAlt(e) {
  const own = new Set(adsOf(e).map(a => a.id));
  return ADS.filter(a => !own.has(a.id) && isOn(a) && a.acc.pf === e.acc.pf && AM.get(a.id).purch >= 2 && AM.get(a.id).roas >= TARGET.roas)
    .sort((x, y) => AM.get(y.id).roas - AM.get(x.id).roas)[0];
}
function analyze(e) {
  const m = M.get(e.id), T = TARGET, r = [], act = [], word = LVL[e.level][0], minS = minSpend();
  const enough = m.spend >= minS, lowCtr = m.impr > 0 && m.ctr < T.ctr, hiFreq = m.freq >= 3.2, hiCpa = m.cpa == null || m.cpa > T.cpa, off = !isOn(e);
  const isToday = S.from === TODAY && S.to === TODAY;
  let v;
  if (m.spend === 0) {
    v = "watch"; r.push(off ? `${cap(word)} ini Off, tidak ada spend di tanggal ini.` : `Belum ada spend di tanggal ini.`);
    act.push(off ? "Tidak perlu tindakan." : "Cek budget, jadwal, atau status review iklan.");
  } else if (enough && (m.purch === 0 || m.roas < T.roas * 0.5 || (m.roas < T.roas * 0.7 && (hiFreq || lowCtr)))) {
    v = "kill";
    r.push(m.purch === 0 ? `Spend ${rp(m.spend)} (≥ 1,5× batas CPA ${rp(T.cpa)}) tapi 0 purchase.` : `ROAS ${f2(m.roas)} jauh di bawah target ${T.roas} — spend ${rp(m.spend)} cuma jadi ${rp(m.value)}.`);
    if (m.cpa) r.push(`Cost per purchase ${rp(m.cpa)} = ${(m.cpa / T.cpa).toFixed(1)}× batas ${rp(T.cpa)}.`);
    if (lowCtr) r.push(`CTR ${f2(m.ctr)}% di bawah ${T.ctr}% — hook nggak bikin orang berhenti scroll.`);
    else if (m.clicks >= 20 && m.cvr < 1) r.push(`CTR oke (${f2(m.ctr)}%) tapi ${num(m.clicks)} klik cuma jadi ${num(m.purch)} purchase.`);
    if (hiFreq) r.push(`Frequency ${m.freq.toFixed(2)} — audience mulai jenuh.`);
    act.push(off ? "Sudah Off — biarkan mati." : `Matikan ${word} ini.`);
    const b = bestAlt(e); if (b) act.push(`Pindahkan budget ke "${b.name}" di ${b.acc.name} (ROAS ${f2(AM.get(b.id).roas)} di tanggal yang sama).`);
  } else if (m.purch >= 3 && m.roas >= T.roas * 1.25 && !hiCpa && !hiFreq) {
    v = "scale";
    r.push(`ROAS ${f2(m.roas)} (${Math.round((m.roas / T.roas - 1) * 100)}% di atas target ${T.roas}) dari ${num(m.purch)} purchase senilai ${rp(m.value)}.`);
    r.push(`Cost per purchase ${rp(m.cpa)} di bawah batas ${rp(T.cpa)}${m.freq ? `, frequency ${m.freq.toFixed(2)} masih aman` : ""}.`);
    act.push(off ? `${cap(word)} ini Off padahal winning — nyalakan lagi.` : "Naikkan budget 20–30% tiap 48 jam (jangan langsung 2× biar learning nggak reset).");
    if (e.level === "ad") {
      const used = new Set(ADS.filter(a => a.ck === e.ck).map(a => a.acc.id));
      const unused = ACCOUNTS.filter(x => x.pf === e.acc.pf && !used.has(x.id)).map(x => x.name);
      if (unused.length) act.push(`Duplikat iklan ini ke ${unused.slice(0, 3).join(", ")} yang belum pakai konten ini.`);
    }
  } else if (m.purch >= 1 && m.roas >= T.roas && !lowCtr) {
    v = "potential";
    r.push(`ROAS ${f2(m.roas)} sudah di atas target ${T.roas} (${num(m.purch)} purchase, value ${rp(m.value)}), tapi datanya belum cukup untuk scale (butuh ≥ 3 purchase & ROAS ≥ ${f2(T.roas * 1.25)}).`);
    r.push(`CTR ${f2(m.ctr)}%, cost per purchase ${rp(m.cpa)}.`);
    if (off) act.push("Lagi Off — nyalakan lagi untuk dites.");
    act.push("Naikkan budget 15–20%, cek lagi setelah tambah 2–3 purchase.");
  } else if (enough) {
    v = "optimize";
    r.push(`ROAS ${f2(m.roas)} ${m.roas >= T.roas ? "masuk target tapi belum kuat untuk di-scale" : `di bawah target ${T.roas}, belum separah itu untuk dimatikan`} (${num(m.purch)} purchase, spend ${rp(m.spend)}).`);
    if (lowCtr) { r.push(`CTR ${f2(m.ctr)}% di bawah ${T.ctr}% → masalah di 3 detik pertama / thumbnail.`); act.push("Ganti hook & thumbnail, tes 3 opening baru dengan body video yang sama."); }
    else if (m.cvr < 2) { r.push(`CTR bagus (${f2(m.ctr)}%) tapi CVR cuma ${f2(m.cvr)}% → orang klik tapi nggak checkout.`); act.push("Cek harga, voucher & halaman produk Shopee — tes bundling / gratis ongkir."); }
    if (hiFreq) { r.push(`Frequency ${m.freq.toFixed(2)} → audience mulai jenuh.`); act.push("Refresh creative atau perluas audience."); }
    if (!act.length) act.push("Tahan budget, tes variasi angle & CTA baru.");
  } else {
    v = "watch";
    r.push(`Data belum cukup: spend ${rp(m.spend)} dari minimal ${rp(minS)}${m.purch ? `, ROAS ${f2(m.roas)}` : ", belum ada purchase"}.`);
    if (m.atc >= 2) r.push(`Sudah ${num(m.atc)} add to cart (cost per ATC ${rp(m.cpatc)}) — sinyal minat ada.`);
    act.push(isToday ? `Data hari ini baru sampai ${hhmm(new Date())}. Untuk keputusan matikan/scale, pakai 7 Hari Terakhir.` : "Biarkan jalan, cek lagi setelah spend mencapai batas minimal.");
  }
  return { v, r, act, score: m.roas * 10 + Math.log10(m.purch + 1) * 8 + m.ctr * 2 };
}
function computeAI() { AI = new Map(); [...CAMPAIGNS, ...ADSETS, ...ADS].forEach(e => AI.set(e.id, analyze(e))); }
const badge = v => `<span class="vb ${V[v].cls}">${V[v].icon} ${V[v].label}</span>`;


// =========================================================
// CLAUDE AI — analisis mendalam (API key di config.js)
// =========================================================
const CL = new Map();                                   // hasil per entity + tanggal + pencarian
const clKey = id => `${id}|${S.from}|${S.to}|${S.q}`;
const clGet = id => CL.get(clKey(id));
const CL_RULES = `Kamu media buyer senior Meta Ads untuk brand skincare Indonesia yang jualan lewat CPAS (Collaborative Ads ke Shopee).
Cara menilai:
- Pakai HANYA angka di data. Jangan mengarang angka atau asumsi yang tidak ada di data.
- Timbang: ROAS vs target, cost per purchase vs batas, VOLUME purchase (1-2 purchase belum signifikan), tren harian (naik/turun/stabil), CTR (kualitas hook/thumbnail), cost per ATC dan rasio ATC→purchase (masalah harga/voucher/checkout), frequency (kejenuhan), umur campaign (masih learning atau tidak), dan perbandingan dengan konten yang sama di akun lain kalau ada.
- Data "hari ini" belum lengkap (baru sampai jam tertentu) — jangan menyuruh matikan hanya karena data jam pagi.
- Kalau datanya terlalu sedikit untuk yakin, bilang jujur: verdict "watch" dan confidence rendah.
- Bahasa Indonesia santai tapi tajam, selalu sebut angka spesifik.
- Pakai juga hook rate (video 3 detik/impressions), hold rate (ThruPlay/impressions), cost per VC, CPC, cost per ATC untuk menentukan MASALAHNYA DI MANA (creative, Shopee, atau audience).
- Jangan pernah menyebut nama model/AI di belakangmu; kamu adalah Montera AI.
Verdict: "scale" (naikkan budget), "potential" (berpotensi winning, tes naikkan pelan), "optimize" (perbaiki creative/offer), "kill" (matikan, bikin rugi), "watch" (pantau, data belum cukup).`;
const CL_ONE = CL_RULES + `
Balas HANYA JSON valid tanpa teks lain:
{"verdict":"scale|potential|optimize|kill|watch","confidence":0-100,"summary":"1-2 kalimat","reasons":["alasan + angka"],"actions":["langkah konkret"],"budget_change_pct":angka (negatif=turunkan, 0=tetap, -100=matikan),"risk":"apa yang bisa bikin penilaian ini salah"}`;
const CL_BULK = CL_RULES + `
Kamu menerima daftar banyak campaign/ad set/iklan. Nilai SETIAP item. Balas HANYA JSON array valid tanpa teks lain, urut dari yang paling mendesak (kill dulu, lalu scale, potential, optimize, watch):
[{"id":"id item","verdict":"...","confidence":0-100,"summary":"1 kalimat + angka","action":"1 langkah konkret","budget_change_pct":angka}]`;

const mObj = m => ({ spend: Math.round(m.spend), purchases: m.purch, conversion_value: Math.round(m.value), roas: +m.roas.toFixed(2), cost_per_purchase: m.cpa ? Math.round(m.cpa) : null,
  content_views: m.vc, cost_per_vc: m.cpvc ? Math.round(m.cpvc) : null, cpc_link: m.cpc ? Math.round(m.cpc) : null, hook_rate_pct: +m.hook.toFixed(2), hold_rate_pct: +m.hold.toFixed(2),
  adds_to_cart: m.atc, cost_per_atc: m.cpatc ? Math.round(m.cpatc) : null, impressions: m.impr, link_clicks: m.clicks, ctr_pct: +m.ctr.toFixed(2), frequency: m.freq ? +m.freq.toFixed(2) : null });
const periodInfo = () => `${S.from} s/d ${S.to}${S.to === TODAY ? ` (data hari ini baru sampai jam ${hhmm(new Date())})` : ""}`;
const targetInfo = () => ({ roas_min: TARGET.roas, cost_per_purchase_max: TARGET.cpa, ctr_min_pct: TARGET.ctr });
const panelDays = e => e.level === "campaign" && !QSET && Object.keys(e.days).length ? [e.days] : adsIn(e).map(a => a.days);

function entityPayload(e) {
  const p = { level: e.level, nama: e.name, akun: e.acc.name, delivery: delivery(e).t, budget: e.budget ? `Rp${Math.round(e.budget)} ${e.budgetType}` : "pakai budget level lain",
    mulai: e.start, periode: periodInfo(), target: targetInfo(), total: mObj(M.get(e.id)),
    harian: dailyFrom(panelDays(e)).filter(r => r.m.spend > 0).reverse().map(r => ({ tanggal: r.d, ...mObj(r.m) })),
    penilaian_rule_engine: { verdict: AI.get(e.id).v, alasan: AI.get(e.id).r } };
  if (e.level === "ad") {
    p.primary_text = e.desc;
    p.konten_sama_di_akun_lain = ADS.filter(a => a.ck === e.ck && a !== e).slice(0, 10).map(a => ({ akun: a.acc.name, delivery: delivery(a).t, ...mObj(AM.get(a.id)) }));
  } else {
    p.iklan_di_dalamnya = adsIn(e).sort((x, y) => AM.get(y.id).spend - AM.get(x.id).spend).slice(0, 15)
      .map(a => ({ nama: a.name, delivery: delivery(a).t, primary_text: a.desc.slice(0, 160), ...mObj(AM.get(a.id)) }));
  }
  return p;
}
// Logo Montera (pengganti ikon bintang)
const MLOGO = `<svg class="mlogo" viewBox="0 0 26 24" aria-hidden="true"><defs><linearGradient id="mlg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#7b4dff"/><stop offset="1" stop-color="#4b2cc9"/></linearGradient></defs><g fill="url(#mlg)" transform="skewX(-14) translate(5 0)"><rect x="1" y="11" width="4.2" height="11" rx="2.1"/><rect x="7.4" y="6" width="4.2" height="16" rx="2.1"/><rect x="13.8" y="1.5" width="4.2" height="20.5" rx="2.1"/></g></svg>`;
const AI_CFG = CFG.ai || { provider: "gemini" };
const AI_NAME = "Montera AI";
function parseJSONText(text) {
  text = String(text || "").replace(/<think>[\s\S]*?<\/think>/g, "").replace(/```json|```/g, "").trim();
  try { return JSON.parse(text); }
  catch { const a = Math.min(...["{", "["].map(ch => text.indexOf(ch)).filter(i => i >= 0)), b = Math.max(text.lastIndexOf("}"), text.lastIndexOf("]")); return JSON.parse(text.slice(a, b + 1)); }
}
async function askClaude(system, payload) { return askAI(system, [{ role: "user", content: "Data (JSON):\n" + JSON.stringify(payload) }]); }
async function askAI(system, msgs) {   // provider & API key diatur di config.php (server)
  const j = await api("ai", { system, messages: msgs, mode: store.get("ac_ai_mode", "deep") });
  if (j.error) throw new Error(j.error.message || j.error);
  const out = parseJSONText(j.text);
  if (out && typeof out === "object") out._model = j.model;
  return out;
}
function claudeHTML(e) {
  const r = clGet(e.id);
  if (!r) return `<button class="btn claude full" data-claude="${e.id}">${MLOGO} Analisis mendalam dengan ${AI_NAME}</button>`;
  if (r.loading) return `<div class="cl-card thinking"><div class="th-h">${MLOGO}<b>${AI_NAME} sedang membedah ${esc(LVL[e.level][0])} ini…</b></div><div class="muted sm">Membaca tren harian, hook & hold rate, CPP, cost per ATC, dan perbandingan konten di akun lain.</div></div>`;
  if (r.error) return `<div class="cl-card errc">${AI_NAME} gagal: ${esc(r.error)} <button class="btn sm-btn" data-claude="${e.id}">Coba lagi</button></div>`;
  const x = r.data, v = V[x.verdict] ? x.verdict : "watch";
  return `<div class="cl-card ${V[v].cls}">
    <div class="cl-h"><b>${MLOGO} Analisis ${AI_NAME}</b>${badge(v)}<span class="chip">Keyakinan ${x.confidence ?? "?"}%</span>${x.budget_change_pct != null ? `<span class="chip">Budget ${x.budget_change_pct > 0 ? "+" : ""}${x.budget_change_pct}%</span>` : ""}</div>
    <p class="cl-sum">${esc(x.summary || "")}</p>
    ${x.reasons?.length ? `<h5>Kenapa</h5><ul>${x.reasons.map(t => `<li>${esc(t)}</li>`).join("")}</ul>` : ""}
    ${x.actions?.length ? `<h5>Yang harus dilakukan</h5><ol>${x.actions.map(t => `<li>${esc(t)}</li>`).join("")}</ol>` : ""}
    ${x.risk ? `<p class="muted sm">⚠ ${esc(x.risk)}</p>` : ""}
    <button class="btn sm-btn" data-claude="${e.id}">Analisis ulang</button>
  </div>`;
}
function refreshClaudeUI() { if (S.panel) { const el = $("#clBox"); if (el) el.innerHTML = claudeHTML(BY_ID.get(S.panel)); } renderAIBox(); renderTable(); }
async function runClaude(id) {
  const e = BY_ID.get(id); if (!e) return;
  const k = clKey(id); CL.set(k, { loading: true }); refreshClaudeUI();
  try { CL.set(k, { data: await askClaude(CL_ONE, entityPayload(e)) }); }
  catch (err) { CL.set(k, { error: err.message }); }
  refreshClaudeUI();
}
S.clBulk = null;
async function runClaudeBulk() {
  const rows = baseRows().filter(e => M.get(e.id).spend > 0).sort((x, y) => M.get(y.id).spend - M.get(x.id).spend).slice(0, 40);
  if (!rows.length) return;
  S.clBulk = { loading: true, n: rows.length }; renderAIBox();
  const payload = { periode: periodInfo(), target: targetInfo(), level: S.level,
    items: rows.map(e => ({ id: e.id, nama: e.name, akun: e.acc.name, delivery: delivery(e).t, mulai: e.start, budget: e.budget ? Math.round(e.budget) : null, ...mObj(M.get(e.id)),
      harian: RANGE.length > 1 ? dailyFrom(panelDays(e)).filter(r => r.m.spend > 0).reverse().map(r => [r.d, Math.round(r.m.spend), r.m.purch, Math.round(r.m.value), r.m.atc]) : undefined,
      iklan_teratas: e.level === "ad" ? undefined : adsIn(e).sort((x, y) => AM.get(y.id).spend - AM.get(x.id).spend).slice(0, 5).map(a => ({ nama: a.name, ...mObj(AM.get(a.id)) })),
      primary_text: e.level === "ad" ? e.desc.slice(0, 200) : undefined })),
    format_harian: "[tanggal, spend, purchases, conversion_value, adds_to_cart]" };
  try {
    const list = await askClaude(CL_BULK, payload);
    (Array.isArray(list) ? list : []).forEach(x => { if (BY_ID.has(String(x.id))) CL.set(clKey(String(x.id)), { data: { ...x, reasons: [x.summary], actions: [x.action] } }); });
    S.clBulk = { list: (Array.isArray(list) ? list : []).filter(x => BY_ID.has(String(x.id))) };
  } catch (err) { S.clBulk = { error: err.message }; }
  renderAIBox(); renderTable();
}
function clBulkHTML() {
  const b = S.clBulk;
  const btn = `<button class="btn claude" id="clBulkBtn">${MLOGO} Minta ${AI_NAME} nilai ${Math.min(40, baseRows().filter(e => M.get(e.id).spend > 0).length)} ${LVL[S.level][1]} teratas</button>`;
  if (!b) return `<div class="cl-bulk">${btn}<span class="muted sm">${AI_NAME} membaca semua angka + tren lalu menentukan mana yang dinaikkan, diturunkan, atau dimatikan.</span></div>`;
  if (b.loading) return `<div class="cl-bulk thinking"><div class="th-h">${MLOGO}<b>${AI_NAME} sedang menilai ${b.n} ${LVL[S.level][1]}…</b></div><div class="muted sm">Menghitung boncos vs winning dan menyusun prioritas.</div></div>`;
  if (b.error) return `<div class="cl-bulk errc">${AI_NAME} gagal: ${esc(b.error)} ${btn}</div>`;
  return `<div class="cl-bulk"><div class="cl-h"><b>${MLOGO} Prioritas dari ${AI_NAME}</b><span class="muted sm">${periodLabel()} · ${rangeText()}</span>${btn}</div>
    ${b.list.map(x => { const e = BY_ID.get(String(x.id)), v = V[x.verdict] ? x.verdict : "watch"; return `<button class="prow" data-open="${e.id}">${badge(v)}<div><b>${esc(e.name)}</b><small>${esc(e.acc.name)} · ${esc(x.summary)}</small><small class="cl-act">→ ${esc(x.action)}</small></div><span class="pv"><b>${x.budget_change_pct != null ? (x.budget_change_pct > 0 ? "+" : "") + x.budget_change_pct + "%" : ""}</b><small>${x.confidence ?? "?"}% yakin</small></span></button>`; }).join("")}</div>`;
}

// =========================================================
// ROWS
// =========================================================
const levelList = () => S.level === "campaign" ? CAMPAIGNS : S.level === "adset" ? ADSETS : ADS;
const F0 = () => ({ funnel: "all", deliv: "all", verdict: "all", spendMin: "", roasMin: "", roasMax: "", purchMin: "", hasSpend: false, hideReject: false });
S.f = F0();
const escRe = x => x.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
const FUNNELS = (CFG.funnels || [{ label: "TOFU", match: ["tofu"] }, { label: "MOFU", match: ["mofu"] }, { label: "BOFU", match: ["bofu"] }, { label: "SHOPEE", match: ["shopee"] }])
  .map(f => ({ ...f, re: new RegExp(`(^|[^a-z0-9])(${f.match.map(escRe).join("|")})`, "i") }));
const campName = e => e.level === "campaign" ? e.name : e.camp.name;
const inFunnel = (e, label) => label === "Lainnya" ? !FUNNELS.some(f => f.re.test(campName(e))) : FUNNELS.find(f => f.label === label)?.re.test(campName(e));
function filterOk(e) {
  const f = S.f, m = M.get(e.id), d = delivery(e).t;
  if (f.funnel !== "all" && !inFunnel(e, f.funnel)) return false;
  if (f.deliv !== "all" && (f.deliv === "Active" ? !["Active", "Learning"].includes(d) : d !== f.deliv)) return false;
  if (f.verdict !== "all") { const c = clGet(e.id)?.data; if ((c && V[c.verdict] ? c.verdict : AI.get(e.id).v) !== f.verdict) return false; }
  if (f.hasSpend && !(m.spend > 0)) return false;
  if (f.spendMin !== "" && m.spend < +f.spendMin) return false;
  if (f.purchMin !== "" && m.purch < +f.purchMin) return false;
  if (f.roasMin !== "" && (!m.purch || m.roas < +f.roasMin)) return false;
  if (f.roasMax !== "" && m.purch && m.roas > +f.roasMax) return false;
  if (f.hideReject && /reject/i.test(e.name + " " + (e.camp?.name || ""))) return false;
  return true;
}
const filterCount = () => Object.entries(S.f).filter(([k, v]) => v !== "" && v !== false && v !== "all").length;
const baseRows = () => levelList().filter(e => adsIn(e).length > 0 && (M.get(e.id).spend > 0 || isOn(e)) && filterOk(e));
function visibleRows() {
  let rows = baseRows();
  if (S.view === "ai" && S.aiFilter) rows = rows.filter(e => AI.get(e.id).v === S.aiFilter);
  if (S.view === "winning") return rows.filter(e => ["scale", "potential"].includes(AI.get(e.id).v)).sort((x, y) => AI.get(y.id).score - AI.get(x.id).score);
  const { k, dir } = S.sort;
  const val = e => k === "name" ? e.name : k === "ai" ? AI.get(e.id).score : k === "budget" ? (e.budget ?? -1) : (M.get(e.id)[k] ?? -1);
  return rows.sort((x, y) => { const a = val(x), b = val(y); return (typeof a === "string" ? a.localeCompare(b) : a - b) * dir; });
}

// =========================================================
// RENDER
// =========================================================
const LOGO = `<span class="plogo"><svg viewBox="0 0 36 24"><path d="M8.5 2C4.4 2 1.5 6.9 1.5 12.4c0 5.6 2.3 9.6 6.2 9.6 2.9 0 4.8-2.2 7.4-6.6l1.9-3.2 1.7 2.8c2.9 4.9 5 7 8.1 7 3.8 0 6.1-3.9 6.1-9.4C32.9 6.6 30 2 25.6 2c-2.8 0-4.9 2-7.5 5.8C15.6 4 13.4 2 8.5 2Z" fill="currentColor"/></svg></span>`;
const rangeText = () => S.from === S.to ? (S.from === TODAY ? `${fmtD(S.from)}, 00:00–${hhmm(new Date())}` : fmtD(S.from)) : `${fmtD(S.from)} – ${fmtD(S.to)}`;
const periodLabel = () => S.preset === "custom" ? "Custom" : PRESET[S.preset][0];

function renderHeader() {
  const acc = ACCOUNTS.find(a => a.id === S.acc);
  $("#scopeLabel").innerHTML = acc ? `${LOGO}<b>${esc(acc.name)}</b><span class="muted">ID ${acc.id}</span>` : `${LOGO}<b>Semua akun</b><span class="muted">${ACCOUNTS.filter(pfOk).length} ad accounts${S.pf !== "all" ? " · " + esc(S.pf) : ""}</span>`;
  const errs = ACCOUNTS.filter(a => a.err);
  $("#srcBadge").className = "src " + (errs.length ? "warn" : "live");
  $("#srcBadge").innerHTML = `<span class="flame">🔥</span><b>LIVE</b>${errs.length ? ` · ${errs.length} akun gagal sync` : " · Meta API"}`;
  $("#updated").textContent = S.lastSync ? `${S.fromCache ? "Data tersimpan" : "Updated"} ${hhmm(S.lastSync)}${S.syncSec != null && !S.fromCache ? ` (${S.syncSec} dtk)` : ""}${S.busy ? " · memperbarui…" : S.to === TODAY ? ` · auto ${CFG.autoRefreshMinutes} mnt` : ""}` : "Menghubungkan…";
  $("#dpLabel").textContent = periodLabel();
  $("#periodRange").textContent = rangeText();
  const kills = ADS.filter(a => isOn(a) && pfOk(a.acc) && AI.get(a.id)?.v === "kill").length;
  $("#railBadge").textContent = kills; $("#railBadge").hidden = !kills;
}

function accCard(sum, sub, m, extra, sel, attr, live, err) {
  return `<button class="acc ${sel ? "sel" : ""} ${err ? "errc" : ""}" ${attr}>
    <div class="acc-h">${sum ? `<span class="plogo all">Σ</span>` : LOGO}<div><b>${esc(sub[0])}</b><small>${esc(sub[1])}</small></div>${live != null ? `<i class="ddot ${live ? "on" : "off"}" title="${live} iklan aktif"></i>` : ""}</div>
    ${err ? `<p class="acc-err">Gagal sync: ${esc(err)}</p>` : `<div class="acc-m">
      <div><small>Amount spent</small><b>${rp(m.spend)}</b></div>
      <div><small>Conversion value</small><b>${rp(m.value)}</b></div>
      <div><small>Purchase ROAS</small><b class="${!m.spend ? "" : m.roas >= TARGET.roas ? "good" : "bad"}">${m.purch ? f2(m.roas) : "—"}</b></div>
      <div><small>Purchases</small><b>${num(m.purch)}</b></div>
      <div><small>Cost per purchase</small><b>${rp(m.cpa)}</b></div>
      <div><small>Adds to cart</small><b>${num(m.atc)}</b></div>
    </div>`}
    <div class="acc-f">${extra}</div>
  </button>`;
}
function renderAccounts() {
  const pfs = [...new Set(ACCOUNTS.map(a => a.pf))];
  $("#pfPills").innerHTML = [["all", "Semua", ACCOUNTS.length], ...pfs.map(p => [p, p, ACCOUNTS.filter(a => a.pf === p).length])]
    .map(([k, t, n]) => `<button class="pill ${S.pf === k ? "on" : ""}" data-pf="${esc(k)}">${esc(t)}<span>${n}</span></button>`).join("");
  let accs = ACCOUNTS.filter(a => pfOk(a) && (a.name + " " + a.id + " " + a.pf).toLowerCase().includes(S.accQ));
  if (S.q) accs = accs.filter(a => a.ads.some(qOk));
  const tot = sumM(accs.filter(a => !a.err).map(accMetrics));
  const allAds = accs.flatMap(a => a.ads.filter(qOk));
  const qInfo = ads => S.q ? `<span class="qtag">"${esc(S.q)}": ${ads.length} iklan · ${new Set(ads.map(a => a.camp.id)).size} campaign</span>` : "";
  let html = `<div class="acc-group"><div class="acc-group-label">${S.q ? `Total "${esc(S.q)}"` : "Total"} · ${periodLabel()}</div><div class="acc-row">${accCard(true, [S.q ? `${accs.length} akun cocok` : "Semua akun", `${accs.length} ad accounts`], tot, qInfo(allAds), !S.acc, `data-acc=""`, null)}</div></div>`;
  const groups = {};
  accs.forEach(a => (groups[a.pf] ||= []).push(a));
  Object.entries(groups).forEach(([pf, list]) => {
    html += `<div class="acc-group"><div class="acc-group-label">${esc(pf)} · ${list.length}</div><div class="acc-row">` + list.map(a => {
      const ads = a.ads.filter(qOk), live = ads.filter(isOn).length;
      const s = ads.filter(x => AI.get(x.id)?.v === "scale").length, k = ads.filter(x => isOn(x) && AI.get(x.id)?.v === "kill").length;
      const extra = qInfo(ads) + (s ? `<span class="vb v-scale">▲ ${s}</span>` : "") + (k ? `<span class="vb v-kill">■ ${k} matikan</span>` : "") + (!S.q && !s && !k ? `<span class="muted">${live} iklan aktif</span>` : "");
      return accCard(false, [a.name, `ID: ${a.id}`], a.err ? null : accMetrics(a), a.err ? "" : extra, S.acc === a.id, `data-acc="${a.id}"`, a.err ? null : live, a.err);
    }).join("") + `</div></div>`;
  });
  $("#accStrip").innerHTML = accs.length ? html : `<p class="muted pad">${S.busy && !S.lastSync ? "Mengambil data akun…" : `Tidak ada akun yang cocok${S.q ? ` dengan "${esc(S.q)}"` : ""}.`}</p>`;
}


function renderFilters() {
  const f = S.f, n = filterCount();
  $("#filters").innerHTML = `
    <label>Funnel <select data-f="funnel">${["all", ...FUNNELS.map(x => x.label), "Lainnya"].map(v => `<option value="${v}" ${f.funnel === v ? "selected" : ""}>${v === "all" ? "Semua" : v}</option>`).join("")}</select></label>
    <label>Delivery <select data-f="deliv">${["all", "Active", "Off", "Rejected", "In review", "Error"].map(v => `<option value="${v}" ${f.deliv === v ? "selected" : ""}>${v === "all" ? "Semua" : v}</option>`).join("")}</select></label>
    <label>Rekomendasi <select data-f="verdict"><option value="all">Semua</option>${Object.keys(V).map(k => `<option value="${k}" ${f.verdict === k ? "selected" : ""}>${V[k].label}</option>`).join("")}</select></label>
    <label>Spend ≥ <input type="number" min="0" step="1000" data-f="spendMin" value="${f.spendMin}" placeholder="Rp"></label>
    <label>ROAS <input type="number" min="0" step="0.1" data-f="roasMin" value="${f.roasMin}" placeholder="min"> – <input type="number" min="0" step="0.1" data-f="roasMax" value="${f.roasMax}" placeholder="max"></label>
    <label>Purchases ≥ <input type="number" min="0" step="1" data-f="purchMin" value="${f.purchMin}"></label>
    <label class="ck"><input type="checkbox" data-f="hasSpend" ${f.hasSpend ? "checked" : ""}> Hanya yang ada spend</label>
    <label class="ck"><input type="checkbox" data-f="hideReject" ${f.hideReject ? "checked" : ""}> Sembunyikan "REJECT"</label>
    ${n ? `<button class="btn sm-btn" id="fReset">Reset filter (${n})</button>` : ""}`;
}


function renderFunnels() {
  const camps = CAMPAIGNS.filter(c => adsIn(c).length && M.get(c.id).spend > 0);
  const groups = [...FUNNELS.map(f => ({ label: f.label, hint: f.match.join(", "), list: camps.filter(c => f.re.test(c.name)) })),
    { label: "Lainnya", hint: "tidak mengandung kata funnel", list: camps.filter(c => !FUNNELS.some(f => f.re.test(c.name))) }];
  // pembagi = TOTAL spend seluruh iklan di akun yang sedang dilihat (bukan cuma campaign yang cocok)
  const total = QSET ? sumM(ADS.filter(a => scopeOk(a) && qOk(a)).map(a => AM.get(a.id))) : sumM(scopedAccounts().filter(a => !a.err && a.loaded).map(accMetrics));
  $("#funnels").innerHTML = `<div class="fn-h"><b>Per funnel</b><span class="muted sm">dari nama campaign · total spend ${rp(total.spend)} · ${periodLabel()}${S.q ? ` · "${esc(S.q)}"` : ""} · klik untuk filter</span></div><div class="fn-row">` +
    groups.map(g => {
      const m = sumM(g.list.map(c => M.get(c.id))), share = total.spend ? m.spend / total.spend * 100 : 0;
      return `<button class="fn ${S.f.funnel === g.label ? "on" : ""} ${!g.list.length ? "empty" : ""}" data-funnel="${g.label}" title="Kata kunci: ${esc(g.hint)}">
        <div class="fn-t"><b>${g.label}</b><span>${g.list.length} campaign · <b class="fn-pct">${share.toFixed(1)}%</b> dari total</span></div>
        <div class="fn-m"><div><small>Spend</small><b>${rpS(m.spend)}</b></div><div><small>Value</small><b>${rpS(m.value)}</b></div><div><small>ROAS</small><b class="${!m.purch ? "" : m.roas >= TARGET.roas ? "good" : "bad"}">${m.purch ? f2(m.roas) : "—"}</b></div><div><small>Purch.</small><b>${num(m.purch)}</b></div><div><small>CPA</small><b>${rpS(m.cpa)}</b></div><div><small>ATC</small><b>${num(m.atc)}</b></div></div>
        <div class="fn-bar"><i style="width:${share}%"></i></div></button>`;
    }).join("") + `</div>`;
}

function renderViews() {
  const views = [["all", "All ads"], ["ai", `${MLOGO} Montera AI`], ["winning", "🏆 Winning ads"], ["content", "▶ Winning content"], ["daily", "📅 Rekap harian"], ["check", "🔍 Cek data"]];
  $("#views").innerHTML = views.map(([k, t]) => `<button class="view ${S.view === k ? "on" : ""}" data-view="${k}">${t}</button>`).join("");
  $$("#levelTabs button").forEach(b => { b.classList.toggle("on", b.dataset.level === S.level); b.disabled = ["content", "daily", "check"].includes(S.view); });
}

function renderQSummary() {
  const box = $("#qSummary");
  if (!S.q) { box.innerHTML = ""; return; }
  const ads = ADS.filter(a => scopeOk(a) && qOk(a)), m = sumM(ads.map(a => AM.get(a.id)));
  const accs = [...new Set(ads.map(a => a.acc))], cnt = k => ads.filter(a => AI.get(a.id).v === k).length;
  box.innerHTML = `<div class="qbox">
    <div class="qbox-h"><b>"${esc(S.q)}"</b> ditemukan di <b>${ads.length} iklan</b> · ${new Set(ads.map(a => a.camp.id)).size} campaign · ${accs.length} akun · <span class="muted">${periodLabel()} (${rangeText()})</span></div>
    <div class="qbox-m"><span>Amount spent <b>${rp(m.spend)}</b></span><span>Purchases <b>${num(m.purch)}</b></span><span>Conversion value <b>${rp(m.value)}</b></span><span>Purchase ROAS <b class="${m.roas >= TARGET.roas ? "good" : "bad"}">${m.purch ? f2(m.roas) : "—"}</b></span><span>Cost per purchase <b>${rp(m.cpa)}</b></span><span>Adds to cart <b>${num(m.atc)}</b></span><span>Iklan aktif <b>${ads.filter(isOn).length}</b></span></div>
    <div class="chips">${Object.keys(V).filter(cnt).map(k => `<span class="vb ${V[k].cls}">${V[k].icon} ${V[k].label} ${cnt(k)}</span>`).join("")}</div>
    <p class="muted sm note">Angka pencarian = jumlah per iklan. Bisa sedikit di atas Ads Manager karena Meta men-dedup purchase yang sama dari beberapa iklan.</p>
  </div>`;
}

function renderAIBox() {
  const box = $("#aiBox");
  if (S.view !== "ai") { box.innerHTML = ""; return; }
  const rows = baseRows(), g = k => rows.filter(e => AI.get(e.id).v === k);
  const sub = {
    scale: l => `Value ${rpS(sumM(l.map(e => M.get(e.id))).value)}`,
    potential: l => `Spend ${rpS(sumM(l.map(e => M.get(e.id))).spend)}`,
    optimize: l => `Spend ${rpS(sumM(l.map(e => M.get(e.id))).spend)}`,
    kill: l => { const m = sumM(l.map(e => M.get(e.id))); return `Boncos ±${rpS(Math.max(0, m.spend - m.value))}`; },
    watch: () => "Data belum cukup"
  };
  const killLive = g("kill").filter(isOn);
  box.innerHTML = `<div class="ai-tiles">${Object.keys(V).map(k => `<button class="tile ${V[k].cls} ${S.aiFilter === k ? "on" : ""}" data-ai="${k}"><span class="tl">${V[k].icon} ${V[k].label}</span><b>${g(k).length}</b><small>${sub[k](g(k))}</small></button>`).join("")}</div>
    <div class="ai-bar">
      <span class="muted">Dinilai dari ${periodLabel()} (${rangeText()}). Target:</span>
      <label>ROAS ≥ <input type="number" step="0.1" min="0" id="tRoas" value="${TARGET.roas}"></label>
      <label>Cost/purchase ≤ Rp <input type="number" step="1000" min="0" id="tCpa" value="${TARGET.cpa}"></label>
      <label>CTR ≥ <input type="number" step="0.1" min="0" id="tCtr" value="${TARGET.ctr}">%</label>
      <button class="btn sm-btn" id="tSave">Terapkan</button>
      <span class="muted sm">Minimal spend untuk keputusan: ${rp(minSpend())}</span>
      <div class="spacer"></div>
      ${S.aiFilter === "kill" && killLive.length ? `<button class="btn danger" id="killAll">Matikan semua (${killLive.length})</button>` : ""}
    </div>${clBulkHTML()}`;
}

// ---------- TABLE ----------
const COLS = [
  { k: "name", t: () => LVL[S.level][2], cls: "c-name" },
  { k: "delivery", t: () => "Delivery", nosort: 1 },
  { k: "ai", t: () => "Rekomendasi AI" },
  { k: "budget", t: () => "Budget", n: 1 }
];
const pct = v => v ? f2(v) + "%" : "—";
const MCOLS = [   // urutan sama dengan view "Columns: CPAS" di Ads Manager
  { k: "spend", t: "Amount spent", c: m => rp(m.spend), f: t => [rp(t.spend), "Total spent"], x: m => Math.round(m.spend) },
  { k: "purch", t: "Purchases with shared items", c: m => m.purch ? num(m.purch) : "—", f: t => [num(t.purch), "Total"], x: m => m.purch },
  { k: "value", t: "Purchases conversion value", c: m => rp(m.value), f: t => [rp(t.value), "Total"], x: m => Math.round(m.value) },
  { k: "roas", t: "Purchase ROAS", c: m => `<b class="${!m.purch ? "" : m.roas >= TARGET.roas ? "good" : "bad"}">${m.purch ? f2(m.roas) : "—"}</b>`, f: t => [t.purch ? f2(t.roas) : "—", "Average"], x: m => +m.roas.toFixed(2) },
  { k: "cpatc", t: "Cost per ATC", c: m => rp(m.cpatc), f: t => [rp(t.cpatc), "Per action"], x: m => m.cpatc ? Math.round(m.cpatc) : null },
  { k: "cpvc", t: "Cost per VC", c: m => rp(m.cpvc), f: t => [rp(t.cpvc), "Per action"], x: m => m.cpvc ? Math.round(m.cpvc) : null },
  { k: "cpc", t: "CPC (link)", c: m => rp(m.cpc), f: t => [rp(t.cpc), "Per action"], x: m => m.cpc ? Math.round(m.cpc) : null },
  { k: "cpa", t: "CPP", c: m => rp(m.cpa), f: t => [rp(t.cpa), "Per purchase"], x: m => m.cpa ? Math.round(m.cpa) : null },
  { k: "hook", t: "Hook rate", c: m => pct(m.hook), f: t => [pct(t.hook), "3 dtk / impr."], x: m => +m.hook.toFixed(2) },
  { k: "hold", t: "Hold rate", c: m => pct(m.hold), f: t => [pct(t.hold), "ThruPlay / impr."], x: m => +m.hold.toFixed(2) },
  { k: "freq", t: "Frequency", c: m => m.freq ? f2(m.freq) : "—", f: t => [t.freq ? f2(t.freq) : "—", "Rata-rata"], x: m => m.freq ? +m.freq.toFixed(2) : null },
  { k: "atc", t: "Adds to cart", c: m => m.atc ? num(m.atc) : "—", f: t => [num(t.atc), "Total"], x: m => m.atc },
  { k: "vc", t: "Content views", c: m => m.vc ? num(m.vc) : "—", f: t => [num(t.vc), "Total"], x: m => m.vc },
  { k: "ctr", t: "CTR (link)", c: m => m.impr ? f2(m.ctr) + "%" : "—", f: t => [t.impr ? f2(t.ctr) + "%" : "—", "Per impr."], x: m => +m.ctr.toFixed(2) },
  { k: "impr", t: "Impressions", c: m => num(m.impr), f: t => [num(t.impr), "Total"], x: m => m.impr }
];
const mhead = sortable => MCOLS.map(c => `<th class="num" ${sortable && S.view !== "winning" ? `data-sort="${c.k}"` : ""}>${c.t}${sortable && S.sort.k === c.k && S.view !== "winning" ? (S.sort.dir < 0 ? " ↓" : " ↑") : ""}</th>`).join("");
function thumb(ad) {
  if (ad.thumb) return `<img class="thumb" src="${esc(ad.thumb)}" alt="" loading="lazy">`;
  const hue = [...(ad.ck || ad.name)].reduce((h, c) => h + c.charCodeAt(0), 0) % 360;
  return `<span class="thumb" style="--h:${hue}">▶</span>`;
}
function budgetCell(e) {
  if (e.budget) return `${rp(e.budget)}<small>${e.budgetType}</small>`;
  if (e.level === "campaign") return `<span class="muted sm">Using ad set budget</span>`;
  if (e.level === "adset") return `<span class="muted sm">Using campaign budget</span>`;
  return `<span class="muted sm">${e.adset.budget ? "Using ad set budget" : "Using campaign budget"}</span>`;
}
const mcells = m => MCOLS.map(c => `<td class="num">${c.c(m)}</td>`).join("");
const mfoot = t => MCOLS.map(c => { const [v, sm] = c.f(t); return `<td class="num"><b>${v}</b><small>${sm}</small></td>`; }).join("");

function renderTable() {
  if (!S.lastSync) { $("#thead").innerHTML = ""; $("#tbody").innerHTML = `<tr><td class="empty">Mengambil data dari Meta API…</td></tr>`; $("#tfoot").innerHTML = ""; return; }
  if (S.view === "content") return renderContent();
  if (S.view === "daily") return renderDaily();
  if (S.view === "check") return renderCheck();
  const rows = visibleRows(), allSel = rows.length && rows.every(e => S.sel.has(e.id));
  $("#thead").innerHTML = `<tr><th class="c-chk"><input type="checkbox" id="chkAll" ${allSel ? "checked" : ""} aria-label="Pilih semua"></th><th class="c-tog">Off / On</th>${COLS.map(c =>
    `<th class="${c.cls || ""} ${c.n ? "num" : ""}" ${c.nosort || S.view === "winning" ? "" : `data-sort="${c.k}"`}>${c.t()}${S.sort.k === c.k && S.view !== "winning" ? (S.sort.dir < 0 ? " ↓" : " ↑") : ""}</th>`).join("")}${mhead(true)}</tr>`;
  $("#tbody").innerHTML = rows.length ? rows.map((e, i) => {
    const m = M.get(e.id), ai = AI.get(e.id), d = delivery(e), n = adsIn(e).length, part = S.q && e.level !== "ad" && n < e.ads.length;
    const sub = e.level === "ad"
      ? `<div class="sub">${esc(e.acc.name)} · ${esc(e.camp.name)}</div><div class="desc">${esc(e.desc)}</div>`
      : `<div class="sub">${esc(e.acc.name)}${e.level === "adset" ? " · " + esc(e.camp.name) : ""} · ${part ? `<b>${n} dari ${e.ads.length} iklan cocok</b>` : `${e.ads.length} iklan`}</div>`;
    return `<tr data-id="${e.id}" class="${S.sel.has(e.id) ? "sel" : ""}">
      <td class="c-chk"><input type="checkbox" data-chk="${e.id}" ${S.sel.has(e.id) ? "checked" : ""} aria-label="Pilih"></td>
      <td class="c-tog"><button class="tog ${e.on ? "on" : ""}" data-tog="${e.id}" role="switch" aria-checked="${e.on}" aria-label="On/Off"></button></td>
      <td class="c-name"><div class="nm">${e.level === "ad" ? thumb(e) : ""}<div>${S.view === "winning" ? `<span class="rank">#${i + 1}</span>` : ""}<a data-open="${e.id}">${esc(e.name)}</a>${sub}</div></div></td>
      <td><span class="dl ${d.c}"><i></i>${d.t}</span></td>
      <td class="c-ai">${(() => { const c = clGet(e.id)?.data; return c ? `<span class="clv">${MLOGO} ${AI_NAME}</span>${badge(V[c.verdict] ? c.verdict : "watch")}<div class="why">${esc(c.summary || "")}</div>` : `${badge(ai.v)}<div class="why">${esc(ai.r[0])}</div>`; })()}</td>
      <td class="num">${budgetCell(e)}</td>${mcells(m)}</tr>`;
  }).join("") : `<tr><td colspan="${COLS.length + MCOLS.length + 2}" class="empty">Tidak ada ${LVL[S.level][1]} dengan delivery untuk filter & tanggal ini.</td></tr>`;
  // footer = total akun (dedup Meta, sama dengan Ads Manager) kalau tanpa pencarian/filter AI
  const accLevel = !QSET && S.view === "all";
  const t = accLevel ? sumM(scopedAccounts().filter(a => !a.err).map(accMetrics)) : sumM(rows.map(e => M.get(e.id)));
  $("#tfoot").innerHTML = `<tr><td class="c-chk"></td><td class="c-tog"></td><td class="c-name"><b>Results from ${rows.length} ${LVL[S.level][1]}</b><div class="sub">${accLevel ? "Total akun (sama dengan Ads Manager)" : "Jumlah baris"} · ${rangeText()}</div></td><td></td><td></td><td></td>${mfoot(t)}</tr>`;
  renderSel();
}

function renderContent() {
  const ads = ADS.filter(a => scopeOk(a) && qOk(a)), groups = {};
  ads.forEach(a => (groups[a.ck] ||= { name: a.name, ads: [] }).ads.push(a));
  const rows = Object.values(groups).map(g => ({ ...g, m: sumM(g.ads.map(a => AM.get(a.id))), accs: [...new Set(g.ads.map(a => a.acc))] }))
    .filter(g => g.m.spend > 0).sort((x, y) => (y.m.purch ? y.m.roas : -1) - (x.m.purch ? x.m.roas : -1) || y.m.spend - x.m.spend);
  $("#thead").innerHTML = `<tr><th class="c-chk"></th><th class="c-tog">#</th><th class="c-name">Konten (nama iklan)</th><th>Dipakai di</th><th>Rekomendasi per iklan</th>${mhead(false)}</tr>`;
  $("#tbody").innerHTML = rows.length ? rows.map((g, i) => {
    const cnt = k => g.ads.filter(a => AI.get(a.id).v === k).length;
    return `<tr data-content="${esc(g.ads[0].ck)}">
      <td class="c-chk"></td><td class="c-tog"><span class="rank">${i + 1}</span></td>
      <td class="c-name"><div class="nm">${thumb(g.ads[0])}<div><a>${esc(g.name)}</a><div class="desc">${esc(g.ads[0].desc)}</div></div></div></td>
      <td><b>${g.accs.length} akun</b> · ${g.ads.length} iklan<div class="chips sm">${g.accs.slice(0, 4).map(a => `<span class="chip">${esc(a.name)}</span>`).join("")}${g.accs.length > 4 ? `<span class="chip">+${g.accs.length - 4}</span>` : ""}</div></td>
      <td><div class="chips sm">${Object.keys(V).filter(cnt).map(k => `<span class="vb ${V[k].cls}">${V[k].icon} ${cnt(k)}</span>`).join("")}</div></td>${mcells(g.m)}</tr>`;
  }).join("") : `<tr><td colspan="${MCOLS.length + 5}" class="empty">Belum ada konten dengan spend di tanggal ini.</td></tr>`;
  $("#tfoot").innerHTML = `<tr><td class="c-chk"></td><td class="c-tog"></td><td class="c-name"><b>${rows.length} konten</b><div class="sub">Konten = nama iklan yang sama di beberapa akun</div></td><td></td><td></td>${mfoot(sumM(rows.map(g => g.m)))}</tr>`;
  renderSel();
}

function dailyFrom(daysList) {
  return RANGE.slice().reverse().map(d => {
    const m = zero(); daysList.forEach(days => { const x = days[d]; if (x) addX(m, x); });
    return { d, m: derive(m) };
  });
}
const dailySource = () => QSET ? ADS.filter(a => scopeOk(a) && qOk(a)).map(a => a.days) : scopedAccounts().filter(a => !a.err).map(a => a.days);
function renderDaily() {
  const rows = dailyFrom(dailySource());
  $("#thead").innerHTML = `<tr><th class="c-chk"></th><th class="c-tog"></th><th class="c-name">Tanggal</th><th></th>${mhead(false)}</tr>`;
  $("#tbody").innerHTML = rows.map(({ d, m }) => `<tr class="norow"><td class="c-chk"></td><td class="c-tog"></td><td class="c-name"><b>${fmtDay(d)}</b>${d === TODAY ? `<div class="sub">s.d. ${hhmm(S.lastSync || new Date())}</div>` : ""}</td><td></td>${mcells(m)}</tr>`).join("");
  $("#tfoot").innerHTML = `<tr><td class="c-chk"></td><td class="c-tog"></td><td class="c-name"><b>Total ${rows.length} hari</b><div class="sub">${S.q ? `"${esc(S.q)}" · ` : ""}${S.acc ? esc(ACCOUNTS.find(a => a.id === S.acc).name) : "semua akun"}${QSET ? "" : " · sama dengan sheet CPAS"}</div></td><td></td>${mfoot(sumM(rows.map(r => r.m)))}</tr>`;
  renderSel();
}

function renderCheck() {   // bandingkan 3 sumber spend dari Meta API supaya bisa dicocokkan dengan Ads Manager / sheet
  const sumD = days => RANGE.reduce((t, d) => t + (days[d]?.[0] || 0), 0);
  const accs = scopedAccounts();
  $("#thead").innerHTML = `<tr><th class="c-chk"></th><th class="c-tog"></th><th class="c-name">Akun</th><th class="num">Spend level akun<small>semua campaign, termasuk yg dihapus</small></th><th class="num">Spend campaign Active+Paused</th><th class="num">Spend jumlah per iklan</th><th class="num">Selisih akun vs campaign</th><th>Dipakai dashboard</th><th class="num">Purchases (akun)</th><th class="num">Conversion value (akun)</th></tr>`;
  let T = [0, 0, 0, 0, 0];
  $("#tbody").innerHTML = accs.map(a => {
    if (a.err) return `<tr class="norow"><td class="c-chk"></td><td class="c-tog"></td><td class="c-name"><b>${esc(a.name)}</b></td><td colspan="7" class="err">Gagal sync: ${esc(a.err)}</td></tr>`;
    const s1 = sumD(a.daysAll), s2 = sumD(a.daysCamp), s3 = a.ads.reduce((t, x) => t + sumD(x.days), 0), m = daysM(a.days);
    T[0] += s1; T[1] += s2; T[2] += s3; T[3] += m.purch; T[4] += m.value;
    const diff = s1 - s2;
    return `<tr class="norow"><td class="c-chk"></td><td class="c-tog"></td><td class="c-name"><b>${esc(a.name)}</b><div class="sub">ID ${a.id}</div></td>
      <td class="num">${rp(s1)}</td><td class="num">${rp(s2)}</td><td class="num">${rp(s3)}</td>
      <td class="num ${Math.abs(diff) >= 1 ? "bad" : "good"}">${Math.abs(diff) >= 1 ? rp(diff) : "cocok"}</td>
      <td>${a.spendSrc === "campaign" ? "Campaign Active+Paused" : "Level akun"}</td><td class="num">${num(m.purch)}</td><td class="num">${rp(m.value)}</td></tr>`;
  }).join("");
  $("#tfoot").innerHTML = `<tr><td class="c-chk"></td><td class="c-tog"></td><td class="c-name"><b>Total ${accs.length} akun</b><div class="sub">${rangeText()}</div></td><td class="num"><b>${rp(T[0])}</b></td><td class="num"><b>${rp(T[1])}</b></td><td class="num"><b>${rp(T[2])}</b></td><td class="num"><b>${rp(T[0] - T[1])}</b></td><td></td><td class="num"><b>${num(T[3])}</b></td><td class="num"><b>${rp(T[4])}</b></td></tr>`;
  renderSel();
}

function renderSel() { const n = S.sel.size; $("#btnOff").disabled = $("#btnOn").disabled = !n; $("#selInfo").textContent = n ? `${n} dipilih` : ""; }

// ---------- PANEL ----------
function trendSvg(daysList) {
  const data = dailyFrom(daysList).reverse().slice(-31);
  if (data.length < 2) return `<p class="muted sm">Pilih rentang ≥ 2 hari untuk melihat tren.</p>`;
  const max = Math.max(1, ...data.map(x => Math.max(x.m.spend, x.m.value))), W = 440, H = 120, bw = W / data.length;
  const bars = data.map((x, i) => {
    const hs = x.m.spend / max * (H - 10), hv = x.m.value / max * (H - 10);
    return `<rect x="${i * bw + 1}" y="${H - hs}" width="${Math.max(1, bw / 2 - 1)}" height="${hs}" class="bs"><title>${fmtD(x.d)} · Spend ${rp(x.m.spend)}</title></rect><rect x="${i * bw + bw / 2}" y="${H - hv}" width="${Math.max(1, bw / 2 - 1)}" height="${hv}" class="bv"><title>${fmtD(x.d)} · Value ${rp(x.m.value)}</title></rect>`;
  }).join("");
  const step = Math.ceil(data.length / 6);
  const lbl = data.map((x, i) => i % step === 0 || i === data.length - 1 ? `<text x="${i * bw + bw / 2}" y="${H + 13}" text-anchor="middle">${parseD(x.d).getDate()}/${parseD(x.d).getMonth() + 1}</text>` : "").join("");
  return `<svg class="trend" viewBox="0 0 ${W} ${H + 16}" role="img" aria-label="Tren harian">${bars}${lbl}</svg><div class="legend"><span><i class="bs"></i>Amount spent</span><span><i class="bv"></i>Conversion value</span></div>`;
}
function dailyTable(daysList) {
  const rows = dailyFrom(daysList).filter(r => r.m.spend > 0 || r.m.purch > 0);
  if (!rows.length) return `<p class="muted sm">Tidak ada delivery di rentang ini.</p>`;
  return `<div class="mini-wrap"><table class="mini-t"><thead><tr><th>Tanggal</th><th>Spend</th><th>ATC</th><th>Purch.</th><th>Value</th><th>ROAS</th></tr></thead><tbody>${rows.map(({ d, m }) =>
    `<tr><td>${fmtDay(d)}</td><td>${rp(m.spend)}</td><td>${num(m.atc)}</td><td>${num(m.purch)}</td><td>${rp(m.value)}</td><td class="${m.purch ? (m.roas >= TARGET.roas ? "good" : "bad") : ""}">${m.purch ? f2(m.roas) : "—"}</td></tr>`).join("")}</tbody></table></div>`;
}
function openPanel(id) {
  const e = BY_ID.get(id); if (!e) return;
  const m = M.get(e.id), ai = AI.get(e.id), d = delivery(e), ads = adsIn(e);
  const daysList = panelDays(e);
  let extra;
  if (e.level === "ad") {
    const same = ADS.filter(a => a.ck === e.ck && a !== e).sort((x, y) => AM.get(y.id).roas - AM.get(x.id).roas);
    extra = `<h4>Konten yang sama di iklan lain (${same.length})</h4>` + (same.map(a => {
      const am = AM.get(a.id);
      return `<button class="prow" data-open="${a.id}">${thumb(a)}<div><b>${esc(a.acc.name)}</b><small>${esc(a.camp.name)} · spend ${rpS(am.spend)} · ${num(am.purch)} purchase</small></div><span class="pv"><b>${am.purch ? f2(am.roas) : "—"}</b>${badge(AI.get(a.id).v)}</span></button>`;
    }).join("") || `<p class="muted sm">Konten ini cuma dipakai di iklan ini.</p>`);
  } else {
    extra = `<h4>Iklan di dalamnya (${ads.length}${ads.length < e.ads.length ? ` dari ${e.ads.length}, sesuai pencarian` : ""})</h4>` + ads.map(a => {
      const am = AM.get(a.id);
      return `<button class="prow" data-open="${a.id}">${thumb(a)}<div><b>${esc(a.name)}</b><small>spend ${rpS(am.spend)} · ${num(am.purch)} purchase · value ${rpS(am.value)}</small></div><span class="pv"><b>${am.purch ? f2(am.roas) : "—"}</b>${badge(AI.get(a.id).v)}</span></button>`;
    }).join("");
  }
  $("#panelBody").innerHTML = `
    <div class="p-head">${e.level === "ad" ? thumb(e) : LOGO}<div><small>${esc(e.acc.name)} · ${LVL[e.level][2]} · ID ${e.id}</small><h3>${esc(e.name)}</h3></div></div>
    <div class="p-status"><button class="tog ${e.on ? "on" : ""}" data-tog="${e.id}" role="switch" aria-checked="${e.on}" aria-label="On/Off"></button><span class="dl ${d.c}"><i></i>${d.t}</span>${badge(ai.v)}</div>
    <p class="muted sm">${periodLabel()} · ${rangeText()}</p>
    ${e.level === "ad" ? `<h4>Primary text</h4><p class="p-desc">${esc(e.desc)}</p><div class="chips sm"><span class="chip">${esc(e.camp.name)}</span><span class="chip">Ad set: ${esc(e.adset.name)}</span></div>` : ""}
    <div class="p-grid">
      <div><small>Amount spent</small><b>${rp(m.spend)}</b></div><div><small>Conversion value</small><b>${rp(m.value)}</b></div><div><small>Purchase ROAS</small><b class="${!m.purch ? "" : m.roas >= TARGET.roas ? "good" : "bad"}">${m.purch ? f2(m.roas) : "—"}</b></div>
      <div><small>Purchases</small><b>${num(m.purch)}</b></div><div><small>Cost per purchase</small><b>${rp(m.cpa)}</b></div><div><small>Adds to cart</small><b>${num(m.atc)}</b></div>
      <div><small>Impressions</small><b>${num(m.impr)}</b></div><div><small>CTR (link)</small><b>${m.impr ? f2(m.ctr) + "%" : "—"}</b></div><div><small>Frequency</small><b>${m.freq ? m.freq.toFixed(2) : "—"}</b></div>
    </div>
    <div id="clBox">${claudeHTML(e)}</div>
    <div class="p-ai ${V[ai.v].cls}">
      <h4>Analisis cepat (rule) — ${V[ai.v].label.toLowerCase()}</h4><ul>${ai.r.map(x => `<li>${esc(x)}</li>`).join("")}</ul>
      <h4>Yang harus dilakukan</h4><ol>${ai.act.map(x => `<li>${esc(x)}</li>`).join("")}</ol>
    </div>
    <h4>Tren harian</h4>${trendSvg(daysList)}
    <h4>Rekap per tanggal</h4>${dailyTable(daysList)}
    ${extra}`;
  $("#panel").classList.add("show"); $("#overlay").classList.add("show"); $("#panel").setAttribute("aria-hidden", "false");
  $("#panel").scrollTop = 0; S.panel = id;
  if (e.level === "ad" && !e._crTried && /Tidak ada primary text/.test(e.desc)) {   // iklan paused tidak ikut ditarik → ambil creative-nya saat dibuka
    e._crTried = true;
    gget(e.id, { fields: "creative{body,title,thumbnail_url}" }, e.acc).then(j => {
      const cr = j.creative || {}; if (cr.body || cr.title) e.desc = (cr.body || cr.title).replace(/\s+/g, " ").slice(0, 400);
      if (cr.thumbnail_url) e.thumb = cr.thumbnail_url; if (S.panel === e.id) openPanel(e.id);
    }).catch(() => {});
  }
}
function closePanel() { $("#panel").classList.remove("show"); $("#overlay").classList.remove("show"); $("#panel").setAttribute("aria-hidden", "true"); S.panel = null; }

function setLoading(t) { S.loading = t; $("#loading").hidden = !t; $("#loading").textContent = t; }

function render() {
  compute();
  renderHeader(); renderAccounts(); renderViews(); renderFunnels(); renderFilters(); renderQSummary(); renderAIBox(); renderTable();
  if (S.panel) openPanel(S.panel);
}
async function setRange(from, to, preset) {
  if (from > to) [from, to] = [to, from];
  if (to > TODAY) to = TODAY;
  S.from = from; S.to = to; S.preset = preset;
  render();
  await loadAll(false);   // tarik insight untuk tanggal baru
}

// =========================================================
// DATE PICKER (kalender 2 bulan, seperti ERP)
// =========================================================
const BULAN = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
const DP = { from: null, to: null, preset: null, y: 0, m: 0 };
function openDP() {
  DP.from = S.from; DP.to = S.to; DP.preset = S.preset;
  const t = parseD(S.to); const l = new Date(t.getFullYear(), t.getMonth() - 1, 1); DP.y = l.getFullYear(); DP.m = l.getMonth();
  renderDP(); $("#dpPop").hidden = false;
}
function closeDP() { $("#dpPop").hidden = true; }
function calHTML(y, m, side) {
  const first = new Date(y, m, 1), off = (first.getDay() + 6) % 7;
  const years = []; for (let yy = NOW.getFullYear() - 3; yy <= NOW.getFullYear(); yy++) years.push(yy);
  let cells = "";
  for (let i = 0; i < 42; i++) {
    const d = new Date(y, m, 1 - off + i), s = ymd(d), out = d.getMonth() !== m, fut = s > TODAY;
    const end = DP.to || DP.from, inr = DP.from && end && s >= DP.from && s <= end;
    const cls = [out && "out", fut && "dis", inr && "inr", s === DP.from && "st", s === end && "en", s === TODAY && "td"].filter(Boolean).join(" ");
    cells += `<button class="dpd ${cls}" ${fut ? "disabled" : `data-dpday="${s}"`}>${d.getDate()}</button>`;
  }
  return `<div class="dpc"><div class="dpc-h">
      ${side === 0 ? `<button class="dpn" data-dpnav="-1" aria-label="Bulan sebelumnya">‹</button>` : `<span class="dpn-sp"></span>`}
      <select data-dpm="${side}">${BULAN.map((b, i) => `<option value="${i}" ${i === m ? "selected" : ""}>${b}</option>`).join("")}</select>
      <select data-dpy="${side}">${years.map(v => `<option ${v === y ? "selected" : ""}>${v}</option>`).join("")}</select>
      ${side === 1 ? `<button class="dpn" data-dpnav="1" aria-label="Bulan berikutnya">›</button>` : `<span class="dpn-sp"></span>`}
    </div><div class="dpw">${["Sn", "Sl", "Rb", "Km", "Jm", "Sb", "Mg"].map(x => `<span>${x}</span>`).join("")}</div><div class="dpg">${cells}</div></div>`;
}
function renderDP() {
  $("#dpPresets").innerHTML = Object.entries(PRESET).map(([k, [t]]) => `<button class="btn sm-btn ${DP.preset === k ? "on" : ""}" data-dppreset="${k}">${t}</button>`).join("");
  const r = new Date(DP.y, DP.m + 1, 1);
  $("#dpCals").innerHTML = calHTML(DP.y, DP.m, 0) + calHTML(r.getFullYear(), r.getMonth(), 1);
  $("#dpSel").textContent = DP.from ? `${fmtNum(DP.from)} - ${fmtNum(DP.to || DP.from)}` : "";
}
$("#dpBtn").addEventListener("click", e => { e.stopPropagation(); $("#dpPop").hidden ? openDP() : closeDP(); });
$("#dpPop").addEventListener("click", e => {
  e.stopPropagation();
  const t = e.target;
  if (t.dataset.dpday) {
    const s = t.dataset.dpday;
    if (!DP.from || DP.to) { DP.from = s; DP.to = null; }
    else if (s < DP.from) { DP.to = DP.from; DP.from = s; } else DP.to = s;
    DP.preset = "custom"; renderDP(); return;
  }
  if (t.dataset.dppreset) { const [f, to] = PRESET[t.dataset.dppreset][1](); DP.from = f; DP.to = to; DP.preset = t.dataset.dppreset; const l = new Date(parseD(to).getFullYear(), parseD(to).getMonth() - 1, 1); DP.y = l.getFullYear(); DP.m = l.getMonth(); renderDP(); return; }
  if (t.dataset.dpnav) { const d = new Date(DP.y, DP.m + +t.dataset.dpnav, 1); DP.y = d.getFullYear(); DP.m = d.getMonth(); renderDP(); return; }
  if (t.id === "dpCancel") { closeDP(); return; }
  if (t.id === "dpApply") {
    const to = DP.to || DP.from;
    const match = Object.entries(PRESET).find(([, [, fn]]) => { const [a, b] = fn(); return a === DP.from && b === to; });
    closeDP(); setRange(DP.from, to, match ? match[0] : "custom");
  }
});
$("#dpPop").addEventListener("change", e => {
  const t = e.target;
  if (t.dataset.dpm != null || t.dataset.dpy != null) {
    const side = +(t.dataset.dpm ?? t.dataset.dpy);
    const m = +$(`[data-dpm="${side}"]`).value, y = +$(`[data-dpy="${side}"]`).value;
    const d = new Date(y, m - side, 1); DP.y = d.getFullYear(); DP.m = d.getMonth(); renderDP();
  }
});
document.addEventListener("click", () => { if (!$("#dpPop").hidden) closeDP(); });

// =========================================================
// EVENTS
// =========================================================
function setQ(q) { S.q = q.trim().toLowerCase(); $("#q").value = q; $("#qClear").hidden = !S.q; }
async function applyStatus(ids, on) {
  const ents = ids.map(id => BY_ID.get(id)).filter(Boolean);
  if (!ents.length || !confirm(`${on ? "Nyalakan" : "Matikan"} ${ents.length} item langsung di Meta Ads Manager?`)) return;
  const fail = [];
  for (const e of ents) { try { await setStatusLive(e, on); e.on = on; } catch (err) { fail.push(`${e.name}: ${err.message}`); } }
  if (fail.length) alert("Sebagian gagal (token butuh izin ads_management):\n" + fail.join("\n"));
  S.sel.clear(); render();
}
document.addEventListener("click", ev => {
  const t = ev.target;
  const cl = t.closest("[data-claude]"); if (cl) { runClaude(cl.dataset.claude); return; }
  if (t.closest("#clBulkBtn")) { runClaudeBulk(); return; }
  const tog = t.closest("[data-tog]"); if (tog) { const e = BY_ID.get(tog.dataset.tog); applyStatus([e.id], !e.on); return; }
  if (t.closest("[data-chk]") || t.id === "chkAll") return;
  const op = t.closest("[data-open]"); if (op) { openPanel(op.dataset.open); return; }
  const ct = t.closest("[data-content]"); if (ct) { setQ(ct.dataset.content); S.view = "all"; S.level = "ad"; closePanel(); render(); return; }
  const acc = t.closest("[data-acc]"); if (acc) { S.acc = acc.dataset.acc || null; S.sel.clear(); render(); return; }
  const fn = t.closest("[data-funnel]"); if (fn) { S.f.funnel = S.f.funnel === fn.dataset.funnel ? "all" : fn.dataset.funnel; render(); return; }
  const pf = t.closest("[data-pf]"); if (pf) { S.pf = pf.dataset.pf; S.acc = null; render(); return; }
  const vw = t.closest("[data-view]"); if (vw) { S.view = vw.dataset.view; if (S.view !== "ai") S.aiFilter = null; if (S.view === "winning" && S.level === "campaign") S.level = "ad"; render(); return; }
  const go = t.closest("[data-goto]"); if (go) { const g = go.dataset.goto; S.view = g === "kill" ? "ai" : g; S.aiFilter = g === "kill" ? "kill" : null; if (g === "kill") S.level = "ad"; render(); return; }
  const lv = t.closest("[data-level]"); if (lv && !lv.disabled) { S.level = lv.dataset.level; S.sel.clear(); render(); return; }
  const ai = t.closest("[data-ai]"); if (ai) { S.aiFilter = S.aiFilter === ai.dataset.ai ? null : ai.dataset.ai; render(); return; }
  const so = t.closest("[data-sort]"); if (so) { const k = so.dataset.sort; S.sort = { k, dir: S.sort.k === k ? -S.sort.dir : (k === "name" ? 1 : -1) }; renderTable(); return; }
  if (t.id === "tSave") { TARGET = { roas: +$("#tRoas").value || TARGET.roas, cpa: +$("#tCpa").value || TARGET.cpa, ctr: +$("#tCtr").value || TARGET.ctr }; store.set("ac_target", TARGET); render(); return; }
  if (t.id === "killAll") { applyStatus(baseRows().filter(e => isOn(e) && AI.get(e.id).v === "kill").map(e => e.id), false); return; }
  if (t.closest("#srcBadge")) { const errs = ACCOUNTS.filter(a => a.err); if (errs.length) alert(errs.map(a => `${a.name}: ${a.err}`).join("\n")); return; }
  const row = t.closest("tr[data-id]"); if (row && !t.closest("a,button,input")) openPanel(row.dataset.id);
});
document.addEventListener("change", ev => {
  const t = ev.target;
  if (t.dataset.chk) { t.checked ? S.sel.add(t.dataset.chk) : S.sel.delete(t.dataset.chk); t.closest("tr").classList.toggle("sel", t.checked); renderSel(); }
  if (t.id === "chkAll") { visibleRows().forEach(e => t.checked ? S.sel.add(e.id) : S.sel.delete(e.id)); renderTable(); }
});
// ---------- SEARCH ala Ads Manager: saran + riwayat (bisa dihapus) ----------
const QH = { list: store.get("ac_qhist", []), sel: -1, items: [] };
function saveHist(q, kind) {
  q = String(q || "").trim(); if (!q) return;
  QH.list = [{ q, kind: kind || "Teks", t: Date.now() }, ...QH.list.filter(h => h.q.toLowerCase() !== q.toLowerCase())].slice(0, 20);
  store.set("ac_qhist", QH.list);
}
const TYPE_LABEL = { hist: "🕘", text: "⌕", acc: "Akun", campaign: "Campaign", adset: "Ad set", ad: "Ad" };
const hl = (s, t) => { t = t.trim(); const i = t ? s.toLowerCase().indexOf(t.toLowerCase()) : -1; return i < 0 ? esc(s) : esc(s.slice(0, i)) + "<mark>" + esc(s.slice(i, i + t.length)) + "</mark>" + esc(s.slice(i + t.length)); };
function suggest(text) {
  const t = text.trim().toLowerCase();
  if (!t) return QH.list.map(h => ({ type: "hist", q: h.q, label: h.q, sub: `${h.kind} · ${new Date(h.t).toLocaleDateString("id-ID", { day: "numeric", month: "short" })}` }));
  const sp = e => M.get(e.id)?.spend || 0;
  const pick = (list, n, type) => list.filter(e => pfOk(e.acc) && (e.name.toLowerCase().includes(t) || e.id === t)).sort((a, b) => sp(b) - sp(a)).slice(0, n)
    .map(e => ({ type, id: e.id, q: e.name, label: e.name, sub: `${e.acc.name} · spend ${rp(sp(e))} · ${delivery(e).t}` }));
  return [{ type: "text", q: text.trim(), label: `Cari "${text.trim()}"`, sub: "di semua campaign, ad set & iklan" },
    ...QH.list.filter(h => h.q.toLowerCase().includes(t) && h.q.toLowerCase() !== t).slice(0, 3).map(h => ({ type: "hist", q: h.q, label: h.q, sub: h.kind })),
    ...ACCOUNTS.filter(a => pfOk(a) && (a.name + " " + a.id).toLowerCase().includes(t)).slice(0, 3).map(a => ({ type: "acc", id: a.id, q: a.name, label: a.name, sub: `Ad account · ${a.pf}` })),
    ...pick(CAMPAIGNS, 6, "campaign"), ...pick(ADSETS, 3, "adset"), ...pick(ADS, 5, "ad")];
}
function renderDrop() {
  const box = $("#qDrop"), text = $("#q").value;
  QH.items = suggest(text);
  if (!QH.items.length) { box.hidden = true; return; }
  box.innerHTML = (!text.trim() ? `<div class="qd-h"><b>Pencarian terakhir</b><button class="lnk" data-qclear>Hapus semua</button></div>` : "") +
    QH.items.map((it, i) => `<div class="qd-it ${i === QH.sel ? "on" : ""}" data-qi="${i}"><span class="qd-t ${it.type}">${TYPE_LABEL[it.type]}</span><div class="qd-b"><b>${hl(it.label, text)}</b><small>${esc(it.sub || "")}</small></div>${it.type === "hist" ? `<button class="qd-x" data-qdel="${esc(it.q)}" title="Hapus dari riwayat">✕</button>` : ""}</div>`).join("");
  box.hidden = false;
}
function hideDrop() { $("#qDrop").hidden = true; QH.sel = -1; }
function chooseSuggestion(it) {
  hideDrop();
  if (it.type === "acc") { S.acc = it.id; setQ(""); saveHist(it.q, "Akun"); render(); return; }
  if (["campaign", "adset", "ad"].includes(it.type)) { S.level = it.type; if (["content", "daily", "check"].includes(S.view)) S.view = "all"; setQ(it.q); saveHist(it.q, LVL[it.type][2]); render(); return; }
  setQ(it.q); saveHist(it.q, it.type === "hist" ? it.sub.split(" · ")[0] : "Teks"); render();
}
$("#q").addEventListener("input", e => { QH.sel = -1; if (!e.target.value.trim() && S.q) { setQ(""); render(); } renderDrop(); });
$("#q").addEventListener("focus", renderDrop);
$("#q").addEventListener("keydown", e => {
  const n = QH.items.length;
  if (e.key === "ArrowDown" && n) { e.preventDefault(); QH.sel = (QH.sel + 1) % n; renderDrop(); }
  else if (e.key === "ArrowUp" && n) { e.preventDefault(); QH.sel = (QH.sel - 1 + n) % n; renderDrop(); }
  else if (e.key === "Enter") { e.preventDefault(); const v = e.target.value.trim(); chooseSuggestion(QH.sel >= 0 ? QH.items[QH.sel] : { type: "text", q: v }); if (!v && QH.sel < 0) { setQ(""); render(); } e.target.blur(); }
  else if (e.key === "Escape") hideDrop();
});
$("#qDrop").addEventListener("mousedown", e => {
  e.preventDefault();   // jangan tutup dropdown sebelum klik diproses
  const del = e.target.closest("[data-qdel]"); if (del) { QH.list = QH.list.filter(h => h.q !== del.dataset.qdel); store.set("ac_qhist", QH.list); renderDrop(); return; }
  if (e.target.closest("[data-qclear]")) { QH.list = []; store.set("ac_qhist", []); renderDrop(); return; }
  const it = e.target.closest("[data-qi]"); if (it) chooseSuggestion(QH.items[+it.dataset.qi]);
});
$("#q").addEventListener("blur", () => setTimeout(hideDrop, 120));
$("#filters").addEventListener("change", e => { const k = e.target.dataset.f; if (!k) return; S.f[k] = e.target.type === "checkbox" ? e.target.checked : e.target.value; render(); });
$("#filters").addEventListener("click", e => { if (e.target.id === "fReset") { S.f = F0(); render(); } });
$("#qClear").addEventListener("click", ev => { ev.preventDefault(); setQ(""); hideDrop(); render(); });
$("#accSearch").addEventListener("input", e => { S.accQ = e.target.value.toLowerCase(); renderAccounts(); });
$("#btnOff").addEventListener("click", () => applyStatus([...S.sel], false));
$("#btnOn").addEventListener("click", () => applyStatus([...S.sel], true));
const manualSync = btn => { btn.classList.add("spin"); setTimeout(() => btn.classList.remove("spin"), 700); loadAll(true); };
$("#syncBtn").addEventListener("click", e => manualSync(e.currentTarget));
$("#railSync").addEventListener("click", e => manualSync(e.currentTarget));
$("#overlay").addEventListener("click", closePanel);
$("#panelClose").addEventListener("click", closePanel);
document.addEventListener("keydown", e => {
  if (e.key === "Escape") { closePanel(); closeDP(); toggleChat(false); }
  if (e.key === "/" && !["INPUT", "SELECT"].includes(document.activeElement.tagName)) { e.preventDefault(); $("#q").focus(); }
});
function loadXLSX() {   // library Excel (SheetJS) dimuat hanya saat tombol Export diklik
  if (window.XLSX) return Promise.resolve(window.XLSX);
  return new Promise((ok, no) => { const sc = document.createElement("script"); sc.src = "https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"; sc.onload = () => ok(window.XLSX); sc.onerror = () => no(new Error("Gagal memuat library Excel (cek internet).")); document.head.appendChild(sc); });
}
function exportData() {
  const mh = MCOLS.map(c => c.t), mv = m => MCOLS.map(c => c.x(m));
  if (S.view === "daily") return { name: "Rekap harian", head: ["Tanggal", ...mh], rows: dailyFrom(dailySource()).map(({ d, m }) => [d, ...mv(m)]) };
  if (S.view === "check") {
    const sumD = days => RANGE.reduce((t, d) => t + (days[d]?.[0] || 0), 0);
    return { name: "Cek data", head: ["Akun", "ID", "Spend level akun", "Spend campaign Active+Paused", "Spend per iklan", "Purchases", "Conversion value"],
      rows: scopedAccounts().filter(a => !a.err).map(a => { const m = daysM(a.days); return [a.name, a.id, Math.round(sumD(a.daysAll)), Math.round(sumD(a.daysCamp)), Math.round(a.ads.reduce((t, x) => t + sumD(x.days), 0)), m.purch, Math.round(m.value)]; }) };
  }
  if (S.view === "content") {
    const groups = {}; ADS.filter(a => scopeOk(a) && qOk(a)).forEach(a => (groups[a.ck] ||= { name: a.name, ads: [] }).ads.push(a));
    return { name: "Winning content", head: ["Konten", "Jumlah akun", "Jumlah iklan", ...mh],
      rows: Object.values(groups).map(g => { const m = sumM(g.ads.map(a => AM.get(a.id))); return [g.name, new Set(g.ads.map(a => a.acc.id)).size, g.ads.length, ...mv(m)]; }).filter(r => r[3] > 0) };
  }
  return { name: LVL[S.level][2] + "s", head: ["Level", "Akun", "ID", "Nama", "Campaign", "Delivery", "Rekomendasi", "Alasan", "Budget harian", ...mh],
    rows: visibleRows().map(e => { const m = M.get(e.id), ai = AI.get(e.id), c = clGet(e.id)?.data;
      return [LVL[e.level][2], e.acc.name, e.id, e.name, e.level === "campaign" ? "" : e.camp.name, delivery(e).t, V[c?.verdict]?.label || V[ai.v].label, c?.summary || ai.r[0], e.budget ? Math.round(e.budget) : null, ...mv(m)]; }) };
}
$("#btnExport").addEventListener("click", async e => {
  const btn = e.currentTarget, old = btn.textContent;
  btn.disabled = true; btn.textContent = "Menyiapkan Excel…";
  try {
    const XLSX = await loadXLSX(), d = exportData();
    const ws = XLSX.utils.aoa_to_sheet([d.head, ...d.rows]);
    ws["!cols"] = d.head.map((h, i) => ({ wch: Math.min(60, Math.max(String(h).length, ...d.rows.slice(0, 300).map(r => String(r[i] ?? "").length)) + 2) }));
    ws["!autofilter"] = { ref: XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: d.rows.length, c: d.head.length - 1 } }) };
    const info = XLSX.utils.aoa_to_sheet([["Montera Ads — export"], ["Tanggal data", rangeText()], ["Akun", S.acc ? ACCOUNTS.find(a => a.id === S.acc).name : `Semua (${S.pf === "all" ? "semua portfolio" : S.pf})`], ["Pencarian", S.q || "-"], ["Funnel", S.f.funnel], ["Diekspor", new Date().toLocaleString("id-ID")]]);
    info["!cols"] = [{ wch: 16 }, { wch: 50 }];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, d.name.slice(0, 30));
    XLSX.utils.book_append_sheet(wb, info, "Info");
    XLSX.writeFile(wb, `montera-ads-${d.name.toLowerCase().replace(/\s+/g, "-")}-${S.from}_${S.to}.xlsx`);
  } catch (err) { alert(err.message); }
  btn.disabled = false; btn.textContent = old;
});


// =========================================================
// AI ASISTEN (chat) — tanya apa saja + AI bisa eksekusi tindakan ke Meta
// =========================================================
const CHAT = { open: false, busy: false, msgs: store.get("ac_chat", []) };   // {role, content, actions?}
const CHAT_SYS = () => CL_RULES + `
Kamu asisten di dashboard "Ads Command". User bertanya soal campaign & iklannya. Jawab berdasarkan DATA DASHBOARD di bawah (sudah sesuai filter akun, pencarian & tanggal yang sedang dibuka user). Kalau yang ditanya tidak ada di data, bilang jujur dan sarankan filter/tanggal yang perlu dibuka.
Kamu BISA mengusulkan tindakan yang akan dieksekusi dashboard ke Meta SETELAH user klik "Jalankan":
- {"type":"pause","id":"<id campaign/adset/iklan dari data>","reason":"alasan + angka"}
- {"type":"activate","id":"...","reason":"..."}
- {"type":"set_budget","id":"<id campaign/adset yang punya budget_harian>","value":<budget harian baru dalam Rupiah>,"reason":"..."}
Aturan tindakan: hanya pakai id yang ada di data. Naik budget maksimal +30% per tindakan, turun maksimal -50% (lebih buruk dari itu → pause). Jangan usulkan tindakan kalau datanya belum cukup — jelaskan saja kenapa. Kamu TIDAK bisa mengedit video/teks creative; untuk itu beri saran konkret (hook, angle, CTA, thumbnail) yang dikerjakan tim.
CARA MENJAWAB (wajib):
- Pakai HANYA data di DATA DASHBOARD sesuai "filter" saat ini. Kalau filter = 1 akun, JANGAN menyebut atau menyarankan campaign dari akun lain. Abaikan angka dari jawaban sebelumnya kalau filter/tanggal berubah.
- Mulai dengan **Ringkasan**: total spend, value, ROAS, purchase di filter ini + 1 kalimat kondisi.
- Lalu **Yang bikin boncos** (nama campaign + spend + ROAS/CPP + kenapa: hook/CTR rendah? ATC banyak tapi purchase sedikit? frequency tinggi?) dan **Yang layak di-scale** (nama + angka).
- Lalu **Rencana budget**: berapa rupiah dikurangi dari mana dan dipindah ke mana, total sebelum → sesudah.
- Hook rate rendah (<20%) = 3 detik awal video lemah; hold rate rendah = isi video tidak menahan; CTR bagus tapi CPP mahal = masalah di Shopee (harga/voucher/listing). Pakai angka ini untuk diagnosis.
- DILARANG saran generik yang tidak didukung angka (mis. "pastikan stok aman"). Jangan pernah menyebut nama model/AI di belakangmu; kamu adalah Montera AI.
- Jujur: kalau data hari ini masih sedikit, katakan keputusan apa yang aman sekarang dan apa yang harus menunggu.
Balas HANYA JSON valid: {"reply":"jawaban untuk user dalam Bahasa Indonesia. Boleh **tebal** dan baris diawali '- ' untuk poin","actions":[...]}
DATA DASHBOARD (JSON):
` + JSON.stringify(chatContext());

function chatContext() {
  const max = AI_CFG.provider === "ollama" ? 25 : 60, multi = RANGE.length > 1;
  const camps = CAMPAIGNS.filter(c => adsIn(c).length && (M.get(c.id).spend > 0 || isOn(c))).sort((x, y) => M.get(y.id).spend - M.get(x.id).spend).slice(0, max);
  return {
    periode: periodInfo(), target: targetInfo(),
    filter: { akun: S.acc ? ACCOUNTS.find(a => a.id === S.acc).name : "semua", portfolio: S.pf, pencarian: S.q || null },
    total_per_akun: scopedAccounts().filter(a => !a.err && a.loaded).map(a => ({ akun: a.name, ...mObj(accMetrics(a)) })),
    per_funnel: [...FUNNELS.map(f => f.label), "Lainnya"].map(l => { const cs = CAMPAIGNS.filter(c => adsIn(c).length && inFunnel(c, l)); return { funnel: l, campaign: cs.length, ...mObj(sumM(cs.map(c => M.get(c.id)))) }; }),
    campaigns: camps.map(c => ({
      id: c.id, nama: c.name, akun: c.acc.name, delivery: delivery(c).t, mulai: c.start,
      budget_harian: c.budgetType === "Daily" ? Math.round(c.budget) : null, ...mObj(M.get(c.id)), penilaian_rule: AI.get(c.id).v,
      adsets_dengan_budget: c.adsets.filter(s => s.budgetType === "Daily").map(s => ({ id: s.id, nama: s.name, budget_harian: Math.round(s.budget) })),
      iklan: adsIn(c).sort((x, y) => AM.get(y.id).spend - AM.get(x.id).spend).slice(0, 4).map(a => ({ id: a.id, nama: a.name, delivery: delivery(a).t, primary_text: a.desc.slice(0, 120), ...mObj(AM.get(a.id)) })),
      harian: multi && AI_CFG.provider !== "ollama" ? dailyFrom(panelDays(c)).filter(r => r.m.spend > 0).reverse().map(r => [r.d, Math.round(r.m.spend), r.m.purch, Math.round(r.m.value)]) : undefined
    })),
    format_harian: "[tanggal, spend, purchases, conversion_value]"
  };
}

const mdLite = t => esc(t).replace(/\*\*(.+?)\*\*/g, "<b>$1</b>").split("\n").map(l => /^\s*[-•]\s+/.test(l) ? `<li>${l.replace(/^\s*[-•]\s+/, "")}</li>` : l ? `<p>${l}</p>` : "").join("").replace(/(<li>[\s\S]*?<\/li>)(?!<li>)/g, "$1</ul>").replace(/(?<!<\/li>)<li>/g, "<ul><li>");

function actionInfo(a) {
  const e = BY_ID.get(String(a.id));
  if (!e) return { e: null, label: "ID tidak dikenal", bad: "Item tidak ada di data dashboard." };
  if (a.type === "pause") return { e, label: `Matikan ${LVL[e.level][0]}` };
  if (a.type === "activate") return { e, label: `Nyalakan ${LVL[e.level][0]}` };
  if (a.type === "set_budget") {
    if (e.budgetType !== "Daily" || !e.budget) return { e, label: "Ubah budget", bad: "Item ini tidak punya budget harian sendiri." };
    const pct = (a.value / e.budget - 1) * 100;
    const bad = pct > 30.5 ? "Kenaikan > 30% sekaligus berisiko reset learning — dibatasi." : pct < -50.5 ? "Penurunan > 50% — lebih baik pause." : a.value < 10000 ? "Budget minimal Rp10.000." : null;
    return { e, label: `Budget ${rp(e.budget)} → ${rp(a.value)} (${pct > 0 ? "+" : ""}${pct.toFixed(0)}%)`, bad };
  }
  return { e, label: a.type, bad: "Jenis tindakan tidak didukung." };
}
function actionCard(a, mi, ai) {
  const inf = actionInfo(a);
  const st = a.status === "done" ? `<span class="vb v-scale">✓ Dijalankan</span>` : a.status === "skip" ? `<span class="vb v-watch">Diabaikan</span>` : a.status === "err" ? `<span class="vb v-kill">Gagal: ${esc(a.err)}</span>` : a.status === "run" ? `<span class="spin-dot"></span>` :
    inf.bad ? `<span class="vb v-opt">${esc(inf.bad)}</span><button class="btn sm-btn" data-act="skip" data-mi="${mi}" data-ai="${ai}">Abaikan</button>` :
    `<button class="btn sm-btn primary" data-act="run" data-mi="${mi}" data-ai="${ai}">Jalankan</button><button class="btn sm-btn" data-act="skip" data-mi="${mi}" data-ai="${ai}">Abaikan</button>`;
  return `<div class="act-card"><div><b>${esc(inf.label)}</b><small>${inf.e ? `${esc(inf.e.name)} · ${esc(inf.e.acc.name)}` : esc(a.id)}</small><small class="muted">${esc(a.reason || "")}</small></div><div class="act-st">${st}</div></div>`;
}
function renderChat() {
  const box = $("#chatMsgs"); if (!box) return;
  const sugg = ["Campaign mana yang harus dimatikan?", "Mana yang layak dinaikkan budget?", "Kenapa ROAS-nya turun?", "Optimasi semua campaign yang lagi tampil"];
  box.innerHTML = (CHAT.msgs.length ? "" : `<div class="chat-hello"><b>Halo! Aku analis iklan ${AI_NAME}.</b><p>Aku membaca data yang sedang tampil (filter akun, pencarian & tanggal). Tanya apa saja — kalau perlu, aku usulkan tindakan (matikan, nyalakan, ubah budget) yang bisa lu jalankan sekali klik.</p></div>`) +
    CHAT.msgs.map((m, mi) => m.role === "user" ? `<div class="msg me">${esc(m.content)}</div>` :
      `<div class="msg bot">${mdLite(m.content)}${(m.actions || []).length ? `<div class="acts">${m.actions.map((a, ai) => actionCard(a, mi, ai)).join("")}${m.actions.filter(a => !a.status && !actionInfo(a).bad).length > 1 ? `<button class="btn sm-btn primary" data-act="runall" data-mi="${mi}">Jalankan semua (${m.actions.filter(a => !a.status && !actionInfo(a).bad).length})</button>` : ""}</div>` : ""}</div>`).join("") +
    (CHAT.busy ? thinkingHTML() : "");
  $("#chatSugg").innerHTML = CHAT.msgs.length ? "" : sugg.map(t => `<button class="chip" data-sugg="${esc(t)}">${esc(t)}</button>`).join("");
  $("#chatScope").textContent = `${S.acc ? ACCOUNTS.find(a => a.id === S.acc).name : "Semua akun"}${S.q ? ` · "${S.q}"` : ""} · ${periodLabel()}`;
  box.scrollTop = box.scrollHeight;
}
const THINK_STEPS = () => [`Membaca ${scopedAccounts().filter(a => a.loaded).length} akun & ${CAMPAIGNS.filter(c => adsIn(c).length && M.get(c.id).spend > 0).length} campaign aktif`, "Menghitung ROAS, CPP, cost per ATC per funnel", "Membedah hook rate & hold rate tiap iklan", "Mencari iklan yang bikin boncos", "Mencari kandidat winning untuk di-scale", "Menyusun rencana budget"];
let thinkT0 = 0, thinkTimer = null;
function thinkingHTML() {
  const steps = THINK_STEPS(), sec = (Date.now() - thinkT0) / 1000, cur = Math.min(steps.length - 1, Math.floor(sec / 2.5));
  return `<div class="msg bot thinking"><div class="th-h">${MLOGO}<b>${AI_NAME} sedang berpikir</b><span class="muted sm">${Math.floor(sec)} dtk</span></div>
    <ol class="th-steps">${steps.map((t, i) => `<li class="${i < cur ? "done" : i === cur ? "now" : ""}">${i < cur ? "✓" : i === cur ? "<span class='spin-dot'></span>" : "•"} ${esc(t)}</li>`).join("")}</ol></div>`;
}
const saveChat = () => store.set("ac_chat", CHAT.msgs.slice(-30));
async function sendChat(text) {
  text = text.trim(); if (!text || CHAT.busy) return;
  if (!S.lastSync) { alert("Tunggu data Meta selesai dimuat dulu."); return; }
  CHAT.msgs.push({ role: "user", content: text }); CHAT.busy = true; thinkT0 = Date.now(); renderChat();
  clearInterval(thinkTimer); thinkTimer = setInterval(() => { const el = $("#chatMsgs .thinking"); if (el && CHAT.busy) el.outerHTML = thinkingHTML(); }, 600);
  try {
    const scope = `[Filter sekarang: ${S.acc ? ACCOUNTS.find(a => a.id === S.acc).name : "semua akun" + (S.pf !== "all" ? " " + S.pf : "")}${S.q ? `, pencarian "${S.q}"` : ""}${S.f.funnel !== "all" ? `, funnel ${S.f.funnel}` : ""} · ${periodLabel()} ${rangeText()}]`;
    const hist = CHAT.msgs.slice(-8).map((m, i, arr) => ({ role: m.role, content: m.role === "assistant" ? JSON.stringify({ reply: m.content, actions: (m.actions || []).map(({ status, err, ...a }) => a) }) : (i === arr.length - 1 ? `${scope}\n${m.content}` : m.content) }));
    const r = await askAI(CHAT_SYS(), hist);
    CHAT.msgs.push({ role: "assistant", content: String(r.reply || "(tidak ada jawaban)"), actions: Array.isArray(r.actions) ? r.actions.filter(a => a && a.id && a.type) : [] });
  } catch (err) { CHAT.msgs.push({ role: "assistant", content: `**Gagal:** ${err.message}`, actions: [] }); }
  CHAT.busy = false; clearInterval(thinkTimer); saveChat(); renderChat();
}
async function setBudgetLive(e, value) {
  const j = await api("post", { acc: e.acc.id, id: e.id, daily_budget: String(Math.round(value * (e.acc.off || 1))) });
  if (j.error) throw new Error(j.error.message || "Gagal");
}
async function runAction(a) {
  const inf = actionInfo(a); if (inf.bad || !inf.e) return;
  a.status = "run"; renderChat();
  try {
    if (a.type === "pause" || a.type === "activate") { await setStatusLive(inf.e, a.type === "activate"); inf.e.on = a.type === "activate"; }
    if (a.type === "set_budget") { await setBudgetLive(inf.e, a.value); inf.e.budget = a.value; }
    a.status = "done";
    const log = store.get("ac_log", []); log.push({ t: new Date().toISOString(), type: a.type, id: a.id, name: inf.e.name, akun: inf.e.acc.name, value: a.value ?? null, reason: a.reason }); store.set("ac_log", log.slice(-500));
  } catch (err) { a.status = "err"; a.err = err.message; }
  saveChat(); renderChat(); render();
}
function toggleChat(open) { CHAT.open = open ?? !CHAT.open; $("#chat").classList.toggle("show", CHAT.open); if (CHAT.open) { renderChat(); $("#chatInput").focus(); } }
$("#chatFab").addEventListener("click", () => toggleChat());
function renderAiMode() { const m = store.get("ac_ai_mode", "deep"); $$("[data-aimode]").forEach(b => b.classList.toggle("on", b.dataset.aimode === m)); }
$$("[data-aimode]").forEach(b => b.addEventListener("click", () => { store.set("ac_ai_mode", b.dataset.aimode); renderAiMode(); }));
renderAiMode();
$("#chatClose").addEventListener("click", () => toggleChat(false));
$("#chatReset").addEventListener("click", () => { if (confirm("Hapus riwayat chat?")) { CHAT.msgs = []; saveChat(); renderChat(); } });
$("#chatForm").addEventListener("submit", e => { e.preventDefault(); const v = $("#chatInput").value; $("#chatInput").value = ""; sendChat(v); });
$("#chatInput").addEventListener("keydown", e => { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); $("#chatForm").requestSubmit(); } });
$("#chat").addEventListener("click", async e => {
  e.stopPropagation();
  const sg = e.target.closest("[data-sugg]"); if (sg) { sendChat(sg.dataset.sugg); return; }
  const b = e.target.closest("[data-act]"); if (!b) return;
  const m = CHAT.msgs[+b.dataset.mi];
  if (b.dataset.act === "skip") { m.actions[+b.dataset.ai].status = "skip"; saveChat(); renderChat(); return; }
  if (b.dataset.act === "run") { const a = m.actions[+b.dataset.ai]; if (confirm(`${actionInfo(a).label}\n${actionInfo(a).e.name}\n\nJalankan langsung di Meta?`)) runAction(a); return; }
  if (b.dataset.act === "runall") {
    const list = m.actions.filter(a => !a.status && !actionInfo(a).bad);
    if (!confirm(`Jalankan ${list.length} tindakan langsung di Meta?\n\n` + list.map(a => `• ${actionInfo(a).label} — ${actionInfo(a).e.name}`).join("\n"))) return;
    for (const a of list) await runAction(a);
  }
});

// ---------- INIT + REAL-TIME ----------
hydrateCache();
render();
loadAll(false);
setInterval(() => { if (S.to === ymd(new Date()) && !document.hidden) loadAll(false); }, CFG.autoRefreshMinutes * 60000);
setInterval(() => { if (!S.busy) renderHeader(); }, 30000);