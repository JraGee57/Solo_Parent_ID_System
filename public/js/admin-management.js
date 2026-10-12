/* Open a collapsed editor when a settings link points to it. */
(() => {
  const revealSection = () => {
    let id;
    try { id = decodeURIComponent(window.location.hash.slice(1)); } catch { return; }
    const target = id && document.getElementById(id);
    if (!target) return;
    if (target instanceof HTMLDetailsElement) target.open = true;
    for (let parent = target.parentElement; parent; parent = parent.parentElement) {
      if (parent instanceof HTMLDetailsElement) parent.open = true;
    }
    target.scrollIntoView({ block: 'start' });
  };
  revealSection();
  window.addEventListener('hashchange', revealSection);
  document.addEventListener('click', event => {
    const link = event.target.closest('a[href^="#"]');
    if (link && link.hash === window.location.hash) revealSection();
  });
})();
