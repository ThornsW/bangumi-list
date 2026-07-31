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
      // 文案读 PHP 渲染的 data 属性,勿在此写死:初始文案在 render.php,两处硬编码会不一致
      var b = sortBtn.querySelector('b');
      if (b) b.textContent = sortBtn.getAttribute(
        sortMode === 'score' ? 'data-label-score' : 'data-label-date') || '';
      applySort();
    });
    // 触屏无 hover,卡片的 <a> 跳转须让位于「展开评语」,否则评语在移动端不可达。
    // 「bgm ↗」是触屏下唯一的跳转出口,对其放行,沿用外层 <a> 的 href。
    if (window.matchMedia && window.matchMedia('(hover: none)').matches) {
      cards.forEach(function (c) {
        c.addEventListener('click', function (e) {
          var t = e.target;
          if (t && t.closest && t.closest('.bgm-go')) return;
          e.preventDefault();
          c.classList.toggle('is-open');
        });
      });
    }
    applySort();
  });
})();
