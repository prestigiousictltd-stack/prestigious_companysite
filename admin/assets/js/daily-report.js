/* =====================================================================
   Daily Report — client-side UX calculations.
   Cosmetic only; the server recalculates everything on save.
   ===================================================================== */
(function () {
  'use strict';

  var config = window.DR_CONFIG || { currencySymbol: '₦', revenueBasis: 'SERVICES' };
  var currencySymbol = config.currencySymbol || '₦';

  function money(n) {
    if (!isFinite(n)) n = 0;
    return currencySymbol + Number(n).toLocaleString('en-NG', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }
  function num(v) { var n = parseFloat(v); return isFinite(n) ? n : 0; }
  function el(id) { return document.getElementById(id); }
  function all(name) { return Array.prototype.slice.call(document.querySelectorAll('[name="' + name + '"]')); }

  function bindRemove(btn) {
    btn.addEventListener('click', function () {
      var row = btn.closest('tr');
      if (row) { row.remove(); renumberAR(); recalc(); }
    });
  }

  // ---------- Row adders ----------
  function addReceivableRow() {
    var tbody = el('ar-rows');
    if (!tbody) return;
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td class="dr-num"></td>' +
      '<td><input type="text"   name="ar_staff[]"    list="dl-staff"    maxlength="150"></td>' +
      '<td><input type="text"   name="ar_service[]"  list="dl-services" maxlength="190"></td>' +
      '<td><input type="number" name="ar_quantity[]" class="ar-qty" step="0.01" min="0" value="1.00"></td>' +
      '<td><input type="number" name="ar_amount[]"   class="ar-amt" step="0.01" min="0" value="0.00"></td>' +
      '<td><input type="text"   class="ar-row-total" readonly tabindex="-1" value="0.00"></td>' +
      '<td><input type="date"   name="ar_due[]"></td>' +
      '<td class="actions"><button type="button" class="row-remove" title="Remove">&times;</button></td>';
    tbody.appendChild(tr);
    bindRemove(tr.querySelector('.row-remove'));
    renumberAR();
    recalc();
  }

  function addServiceRow() {
    var tbody = el('svc-rows');
    if (!tbody) return;
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><input type="text"   name="svc_name[]"    list="dl-services" maxlength="190"></td>' +
      '<td><input type="text"   name="svc_staff[]"   list="dl-staff"    maxlength="150"></td>' +
      '<td><input type="number" name="svc_units[]"   step="1"    min="0" value="0"></td>' +
      '<td><input type="number" name="svc_revenue[]" step="0.01" min="0" value="0.00"></td>' +
      '<td><input type="date"   name="svc_due[]"></td>' +
      '<td class="actions"><button type="button" class="row-remove" title="Remove">&times;</button></td>';
    tbody.appendChild(tr);
    bindRemove(tr.querySelector('.row-remove'));
    recalc();
  }

  function addExpenseRow() {
    var tbody = el('exp-rows');
    if (!tbody) return;
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><input type="text"   name="exp_category[]" list="dl-expenses" maxlength="150"></td>' +
      '<td><input type="text"   name="exp_desc[]"     maxlength="255"></td>' +
      '<td><input type="number" name="exp_amount[]"   step="0.01" min="0" value="0.00"></td>' +
      '<td class="actions"><button type="button" class="row-remove" title="Remove">&times;</button></td>';
    tbody.appendChild(tr);
    bindRemove(tr.querySelector('.row-remove'));
    recalc();
  }

  // ---------- Live recalculation ----------
  function recalc() {
    // ---- Accounts Receivable ----
    var arGrand = 0;
    document.querySelectorAll('#ar-rows tr').forEach(function (row) {
      var qtyInp = row.querySelector('input.ar-qty');
      var amtInp = row.querySelector('input.ar-amt');
      var outInp = row.querySelector('input.ar-row-total');

      var qty = qtyInp ? num(qtyInp.value) : 0;
      var amt = amtInp ? num(amtInp.value) : 0;
      var rowTotal = qty * amt;

      if (outInp) outInp.value = rowTotal.toFixed(2);
      arGrand += rowTotal;
    });
    var arGrandEl = el('ar-total');
    if (arGrandEl) arGrandEl.textContent = money(arGrand);

    // ---- Services Rendered Out ----
    var svcUnits = 0, svcRevenue = 0;
    all('svc_units[]').forEach(function (i) { svcUnits += num(i.value); });
    all('svc_revenue[]').forEach(function (i) { svcRevenue += num(i.value); });
    if (el('svc-units-total')) el('svc-units-total').textContent = svcUnits.toLocaleString();
    if (el('svc-revenue-total')) el('svc-revenue-total').textContent = money(svcRevenue);

    // ---- Expenses ----
    var expTotal = all('exp_amount[]').reduce(function (s, i) { return s + num(i.value); }, 0);
    if (el('exp-total')) el('exp-total').textContent = money(expTotal);

    // ---- Financial Summary ----
    // WEEKLY_SALES and MANUAL no longer have meaning because the Weekly Revenue
    // section has been removed. Fall back to Services for those values.
    var revenueBasis = config.revenueBasis || 'SERVICES';
    var revenue;
    switch (revenueBasis) {
      case 'SERVICES_PLUS_RECEIVABLE':
        revenue = svcRevenue + arGrand;
        break;
      case 'WEEKLY_SALES':
      case 'MANUAL':
      default:
        revenue = svcRevenue;
    }
    var net = revenue - expTotal;

    if (el('fin-revenue'))  el('fin-revenue').textContent  = money(revenue);
    if (el('fin-expenses')) el('fin-expenses').textContent = money(expTotal);
    if (el('fin-net')) {
      el('fin-net').textContent = money(net);
      var box = el('fin-net-box');
      if (box) box.classList.toggle('fin-summary__item--neg', net < 0);
    }
  }

  function renumberAR() {
    document.querySelectorAll('#ar-rows tr').forEach(function (row, i) {
      var c = row.querySelector('.dr-num');
      if (c) c.textContent = String(i + 1);
    });
  }

  // ---------- Init ----------
  document.addEventListener('DOMContentLoaded', function () {
    var bindAdd = function (btnId, fn) {
      var b = el(btnId);
      if (b) b.addEventListener('click', function (e) { e.preventDefault(); fn(); });
    };
    bindAdd('add-ar',  addReceivableRow);
    bindAdd('add-svc', addServiceRow);
    bindAdd('add-exp', addExpenseRow);

    document.querySelectorAll('#ar-rows .row-remove, #svc-rows .row-remove, #exp-rows .row-remove').forEach(bindRemove);

    document.addEventListener('input', function (e) {
      var n = e.target && e.target.name;
      if (!n) return;
      if (n.indexOf('ar_') === 0 || n.indexOf('svc_') === 0 || n.indexOf('exp_') === 0) {
        recalc();
      }
    });

    recalc();
  });

  window.DailyReport = { recalc: recalc };
})();