/* Capture a valid checkout mobile number for an existing WooCommerce cart.
 * Works with classic checkout and common Checkout Block input markup.
 * No new field or consent checkbox is injected. */
(function () {
  'use strict';
  if (typeof MCARCheckout === 'undefined') return;
  let timer = 0;
  let last = '';
  function getInput(kind) {
    const selectors = kind === 'phone' ? [
      '[name="billing_phone"]', '#billing_phone', '#billing-phone',
      'input[id*="billing-phone"]', 'input[autocomplete="tel"]',
      'input[type="tel"]'
    ] : [
      '[name="billing_first_name"]', '#billing_first_name', '#billing-first_name',
      'input[id*="billing-first_name"]', 'input[autocomplete="given-name"]'
    ];
    for (const selector of selectors) {
      const element = document.querySelector(selector);
      if (element) return element;
    }
    return null;
  }
  function normalize(phone) {
    const persian = '۰۱۲۳۴۵۶۷۸۹', arabic = '٠١٢٣٤٥٦٧٨٩';
    phone = String(phone || '').replace(/[۰-۹]/g, c => persian.indexOf(c))
                               .replace(/[٠-٩]/g, c => arabic.indexOf(c))
                               .replace(/[^0-9+]/g, '');
    if (phone.startsWith('+98')) phone = '0' + phone.substring(3);
    else if (phone.startsWith('0098')) phone = '0' + phone.substring(4);
    else if (/^989\d{9}$/.test(phone)) phone = '0' + phone.substring(2);
    else if (/^9\d{9}$/.test(phone)) phone = '0' + phone;
    return /^09\d{9}$/.test(phone) ? phone : '';
  }
  function capture() {
    const el = getInput('phone');
    if (!el) return;
    const phone = normalize(el.value);
    if (!phone) return;
    const nameEl = getInput('name');
    const name = nameEl ? nameEl.value.trim() : '';
    const fingerprint = phone + '|' + name;
    if (fingerprint === last) return;
    last = fingerprint;
    const data = new URLSearchParams({
      action: 'mcar_capture', nonce: MCARCheckout.nonce, phone: phone, name: name
    });
    fetch(MCARCheckout.ajax, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: data.toString(), keepalive: false
    }).then(r => { if (!r.ok) last = ''; }).catch(() => { last = ''; });
  }
  function debounce() {
    window.clearTimeout(timer);
    timer = window.setTimeout(capture, 800);
  }
  document.addEventListener('input', function (event) {
    if (event.target && event.target.matches('input')) debounce();
  }, true);
  document.addEventListener('change', debounce, true);
  document.addEventListener('focusout', debounce, true);
  document.addEventListener('DOMContentLoaded', function () {
    window.setTimeout(capture, 1200);
  });
  // Checkout Blocks may mount their fields asynchronously.
  if (typeof MutationObserver !== 'undefined') {
    let checked = false;
    const observer = new MutationObserver(function () {
      if (!checked && getInput('phone')) { checked = true; debounce(); }
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }
}());
