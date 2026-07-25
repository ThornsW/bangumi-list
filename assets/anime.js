(function () {
  function ready(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  ready(function () {
    var app = document.querySelector('.bgm-app'); if (!app) return;
    var grid = app.querySelector('.bgm-grid'); if (!grid) return;
    var cards = Array.prototype.slice.call(grid.querySelectorAll('.bgm-card'));
    var search = app.querySelector('.bgm-search-input');
    var sortBtn = app.querySelector('.bgm-sort');
    var countEl = app.querySelector('.bgm-count');
    var sortMode = 'score';

    function applySearch(){
      var q = (search && search.value || '').trim().toLowerCase();
      var shown = 0;
      cards.forEach(function (c) {
        var t = c.getAttribute('data-title') || '';
        var hit = !q || t.indexOf(q) !== -1;
        c.style.display = hit ? '' : 'none';
        if (hit) shown++;
      });
      if (countEl) countEl.textContent = '// ' + shown + ' 部';
    }
    function num(c, attr, dflt){ var v = parseFloat(c.getAttribute(attr)); return isNaN(v) ? dflt : v; }
    function applySort(){
      var sorted = cards.slice().sort(function (a, b) {
        if (sortMode === 'score') return num(b,'data-score',-1) - num(a,'data-score',-1);
        return num(b,'data-date',0) - num(a,'data-date',0);
      });
      sorted.forEach(function (c) { grid.appendChild(c); });
    }
    if (search) search.addEventListener('input', applySearch);
    if (sortBtn) sortBtn.addEventListener('click', function () {
      sortMode = (sortMode === 'score') ? 'date' : 'score';
      var b = sortBtn.querySelector('b'); if (b) b.textContent = (sortMode === 'score') ? '评分 ↓' : '最近添加 ↓';
      applySort();
    });
    if (window.matchMedia && window.matchMedia('(hover: none)').matches) {
      cards.forEach(function (c) { c.addEventListener('click', function () { c.classList.toggle('is-open'); }); });
    }
    applySort();
  });
})();
