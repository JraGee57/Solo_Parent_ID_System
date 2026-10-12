// Tooltips for the analytics charts: any element with data-tip shows its text
// on hover or keyboard focus (multi-line via "\n"), next to the mark.
(function () {
  var tip = document.createElement('div');
  tip.className = 'vz-tooltip';
  tip.setAttribute('role', 'tooltip');
  tip.hidden = true;
  document.body.appendChild(tip);

  function show(el) {
    var lines = el.getAttribute('data-tip').split('\n');
    tip.textContent = '';
    lines.forEach(function (line, i) {
      var div = document.createElement('div');
      div.textContent = line;
      if (i === 0 && lines.length > 1) div.className = 'vz-tooltip-title';
      tip.appendChild(div);
    });
    tip.hidden = false;
    var r = el.getBoundingClientRect();
    var t = tip.getBoundingClientRect();
    var left = r.left + r.width / 2 - t.width / 2;
    left = Math.max(8, Math.min(left, window.innerWidth - t.width - 8));
    var top = r.top - t.height - 8;
    if (top < 8) top = r.bottom + 8;
    tip.style.left = (left + window.scrollX) + 'px';
    tip.style.top = (top + window.scrollY) + 'px';
  }
  function hide() { tip.hidden = true; }

  document.querySelectorAll('[data-tip]').forEach(function (el) {
    el.addEventListener('mouseenter', function () { show(el); });
    el.addEventListener('mouseleave', hide);
    el.addEventListener('focus', function () { show(el); });
    el.addEventListener('blur', hide);
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });
})();
