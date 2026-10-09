/**
 * script.js — Plate Plan · Frontend Logic
 *
 * Features:
 *  - Ingredient autocomplete (datalist from known DB ingredients)
 *  - Meal cards show ingredient list in sidebar + dashboard columns
 *  - Grocery modal: By-Day tab + Total-Needed tab with aggregated quantities
 *  - Fixture loader button (wipes DB, inserts fixture.php data)
 */

/* ── Constants ───────────────────────────────────────────── */
const API  = 'index.php';
const DAYS = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
const MEAL_TYPES = {
  breakfast: { icon: '🌅', label: 'Breakfast', color: '#f97316' },
  lunch:     { icon: '☀️',  label: 'Lunch',     color: '#10b981' },
  dinner:    { icon: '🌙', label: 'Dinner',    color: '#6366f1' },
  snack:     { icon: '🍎', label: 'Snack',     color: '#ec4899' },
};

/* ── State ───────────────────────────────────────────────── */
let meals      = [];     // [{id, name, ingredient_count, ingredients:[{name,quantity}]}]
let weeklyPlan = {};     // {day_number: [{id, meal_id, meal_type, meal_name}]}
let pendingDrop = null;  // {mealId, mealName, dayOfWeek}
let groceryData = null;  // {grocery:[], totals:[]}  — cached after last fetch
let activeGroceryTab = 'by-day';

/* ── DOM ─────────────────────────────────────────────────── */
const $  = id  => document.getElementById(id);
const $$ = sel => document.querySelectorAll(sel);

/* ════════════════════════════════════════════════════════════
   BOOT
   ════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
  buildWeekGrid();
  loadInitialData();
  bindFormEvents();
  bindMenuToggle();
  bindSearch();
  bindGroceryButton();
  bindGroceryTabs();
  bindFixtureButton();
  bindModalCloseOnOverlay();
  bindAiEvaluation();
  bindChatbot();
});

async function loadInitialData() {
  await Promise.all([fetchMeals(), fetchWeeklyPlan()]);
  renderMealList();
  renderAllColumns();
  initSortable();
  refreshIngredientAutocomplete();
}

/* ════════════════════════════════════════════════════════════
   API LAYER
   ════════════════════════════════════════════════════════════ */
async function apiFetch(params) {
  const res = await fetch(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(params).toString(),
  });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const json = await res.json();
  if (json.error) throw new Error(json.error);
  return json;
}

async function fetchMeals() {
  const data = await apiFetch({ action: 'get_meals' });
  meals = data.meals || [];
}

async function fetchWeeklyPlan() {
  const data = await apiFetch({ action: 'get_weekly_plan' });
  weeklyPlan = {};
  (data.plan || []).forEach(row => {
    const d = row.day_of_week;
    if (!weeklyPlan[d]) weeklyPlan[d] = [];
    weeklyPlan[d].push(row);
  });
}

/* ════════════════════════════════════════════════════════════
   INGREDIENT AUTOCOMPLETE
   ════════════════════════════════════════════════════════════ */
async function refreshIngredientAutocomplete() {
  try {
    const data = await apiFetch({ action: 'get_all_ingredients' });
    const list = $('ing-datalist');
    if (!list) return;
    list.innerHTML = (data.ingredients || [])
      .map(name => `<option value="${escHtml(name)}">`)
      .join('');
  } catch (_) { /* non-critical */ }
}

/* ════════════════════════════════════════════════════════════
   WEEK GRID — BUILD
   ════════════════════════════════════════════════════════════ */
function buildWeekGrid() {
  const grid = $('week-grid');
  grid.innerHTML = '';

  DAYS.forEach((day, i) => {
    const dayNum = i + 1;
    const isToday = dayNum === getTodayDayNum();

    const col = document.createElement('div');
    col.className = 'day-col' + (isToday ? ' day-col--today' : '');
    col.id = `col-day-${dayNum}`;
    col.dataset.day = dayNum;

    col.innerHTML = `
      <div class="day-col-header">
        <div class="day-col-name">
          ${isToday ? '<span class="today-dot"></span>' : ''}
          ${day}
        </div>
        <div class="day-col-meta">
          <span class="day-meal-count" id="count-day-${dayNum}"></span>
          <button class="btn-clear-col" data-day="${dayNum}" title="Clear day">✕</button>
        </div>
      </div>
      <div class="day-col-body" id="body-day-${dayNum}" data-day="${dayNum}">
        <div class="col-drop-hint" id="hint-day-${dayNum}">
          <div class="hint-icon">＋</div>
          <div class="hint-text">Drop a meal here</div>
        </div>
        <div class="col-entries" id="entries-day-${dayNum}"></div>
      </div>`;

    grid.appendChild(col);
  });

  // Clear buttons
  $$('.btn-clear-col').forEach(btn => {
    btn.addEventListener('click', () => handleClearDay(parseInt(btn.dataset.day, 10)));
  });

  // HTML5 drag-and-drop on column bodies
  $$('.day-col-body').forEach(body => {
    body.addEventListener('dragover', e => {
      e.preventDefault();
      body.closest('.day-col').classList.add('drag-over');
    });
    body.addEventListener('dragleave', e => {
      if (!body.contains(e.relatedTarget)) {
        body.closest('.day-col').classList.remove('drag-over');
      }
    });
    body.addEventListener('drop', e => {
      e.preventDefault();
      body.closest('.day-col').classList.remove('drag-over');
      const toDay = parseInt(body.dataset.day, 10);

      // A planned meal dragged from another day: move it, keeping its meal type
      const planId = parseInt(e.dataTransfer.getData('planId'), 10);
      if (planId) {
        handleMoveInPlan(planId, parseInt(e.dataTransfer.getData('fromDay'), 10), toDay);
        return;
      }

      const mealId   = parseInt(e.dataTransfer.getData('mealId'), 10);
      const mealName = e.dataTransfer.getData('mealName');
      if (!mealId) return;
      openMealTypeModal(mealId, mealName, toDay);
    });
  });
}

/* ════════════════════════════════════════════════════════════
   WEEK GRID — RENDER COLUMNS
   ════════════════════════════════════════════════════════════ */
function renderAllColumns() {
  for (let d = 1; d <= 7; d++) renderColumn(d);
}

function renderColumn(dayNum) {
  const entries   = weeklyPlan[dayNum] || [];
  const container = $(`entries-day-${dayNum}`);
  const hint      = $(`hint-day-${dayNum}`);
  const countEl   = $(`count-day-${dayNum}`);
  if (!container) return;

  const ORDER = ['breakfast','lunch','dinner','snack'];
  const sorted = [...entries].sort((a, b) => ORDER.indexOf(a.meal_type) - ORDER.indexOf(b.meal_type));

  container.innerHTML = '';
  sorted.forEach(entry => {
    const info = MEAL_TYPES[entry.meal_type] || MEAL_TYPES.snack;

    // Find the meal's full ingredient list from state
    const meal = meals.find(m => String(m.id) === String(entry.meal_id));
    const ings = meal?.ingredients || [];

    const card = document.createElement('div');
    card.className = 'col-entry';
    card.dataset.type = entry.meal_type;
    card.draggable = true;
    card.title = 'Drag to another day to move';
    card.innerHTML = `
      <div class="col-entry-type" style="background:${info.color}20;color:${info.color}">
        <span>${info.icon}</span><span>${info.label}</span>
      </div>
      <div class="col-entry-name">${escHtml(entry.meal_name)}</div>
      ${ings.length > 0 ? `
      <div class="col-entry-ings">
        ${ings.map(i => `<span class="col-ing-pill">${escHtml(i.name)}${i.quantity ? ` <em>${escHtml(i.quantity)}</em>` : ''}</span>`).join('')}
      </div>` : ''}
      <button class="col-entry-remove" data-plan-id="${entry.id}" data-day="${dayNum}" title="Remove">✕</button>`;

    card.addEventListener('dragstart', e => {
      e.dataTransfer.setData('planId', entry.id);
      e.dataTransfer.setData('fromDay', dayNum);
      e.dataTransfer.effectAllowed = 'move';
      card.classList.add('dragging');
    });
    card.addEventListener('dragend', () => card.classList.remove('dragging'));

    card.querySelector('.col-entry-remove').addEventListener('click', e => {
      e.stopPropagation();
      handleRemoveFromPlan(entry.id, dayNum);
    });
    container.appendChild(card);
  });

  hint.style.display    = entries.length === 0 ? 'flex' : 'none';
  const n = entries.length;
  countEl.textContent   = n === 0 ? '' : `${n} meal${n !== 1 ? 's' : ''}`;
  countEl.style.display = n === 0 ? 'none' : 'inline';
}

/* ════════════════════════════════════════════════════════════
   SIDEBAR — MEAL LIST
   ════════════════════════════════════════════════════════════ */
function renderMealList() {
  const list = $('sortable-meals');

  if (meals.length === 0) {
    list.innerHTML = `
      <div class="empty-state">
        <div class="es-icon">🍽️</div>
        <p>No meals yet.<br>Create your first one!</p>
      </div>`;
    return;
  }

  list.innerHTML = '';
  meals.forEach(meal => {
    const ings = meal.ingredients || [];
    const card = document.createElement('div');
    card.className = 'meal-card';
    card.dataset.mealId   = meal.id;
    card.dataset.mealName = meal.name;
    card.draggable = true;

    card.innerHTML = `
      <div class="meal-card-icon">🍴</div>
      <div class="meal-card-info">
        <div class="meal-card-name">${escHtml(meal.name)}</div>
        ${ings.length > 0 ? `
        <div class="meal-card-ings">
          ${ings.map(i => `<span class="mc-ing">${escHtml(i.name)}${i.quantity ? ` <em>${escHtml(i.quantity)}</em>` : ''}</span>`).join('')}
        </div>` : `<div class="meal-card-meta">No ingredients</div>`}
      </div>
      <div class="meal-card-actions">
        <button class="btn-icon" title="Delete meal" data-delete="${meal.id}">🗑</button>
      </div>`;

    card.addEventListener('dragstart', e => {
      e.dataTransfer.setData('mealId', meal.id);
      e.dataTransfer.setData('mealName', meal.name);
      e.dataTransfer.effectAllowed = 'copy';
    });

    card.querySelector('[data-delete]').addEventListener('click', e => {
      e.stopPropagation();
      handleDeleteMeal(parseInt(meal.id, 10));
    });

    list.appendChild(card);
  });

  initSortable();

  // Re-apply active search filter (in case renderMealList was called after fixture load etc.)
  const searchInput = $('meal-search');
  if (searchInput && searchInput.value.trim()) {
    searchInput.dispatchEvent(new Event('input'));
  }
}

/* ════════════════════════════════════════════════════════════
   SORTABLEJS
   ════════════════════════════════════════════════════════════ */
let _sortable = null;

function initSortable() {
  if (_sortable) { _sortable.destroy(); _sortable = null; }
  const list = $('sortable-meals');
  if (!list || list.querySelector('.empty-state')) return;

  _sortable = Sortable.create(list, {
    group:      { name: 'meals', pull: 'clone', put: false },
    sort:       false,
    animation:  150,
    ghostClass: 'sortable-ghost',
    chosenClass:'sortable-chosen',
    onEnd(evt) {
      if (evt.to !== list && evt.item.parentNode !== list) evt.item.remove();
      renderMealList();
    },
  });
}

/* ════════════════════════════════════════════════════════════
   MEAL TYPE MODAL
   ════════════════════════════════════════════════════════════ */
function openMealTypeModal(mealId, mealName, dayOfWeek) {
  pendingDrop = { mealId, mealName, dayOfWeek };
  $('modal-meal-name').textContent = mealName;
  $('modal-day-name').textContent  = DAYS[dayOfWeek - 1];
  $$('.meal-type-btn').forEach(btn => btn.classList.remove('selected'));
  openModal('modal-meal-type');
}

function bindMealTypeButtons() {
  $$('.meal-type-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      if (!pendingDrop) return;
      const { mealId, mealName, dayOfWeek } = pendingDrop;
      btn.classList.add('selected');
      setTimeout(async () => {
        closeModal('modal-meal-type');
        pendingDrop = null;
        await commitDropToDay(mealId, mealName, dayOfWeek, btn.dataset.type);
      }, 220);
    });
  });
}

async function commitDropToDay(mealId, mealName, dayOfWeek, mealType) {
  try {
    const data = await apiFetch({ action: 'add_to_plan', day_of_week: dayOfWeek, meal_id: mealId, meal_type: mealType });
    if (!weeklyPlan[dayOfWeek]) weeklyPlan[dayOfWeek] = [];
    weeklyPlan[dayOfWeek].push({ id: data.plan_id, meal_id: mealId, meal_type: mealType, meal_name: mealName, day_of_week: dayOfWeek });
    renderColumn(dayOfWeek);
    showToast(`${mealName} → ${DAYS[dayOfWeek-1]} (${mealType})`, 'success');
  } catch (e) { showToast('Save failed: ' + e.message, 'error'); }
}

/* ════════════════════════════════════════════════════════════
   MEAL CREATION FORM
   ════════════════════════════════════════════════════════════ */
function bindFormEvents() {
  $('btn-add-ingredient').addEventListener('click', addIngredientRow);
  bindRemoveIngredient($('ingredients-list').querySelector('.btn-remove-ing'));
  bindMealTypeButtons();

  $('meal-form').addEventListener('submit', async e => {
    e.preventDefault();
    const nameInput = $('meal-name-input');
    const name = nameInput.value.trim();
    if (!name) { nameInput.focus(); return; }

    const rows = $$('#ingredients-list .ingredient-row');
    const ingredients = [];
    rows.forEach(row => {
      const n = row.querySelector('.ing-name').value.trim();
      const q = row.querySelector('.ing-qty').value.trim();
      if (n) ingredients.push({ name: n, quantity: q });
    });

    const btn = $('btn-save-meal');
    btn.disabled = true; btn.textContent = 'Saving…';

    try {
      const params = { action: 'save_meal', name, ing_count: ingredients.length };
      ingredients.forEach((ing, i) => { params[`ing_name_${i}`] = ing.name; params[`ing_quantity_${i}`] = ing.quantity; });
      await apiFetch(params);
      await fetchMeals();
      renderMealList();
      renderAllColumns(); // refresh dashboard cards with new meal data
      nameInput.value = '';
      $('ingredients-list').innerHTML = '';
      addIngredientRow();
      await refreshIngredientAutocomplete();
      showToast(`"${name}" created!`, 'success');
    } catch (err) {
      showToast('Error: ' + err.message, 'error');
    } finally {
      btn.disabled = false; btn.textContent = 'Save Meal';
    }
  });
}

function addIngredientRow() {
  const row = document.createElement('div');
  row.className = 'ingredient-row';
  row.innerHTML = `
    <input class="form-control-dark ing-name" placeholder="Ingredient" type="text" list="ing-datalist" autocomplete="off">
    <input class="form-control-dark ing-qty qty-input" placeholder="Qty (e.g. 200g, 2 pcs)" type="text">
    <button type="button" class="btn-remove-ing" title="Remove">✕</button>`;
  $('ingredients-list').appendChild(row);
  bindRemoveIngredient(row.querySelector('.btn-remove-ing'));
  row.querySelector('.ing-name').focus();
}

function bindRemoveIngredient(btn) {
  btn.addEventListener('click', () => {
    const rows = $$('#ingredients-list .ingredient-row');
    if (rows.length <= 1) {
      btn.closest('.ingredient-row').querySelectorAll('input').forEach(i => i.value = '');
    } else {
      btn.closest('.ingredient-row').remove();
    }
  });
}

/* ════════════════════════════════════════════════════════════
   HANDLERS — DELETE / REMOVE / CLEAR
   ════════════════════════════════════════════════════════════ */
async function handleDeleteMeal(mealId) {
  if (!confirm('Delete this meal? It will be removed from the weekly plan too.')) return;
  try {
    await apiFetch({ action: 'delete_meal', meal_id: mealId });
    meals = meals.filter(m => m.id !== mealId);
    Object.keys(weeklyPlan).forEach(d => { weeklyPlan[d] = weeklyPlan[d].filter(e => e.meal_id !== mealId); });
    renderMealList();
    renderAllColumns();
    showToast('Meal deleted.', 'info');
  } catch (e) { showToast('Error: ' + e.message, 'error'); }
}

async function handleRemoveFromPlan(planId, dayNum) {
  try {
    await apiFetch({ action: 'remove_from_plan', plan_id: planId });
    weeklyPlan[dayNum] = (weeklyPlan[dayNum] || []).filter(e => e.id !== planId);
    renderColumn(dayNum);
    showToast('Removed from plan.', 'info');
  } catch (e) { showToast('Error: ' + e.message, 'error'); }
}

async function handleMoveInPlan(planId, fromDay, toDay) {
  if (!fromDay || fromDay === toDay) return;
  const entry = (weeklyPlan[fromDay] || []).find(e => String(e.id) === String(planId));
  if (!entry) return;
  try {
    await apiFetch({ action: 'move_in_plan', plan_id: planId, day_of_week: toDay });
    weeklyPlan[fromDay] = weeklyPlan[fromDay].filter(e => e !== entry);
    entry.day_of_week = toDay;
    if (!weeklyPlan[toDay]) weeklyPlan[toDay] = [];
    weeklyPlan[toDay].push(entry);
    renderColumn(fromDay);
    renderColumn(toDay);
    showToast(`${entry.meal_name} → ${DAYS[toDay-1]}`, 'success');
  } catch (e) { showToast('Move failed: ' + e.message, 'error'); }
}

async function handleClearDay(dayNum) {
  const count = (weeklyPlan[dayNum] || []).length;
  if (count === 0) { showToast('Day is already empty.', 'info'); return; }
  if (!confirm(`Clear all ${count} meal(s) from ${DAYS[dayNum-1]}?`)) return;
  try {
    await apiFetch({ action: 'clear_day', day_of_week: dayNum });
    weeklyPlan[dayNum] = [];
    renderColumn(dayNum);
    showToast(`${DAYS[dayNum-1]} cleared.`, 'info');
  } catch (e) { showToast('Error: ' + e.message, 'error'); }
}

async function handleClearWeek() {
  const count = Object.values(weeklyPlan).reduce((n, entries) => n + (entries || []).length, 0);
  if (count === 0) { showToast('Week is already empty.', 'info'); return; }
  if (!confirm(`Clear all ${count} meal(s) from the whole week?`)) return;
  try {
    await apiFetch({ action: 'clear_week' });
    for (let d = 1; d <= 7; d++) weeklyPlan[d] = [];
    renderAllColumns();
    showToast('Week cleared.', 'info');
  } catch (e) { showToast('Error: ' + e.message, 'error'); }
}

/* ════════════════════════════════════════════════════════════
   FIXTURE LOADER
   ════════════════════════════════════════════════════════════ */
function bindFixtureButton() {
  const btn = $('btn-fixtures');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    if (!confirm('Load fixture meals?\n\n• Existing meals with the same name will have their ingredients refreshed.\n• New meals from the fixture will be added.\n• Your existing meals and weekly plan will NOT be deleted.')) return;
    btn.disabled = true;
    btn.textContent = 'Loading…';
    try {
      const data = await apiFetch({ action: 'load_fixtures' });
      await fetchMeals();
      weeklyPlan = {};
      renderMealList();
      renderAllColumns();
      await refreshIngredientAutocomplete();
      const parts = [];
      if (data.inserted > 0) parts.push(`${data.inserted} added`);
      if (data.updated  > 0) parts.push(`${data.updated} updated`);
      showToast(`✅ Fixtures loaded — ${parts.join(', ')}`, 'success');
    } catch (e) {
      showToast('Fixture load failed: ' + e.message, 'error');
    } finally {
      btn.disabled = false;
      btn.textContent = '🗂️ Load Fixture Meals';
    }
  });
}

/* ════════════════════════════════════════════════════════════
   GROCERY LIST
   ════════════════════════════════════════════════════════════ */
function bindGroceryButton() {
  $('btn-clear-week').addEventListener('click', handleClearWeek);

  $('btn-grocery').addEventListener('click', async () => {
    groceryData = null;
    activeGroceryTab = 'by-day';
    // Reset tab UI
    $$('.grocery-tab').forEach(t => t.classList.toggle('active', t.dataset.gtab === 'by-day'));

    const content = $('grocery-list-content');
    content.innerHTML = '<div class="grocery-empty"><div class="spinner" style="margin:auto"></div></div>';
    openModal('modal-grocery');

    try {
      groceryData = await apiFetch({ action: 'get_grocery_list' });
      renderGroceryTab('by-day');
    } catch (e) {
      content.innerHTML = `<div class="grocery-empty">Error: ${escHtml(e.message)}</div>`;
    }
  });
}

function bindGroceryTabs() {
  $$('.grocery-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      $$('.grocery-tab').forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      activeGroceryTab = tab.dataset.gtab;
      if (groceryData) renderGroceryTab(activeGroceryTab);
    });
  });
}

function renderGroceryTab(tab) {
  if (tab === 'by-day') renderGroceryByDay(groceryData.grocery || []);
  else                  renderGroceryTotals(groceryData.totals || []);
}

/* ── By-Day view ────────────────────────────────────────── */
function renderGroceryByDay(grocery) {
  const content = $('grocery-list-content');
  if (grocery.length === 0) {
    content.innerHTML = `<div class="grocery-empty"><div style="font-size:40px;margin-bottom:10px">🛒</div><p>No meals planned yet.</p></div>`;
    return;
  }

  const byDay = {};
  grocery.forEach(item => {
    const key = item.day_of_week;
    if (!byDay[key]) byDay[key] = {};
    const mk = item.meal_name;
    if (!byDay[key][mk]) byDay[key][mk] = [];
    byDay[key][mk].push(item);
  });

  let html = '';
  Object.keys(byDay).sort((a,b) => a-b).forEach(day => {
    html += `<div class="grocery-day-group"><div class="grocery-day-label">📅 ${DAYS[day-1]}</div>`;
    Object.keys(byDay[day]).forEach(mealName => {
      html += `<div class="grocery-meal-group"><div class="grocery-meal-name">🍴 ${escHtml(mealName)}</div>`;
      byDay[day][mealName].forEach(ing => {
        if (!ing.ingredient_name) return;
        html += `<div class="grocery-ingredient-row">
          <span class="grocery-ingredient-name">${escHtml(ing.ingredient_name)}</span>
          <span class="grocery-ingredient-qty">${escHtml(ing.quantity || '—')}</span>
        </div>`;
      });
      html += `</div>`;
    });
    html += `</div>`;
  });
  content.innerHTML = html;
}

/* ── Totals view ────────────────────────────────────────── */
function renderGroceryTotals(totals) {
  const content = $('grocery-list-content');
  if (totals.length === 0) {
    content.innerHTML = `<div class="grocery-empty"><div style="font-size:40px;margin-bottom:10px">🛒</div><p>No meals planned yet.</p></div>`;
    return;
  }

  let html = `<div class="grocery-totals-header">
    <span>Ingredient</span><span>Total Needed</span>
  </div>`;
  totals.forEach(row => {
    html += `<div class="grocery-total-row">
      <span class="grocery-ingredient-name">${escHtml(row.name)}</span>
      <span class="grocery-total-qty">${escHtml(row.total)}</span>
    </div>`;
  });
  content.innerHTML = html;
}

/* ════════════════════════════════════════════════════════════
   MODAL HELPERS
   ════════════════════════════════════════════════════════════ */
function openModal(id)  { $(id).classList.add('open');    document.body.style.overflow = 'hidden'; }
function closeModal(id) { $(id).classList.remove('open'); document.body.style.overflow = '';       }

function bindModalCloseOnOverlay() {
  $$('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(overlay.id); });
  });
  $$('[data-close-modal]').forEach(btn => {
    btn.addEventListener('click', () => closeModal(btn.dataset.closeModal));
  });
}

/* ════════════════════════════════════════════════════════════
   MOBILE MENU TOGGLE
   ════════════════════════════════════════════════════════════ */
/* ════════════════════════════════════════════════════════
   SEARCH
   ════════════════════════════════════════════════════════ */
function bindSearch() {
  const input   = $('meal-search');
  const clearBtn = $('meal-search-clear');
  const emptyEl = $('search-empty');
  const termEl  = $('search-term-label');
  if (!input) return;

  input.addEventListener('input', () => {
    applySearch(input.value.trim());
    clearBtn.style.display = input.value ? 'block' : 'none';
  });

  clearBtn.addEventListener('click', () => {
    input.value = '';
    clearBtn.style.display = 'none';
    applySearch('');
    input.focus();
  });

  function applySearch(query) {
    const q = query.toLowerCase();
    const cards = $$('#sortable-meals .meal-card');
    let visible = 0;

    cards.forEach(card => {
      const name  = (card.dataset.mealName || '').toLowerCase();
      // Also search inside ingredient pill text
      const ings  = Array.from(card.querySelectorAll('.mc-ing'))
                         .map(el => el.textContent.toLowerCase())
                         .join(' ');
      const match = !q || name.includes(q) || ings.includes(q);
      card.style.display = match ? '' : 'none';
      if (match) visible++;
    });

    // Show/hide no-results state
    if (emptyEl) {
      const hasCards = cards.length > 0;
      emptyEl.style.display = (hasCards && visible === 0 && q) ? 'block' : 'none';
      if (termEl) termEl.textContent = `"${query}"`;
    }
  }
}

/* ════════════════════════════════════════════════════════
   MOBILE MENU TOGGLE
   ════════════════════════════════════════════════════════ */
function bindMenuToggle() {
  const sidebar = $('sidebar');
  const overlay = $('sidebar-overlay');
  $('btn-menu-toggle').addEventListener('click', () => {
    sidebar.classList.toggle('open');
    overlay.classList.toggle('open');
  });
  overlay.addEventListener('click', () => {
    sidebar.classList.remove('open');
    overlay.classList.remove('open');
  });
}

/* ════════════════════════════════════════════════════════════
   TOASTS
   ════════════════════════════════════════════════════════════ */
function showToast(message, type = 'info') {
  const icons = { success: '✅', error: '❌', info: 'ℹ️' };
  const container = $('toast-container');
  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  toast.innerHTML = `<span>${icons[type]}</span><span>${escHtml(message)}</span>`;
  container.appendChild(toast);
  setTimeout(() => toast.remove(), 3200);
}

/* ════════════════════════════════════════════════════════════
   UTILITIES
   ════════════════════════════════════════════════════════════ */
function escHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function getTodayDayNum() {
  const d = new Date().getDay(); // 0=Sun
  return d === 0 ? 7 : d;
}

/* ════════════════════════════════════════════════════════════
   AI EVALUATION LOGIC
   ════════════════════════════════════════════════════════════ */
function bindAiEvaluation() {
  const openModal = () => {
    $('modal-evaluation').classList.add('open');
    runEvaluation();
  };

  const btnEval = $('btn-ai-eval');
  if (btnEval) btnEval.addEventListener('click', openModal);

  const btnBanner = $('btn-banner-eval');
  if (btnBanner) btnBanner.addEventListener('click', openModal);

  const btnOpenChat = $('btn-open-chat-from-eval');
  if (btnOpenChat) {
    btnOpenChat.addEventListener('click', () => {
      $('modal-evaluation').classList.remove('open');
      openChatbotWithPrompt('Can you suggest some improvements based on the weekly evaluation?');
    });
  }
}

async function runEvaluation() {
  const content = $('eval-modal-content');
  content.innerHTML = `
    <div class="eval-loading-wrap">
      <div class="eval-spinner"></div>
      <p style="font-weight:600;color:var(--text-primary);margin-bottom:6px;">Analyzing your weekly meal schedule...</p>
      <p style="font-size:12px;color:var(--text-secondary);">Evaluating caloric distribution, macro balance, ingredient diversity, and variety gaps.</p>
    </div>
  `;

  try {
    const data = await apiFetch({ action: 'evaluate_weekly_plan' });
    if (data.evaluation) {
      content.innerHTML = formatMarkdown(data.evaluation);
    } else {
      content.innerHTML = `<p style="color:var(--danger)">Could not load evaluation. Please try again.</p>`;
    }
  } catch (err) {
    content.innerHTML = `<p style="color:var(--danger)">Error running evaluation: ${escHtml(err.message)}</p>`;
  }
}

/* ════════════════════════════════════════════════════════════
   AI CHATBOT LOGIC
   ════════════════════════════════════════════════════════════ */
let chatHistory = [];

function bindChatbot() {
  const toggleBtn   = $('btn-chatbot-toggle');
  const topbarBtn   = $('btn-topbar-chat');
  const panel       = $('chatbot-panel');
  const closeBtn    = $('btn-chatbot-close');
  const clearBtn    = $('btn-chatbot-clear');
  const form        = $('chatbot-form');
  const input       = $('chatbot-input');
  const suggestions = $$('.chatbot-suggestions .chip');

  const toggleChatbot = () => setChatOpen(!panel.classList.contains('open'));

  if (toggleBtn)   toggleBtn.addEventListener('click', toggleChatbot);
  if (topbarBtn)   topbarBtn.addEventListener('click', toggleChatbot);

  const peek        = $('chatbot-peek');
  const collapseBtn = $('btn-chatbot-collapse');
  if (peek)        peek.addEventListener('click', () => setChatExpanded(!panel.classList.contains('expanded')));
  if (collapseBtn) collapseBtn.addEventListener('click', () => setChatExpanded(false));

  // Escape collapses the history first, then closes the dock
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape' || !panel || !panel.classList.contains('open')) return;
    if (panel.classList.contains('expanded')) setChatExpanded(false);
    else setChatOpen(false);
  });

  if (closeBtn) closeBtn.addEventListener('click', () => setChatOpen(false));

  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      chatHistory = [];
      const messages = $('chatbot-messages');
      messages.innerHTML = `
        <div class="chat-msg chat-msg-assistant">
          <div class="chat-msg-avatar">🤖</div>
          <div class="chat-msg-body">
            <p>Conversation cleared. How can I help you plan your meals?</p>
          </div>
        </div>
      `;
      setChatPeek('Conversation cleared. How can I help you plan your meals?');
      showToast('Conversation cleared', 'info');
    });
  }

  suggestions.forEach(chip => {
    chip.addEventListener('click', () => {
      const prompt = chip.getAttribute('data-prompt');
      if (prompt) {
        input.value = prompt;
        form.dispatchEvent(new Event('submit'));
      }
    });
  });

  if (form) {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const message = input.value.trim();
      if (!message) return;

      appendChatMessage('user', message);
      input.value = '';
      input.disabled = true;
      $('btn-chatbot-send').disabled = true;

      // Add temporary thinking indicator
      const thinkingMsg = appendChatMessage('assistant', 'Thinking and planning...');
      thinkingMsg.classList.add('chat-msg-thinking');
      setChatPeek('Thinking and planning...', true);

      try {
        const payload = {
          action: 'chat',
          message: message,
          history: JSON.stringify(chatHistory.slice(-10)),
        };

        const res = await apiFetch(payload);
        thinkingMsg.remove();

        const reply = res.reply || 'Action completed.';
        const replyMsg = appendChatMessage('assistant', reply, res.actions || []);
        setChatPeek(replyMsg.querySelector('.chat-msg-body').textContent);

        chatHistory.push({ role: 'user', content: message });
        chatHistory.push({ role: 'model', content: reply });

        // If the assistant performed mutations, automatically refresh the board & list!
        if (res.actions && res.actions.length > 0) {
          await Promise.all([fetchMeals(), fetchWeeklyPlan()]);
          renderMealList();
          renderAllColumns();
          initSortable();
          refreshIngredientAutocomplete();
          showToast('Planner updated by AI Assistant!', 'success');
        }
      } catch (err) {
        thinkingMsg.remove();
        appendChatMessage('assistant', `⚠️ Sorry, an error occurred: ${err.message}`);
        setChatPeek(`⚠️ Sorry, an error occurred: ${err.message}`);
      } finally {
        input.disabled = false;
        $('btn-chatbot-send').disabled = false;
        input.focus();
      }
    });
  }
}

function setChatOpen(open) {
  const panel = $('chatbot-panel');
  panel.classList.toggle('open', open);
  document.body.classList.toggle('chat-open', open);
  if (open) $('chatbot-input').focus();
  else setChatExpanded(false);
}

function setChatExpanded(expanded) {
  const panel = $('chatbot-panel');
  panel.classList.toggle('expanded', expanded);
  $('chatbot-peek').setAttribute('aria-expanded', String(expanded));
  $('chatbot-peek-toggle').textContent = expanded ? 'Show less ▾' : 'Show more ▴';
  if (expanded) {
    const messages = $('chatbot-messages');
    messages.scrollTop = messages.scrollHeight;
  }
}

// Shows a single line (thinking state or latest reply) above the chat input
function setChatPeek(text, thinking = false) {
  $('chatbot-peek-text').textContent = text.replace(/\s+/g, ' ').trim();
  $('chatbot-peek').classList.toggle('thinking', thinking);
}

function openChatbotWithPrompt(prompt) {
  setChatOpen(true);
  const input = $('chatbot-input');
  input.value = prompt;
  input.focus();
}

function appendChatMessage(role, text, actions = []) {
  const container = $('chatbot-messages');
  const msgEl = document.createElement('div');
  msgEl.className = `chat-msg chat-msg-${role}`;

  let actionHtml = '';
  if (actions && actions.length > 0) {
    actionHtml = '<div class="chat-actions-container">' + actions.map(a => {
      const toolName = a.tool || 'action';
      return `<span class="chat-action-badge">⚡ ${escHtml(toolName)}</span>`;
    }).join('') + '</div>';
  }

  const avatar = role === 'assistant' ? '<div class="chat-msg-avatar">🤖</div>' : '';
  const formatted = role === 'assistant' ? formatMarkdown(text) : `<p>${escHtml(text)}</p>`;

  msgEl.innerHTML = `
    ${avatar}
    <div class="chat-msg-body">
      ${formatted}
      ${actionHtml}
    </div>
  `;

  container.appendChild(msgEl);
  container.scrollTop = container.scrollHeight;
  return msgEl;
}

function formatMarkdown(text) {
  if (!text) return '';
  let html = escHtml(text);

  // Headers
  html = html.replace(/^#### (.*$)/gim, '<h4>$1</h4>');
  html = html.replace(/^### (.*$)/gim, '<h3>$1</h3>');
  html = html.replace(/^## (.*$)/gim, '<h3>$1</h3>');
  html = html.replace(/^# (.*$)/gim, '<h3>$1</h3>');

  // Bold & Italic
  html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
  html = html.replace(/\*(.*?)\*/g, '<em>$1</em>');

  // Bullet points
  html = html.replace(/(?:^|\n)[•*-] (.*?)(?=(?:\n[•*-] )|\n\n|$)/gs, (match) => {
    const items = match.trim().split(/\n[•*-] /).map(s => s.replace(/^[•*-] /, '').trim()).filter(Boolean);
    return '<ul>' + items.map(i => `<li>${i}</li>`).join('') + '</ul>';
  });

  // Paragraphs
  html = html.split('\n\n').map(p => {
    p = p.trim();
    if (!p) return '';
    if (p.startsWith('<h3>') || p.startsWith('<h4>') || p.startsWith('<ul>') || p.startsWith('<div')) {
      return p;
    }
    return `<p>${p.replace(/\n/g, '<br>')}</p>`;
  }).join('');

  return html;
}
