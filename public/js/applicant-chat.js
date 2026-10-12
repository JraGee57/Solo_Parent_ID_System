(function () {
  'use strict';
  var thread = document.getElementById('chat-thread');
  if (thread) thread.scrollTop = thread.scrollHeight;
  var box = document.getElementById('chat-message');
  var count = document.getElementById('chat-character-count');
  if (box) {
    function updateCount() {
      if (count) count.textContent = Array.from(box.value).length + ' / ' + box.maxLength;
    }
    updateCount();
    box.addEventListener('input', updateCount);
    box.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && event.keyCode !== 229 && box.value.trim() !== '') {
        event.preventDefault();
        box.form.querySelector('button[value="ask"]').click();
      }
    });
  }
  var search = document.getElementById('faq-search');
  var empty = document.getElementById('faq-no-results');
  if (!search) return;
  var items = document.querySelectorAll('.applicant-chat .faq-item');
  search.addEventListener('input', function () {
    var query = search.value.trim().toLowerCase();
    var matches = 0;
    items.forEach(function (item) {
      item.hidden = !!query && item.dataset.faqQ.indexOf(query) === -1;
      if (!item.hidden) matches++;
    });
    if (empty) empty.hidden = !query || matches > 0 || items.length === 0;
  });
})();
