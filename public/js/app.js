(function () {
  'use strict';

  // ---- Nav header slide-out drawer ----
  var navMenuBtn = document.getElementById('nav-menu-btn');
  var navDrawer = document.getElementById('nav-drawer');
  var navOverlay = document.getElementById('nav-drawer-overlay');
  var navDrawerClose = document.getElementById('nav-drawer-close');

  function openNavDrawer() {
    navOverlay.hidden = false;
    requestAnimationFrame(function () {
      navDrawer.classList.add('open');
      navOverlay.classList.add('open');
    });
    document.body.classList.add('nav-drawer-open');
    navMenuBtn.setAttribute('aria-expanded', 'true');
    navDrawer.setAttribute('aria-hidden', 'false');
  }
  function closeNavDrawer() {
    navDrawer.classList.remove('open');
    navOverlay.classList.remove('open');
    document.body.classList.remove('nav-drawer-open');
    navMenuBtn.setAttribute('aria-expanded', 'false');
    navDrawer.setAttribute('aria-hidden', 'true');
    window.setTimeout(function () {
      if (!navOverlay.classList.contains('open')) navOverlay.hidden = true;
    }, 300);
  }

  if (navMenuBtn && navDrawer && navOverlay) {
    navMenuBtn.addEventListener('click', function () {
      if (navDrawer.classList.contains('open')) {
        closeNavDrawer();
      } else {
        openNavDrawer();
      }
    });
    navOverlay.addEventListener('click', closeNavDrawer);
    if (navDrawerClose) navDrawerClose.addEventListener('click', closeNavDrawer);
    navDrawer.querySelectorAll('.nav-drawer-link').forEach(function (link) {
      link.addEventListener('click', closeNavDrawer);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && navDrawer.classList.contains('open')) closeNavDrawer();
    });
  }

  // ---- Nav drawer: expandable submenus (My Application, ID Renewal, ...) ----
  document.querySelectorAll('.nav-drawer-toggle').forEach(function (toggle) {
    var submenu = document.getElementById(toggle.getAttribute('aria-controls'));
    if (!submenu) return;
    toggle.addEventListener('click', function () {
      var willOpen = !submenu.classList.contains('open');
      submenu.classList.toggle('open', willOpen);
      toggle.classList.toggle('open', willOpen);
      toggle.setAttribute('aria-expanded', String(willOpen));
    });
  });

  // ---- Category -> required documents preview (Apply Step 1) ----
  var categorySelect = document.getElementById('circumstance-select');
  var categoryBox = document.getElementById('category-doc-box');
  if (categorySelect && categoryBox && window.CATEGORY_DOCS) {
    function renderCategoryDocs() {
      var docs = window.CATEGORY_DOCS[categorySelect.value];
      if (!docs || !docs.length) {
        categoryBox.style.display = 'none';
        categoryBox.innerHTML = '';
        return;
      }
      categoryBox.textContent = '';
      var heading = document.createElement('div');
      heading.className = 'category-requirements-heading';
      heading.textContent = 'Documents required for this category:';
      categoryBox.appendChild(heading);
      var list = document.createElement('ul');
      list.className = 'category-requirements-list';
      docs.forEach(function (doc) {
        var item = document.createElement('li');
        var label = typeof doc === 'string' ? doc : doc.label;
        if (typeof doc === 'object' && doc.url) {
          var link = document.createElement('a');
          link.href = doc.url;
          link.target = '_blank';
          link.rel = 'noopener';
          link.textContent = label;
          item.appendChild(link);
        } else {
          item.textContent = label;
        }
        list.appendChild(item);
      });
      categoryBox.appendChild(list);
      var note = document.createElement('p');
      note.className = 'category-requirements-note';
      note.textContent = 'Select a requirement to read its guide, then return to your application.';
      categoryBox.appendChild(note);
      categoryBox.style.display = 'block';
    }
    categorySelect.addEventListener('change', renderCategoryDocs);
    renderCategoryDocs();
  }

  // ---- Read requirements without leaving or resetting the application form ----
  var requirementDialog = document.getElementById('application-requirement-dialog');
  if (requirementDialog && typeof requirementDialog.showModal === 'function' && window.fetch && window.DOMParser) {
    var requirementContent = document.getElementById('application-requirement-content');
    var requirementBack = document.getElementById('application-requirement-back');
    var requirementOrigin = null;
    var requirementRequest = 0;
    var requirementScroll = 0;

    function loadRequirementGuide(url) {
      var request = ++requirementRequest;
      requirementContent.setAttribute('aria-busy', 'true');
      requirementContent.textContent = '';
      var status = document.createElement('p');
      status.setAttribute('role', 'status');
      status.textContent = 'Loading requirement instructions…';
      requirementContent.appendChild(status);
      window.fetch(url, { credentials: 'same-origin' }).then(function (response) {
        if (!response.ok) throw new Error('Guide unavailable');
        return response.text();
      }).then(function (html) {
        if (request !== requirementRequest || !requirementDialog.open) return;
        var page = new window.DOMParser().parseFromString(html, 'text/html');
        var guide = page.querySelector('.requirements-guide');
        if (!guide) throw new Error('Guide unavailable');
        guide.querySelectorAll('a').forEach(function (link) {
          var destination = new URL(link.getAttribute('href'), url);
          link.href = destination.href;
          // Support and official references stay separate from the unsaved form.
          link.target = '_blank';
          link.rel = 'noopener';
        });
        requirementContent.textContent = '';
        requirementContent.appendChild(document.importNode(guide, true));
      }).catch(function () {
        if (request !== requirementRequest || !requirementDialog.open) return;
        requirementContent.textContent = '';
        var message = document.createElement('p');
        message.setAttribute('role', 'alert');
        message.textContent = 'The guide could not load. Try opening it in a new tab, or return to your application.';
        var fallback = document.createElement('a');
        fallback.href = url;
        fallback.target = '_blank';
        fallback.rel = 'noopener';
        fallback.textContent = 'Open guide in a new tab';
        requirementContent.appendChild(message);
        requirementContent.appendChild(fallback);
      }).finally(function () {
        if (request === requirementRequest) requirementContent.setAttribute('aria-busy', 'false');
      });
    }

    function openRequirementGuide(event) {
      var link = event.target.closest('a');
      if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      var url = new URL(link.href, window.location.href);
      if (url.origin !== window.location.origin || url.searchParams.get('section') !== 'requirements') return;
      event.preventDefault();
      requirementOrigin = link;
      requirementScroll = window.scrollY;
      requirementDialog.showModal();
      document.body.classList.add('application-requirement-open');
      loadRequirementGuide(url.href);
    }

    if (categoryBox) categoryBox.addEventListener('click', openRequirementGuide);
    var allRequirementsLink = document.querySelector('.personal-requirements-guide-link');
    if (allRequirementsLink) allRequirementsLink.addEventListener('click', openRequirementGuide);
    requirementBack.addEventListener('click', function () { requirementDialog.close(); });
    requirementDialog.addEventListener('close', function () {
      ++requirementRequest;
      document.body.classList.remove('application-requirement-open');
      requirementContent.textContent = '';
      requirementContent.setAttribute('aria-busy', 'false');
      if (requirementOrigin && requirementOrigin.isConnected) requirementOrigin.focus({ preventScroll: true });
      window.scrollTo(0, requirementScroll);
    });
    requirementContent.addEventListener('click', function (event) {
      var link = event.target.closest('a');
      if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      var url = new URL(link.href, window.location.href);
      if (url.origin === window.location.origin && url.pathname === window.location.pathname && url.searchParams.get('section') === 'requirements') {
        event.preventDefault();
        loadRequirementGuide(url.href);
      }
    });
  }

  // ---- File input: show chosen filename immediately, basic client check ----
  document.querySelectorAll('input[type=file][data-doc-input]').forEach(function (input) {
    input.addEventListener('change', function () {
      var row = input.closest('.doc-row');
      if (!row) return;
      var nameEl = row.querySelector('.doc-filename');
      var file = input.files && input.files[0];
      if (!file) return;
      var okType = /\.(jpe?g|png|pdf)$/i.test(file.name);
      var okSize = file.size <= 5 * 1024 * 1024;
      if (!okType || !okSize) {
        nameEl.textContent = !okType ? 'This file type is not accepted. Please choose a JPG, PNG, or PDF file.' : 'This file is larger than 5MB. Please choose a smaller file or a lower-resolution scan.';
        nameEl.classList.remove('attached');
        return;
      }
      // The upload form submits as soon as a file is chosen (see step-documents.php).
      nameEl.textContent = file.name + ' — uploading, please wait…';
      nameEl.classList.add('attached');
    });
  });

  // ---- Consent checkbox toggling the submit button (extra client guard) ----
  var consentBox = document.getElementById('consent-checkbox');
  var submitBtn = document.getElementById('submit-application-btn');
  if (consentBox && submitBtn) {
    var syncSubmit = function () { submitBtn.disabled = !consentBox.checked; };
    consentBox.addEventListener('change', syncSubmit);
    syncSubmit();
  }

  // ---- Loading state for every POST form ----
  // Once a form is really submitting (it passed validation and any confirm()),
  // its button shows "Please wait…" and further submits are ignored, so a
  // double click can't send an application, upload or SMS twice. The button
  // is not disabled: a disabled button would drop its name/value (e.g.
  // action=...) from the submitted data. Forms opting out: data-no-loading.
  var busyForms = [];
  function resetBusy() {
    busyForms.forEach(function (entry) {
      entry.form.removeAttribute('aria-busy');
      delete entry.form.dataset.submitting;
      if (entry.button) {
        entry.button.classList.remove('is-loading');
        entry.button.removeAttribute('aria-disabled');
        if (entry.label !== null) entry.button.innerHTML = entry.label;
      }
      if (entry.textNode) {
        entry.textNode.nodeValue = entry.text;
        entry.textNode.parentNode.classList.remove('is-loading');
      }
    });
    busyForms = [];
  }
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement) || (form.method || '').toLowerCase() !== 'post' || form.hasAttribute('data-no-loading') || form.target === '_blank') return;
    if (form.dataset.submitting) { e.preventDefault(); return; }
    if (e.defaultPrevented) return; // cancelled by a confirm() or other handler
    form.dataset.submitting = '1';
    form.setAttribute('aria-busy', 'true');
    var button = e.submitter || form.querySelector('button[type=submit], button:not([type]), input[type=submit]');
    var entry = { form: form, button: button, label: null, textNode: null, text: null };
    // Upload forms that send as soon as a file is picked have no button: their "Choose file" label says "Uploading…".
    var picker = !button && form.querySelector('label > input[type=file]');
    if (picker) {
      var label = picker.parentNode;
      for (var n = label.firstChild; n; n = n.nextSibling) {
        if (n.nodeType === 3 && n.nodeValue.trim() !== '') { entry.textNode = n; entry.text = n.nodeValue; n.nodeValue = 'Uploading…'; label.classList.add('is-loading'); break; }
      }
    }
    if (button) {
      button.classList.add('is-loading');
      button.setAttribute('aria-disabled', 'true');
      if (button.tagName === 'BUTTON' && button.textContent.trim() !== '') {
        entry.label = button.innerHTML;
        button.style.minWidth = button.offsetWidth + 'px';
        button.textContent = 'Please wait…';
      }
    }
    busyForms.push(entry);
    // A response that doesn't leave the page (e.g. a file download) mustn't leave the form stuck.
    setTimeout(resetBusy, 10000);
  });
  // Coming back with the browser's Back button restores the page as it was left.
  window.addEventListener('pageshow', function (e) { if (e.persisted) resetBusy(); });
})();

// Progressively enhance the public checker; the complete form works without JS.
(function () {
  'use strict';
  var form = document.querySelector('[data-eligibility-wizard]');
  if (!form) return;
  var steps = Array.from(form.querySelectorAll('[data-eligibility-step]'));
  var progress = form.querySelector('[data-eligibility-progress]');
  var label = form.querySelector('[data-eligibility-step-label]');
  var back = form.querySelector('[data-eligibility-back]');
  var next = form.querySelector('[data-eligibility-next]');
  var submit = form.querySelector('[data-eligibility-submit]');
  var current = 0;
  function show(index, focus) {
    current = index;
    steps.forEach(function (step, i) {
      step.hidden = i !== index;
      step.querySelectorAll('input[type="radio"]').forEach(function (input) { input.required = i === index; });
    });
    progress.hidden = false;
    label.textContent = 'Question ' + (index + 1) + ' of ' + steps.length;
    progress.querySelector('progress').value = index + 1;
    back.hidden = index === 0;
    next.hidden = index === steps.length - 1;
    submit.hidden = index !== steps.length - 1;
    if (focus) steps[index].focus();
  }
  function answered(index) { return !!steps[index].querySelector('input:checked'); }
  function advance() {
    if (!answered(current)) {
      steps[current].querySelector('input').reportValidity();
      return;
    }
    show(current + 1, true);
  }
  back.addEventListener('click', function () { show(current - 1, true); });
  next.addEventListener('click', advance);
  form.addEventListener('submit', function (event) {
    if (current < steps.length - 1) { event.preventDefault(); advance(); return; }
    var missing = steps.findIndex(function (step, i) { return !answered(i); });
    if (missing !== -1) { event.preventDefault(); show(missing, true); steps[missing].querySelector('input').reportValidity(); }
  });
  show(0, false);
})();
