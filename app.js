/* ============================
   app.js — SEI Gestão de Versões
   Protótipo Funcional (Frontend)
   ============================ */

'use strict';

// ── State ──────────────────────────────────────────────
// ── Local Database Service (Mock DB) ──────────────────
const DB_NAME = 'sei_simulated_db';

const MockDB = {
  // Inicializa o banco com dados fictícios se estiver vazio ou com poucos dados
  init() {
    const existingData = localStorage.getItem(DB_NAME);
    let shouldGenerate = false;
    
    if (!existingData) {
      shouldGenerate = true;
    } else {
      const parsed = JSON.parse(existingData);
      // Se o usuário ainda estiver com a versão antiga de 60 documentos, recriamos com 10.000
      if (parsed.length < 5000) {
        shouldGenerate = true;
      }
    }

    if (shouldGenerate) {
      console.log('Inicializando banco de dados fictício com 10.000 registros...');
      const initialData = this.generateInitialData();
      localStorage.setItem(DB_NAME, JSON.stringify(initialData));
    }
  },

  generateInitialData() {
    const TIPOS = ['Ofício', 'Despacho', 'Memorando', 'Contrato', 'Relatório', 'Nota Técnica', 'Portaria', 'Resolução'];
    const docs = [];
    const today = new Date();
    
    // Gerando 10.000 documentos
    for (let i = 1; i <= 10000; i++) {
      const tipo = TIPOS[Math.floor(Math.random() * TIPOS.length)];
      
      // Data de criação nos últimos 6 meses
      const criado = new Date();
      criado.setDate(today.getDate() - Math.floor(Math.random() * 180));
      
      // Último acesso entre 15 e 400 dias atrás
      const acesso = new Date();
      acesso.setDate(today.getDate() - (15 + Math.floor(Math.random() * 385)));
      
      const totalVersions = Math.floor(Math.random() * 12) + 3;
      const signedVersions = Math.floor(Math.random() * 3);
      const preserved = Math.min(2 + signedVersions, totalVersions);
      const toDelete = totalVersions - preserved;
      const sizeMb = +(toDelete * (Math.random() * 0.8 + 0.2)).toFixed(2);

      docs.push({
        id: 1000 + i,
        protocolo: `SEI-202${Math.floor(Math.random()*5)}.${String(Math.floor(Math.random()*999999)).padStart(6,'0')}-${Math.floor(Math.random()*9)}`,
        tipo: tipo,
        criado: criado.toISOString().slice(0, 10),
        ultimoAcesso: acesso.toISOString().slice(0, 10),
        totalVersions,
        signedVersions,
        preserved,
        toDelete,
        sizeMb
      });
    }
    return docs;
  },

  getAll() {
    return JSON.parse(localStorage.getItem(DB_NAME) || '[]');
  },

  search(filters) {
    let docs = this.getAll();
    const { tipo, dias, dataIni, dataFim } = filters;

    return docs.filter(doc => {
      // Filtro por Tipo
      if (tipo && !doc.tipo.toLowerCase().includes(tipo.toLowerCase())) return false;
      
      // Filtro por Data de Criação
      if (doc.criado < dataIni || doc.criado > dataFim) return false;
      
      // Filtro por Prazo de Último Acesso (dias atrás)
      const diffDays = Math.floor((new Date() - new Date(doc.ultimoAcesso)) / 86400000);
      if (diffDays < dias) return false;

      return true;
    });
  },

  delete(ids) {
    const all = this.getAll();
    const remaining = all.filter(doc => !ids.includes(doc.id));
    localStorage.setItem(DB_NAME, JSON.stringify(remaining));
    return ids.length;
  },

  reset() {
    localStorage.removeItem(DB_NAME);
    this.init();
    location.reload();
  }
};

// ── State ──────────────────────────────────────────────
const state = {
  allDocuments: [],
  filteredDocuments: [],
  currentPage: 1,
  pageSize: 10,
  selectedIds: new Set(),
  lastFilters: null
};

// ── Helpers ────────────────────────────────────────────
const $ = id => document.getElementById(id);
const show = id => $(id).classList.remove('hidden');
const hide = id => $(id).classList.add('hidden');

function formatDate(dateStr) {
  if (!dateStr) return '—';
  const d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString('pt-BR');
}

function daysDiff(dateStr) {
  const d = new Date(dateStr);
  const now = new Date();
  return Math.floor((now - d) / 86400000);
}

function formatBytes(mb) {
  if (mb < 1) return `${Math.round(mb * 1024)} KB`;
  return `${mb.toFixed(1)} MB`;
}

// ── Form Helpers ────────────────────────────────────────
function setTipoDoc(val) {
  $('tipo-documento').value = val;
}

function clearForm() {
  $('search-form').reset();
  hide('window-info');
  hide('date-range-error');
  hide('results-section');
  state.allDocuments = [];
  state.selectedIds.clear();
}

// ── Date validation ─────────────────────────────────────
function validateDateRange() {
  const ini = $('data-inicial').value;
  const fim = $('data-final').value;
  if (!ini || !fim) { hide('window-info'); hide('date-range-error'); return null; }

  const d1 = new Date(ini), d2 = new Date(fim);
  if (d2 < d1) {
    show('date-range-error');
    $('date-range-error').textContent = '⚠ A data final deve ser maior que a data inicial.';
    hide('window-info');
    return false;
  }

  const diffMonths = (d2 - d1) / (1000 * 60 * 60 * 24 * 30);
  if (diffMonths > 6) {
    show('date-range-error');
    $('date-range-error').textContent = '⚠ O intervalo máximo permitido é de 6 meses.';
    hide('window-info');
    return false;
  }

  hide('date-range-error');
  const days = Math.ceil((d2 - d1) / 86400000);
  $('window-info-text').textContent = `Janela selecionada: ${formatDate(ini)} a ${formatDate(fim)} (${days} dias)`;
  show('window-info');
  return true;
}

$('data-inicial').addEventListener('change', validateDateRange);
$('data-final').addEventListener('change', validateDateRange);

// ── Search ──────────────────────────────────────────────
$('search-form').addEventListener('submit', function(e) {
  e.preventDefault();
  const tipo  = $('tipo-documento').value;
  const dias  = $('ultimo-acesso').value;
  const dataIni = $('data-inicial').value;
  const dataFim = $('data-final').value;

  if (!dias) { alert('Selecione o prazo desde o último acesso.'); return; }
  if (!dataIni || !dataFim) { alert('Informe a data inicial e final de criação.'); return; }
  if (validateDateRange() === false) return;

  state.lastFilters = { tipo, dias, dataIni, dataFim };
  state.currentPage = 1;
  state.selectedIds.clear();

  hide('results-section');
  show('loading-overlay');

  setTimeout(() => {
    const docs = MockDB.search(state.lastFilters);
    state.allDocuments = docs;
    state.filteredDocuments = [...docs];
    hide('loading-overlay');
    renderResults();
    show('results-section');
    $('results-section').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }, 1500);
});

// ── Render Results ──────────────────────────────────────
function renderResults() {
  const docs = state.filteredDocuments;
  const total = docs.length;
  const totalVersions = docs.reduce((s, d) => s + d.toDelete, 0);
  const totalPreserved = docs.reduce((s, d) => s + d.preserved, 0);
  const totalStorage = docs.reduce((s, d) => s + d.sizeMb, 0);

  $('stat-docs').textContent     = total;
  $('stat-versions').textContent = totalVersions;
  $('stat-preserved').textContent = totalPreserved;
  $('stat-storage').textContent  = formatBytes(totalStorage);

  renderPage();
  renderPagination();
  updateDeleteBtn();
}

function renderPage() {
  const docs = state.filteredDocuments;
  const from = (state.currentPage - 1) * state.pageSize;
  const to   = Math.min(from + state.pageSize, docs.length);
  const page = docs.slice(from, to);

  $('page-from').textContent  = docs.length ? from + 1 : 0;
  $('page-to').textContent    = to;
  $('page-total').textContent = docs.length;

  const tbody = $('results-tbody');
  tbody.innerHTML = '';

  if (page.length === 0) {
    tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--gray-400)">Nenhum documento encontrado com os filtros aplicados.</td></tr>`;
    return;
  }

  page.forEach(doc => {
    const checked = state.selectedIds.has(doc.id) ? 'checked' : '';
    const selectedClass = state.selectedIds.has(doc.id) ? 'selected' : '';
    const accessDays = daysDiff(doc.ultimoAcesso);
    
    let statusBadge;
    if (doc.toDelete >= 10) statusBadge = `<span class="badge badge-danger">${doc.toDelete} versões</span>`;
    else if (doc.toDelete >= 5) statusBadge = `<span class="badge badge-warning">${doc.toDelete} versões</span>`;
    else statusBadge = `<span class="badge badge-gray">${doc.toDelete} versões</span>`;

    tbody.innerHTML += `
      <tr class="${selectedClass}" data-id="${doc.id}">
        <td class="col-check">
          <input type="checkbox" ${checked} onchange="toggleRow(${doc.id}, this)" />
        </td>
        <td><code style="font-size:.78rem;color:var(--brand)">${doc.protocolo}</code></td>
        <td><span class="badge badge-success">${doc.tipo}</span></td>
        <td>${formatDate(doc.criado)}</td>
        <td title="${accessDays} dias atrás">${formatDate(doc.ultimoAcesso)}<br><small style="color:var(--gray-400)">${accessDays} dias atrás</small></td>
        <td class="col-center"><strong>${doc.totalVersions}</strong></td>
        <td class="col-center">${statusBadge}</td>
        <td class="col-center"><span class="badge badge-success">${doc.preserved}</span></td>
        <td class="col-center"><span class="badge badge-danger">~${formatBytes(doc.sizeMb)}</span></td>
      </tr>`;
  });
}

// ── Pagination ──────────────────────────────────────────
function renderPagination() {
  const total = state.filteredDocuments.length;
  const pages = Math.ceil(total / state.pageSize);
  const cur   = state.currentPage;

  $('btn-prev').disabled = cur === 1;
  $('btn-next').disabled = cur === pages || pages === 0;

  const nums = $('page-numbers');
  nums.innerHTML = '';
  const start = Math.max(1, cur - 2);
  const end   = Math.min(pages, start + 4);
  for (let p = start; p <= end; p++) {
    const btn = document.createElement('button');
    btn.className = 'page-num' + (p === cur ? ' active' : '');
    btn.textContent = p;
    btn.onclick = () => goPage(p);
    nums.appendChild(btn);
  }
}

function changePage(delta) { goPage(state.currentPage + delta); }
function goPage(p) {
  const pages = Math.ceil(state.filteredDocuments.length / state.pageSize);
  state.currentPage = Math.max(1, Math.min(p, pages));
  renderPage();
  renderPagination();
}

// ── Selection ───────────────────────────────────────────
function toggleRow(id, cb) {
  if (cb.checked) state.selectedIds.add(id);
  else state.selectedIds.delete(id);
  const row = cb.closest('tr');
  row.classList.toggle('selected', cb.checked);
  updateDeleteBtn();
}

function toggleSelectAll(cb) {
  const docs = state.filteredDocuments;
  if (cb.checked) docs.forEach(d => state.selectedIds.add(d.id));
  else state.selectedIds.clear();
  renderPage();
  updateDeleteBtn();
}

function updateDeleteBtn() {
  const n = state.selectedIds.size;
  const btn = $('btn-delete');
  const info = $('selected-info');
  if (n === 0) {
    btn.disabled = true;
    info.textContent = 'Nenhum documento selecionado';
  } else {
    btn.disabled = false;
    const versionCount = state.allDocuments
      .filter(d => state.selectedIds.has(d.id))
      .reduce((s, d) => s + d.toDelete, 0);
    info.textContent = `${n} documento(s) selecionado(s) · ${versionCount} versões a excluir`;
  }
}

// ── Table Filter ────────────────────────────────────────
function filterTable(val) {
  const q = val.toLowerCase();
  state.filteredDocuments = q 
    ? state.allDocuments.filter(d => d.protocolo.toLowerCase().includes(q) || d.tipo.toLowerCase().includes(q))
    : [...state.allDocuments];
  state.currentPage = 1;
  renderResults();
}

// ── Modal ───────────────────────────────────────────────
function confirmDelete() {
  if (state.selectedIds.size === 0) return;
  const selected = state.allDocuments.filter(d => state.selectedIds.has(d.id));
  const totalVersions = selected.reduce((s, d) => s + d.toDelete, 0);
  const totalStorage  = selected.reduce((s, d) => s + d.sizeMb, 0);

  $('modal-summary').innerHTML = `
    <div class="modal-summary-row"><span>Documentos selecionados:</span><strong>${selected.length}</strong></div>
    <div class="modal-summary-row"><span>Versões a excluir:</span><strong style="color:var(--danger)">${totalVersions}</strong></div>
    <div class="modal-summary-row"><span>Espaço a liberar:</span><strong style="color:#7c3aed">~${formatBytes(totalStorage)}</strong></div>
  `;
  $('confirm-checkbox').checked = false;
  $('btn-confirm-delete').disabled = true;
  show('modal-backdrop');
}

function closeModal() { hide('modal-backdrop'); }
function toggleConfirmBtn(cb) { $('btn-confirm-delete').disabled = !cb.checked; }

function executeDelete() {
  hide('modal-backdrop');
  show('loading-overlay');
  
  const ids = Array.from(state.selectedIds);
  const selected = state.allDocuments.filter(d => state.selectedIds.has(d.id));
  const totalVersions = selected.reduce((s, d) => s + d.toDelete, 0);
  const totalStorage  = selected.reduce((s, d) => s + d.sizeMb, 0);

  setTimeout(() => {
    MockDB.delete(ids); // Persiste no LocalStorage
    state.allDocuments = state.allDocuments.filter(d => !state.selectedIds.has(d.id));
    state.filteredDocuments = [...state.allDocuments];
    state.selectedIds.clear();
    state.currentPage = 1;
    hide('loading-overlay');
    
    $('success-summary').innerHTML = `
      <div class="modal-summary-row"><span>Versões excluídas:</span><strong style="color:var(--success)">${totalVersions}</strong></div>
      <div class="modal-summary-row"><span>Espaço liberado:</span><strong style="color:#7c3aed">~${formatBytes(totalStorage)}</strong></div>
    `;
    show('success-backdrop');
    if (state.allDocuments.length > 0) renderResults();
    else hide('results-section');
  }, 2000);
}

function closeSuccessModal() { hide('success-backdrop'); }

// ── Init ───────────────────────────────────────────────
(function init() {
  MockDB.init(); // Garante que o banco fictício existe
  
  const today = new Date();
  const sixMonthsAgo = new Date();
  sixMonthsAgo.setMonth(today.getMonth() - 6);
  $('data-final').value   = today.toISOString().slice(0, 10);
  $('data-inicial').value = sixMonthsAgo.toISOString().slice(0, 10);
  validateDateRange();
})();

