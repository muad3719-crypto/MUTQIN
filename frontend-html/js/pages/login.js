/* ============================================================
   صفحة تسجيل الدخول — مُتقِن
   ============================================================ */
(function () {
    // إن كان مسجّلاً مسبقاً، حوّله للوحته
    Auth.redirectIfLoggedIn();

    const form    = document.getElementById('login-form');
    const submit  = document.getElementById('login-submit');
    const alertEl = document.getElementById('login-alert');

    // إظهار/إخفاء كلمة المرور
    UI.bindPasswordToggle('password', 'toggle-pass');

    // تعبئة الحقول عند الضغط على حساب تجريبي
    function fill(email) {
        document.getElementById('email').value = email;
        document.getElementById('password').value = 'password';
    }

    // جلب الحسابات التجريبية وعرضها مجمّعة حسب الدور
    (async function loadDemoAccounts() {
        const host = document.getElementById('demo-accounts');
        if (!host) return;
        const esc = (UI && UI.escapeHtml) ? UI.escapeHtml : (s => String(s ?? ''));
        try {
            const res = await API.get('/public/demo-accounts');
            const all = res.data || [];
            // في الإنتاج تعود القائمة فارغة → أخفِ قسم «البيانات التجريبية» بالكامل
            if (!all.length) {
                const box = host.closest('details') || host;
                box.style.display = 'none';
                return;
            }
            const groups = [
                ['admin', '👑 المدير', '#04532F'],
                ['teacher', '👨‍🏫 المعلمون', '#04532F'],
                ['parent', '👨‍👧‍👦 أولياء الأمور', '#9A7A1E'],
            ];
            host.innerHTML = groups.map(([role, label, color]) => {
                const items = all.filter(u => u.role === role);
                if (!items.length) return '';
                return `<div style="margin-bottom:10px;">
                    <div style="font-size:11.5px;font-weight:700;color:${color};margin:6px 2px;">${label} (${items.length})</div>
                    ${items.map(u => `
                        <button type="button" class="demo-fill" data-email="${esc(u.email)}"
                            style="display:block;width:100%;text-align:right;cursor:pointer;font-family:'Cairo';background:#FBF9EE;border:1px solid rgba(4,83,47,.15);border-radius:9px;padding:8px 11px;font-size:11.5px;color:#04361F;margin-bottom:5px;">
                            ${esc(u.name)} — <span style="direction:ltr;display:inline-block;color:#7A8F82;">${esc(u.email)}</span>
                        </button>`).join('')}
                </div>`;
            }).join('') || '<div style="color:#9DB3A6;font-size:12px;text-align:center;">لا توجد حسابات</div>';

            host.querySelectorAll('.demo-fill').forEach(btn => btn.addEventListener('click', () => fill(btn.dataset.email)));
        } catch (e) {
            host.innerHTML = '<div style="color:#B23A48;font-size:12px;text-align:center;">تعذّر تحميل الحسابات التجريبية</div>';
        }
    })();

    function showAlert(msg) {
        alertEl.textContent = '✕ ' + msg;
        alertEl.style.display = 'flex';
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        alertEl.style.display = 'none';
        UI.setFieldErrors(null);

        const email = document.getElementById('email').value.trim();
        const password = document.getElementById('password').value;

        submit.disabled = true;
        const original = submit.textContent;
        submit.textContent = 'جارٍ الدخول...';

        try {
            const user = await Auth.login(email, password);
            UI.toast('تم تسجيل الدخول بنجاح', 'success');
            Auth.redirectByRole(user.role);
        } catch (err) {
            // أخطاء حقول التحقق (422) تُعرض تحت الحقول، وإلا تنبيه عام
            if (err.errors && Object.keys(err.errors).length) {
                UI.setFieldErrors(err.errors);
            }
            showAlert(err.message || 'تعذّر تسجيل الدخول');
        } finally {
            submit.disabled = false;
            submit.textContent = original;
        }
    });
})();
