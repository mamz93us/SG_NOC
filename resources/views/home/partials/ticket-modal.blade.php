{{--
    IT Service Desk modal.

    Posts to home.tickets.store, which delegates to the SAME NocTicketService
    the admin page uses. Category and sub-category are loaded from
    home.tickets.catalog on first open — the tree comes from the ticketing
    API's own getCategories, so it is never a hardcoded list here.

    Up to three attachments, sent as repeated `file` parts. Multipart allows a
    duplicate part name and Guzzle emits one per attach(). If a deployment reads
    only the first, the extras vanish SILENTLY while the ticket still succeeds —
    so verify a real submit lists every file back before trusting it.
--}}
<div class="modal-overlay" id="ticketModalOverlay" aria-hidden="true">
  <div class="ticket-modal" role="dialog" aria-modal="true" aria-labelledby="ticketModalTitle">

    <div class="ticket-modal-header">
      <div class="ticket-modal-header-left">
        <button type="button" class="icon-btn" id="ticketBackBtn" aria-label="Close">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 5 8 12l7 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <h2 id="ticketModalTitle">Add Ticketing Request</h2>
      </div>
      <div class="ticket-modal-header-right">
        <button type="button" class="btn btn-primary" id="ticketSubmitBtn">Submit Ticket</button>
        <button type="button" class="btn btn-ghost" id="ticketCancelBtn">Cancel</button>
      </div>
    </div>

    <div class="ticket-modal-body" id="ticketFormView">

      <div class="field">
        <label for="ticketCategory">Category</label>
        <select id="ticketCategory">
          <option value="">Loading…</option>
        </select>
      </div>

      <div class="field">
        <label for="ticketSubCategory">Sub Category</label>
        <select id="ticketSubCategory" disabled>
          <option value="">-- Choose a category first --</option>
        </select>
      </div>

      <div class="field">
        <label for="ticketTitle">Ticket Title</label>
        <input type="text" id="ticketTitle" placeholder="Briefly describe the issue" maxlength="120">
      </div>

      <div class="field">
        <label for="ticketDescription">Ticket Description</label>
        <textarea id="ticketDescription" rows="5" placeholder="Provide as much detail as possible…" maxlength="5000"></textarea>
      </div>

      <div class="field-error" id="ticketFormError"></div>

      <div class="attachments-panel">
        <h3>Attachments</h3>
        <div class="upload-zone" id="uploadZone">
          <p class="upload-title">Upload Supporting Documents</p>
          <p class="upload-sub">Up to 3 files, 20 MB each</p>
          <p class="upload-sub">Allowed files [Images · Videos · PDF · Word · Excel]</p>
          <div class="upload-control">
            <button type="button" class="choose-file-btn" id="chooseFileBtn">Choose File</button>
            <span id="chooseFileLabel">No file chosen</span>
          </div>
          <input type="file" id="ticketFileInput" hidden multiple
                 accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.mov,.avi,.pdf,.doc,.docx,.xls,.xlsx">
        </div>
        <ul class="file-list" id="fileList"></ul>
      </div>

    </div>

    <div class="ticket-modal-body ticket-success" id="ticketSuccessView" hidden>
      <div class="success-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><path d="M7.5 12.5 10.3 15.3 16.5 9" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </div>
      <h3>Ticket submitted</h3>
      <p>Reference number <strong id="ticketRefNum">—</strong>. The IT team will follow up shortly.</p>
      <button type="button" class="btn btn-primary" id="ticketDoneBtn">Done</button>
    </div>

  </div>
</div>

@push('scripts')
<script>
(function () {
  'use strict';

  var overlay = document.getElementById('ticketModalOverlay');
  var openBtn = document.getElementById('itServiceDeskCard');
  if (!overlay || !openBtn) return;

  var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  var catalogUrl = @json(route('home.tickets.catalog'));
  var storeUrl = @json(route('home.tickets.store'));
  var MAX_BYTES = 20 * 1024 * 1024;
  var MAX_FILES = 3;
  // Held here rather than read from the input at submit time: the input is
  // replaced on every pick, so removing one file has to rebuild the set.
  var chosen = [];

  var categorySelect = document.getElementById('ticketCategory');
  var subSelect = document.getElementById('ticketSubCategory');
  var titleInput = document.getElementById('ticketTitle');
  var descInput = document.getElementById('ticketDescription');
  var errorEl = document.getElementById('ticketFormError');
  var formView = document.getElementById('ticketFormView');
  var successView = document.getElementById('ticketSuccessView');
  var submitBtn = document.getElementById('ticketSubmitBtn');
  var fileInput = document.getElementById('ticketFileInput');
  var fileLabel = document.getElementById('chooseFileLabel');
  var fileList = document.getElementById('fileList');

  var categories = [];
  var catalogLoaded = false;
  var lastFocused = null;

  function setError(msg) { errorEl.textContent = msg || ''; }

  function humanSize(bytes) {
    var units = ['B', 'KB', 'MB'];
    var i = 0, n = bytes;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return (i === 0 ? n : n.toFixed(1)) + ' ' + units[i];
  }

  // ─── Catalog ───────────────────────────────────────────────
  function loadCatalog() {
    if (catalogLoaded) return Promise.resolve();

    return fetch(catalogUrl, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)); })
      .then(function (data) {
        categories = data.categories || [];
        catalogLoaded = true;
        categorySelect.innerHTML = '';
        var placeholder = new Option(
          categories.length ? '-- Choose Category --' : 'No categories available',
          ''
        );
        categorySelect.appendChild(placeholder);
        categories.forEach(function (cat) {
          categorySelect.appendChild(new Option(cat.name, cat.id));
        });
        if (!data.configured) {
          setError('The ticketing system is not reachable right now. Please contact IT directly.');
        }
      })
      .catch(function () {
        categorySelect.innerHTML = '';
        categorySelect.appendChild(new Option('Could not load categories', ''));
        setError('The ticketing system is not reachable right now. Please contact IT directly.');
      });
  }

  function fillSubcategories() {
    var id = categorySelect.value;
    var cat = categories.find(function (c) { return String(c.id) === String(id); });
    var subs = (cat && cat.subcategories) || [];

    subSelect.innerHTML = '';
    subSelect.appendChild(new Option(
      id ? (subs.length ? '-- Choose Sub Category --' : 'No sub-categories') : '-- Choose a category first --',
      ''
    ));
    subs.forEach(function (sub) {
      subSelect.appendChild(new Option(sub.name, sub.id));
    });
    subSelect.disabled = !id || subs.length === 0;
  }

  categorySelect.addEventListener('change', function () { fillSubcategories(); setError(''); });

  // ─── File ──────────────────────────────────────────────────
  document.getElementById('chooseFileBtn').addEventListener('click', function () { fileInput.click(); });

  function humanTotal() {
    return chosen.reduce(function (n, f) { return n + f.size; }, 0);
  }

  function renderFiles() {
    fileList.innerHTML = '';

    chosen.forEach(function (file, idx) {
      var li = document.createElement('li');
      var name = document.createElement('span');
      name.textContent = file.name;
      var size = document.createElement('span');
      size.className = 'file-size';
      size.textContent = humanSize(file.size);
      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'file-remove';
      remove.setAttribute('aria-label', 'Remove ' + file.name);
      remove.textContent = '×';
      remove.addEventListener('click', function () {
        chosen.splice(idx, 1);
        renderFiles();
      });
      li.appendChild(name);
      li.appendChild(size);
      li.appendChild(remove);
      fileList.appendChild(li);
    });

    fileLabel.textContent = chosen.length
      ? chosen.length + ' of ' + MAX_FILES + ' selected (' + humanSize(humanTotal()) + ')'
      : 'No file chosen';
  }

  fileInput.addEventListener('change', function () {
    var picked = Array.prototype.slice.call(fileInput.files || []);
    var rejected = [];

    picked.forEach(function (file) {
      if (file.size > MAX_BYTES) {
        rejected.push(file.name + ' is ' + humanSize(file.size));
        return;
      }
      if (chosen.length >= MAX_FILES) {
        rejected.push(file.name + ' (limit is ' + MAX_FILES + ')');
        return;
      }
      // Same file picked twice in a row is almost always a mis-click.
      var dup = chosen.some(function (f) { return f.name === file.name && f.size === file.size; });
      if (!dup) chosen.push(file);
    });

    // Clear the input so re-picking the same file still fires `change`.
    fileInput.value = '';

    setError(rejected.length ? 'Not added: ' + rejected.join('; ') + '.' : '');
    renderFiles();
  });

  // ─── Open / close ──────────────────────────────────────────
  function open() {
    lastFocused = document.activeElement;
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    loadCatalog().then(function () { categorySelect.focus(); });
  }

  function close() {
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (lastFocused && lastFocused.focus) lastFocused.focus();
  }

  function reset() {
    formView.hidden = false;
    successView.hidden = true;
    categorySelect.value = '';
    fillSubcategories();
    titleInput.value = '';
    descInput.value = '';
    fileInput.value = '';
    chosen = [];
    renderFiles();
    setError('');
    submitBtn.disabled = false;
    submitBtn.textContent = 'Submit Ticket';
  }

  openBtn.addEventListener('click', open);
  document.getElementById('ticketBackBtn').addEventListener('click', close);
  document.getElementById('ticketCancelBtn').addEventListener('click', function () { reset(); close(); });
  document.getElementById('ticketDoneBtn').addEventListener('click', function () { reset(); close(); });

  overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && overlay.classList.contains('open')) close();
  });

  // ─── Submit ────────────────────────────────────────────────
  submitBtn.addEventListener('click', function () {
    setError('');

    if (!categorySelect.value) { setError('Please choose a category.'); categorySelect.focus(); return; }
    if (!subSelect.value) { setError('Please choose a sub category.'); subSelect.focus(); return; }
    if (!titleInput.value.trim()) { setError('Please give the ticket a title.'); titleInput.focus(); return; }
    if (!descInput.value.trim()) { setError('Please describe the issue.'); descInput.focus(); return; }

    var body = new FormData();
    body.append('category_id', categorySelect.value);
    body.append('subcategory_id', subSelect.value);
    body.append('title', titleInput.value.trim());
    body.append('description', descInput.value.trim());
    chosen.forEach(function (file) {
      body.append('attachments[]', file);
    });

    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting…';

    fetch(storeUrl, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
      body: body
    })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
      .then(function (res) {
        if (!res.ok) {
          // Laravel validation comes back as {errors: {field: [msg]}}.
          var msg = res.data.message || 'The ticket could not be submitted.';
          if (res.data.errors) {
            var first = Object.keys(res.data.errors)[0];
            if (first) msg = res.data.errors[first][0];
          }
          throw new Error(msg);
        }
        document.getElementById('ticketRefNum').textContent = res.data.reference || 'pending';
        formView.hidden = true;
        successView.hidden = false;
      })
      .catch(function (err) {
        setError(err.message || 'The ticket could not be submitted. Please try again.');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit Ticket';
      });
  });
})();
</script>
@endpush
