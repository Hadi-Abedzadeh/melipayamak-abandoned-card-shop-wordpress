/* MeliPayamak pattern preview (admin only). No SMS is sent. */
(function () {
  'use strict';
  var config = window.MCARPatternPreview || {};
  var sampleValues = config.samples || {};
  function sampleFor(key, stage) {
    var values = sampleValues[String(stage)] || sampleValues[stage] || {};
    if (key === 'discount_amount') {
      var amount = document.querySelector('input[name="settings[coupon_amount]"]');
      var type = document.querySelector('select[name="settings[coupon_type]"]');
      if (amount && type && amount.value && !isNaN(Number(amount.value))) {
        var number = Number(amount.value);
        if (type.value === 'percent') return String(number) + '٪';
        return number.toLocaleString('fa-IR') + ' ' + (config.currency_symbol || '');
      }
    }
    return Object.prototype.hasOwnProperty.call(values, key) ? String(values[key]) : '';
  }
  function refresh(panel) {
    var stage = panel.id === 'mcar-stage-second' ? 2 : 1;
    var template = panel.querySelector('.mcar-preview-template');
    var view = panel.querySelector('.mcar-preview-render');
    var selections = Array.prototype.slice.call(panel.querySelectorAll('.mcar-pattern-panel select[name$="_vars][]"]'));
    var keys = selections.map(function (element) { return element.value; }).filter(Boolean);
    var vals = keys.map(function (key) { return sampleFor(key, stage); });
    if (!view || !template) return;
    if (!template.value.trim()) {
      view.textContent = 'متن الگو هنوز دریافت نشده است. مقادیر نمونه‌ای که به API ارسال می‌شوند:\n' +
        (vals.length ? vals.join(';') : 'متغیری انتخاب نشده است.');
      return;
    }
    var misses = [];
    var result = template.value.replace(/\{(\d+)\}/g, function (match, position) {
      var idx = Number(position);
      if (idx >= vals.length || vals[idx] === '') {
        misses.push(match);
        return match;
      }
      return vals[idx];
    });
    view.textContent = result + (misses.length ? '\n\nتوجه: برای متغیرهای ' + misses.join('، ') + ' مقداری در نگاشت مرحله انتخاب نشده است.' : '');
  }
  function requestPattern(panel) {
    var idInput = panel.querySelector('input[name$="_id]"]');
    var template = panel.querySelector('.mcar-preview-template');
    var status = panel.querySelector('.mcar-preview-status');
    var btn = panel.querySelector('.mcar-preview-fetch');
    var id = idInput ? idInput.value.trim() : '';
    if (!/^\d+$/.test(id) || Number(id) < 1) {
      status.textContent = 'ابتدا شناسه عددی الگو را وارد کنید.';
      return;
    }
    btn.disabled = true;
    status.textContent = 'در حال دریافت متن الگو...';
    var data = new URLSearchParams();
    data.append('action', 'mcar_pattern_preview');
    data.append('nonce', config.nonce || '');
    data.append('body_id', id);
    fetch(config.ajax, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:data.toString()})
      .then(function (res) { return res.json(); })
      .then(function (res) {
        if (!res || !res.success || !res.data) throw new Error(res && res.data && res.data.message ? res.data.message : 'دریافت متن الگو ناموفق بود.');
        template.value = res.data.body || '';
        var approved = Number(res.data.body_status) === 1;
        status.textContent = 'متن الگو دریافت شد (' + (res.data.title || 'بدون عنوان') + '). ' +
          (approved ? 'وضعیت: تأییدشده.' : 'توجه: وضعیت الگو تأییدشده نیست؛ پیش از استفاده بررسی کنید.') + ' برای حفظ متن، تنظیمات را ذخیره کنید.';
        refresh(panel);
      })
      .catch(function (err) { status.textContent = (err && err.message ? err.message : 'خطا در ارتباط') + '؛ می‌توانید متن را دستی در کادر پیش‌نمایش وارد کنید.'; })
      .finally(function () { btn.disabled = false; });
  }
  function checkBalance() {
    var button = document.getElementById('mcar-check-balance');
    var result = document.getElementById('mcar-check-balance-result');
    if (!button || !result) return;
    button.disabled = true;
    result.textContent = 'در حال استعلام اعتبار...';
    var data = new URLSearchParams();
    data.append('action', 'mcar_credit_check');
    data.append('nonce', config.credit_nonce || '');
    fetch(config.ajax, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:data.toString()})
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (!r || !r.success || !r.data) throw new Error(r && r.data && r.data.message ? r.data.message : 'خطا در دریافت موجودی');
        result.textContent = 'موجودی: ' + Number(r.data.toman).toLocaleString('fa-IR') + ' تومان — ' +
          (r.data.allowed ? 'مجاز برای شروع پردازش صف' : 'کمتر یا مساوی حد مجاز؛ صف متوقف می‌ماند');
      })
      .catch(function (e) { result.textContent = 'استعلام ناموفق: ' + (e && e.message ? e.message : 'خطای ناشناخته'); })
      .finally(function () { button.disabled = false; });
  }
  function onReady() {
    var checkButton = document.getElementById('mcar-check-balance');
    if (checkButton) checkButton.addEventListener('click', checkBalance);
    Array.prototype.forEach.call(document.querySelectorAll('.mcar-stage-config'), function (panel) {
      var btn = panel.querySelector('.mcar-preview-fetch');
      if (btn) btn.addEventListener('click', function () { requestPattern(panel); });
      panel.addEventListener('input', function () { refresh(panel); });
      panel.addEventListener('change', function () { refresh(panel); });
      refresh(panel);
    });
    ['input[name="settings[coupon_amount]"]','select[name="settings[coupon_type]"]'].forEach(function (sel) {
      var field = document.querySelector(sel);
      if (field) ['input','change'].forEach(function (eventName) {
        field.addEventListener(eventName, function () {
          Array.prototype.forEach.call(document.querySelectorAll('.mcar-stage-config'), refresh);
        });
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', onReady);
  else onReady();
})();
