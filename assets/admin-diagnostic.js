(function () {
  'use strict';
  const button = document.getElementById('mcar-diagnose-cart');
  const result = document.getElementById('mcar-diagnose-result');
  if (!button || !result || typeof MCARDiagnostic === 'undefined') return;
  button.addEventListener('click', async function () {
    button.disabled = true;
    result.textContent = 'در حال بررسی سبد مرورگر فعلی...';
    try {
      const data = new URLSearchParams({action:'mcar_diagnose_cart',nonce:MCARDiagnostic.nonce,capture:'1'});
      const response = await fetch(MCARDiagnostic.ajax, {
        method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body:data.toString()
      });
      const json = await response.json();
      if (!response.ok || !json.success) throw new Error(json.data && json.data.message || 'خطای درخواست تشخیص');
      const s = json.data;
      const line = (label, value) => label + ': ' + value;
      const yes = v => v ? 'بله' : 'خیر';
      const lines = [
        line('ارسال خودکار فعال است',yes(s.enabled)),
        line('جدول صف موجود است',yes(s.table_exists)),
        line('نشست ووکامرس موجود است',yes(s.session)),
        line('تعداد کالاها در سبد این مرورگر',s.cart_items),
        line('شناسه نشست معتبر است',yes(s.session_key)),
        line('شماره موبایل معتبر پیدا شده',yes(s.phone_found) + (s.phone_masked ? ' (' + s.phone_masked + ')' : '')),
        line('منبع موبایل',s.phone_source || 'یافت نشد'),
        line('سبد در صف ثبت شده',yes(s.queued)),
        line('شناسه صف',s.job_id || '—'),
        line('وضعیت صف',s.job_status || '—'),
        line('مرحله',s.job_stage || '—'),
        line('موعد بررسی بعدی',s.next_send || '—'),
        line('رویداد کرون ثبت شده',yes(s.cron_scheduled))
      ];
      if (!s.queued && s.cart_items && !s.phone_found) {
        lines.push('راهنما: سبد شماره موبایل معتبر ندارد و به همین دلیل در صف ثبت نمی‌شود؛ شماره صورتحساب یا کلید متای سفارشی را بررسی کنید.');
      } else if (!s.queued && !s.cart_items) {
        lines.push('راهنما: سبدی برای این نشست پیدا نشد؛ سبد را در همین مرورگر باز کنید و دوباره بررسی کنید.');
      } else if (!s.queued && !s.enabled) {
        lines.push('راهنما: ارسال خودکار غیرفعال است.');
      } else if (!s.queued) {
        lines.push('راهنما: بخش لاگ‌ها را برای خطای درج در دیتابیس بررسی کنید.');
      }
      result.textContent = lines.join('\n');
    } catch (error) { result.textContent = 'عیب‌یابی انجام نشد: ' + error.message; }
    finally { button.disabled = false; }
  });
}());
