(function () {
  'use strict';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var rupee = function (n) { return '₹' + Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };

  // Dark mode toggle
  var themeBtn = $('#themeBtn');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var isDark = document.documentElement.classList.toggle('dark');
      localStorage.setItem('theme', isDark ? 'dark' : 'light');
    });
  }

  // Mobile menu
  var menuBtn = $('#menuBtn');
  if (menuBtn) {
    menuBtn.addEventListener('click', function (e) { e.stopPropagation(); $('#sidebar').classList.toggle('open'); });
    document.addEventListener('click', function (e) {
      var sb = $('#sidebar');
      if (sb.classList.contains('open') && !sb.contains(e.target)) sb.classList.remove('open');
    });
  }

  // Confirm dangerous actions
  $$('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!window.confirm(f.dataset.confirm)) e.preventDefault(); });
  });

  // Dashboard bars
  requestAnimationFrame(function () { $$('.bar[data-h]').forEach(function (b) { b.style.height = b.dataset.h + '%'; }); });

  // Invoice printing
  var printBtn = $('#printBtn');
  if (printBtn) printBtn.addEventListener('click', function () { window.print(); });
  if (document.body.dataset.autoprint) setTimeout(function () { window.print(); }, 400);

  // Copy bill text
  var copyBtn = $('#copyTextBtn');
  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      var txt = copyBtn.dataset.text || '';
      if (navigator.clipboard) {
        navigator.clipboard.writeText(txt).then(function () {
          var orig = copyBtn.textContent;
          copyBtn.textContent = '✓ Copied!';
          setTimeout(function () { copyBtn.textContent = orig; }, 2000);
        });
      } else {
        prompt('Copy bill text:', txt);
      }
    });
  }

  // UPI Dynamic QR Code renderer (using qrcode.min.js)
  if (window.QRCode) {
    $$('.upi-qr').forEach(function (el) {
      var text = el.dataset.qr;
      if (text && !el.hasChildNodes()) {
        var size = el.closest('.invoice-thermal') ? 110 : 120;
        try {
          new QRCode(el, {
            text: text,
            width: size,
            height: size,
            colorDark: '#000000',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
          });
        } catch (err) {
          console.error('QR render error:', err);
        }
      }
    });
  }

  /**
   * Medicine autocomplete. Calls api.php and hands the chosen medicine to onPick.
   */
  function autocomplete(input, list, mode, onPick, renderMeta) {
    var timer, items = [], hl = -1, seq = 0;
    function close() { list.classList.remove('open'); list.innerHTML = ''; hl = -1; }
    function draw() {
      if (!items.length) {
        list.innerHTML = '<div class="ac-item disabled" style="display:flex; justify-content:space-between; align-items:center;">' +
          '<span>No matching medicine</span>' +
          (mode === 'sale' ? '<button type="button" class="btn btn-sm btn-outline ac-shortage-btn">Shortage Book</button>' : '') +
          '</div>';
        list.classList.add('open');
        var sBtn = $('.ac-shortage-btn', list);
        if (sBtn) {
          sBtn.addEventListener('mousedown', function (e) {
            e.preventDefault();
            e.stopPropagation();
            close();
            var qsm = $('#quickShortageModal');
            if (qsm) {
              $('#qsMedName').value = input.value.trim();
              qsm.showModal();
            }
          });
        }
        return;
      }
      list.innerHTML = items.map(function (m, i) {
        var altBadge = (mode === 'sale' && m.substitute_count > 0)
          ? ' <button type="button" class="tag tag-warn btn-sub-tag" data-i="' + i + '" title="Find other brands with same salt composition">🔄 ' + m.substitute_count + ' Alt</button>'
          : '';
        return '<div class="ac-item' + (i === hl ? ' hl' : '') + (mode === 'sale' && !m.stock ? ' disabled' : '') + '" data-i="' + i + '">' +
          '<div><strong>' + esc(m.name) + '</strong>' + (m.schedule !== 'OTC' ? '<span class="rx-flag">Rx ' + esc(m.schedule) + '</span>' : '') +
          altBadge +
          '<small>' + esc(m.generic_name || m.manufacturer || '') + (m.barcode ? ' · 📱 ' + esc(m.barcode) : '') + '</small></div>' +
          '<div class="num">' + renderMeta(m) + '</div></div>';
      }).join('');
      list.classList.add('open');

      // Bind substitute button clicks
      $$('.btn-sub-tag', list).forEach(function (btn) {
        btn.addEventListener('mousedown', function (e) {
          e.preventDefault();
          e.stopPropagation();
          var itemIdx = +btn.dataset.i;
          var med = items[itemIdx];
          if (med) openSubstituteModal(med);
        });
      });
    }
    function pick(i) {
      var m = items[i];
      if (!m || (mode === 'sale' && !m.stock)) return;
      close();
      onPick(m);
    }
    input.addEventListener('input', function () {
      clearTimeout(timer);
      var q = input.value.trim();
      if (q.length < 2) { close(); return; }
      timer = setTimeout(function () {
        var my = ++seq;
        fetch('api.php?mode=' + mode + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
          .then(function (r) { if (r.status === 401) { location.href = 'login.php'; } return r.json(); })
          .then(function (data) {
            if (my !== seq) return;
            items = data || [];
            hl = items.length ? 0 : -1;
            draw();
            // Fast barcode exact match auto-pick (when scanned with barcode scanner)
            if (items.length === 1 && (items[0].barcode === q || (items[0].batches && items[0].batches[0] && items[0].batches[0].batch_no === q))) {
              pick(0);
            }
          })
          .catch(function () { close(); });
      }, 180);
    });
    input.addEventListener('keydown', function (e) {
      if (!list.classList.contains('open')) return;
      if (e.key === 'ArrowDown') { hl = Math.min(items.length - 1, hl + 1); draw(); e.preventDefault(); }
      else if (e.key === 'ArrowUp') { hl = Math.max(0, hl - 1); draw(); e.preventDefault(); }
      else if (e.key === 'Enter') { e.preventDefault(); pick(hl); }
      else if (e.key === 'Escape') { close(); }
    });
    list.addEventListener('mousedown', function (e) {
      if (e.target.closest('.btn-sub-tag')) return;
      var el = e.target.closest('.ac-item');
      if (el && el.dataset.i) { e.preventDefault(); pick(+el.dataset.i); }
    });
    input.addEventListener('blur', function () { setTimeout(close, 200); });
  }

  // ---------------- Purchase entry ----------------
  var pRows = $('#purchaseRows');
  if (pRows) {
    var tpl = $('#purchaseRowTpl').innerHTML, idx = 0, pForm = $('#purchaseForm');
    var field = function (tr, name) { return $('[name$="[' + name + ']"]', tr); };
    var round2 = function (n) { return Math.round(n * 100) / 100; };

    var recalc = function () {
      var taxable = 0, gst = 0;
      $$('tr', pRows).forEach(function (tr) {
        var packs = +(field(tr, 'packs').value || 0), rate = +(field(tr, 'rate').value || 0);
        var disc = +(field(tr, 'disc_pct').value || 0), g = +(field(tr, 'gst_rate').value || 0);
        var t = round2(packs * rate * (1 - disc / 100)), tax = round2(t * g / 100);
        $('.row-amt', tr).textContent = rupee(t + tax);
        taxable += t; gst += tax;
      });
      var ro = +($('#pRound').value || 0), total = round2(taxable + gst + ro);
      $('#pTaxable').textContent = rupee(taxable);
      $('#pGst').textContent = rupee(gst);
      $('#purchaseTotal').textContent = rupee(total);
      var st = $('#scannedTotal').value, chk = $('#scanCheck');
      if (st !== '') {
        var diff = round2(total - +st);
        chk.hidden = false;
        chk.className = 'scan-check ' + (Math.abs(diff) < 1 ? 'ok' : 'bad');
        chk.textContent = Math.abs(diff) < 1
          ? '✓ Matches scanned bill total ' + rupee(+st)
          : '⚠ Scanned bill total is ' + rupee(+st) + ' (difference ' + rupee(diff) + '). Check qty, rate, discount and GST.';
      } else chk.hidden = true;
      return taxable + gst;
    };

    var setMedicine = function (tr, m) {
      $('.ac-input', tr).value = m.name;
      $('.ac-id', tr).value = m.id;
      $('.new-med', tr).hidden = true;
      $('.nm-name', tr).value = '';
      $('.ac-meta', tr).textContent = m.pack_size + ' ' + m.unit_label + '/pack' + (m.stock != null ? ' · stock ' + m.stock : '');
    };
    var setNewMedicine = function (tr, nm) {
      $('.ac-input', tr).value = nm.name;
      $('.ac-id', tr).value = '';
      $('.ac-meta', tr).textContent = '';
      $('.new-med', tr).hidden = false;
      $('.nm-name', tr).value = nm.name;
      $('.nm-pack', tr).value = nm.pack_size || 1;
      $('.nm-unit', tr).value = nm.unit_label || 'Unit';
      $('.nm-cat', tr).value = nm.category || 'Other';
      $('.nm-hsn', tr).value = nm.hsn || '';
    };

    var addRow = function (d) {
      var tbody = document.createElement('tbody');
      tbody.innerHTML = tpl.replace(/__i/g, String(idx++));
      var tr = tbody.firstElementChild;
      pRows.appendChild(tr);
      var inp = $('.ac-input', tr);
      inp.addEventListener('input', function () {
        $('.ac-id', tr).value = ''; $('.ac-meta', tr).textContent = '';
        $('.new-med', tr).hidden = true; $('.nm-name', tr).value = '';
      });
      autocomplete(inp, $('.ac-list', tr), 'purchase', function (m) {
        setMedicine(tr, m);
        if (m.last_mrp && !field(tr, 'mrp').value) field(tr, 'mrp').value = m.last_mrp;
        if (m.last_rate && !field(tr, 'rate').value) field(tr, 'rate').value = m.last_rate;
        field(tr, 'gst_rate').value = String(+m.gst_rate);
        recalc();
        field(tr, 'batch_no').focus();
      }, function (m) { return '<small>' + m.pack_size + ' ' + esc(m.unit_label) + '/pk</small>' + m.stock + ' in stock'; });
      $$('.calc', tr).forEach(function (el) { el.addEventListener('input', recalc); el.addEventListener('change', recalc); });
      $('.row-del', tr).addEventListener('click', function () { tr.remove(); recalc(); });
      if (d) {
        if (d.medicine) setMedicine(tr, d.medicine); else if (d.new_medicine) setNewMedicine(tr, d.new_medicine);
        field(tr, 'batch_no').value = d.batch_no || '';
        field(tr, 'expiry').value = d.expiry || '';
        field(tr, 'packs').value = d.packs || 0;
        field(tr, 'free_packs').value = d.free_packs || 0;
        field(tr, 'rate').value = d.rate || '';
        field(tr, 'mrp').value = d.mrp || '';
        field(tr, 'disc_pct').value = d.disc_pct || 0;
        field(tr, 'gst_rate').value = String(+d.gst_rate);
        if (d.scanned_name && d.medicine && d.scanned_name.toLowerCase() !== d.medicine.name.toLowerCase()) {
          $('.ac-meta', tr).textContent += ' · on bill: ' + d.scanned_name;
        }
      } else inp.focus();
      return tr;
    };

    var supplierSel = $('#supplierSel');
    var toggleSupplier = function () {
      var isNew = supplierSel.value === 'new';
      $('#newSupplier').hidden = !isNew;
      $('#nsName').required = isNew;
    };
    supplierSel.addEventListener('change', toggleSupplier);
    $('#pRound').addEventListener('input', recalc);

    var loadData = function (data) {
      pRows.innerHTML = '';
      if (data.supplier) {
        var s = data.supplier;
        if (s.id) supplierSel.value = String(s.id);
        else if (s.name) {
          supplierSel.value = 'new';
          $('#nsName').value = s.name || ''; $('#nsGstin').value = s.gstin || ''; $('#nsPhone').value = s.phone || '';
          $('#nsDl').value = s.dl_no || ''; $('#nsAddress').value = s.address || '';
        }
      }
      if (data.supplier_sel != null) supplierSel.value = data.supplier_sel;
      if (data.new_supplier) {
        var n2 = data.new_supplier;
        $('#nsName').value = n2.name || ''; $('#nsGstin').value = n2.gstin || ''; $('#nsPhone').value = n2.phone || '';
        $('#nsDl').value = n2.dl_no || ''; $('#nsAddress').value = n2.address || '';
      }
      toggleSupplier();
      if (data.invoice_no) $('#invoiceNo').value = data.invoice_no;
      if (data.invoice_date) $('#invoiceDate').value = data.invoice_date;
      $('#billFileName').value = data.bill_file || '';
      $('#scannedTotal').value = data.scanned_total != null ? data.scanned_total : '';
      (data.items || []).forEach(function (it) { addRow(it); });
      if (!(data.items || []).length) addRow();
      if (data.round_off != null && data.round_off !== '') {
        $('#pRound').value = data.round_off;
      } else {
        $('#pRound').value = 0;
        var sub = recalc(), st = $('#scannedTotal').value;
        var ro = st !== '' && Math.abs(+st - sub) <= 10 ? round2(+st - sub) : round2(Math.round(sub) - sub);
        $('#pRound').value = ro.toFixed(2);
      }
      recalc();
    };

    $('#addPurchaseRow').addEventListener('click', function () { addRow(); });
    pForm.addEventListener('submit', function (e) {
      $$('tr', pRows).forEach(function (tr) {
        if (!$('.ac-input', tr).value.trim() && !field(tr, 'batch_no').value.trim()) tr.remove();
      });
      var bad = $$('tr', pRows).filter(function (tr) { return !$('.ac-id', tr).value && !$('.nm-name', tr).value; });
      if (bad.length) { e.preventDefault(); alert('Pick each medicine from the search list (or keep the scanned "new medicine").'); $('.ac-input', bad[0]).focus(); return; }
      if (!$$('tr', pRows).length) { e.preventDefault(); alert('Add at least one medicine.'); addRow(); }
    });

    // ---- AI scan ----
    var fileInput = $('#billFile');
    if (fileInput) {
      var status = $('#scanStatus'), drop = $('#scanDrop');
      var showStatus = function (cls, html) { status.hidden = false; status.className = 'scan-status ' + cls; status.innerHTML = html; };
      var scan = function (file) {
        if (!file) return;
        if (file.size > 10 * 1024 * 1024) { showStatus('err', 'File is too large (max 10 MB).'); return; }
        var fd = new FormData();
        fd.append('csrf', $('[name=csrf]', pForm).value);
        fd.append('bill', file);
        drop.classList.add('busy');
        showStatus('busy', '<span class="spinner"></span> Reading the bill with AI… this takes 10–40 seconds.');
        fetch('ocr.php', { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            drop.classList.remove('busy');
            if (!res.ok) { showStatus('err', esc(res.j.error || 'Scan failed.')); return; }
            loadData(res.j);
            var newMeds = res.j.items.filter(function (i) { return !i.medicine; }).length;
            var msg = '✓ Read <b>' + res.j.items.length + '</b> item(s)' +
              (res.j.supplier && res.j.supplier.id ? ' from <b>' + esc(res.j.supplier.matched_name) + '</b>' : res.j.supplier && res.j.supplier.name ? ' · new supplier <b>' + esc(res.j.supplier.name) + '</b> will be added' : '') +
              (newMeds ? ' · <b>' + newMeds + '</b> new medicine(s) will be added' : '') + '. Please check the rows below, then save.';
            showStatus('ok', msg);
            var w = $('#scanWarnings');
            w.hidden = !res.j.warnings.length;
            w.innerHTML = res.j.warnings.length ? '<strong>Please check:</strong><ul>' + res.j.warnings.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>' : '';
            pForm.scrollIntoView({ behavior: 'smooth' });
          })
          .catch(function () { drop.classList.remove('busy'); showStatus('err', 'Network error while scanning. Try again.'); });
      };
      fileInput.addEventListener('change', function () { scan(fileInput.files[0]); fileInput.value = ''; });
      ['dragover', 'dragenter'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
      ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function () { drop.classList.remove('over'); }); });
      drop.addEventListener('drop', function (e) { e.preventDefault(); scan(e.dataTransfer.files[0]); });
    }

    var pre = null;
    try { pre = JSON.parse(pForm.dataset.prefill || 'null'); } catch (err) { pre = null; }
    if (pre) loadData(pre); else { addRow(); toggleSupplier(); recalc(); }
  }

  // ---------------- Billing (POS) ----------------
  var billForm = $('#billForm');
  var globalAddItem = null;

  function openSubstituteModal(m) {
    var modal = $('#substituteModal');
    if (!modal) return;
    $('#subModalTitle').textContent = 'Substitutes for ' + m.name;
    $('#subModalDesc').textContent = 'Salt Composition: ' + (m.generic_name || 'Generic');
    var container = $('#subModalContent');
    container.innerHTML = '<div class="empty">Finding in-stock alternatives with the same salt…</div>';
    modal.showModal();

    fetch('api.php?mode=substitutes&med_id=' + m.id + '&salt=' + encodeURIComponent(m.generic_name || ''), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (alts) {
        if (!alts || !alts.length) {
          container.innerHTML = '<div class="empty">No other in-stock medicines currently available with this salt.</div>';
          return;
        }
        container.innerHTML = '<table class="table compact"><thead><tr><th>Brand Name</th><th>Manufacturer</th><th class="num">In Stock</th><th class="num">MRP / pack</th><th></th></tr></thead><tbody>' +
          alts.map(function (a, idx) {
            var mrp = a.batches && a.batches[0] ? rupee(a.batches[0].mrp) : '';
            return '<tr>' +
              '<td><strong>' + esc(a.name) + '</strong>' + (a.rack ? ' <small class="muted">Rack ' + esc(a.rack) + '</small>' : '') + '</td>' +
              '<td>' + esc(a.manufacturer || '') + '</td>' +
              '<td class="num"><span class="tag tag-ok">' + a.stock + ' ' + esc(a.unit_label) + '</span></td>' +
              '<td class="num">' + mrp + '</td>' +
              '<td class="num"><button type="button" class="btn btn-sm btn-primary add-alt-btn" data-idx="' + idx + '">+ Add to Bill</button></td>' +
              '</tr>';
          }).join('') + '</tbody></table>';

        $$('.add-alt-btn', container).forEach(function (btn) {
          btn.addEventListener('click', function () {
            var chosen = alts[+btn.dataset.idx];
            modal.close();
            if (globalAddItem && chosen) {
              globalAddItem(chosen);
            }
          });
        });
      })
      .catch(function () {
        container.innerHTML = '<div class="empty">Error loading substitutes.</div>';
      });
  }

  if (billForm) {
    var rows = $('#billRows'), n = 0, maxDisc = +billForm.dataset.maxDisc;
    var emptyRow = rows.innerHTML;

    var recalcBill = function () {
      var d = Math.min(maxDisc, Math.max(0, +$('#discPct').value || 0));
      var gross = 0, net = 0, gst = 0;
      $$('tr[data-row]', rows).forEach(function (tr) {
        var sel = $('select', tr), opt = sel.options[sel.selectedIndex];
        var unit = +opt.dataset.mrp / +tr.dataset.pack, avail = +opt.dataset.qty;
        var qInp = $('.qty', tr), qty = Math.max(0, parseInt(qInp.value, 10) || 0);
        qInp.max = avail;
        qInp.setCustomValidity(qty > avail ? 'Only ' + avail + ' in this batch' : '');
        $('.unit', tr).textContent = rupee(unit);
        var g = Math.round(unit * qty * 100) / 100, a = Math.round(g * (1 - d / 100) * 100) / 100;
        $('.amt', tr).textContent = rupee(a);
        gross += g; net += a;
        gst += a - a / (1 + (+tr.dataset.gst) / 100);
      });
      var total = Math.round(net);
      $('#sGross').textContent = rupee(gross);
      $('#sDisc').textContent = '-' + rupee(gross - net);
      $('#sGst').textContent = rupee(gst);
      $('#sRound').textContent = rupee(total - net);
      $('#sTotal').textContent = '₹' + total.toLocaleString('en-IN');
      if (!$('tr[data-row]', rows)) rows.innerHTML = emptyRow;
    };

    var addItem = function (m, batchId, qty) {
      var er = $('.empty-row', rows);
      if (er) er.remove();
      var existing = $('tr[data-med="' + m.id + '"]', rows);
      if (existing && !batchId) {
        var qi = $('.qty', existing);
        qi.value = (+qi.value || 0) + 1;
        recalcBill();
        qi.focus(); qi.select();
        return;
      }
      var i = n++;
      var tr = document.createElement('tr');
      tr.dataset.row = '1';
      tr.dataset.med = m.id;
      tr.dataset.pack = m.pack_size;
      tr.dataset.gst = m.gst_rate;
      tr.innerHTML =
        '<td><strong>' + esc(m.name) + '</strong>' + (m.schedule !== 'OTC' ? '<span class="rx-flag">Rx ' + esc(m.schedule) + '</span>' : '') +
        '<small class="block muted">' + esc(m.generic_name || '') + (m.rack ? ' · Rack ' + esc(m.rack) : '') + '</small></td>' +
        '<td><select name="items[' + i + '][batch_id]">' + m.batches.map(function (b) {
          return '<option value="' + b.id + '" data-mrp="' + b.mrp + '" data-qty="' + b.qty + '"' + (b.id === batchId ? ' selected' : '') + '>' +
            esc(b.batch_no) + ' · ' + esc(b.expiry) + ' · ' + b.qty + ' left</option>';
        }).join('') + '</select></td>' +
        '<td class="num unit"></td>' +
        '<td><input type="number" class="w-xs qty" name="items[' + i + '][qty]" min="1" value="' + (qty || 1) + '" required>' +
        (m.pack_size > 1 ? '<span class="qty-hint">1 pack = ' + m.pack_size + ' ' + esc(m.unit_label) + ' <button type="button" class="icon-btn add-pack" title="Add one full pack">+pk</button></span>' : '<span class="qty-hint">' + esc(m.unit_label) + '</span>') + '</td>' +
        '<td class="num amt"></td>' +
        '<td><button type="button" class="icon-btn row-del" title="Remove">✕</button></td>';
      rows.appendChild(tr);
      $('select', tr).addEventListener('change', recalcBill);
      $('.qty', tr).addEventListener('input', recalcBill);
      var ap = $('.add-pack', tr);
      if (ap) ap.addEventListener('click', function () { var q = $('.qty', tr); q.value = (+q.value || 0) + m.pack_size; recalcBill(); });
      $('.row-del', tr).addEventListener('click', function () { tr.remove(); recalcBill(); $('#medSearch').focus(); });
      recalcBill();
      if (!batchId) { var qn = $('.qty', tr); qn.focus(); qn.select(); }
    };

    globalAddItem = addItem;

    var search = $('#medSearch');
    autocomplete(search, $('.ac-list', search.parentNode), 'sale', function (m) {
      search.value = '';
      addItem(m);
    }, function (m) {
      var price = m.batches.length ? rupee(m.batches[0].mrp) + '/pk' : '';
      return '<small>' + price + '</small>' + (m.stock ? '<span class="tag tag-ok">' + m.stock + ' ' + esc(m.unit_label) + '</span>' : '<span class="tag tag-danger">Out of stock</span>');
    });

    // Customer Phone Autocomplete
    var custPhoneInput = $('#custPhone');
    var custAcList = $('#custAcList');
    var custNotice = $('#custBalanceNotice');
    if (custPhoneInput && custAcList) {
      var cTimer;
      custPhoneInput.addEventListener('input', function () {
        clearTimeout(cTimer);
        var q = custPhoneInput.value.trim();
        if (q.length < 3) { custAcList.classList.remove('open'); return; }
        cTimer = setTimeout(function () {
          fetch('api.php?mode=customer&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (custs) {
              if (!custs || !custs.length) { custAcList.classList.remove('open'); return; }
              custAcList.innerHTML = custs.map(function (c, i) {
                var balTag = c.balance > 0 ? '<span class="tag tag-danger" style="margin-left:6px;">Udhaar ' + rupee(c.balance) + '</span>' : '';
                return '<div class="ac-item cust-pick" data-i="' + i + '">' +
                  '<div><strong>' + esc(c.name) + '</strong> (' + esc(c.phone) + ')' + balTag + '</div>' +
                  '</div>';
              }).join('');
              custAcList.classList.add('open');

              $$('.cust-pick', custAcList).forEach(function (itemEl) {
                itemEl.addEventListener('mousedown', function (e) {
                  e.preventDefault();
                  var chosen = custs[+itemEl.dataset.i];
                  if (chosen) {
                    custPhoneInput.value = chosen.phone;
                    if ($('#custName')) $('#custName').value = chosen.name;
                    if ($('#docName') && chosen.doctor_name) $('#docName').value = chosen.doctor_name;
                    if (custNotice) {
                      if (chosen.balance > 0) {
                        custNotice.style.display = 'block';
                        custNotice.innerHTML = '⚠ Outstanding Udhaar: <b>' + rupee(chosen.balance) + '</b> (Credit Limit: ' + rupee(chosen.credit_limit) + ')';
                      } else {
                        custNotice.style.display = 'none';
                      }
                    }
                  }
                  custAcList.classList.remove('open');
                });
              });
            })
            .catch(function () { custAcList.classList.remove('open'); });
        }, 150);
      });
      custPhoneInput.addEventListener('blur', function () {
        setTimeout(function () { custAcList.classList.remove('open'); }, 200);
      });
    }

    // Quick Shortage Book Button
    var qsBtn = $('#quickShortageBtn');
    if (qsBtn) {
      qsBtn.addEventListener('click', function () {
        var modal = $('#quickShortageModal');
        if (modal) {
          if ($('#qsMedName')) $('#qsMedName').value = (search ? search.value.trim() : '');
          if ($('#qsCustName') && $('#custName')) $('#qsCustName').value = $('#custName').value.trim();
          if ($('#qsCustPhone') && custPhoneInput) $('#qsCustPhone').value = custPhoneInput.value.trim();
          modal.showModal();
        }
      });
    }

    // Qty field: Enter jumps back to search instead of submitting the bill.
    rows.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.target.classList.contains('qty')) { e.preventDefault(); search.focus(); }
    });
    $('#discPct').addEventListener('input', recalcBill);
    billForm.addEventListener('submit', function (e) {
      if (!$('tr[data-row]', rows)) { e.preventDefault(); alert('Add at least one medicine.'); search.focus(); return; }
      $('#saveBill').disabled = true;
      $('#saveBill').textContent = 'Saving…';
    });

    // Keyboard Shortcuts:
    // F2 = focus search
    // F4 = focus customer phone
    // F9 = save
    document.addEventListener('keydown', function (e) {
      if (e.key === 'F2') { e.preventDefault(); if (search) search.focus(); }
      if (e.key === 'F4') { e.preventDefault(); if (custPhoneInput) custPhoneInput.focus(); }
      if (e.key === 'F9') { e.preventDefault(); billForm.requestSubmit(); }
    });

    try {
      JSON.parse(billForm.dataset.prefill || '[]').forEach(function (p) {
        if (p.med.batches.length) addItem(p.med, p.batch_id, p.qty);
      });
    } catch (err) { /* ignore a malformed prefill */ }
    recalcBill();
  }
})();
