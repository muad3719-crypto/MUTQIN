/* ============================================================
   أدوات الواجهة — مُتقِن
   تنبيهات، تأكيد حذف، إظهار/إخفاء كلمة المرور، بحث في الجداول،
   شارات الحالة، عرض أخطاء الحقول.
   ============================================================ */
(function () {

    // ===== تنبيه عائم (toast) =====
    function toast(message, type = 'success') {
        let host = document.getElementById('mq-toasts');
        if (!host) {
            host = document.createElement('div');
            host.id = 'mq-toasts';
            host.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:2000;display:flex;flex-direction:column;gap:10px;';
            document.body.appendChild(host);
        }
        const colors = {
            success: ['#04532F', 'rgba(4,83,47,.12)'],
            danger:  ['#B23A48', 'rgba(178,58,72,.12)'],
            warning: ['#9A7A1E', 'rgba(212,175,55,.18)'],
            info:    ['#2A6F8E', 'rgba(42,111,142,.12)'],
        };
        const [fg, bg] = colors[type] || colors.success;
        const el = document.createElement('div');
        el.style.cssText = `min-width:260px;max-width:420px;padding:13px 18px;border-radius:12px;font-weight:600;font-size:14px;color:${fg};background:${bg};border:1px solid ${fg}33;box-shadow:0 12px 30px -12px rgba(4,54,31,.4);text-align:center;`;
        el.textContent = message;
        host.appendChild(el);
        setTimeout(() => { el.style.transition = 'opacity .3s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3200);
    }

    // ===== تأكيد (يرجع Promise<boolean>) =====
    function confirmDialog(message) {
        return Promise.resolve(window.confirm(message));
    }

    // ===== إظهار/إخفاء كلمة المرور =====
    // مرّر معرّف حقل الإدخال ومعرّف زر التبديل
    function bindPasswordToggle(inputId, toggleId) {
        const input = document.getElementById(inputId);
        const btn = document.getElementById(toggleId);
        if (!input || !btn) return;
        btn.addEventListener('click', () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.textContent = show ? '🙈' : '👁';
        });
    }

    // ===== بحث/تصفية صفوف جدول =====
    // يبحث في «كل أعمدة الصف» (نصّ الصف كاملاً) مع تطبيع عربي مطابق لخادمنا
    // (ArabicText::normalize): «البيضا»=«البيضاء»، «فاطمه»=«فاطمة»، ٠٤١=041.
    function normalizeSearch(s) {
        return String(s || '')
            .toLowerCase()
            // أرقام عربية-هندية → غربية
            .replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))
            // إزالة التطويل والتشكيل
            .replace(/[ـً-ْٰ]/g, '')
            // توحيد صور الألف والياء والتاء المربوطة والهمزات
            .replace(/[أإآٱ]/g, 'ا')
            .replace(/ى/g, 'ي').replace(/ة/g, 'ه').replace(/ؤ/g, 'و').replace(/ئ/g, 'ي').replace(/ء/g, '')
            .replace(/\s+/g, ' ').trim();
    }
    // searchInputId: حقل البحث، tableId: الجدول
    function bindTableSearch(searchInputId, tableId) {
        const input = document.getElementById(searchInputId);
        const table = document.getElementById(tableId);
        if (!input || !table) return;
        input.addEventListener('input', () => {
            const q = normalizeSearch(input.value);
            table.querySelectorAll('tbody tr').forEach(tr => {
                tr.style.display = normalizeSearch(tr.textContent).includes(q) ? '' : 'none';
            });
        });
    }

    // ===== عرض أخطاء التحقق على الحقول =====
    // errors: { field: [msg,...] } — يبحث عن عنصر [data-error="field"]
    function setFieldErrors(errors) {
        document.querySelectorAll('[data-error]').forEach(el => { el.textContent = ''; });
        if (!errors) return;
        Object.keys(errors).forEach(field => {
            const box = document.querySelector(`[data-error="${field}"]`);
            if (box) box.textContent = Array.isArray(errors[field]) ? errors[field][0] : errors[field];
        });
    }

    // ===== شارات الحالة =====
    function attendanceBadge(status) {
        const map = {
            present: ['حاضر', 'success'],
            absent:  ['غائب', 'danger'],
            late:    ['متأخر', 'warning'],
        };
        const [label, kind] = map[status] || [status, 'secondary'];
        return badge(label, kind);
    }
    function qualityBadge(q) {
        const map = {
            excellent: ['ممتاز', 'success'],
            good:      ['جيد', 'primary'],
            average:   ['مقبول', 'warning'],
            weak:      ['ضعيف', 'danger'],
        };
        const [label, kind] = map[q] || [q || '—', 'secondary'];
        return badge(label, kind);
    }
    function resultBadge(result) {
        return result === 'ناجح' ? badge('ناجح', 'success') : badge('راسب', 'danger');
    }
    function badge(text, kind) {
        return `<span class="mq-badge mq-badge-${kind}">${escapeHtml(text)}</span>`;
    }

    // ===== مساعدات =====
    function escapeHtml(v) {
        return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
    function initial(name) {
        return (name || '؟').trim().charAt(0);
    }
    function fmtDate(d) {
        if (!d) return '—';
        const date = new Date(d);
        if (isNaN(date)) return d;
        return date.toLocaleDateString('ar-LY', { year: 'numeric', month: '2-digit', day: '2-digit' });
    }
    // تاريخ اليوم «المحلي» YYYY-MM-DD — لا toISOString():
    // toISOString يُرجع تاريخ UTC، فبين منتصف الليل والثانية صباحاً بتوقيت ليبيا (+2)
    // كان يُقترح تاريخ الأمس في نماذج الحضور/الحفظ/الاختبارات.
    function todayStr() {
        const d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    // ===== نافذة نموذج عامة (إضافة/تعديل) =====
    // fields: [{name,label,type,required,value,options:[{value,label}],placeholder,help}]
    // onSubmit(values, helpers) — helpers.setErrors(errors), helpers.close()
    function formModal({ title, submitText = 'حفظ', fields = [], onSubmit, onMount }) {
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(4,54,31,.45);z-index:1500;display:flex;align-items:center;justify-content:center;padding:24px;';

        const fieldHtml = fields.map(f => {
            const v = f.value != null ? String(f.value) : '';
            let input;
            if (f.type === 'select') {
                input = `<select class="mq-input" name="${f.name}">${(f.options || []).map(o =>
                    `<option value="${o.value}" ${String(o.value) === v ? 'selected' : ''}>${escapeHtml(o.label)}</option>`).join('')}</select>`;
            } else if (f.type === 'textarea') {
                input = `<textarea class="mq-input" name="${f.name}" rows="3" placeholder="${f.placeholder || ''}">${escapeHtml(v)}</textarea>`;
            } else {
                input = `<input class="mq-input" name="${f.name}" type="${f.type || 'text'}" value="${escapeHtml(v)}" placeholder="${f.placeholder || ''}">`;
            }
            return `<div style="margin-bottom:14px;">
                <label class="mq-label">${f.label} ${f.required ? '<span style="color:#B23A48;">*</span>' : ''}</label>
                ${input}
                ${f.help ? `<div style="font-size:12px;color:#7A8F82;margin-top:4px;">${f.help}</div>` : ''}
                <div class="mq-field-error" data-error="${f.name}"></div>
            </div>`;
        }).join('');

        overlay.innerHTML = `
            <div class="mq-card" style="width:100%;max-width:640px;max-height:90vh;overflow:auto;margin:0;">
              <div class="mq-card-header"><h3 class="mq-card-title">${escapeHtml(title)}</h3>
                <button type="button" data-close style="background:none;border:none;font-size:22px;cursor:pointer;color:#7A8F82;">×</button></div>
              <form class="mq-card-body" id="mq-modal-form">
                ${fieldHtml}
                <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:8px;">
                  <button type="button" class="mq-btn mq-btn-outline" data-close>إلغاء</button>
                  <button type="submit" class="mq-btn mq-btn-primary">${submitText}</button>
                </div>
              </form>
            </div>`;

        document.body.appendChild(overlay);
        const close = () => overlay.remove();
        overlay.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', close));
        overlay.addEventListener('mousedown', e => { if (e.target === overlay) close(); });

        const form = overlay.querySelector('#mq-modal-form');
        const setErrors = (errors) => {
            overlay.querySelectorAll('[data-error]').forEach(el => el.textContent = '');
            if (!errors) return;
            Object.keys(errors).forEach(k => {
                const box = overlay.querySelector(`[data-error="${k}"]`);
                if (box) box.textContent = Array.isArray(errors[k]) ? errors[k][0] : errors[k];
            });
        };

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            setErrors(null);
            const values = {};
            new FormData(form).forEach((val, key) => values[key] = val);
            const btn = form.querySelector('button[type="submit"]');
            btn.disabled = true;
            try {
                await onSubmit(values, { setErrors, close });
            } catch (err) {
                if (err.errors && Object.keys(err.errors).length) setErrors(err.errors);
                toast(err.message || 'فشل الحفظ', 'danger');
            } finally {
                btn.disabled = false;
            }
        });

        if (typeof onMount === 'function') onMount(form, overlay);

        return { close };
    }

    // ===== رفع ملف حضور البصمة (xlsx) + عرض ملخّص النتيجة =====
    function runAttendanceImport(onComplete, uploadPath = '/attendance/import') {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = '.xlsx';
        input.onchange = async () => {
            if (!input.files || !input.files.length) return;
            const fd = new FormData();
            fd.append('attendance_file', input.files[0]);
            toast('جارٍ رفع الملف ومعالجته...', 'info');
            try {
                const res = await window.API.upload(uploadPath, fd);
                importSummaryModal(res);
                if (onComplete) onComplete(res);
            } catch (err) {
                toast(err.message || 'تعذّر رفع الملف', 'danger');
            }
        };
        input.click();
    }

    // نافذة ملخّص الاستيراد
    function importSummaryModal(r) {
        const cell = (val, lbl, color, bg) => `<div style="text-align:center;padding:16px;border-radius:12px;background:${bg};">
            <div style="font-family:'Amiri',serif;font-size:30px;font-weight:700;color:${color};">${val}</div>
            <div style="font-size:12.5px;color:#7A8F82;font-weight:600;margin-top:4px;">${lbl}</div></div>`;

        const ignored = (r.ignored_other_teachers || 0) > 0
            ? `<div style="display:flex;align-items:center;gap:9px;background:rgba(42,111,142,.1);color:#2A6F8E;border-right:4px solid #2A6F8E;border-radius:11px;padding:11px 15px;font-size:13.5px;font-weight:600;margin-bottom:14px;">
                 ℹ️ تم تجاهل ${r.ignored_other_teachers} سجلاً لطلاب من خارج طلابك (تصفية تلقائية).</div>` : '';

        const errs = (r.errors && r.errors.length)
            ? `<div style="margin-top:6px;"><div style="font-weight:700;color:#B23A48;margin-bottom:8px;">⚠️ صفوف بها أخطاء (${r.errors.length}):</div>
               <div style="border:1px solid var(--hair);border-radius:12px;overflow:hidden;max-height:220px;overflow-y:auto;">
                 <table class="mq-table"><thead><tr><th>الصف</th><th>السبب</th></tr></thead><tbody>
                 ${r.errors.map(e => `<tr><td style="font-weight:700;color:#B23A48;">${e.row ?? '—'}</td><td style="font-size:13px;">${escapeHtml(e.reason || '—')}</td></tr>`).join('')}
                 </tbody></table></div></div>`
            : `<div style="text-align:center;color:#04532F;font-weight:600;margin-top:6px;">✓ لا توجد أخطاء في الملف</div>`;

        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:1600;background:rgba(4,54,31,.5);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;padding:24px;';
        overlay.innerHTML = `<div class="mq-card" style="width:100%;max-width:600px;max-height:90vh;overflow:auto;margin:0;">
            <div class="mq-card-header"><h3 class="mq-card-title">نتيجة استيراد الحضور</h3>
              <button class="mq-act" data-close style="background:rgba(4,54,31,.06);color:#3D6B52;font-size:20px;">×</button></div>
            <div class="mq-card-body">
              <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;">
                ${cell(r.present || 0, 'حاضر', '#04532F', 'rgba(4,83,47,.08)')}
                ${cell(r.late || 0, 'متأخر', '#9A7A1E', 'rgba(212,175,55,.15)')}
                ${cell(r.absent || 0, 'غائب (محتسب)', '#B23A48', 'rgba(178,58,72,.1)')}
              </div>
              ${ignored}
              ${errs}
              <div style="display:flex;justify-content:flex-end;margin-top:18px;">
                <button class="mq-btn mq-btn-primary" data-close>تم</button></div>
            </div></div>`;
        document.body.appendChild(overlay);
        const close = () => overlay.remove();
        overlay.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', close));
        overlay.addEventListener('mousedown', e => { if (e.target === overlay) close(); });
    }

    // ===== إكمال تلقائي للأثمان على حقل نصّي (يربطه بـ /api/athman/search) =====
    // input: حقل بداية الثمن، opts.onSelect(item) يُستدعى عند اختيار ثمن.
    // يبقى الإدخال الحر مسموحاً (لا يفرض الاختيار).
    function attachAthmanSearch(input, opts = {}) {
        const wrap = input.parentElement;
        wrap.style.position = 'relative';

        const dd = document.createElement('div');
        dd.style.cssText = 'display:none;position:absolute;top:100%;left:0;right:0;z-index:2000;background:#fff;border:1px solid rgba(4,83,47,.2);border-radius:12px;margin-top:5px;max-height:260px;overflow-y:auto;box-shadow:0 18px 44px -16px rgba(4,54,31,.55);';
        wrap.appendChild(dd);

        let timer = null, lastReq = 0;
        const hide = () => { dd.style.display = 'none'; };

        input.addEventListener('input', () => {
            clearTimeout(timer);
            const q = input.value.trim();
            if (q.length < 2) { hide(); return; }
            timer = setTimeout(async () => {
                const reqId = ++lastReq;
                try {
                    const res = await window.API.get('/athman/search?q=' + encodeURIComponent(q));
                    if (reqId !== lastReq) return; // تجاهل نتائج قديمة
                    const items = res.data || [];
                    if (!items.length) { dd.innerHTML = `<div style="padding:11px 14px;color:#9DB3A6;font-size:13px;">لا توجد أثمان مطابقة — يمكنك الكتابة يدوياً</div>`; dd.style.display = 'block'; return; }
                    dd.innerHTML = items.map((x, i) => `
                        <div class="mq-ac-item" data-i="${i}" style="padding:10px 14px;cursor:pointer;border-bottom:1px solid rgba(4,54,31,.06);">
                          <div style="font-family:'Amiri',serif;font-size:15.5px;color:#04361F;font-weight:700;line-height:1.5;">${escapeHtml(x.start_text)}</div>
                          <div style="font-size:11.5px;color:#7A8F82;margin-top:3px;">${escapeHtml(x.surah_name)} · حزب ${x.hizb} · ثمن ${x.thumn_in_hizb} · صفحة ${x.page}</div>
                        </div>`).join('');
                    dd.querySelectorAll('.mq-ac-item').forEach(el => {
                        el.addEventListener('mousedown', (e) => {
                            e.preventDefault();
                            const it = items[+el.dataset.i];
                            input.value = it.start_text;       // تعبئة النص القانوني
                            hide();
                            if (opts.onSelect) opts.onSelect(it);
                        });
                        el.addEventListener('mouseenter', () => el.style.background = 'rgba(4,83,47,.05)');
                        el.addEventListener('mouseleave', () => el.style.background = '');
                    });
                    dd.style.display = 'block';
                } catch (e) { hide(); }
            }, 300);
        });
        input.addEventListener('blur', () => setTimeout(hide, 150));
        document.addEventListener('mousedown', (e) => { if (!wrap.contains(e.target)) hide(); });
    }

    // ===== فتح/تنزيل ملف PDF محمي (يجلبه بترويسة Bearer ثم يفتحه في تبويب) =====
    async function openPdf(path, opts = {}) {
        const C = window.MutqinConfig;
        const token = localStorage.getItem(C.STORAGE_TOKEN);
        try {
            toast('جارٍ تجهيز التقرير...', 'info');
            const res = await fetch(C.API_BASE_URL + path, {
                headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/pdf' }
            });
            if (res.status === 401) { localStorage.removeItem(C.STORAGE_TOKEN); location.href = C.APP_ROOT + 'login.html'; return; }
            if (!res.ok) {
                // قد يرجع JSON خطأ
                let msg = 'تعذّر إنشاء التقرير (' + res.status + ')';
                try { const j = await res.json(); msg = j.message || msg; } catch (_) {}
                toast(msg, 'danger');
                return;
            }
            const blob = await res.blob();
            const url = URL.createObjectURL(blob);
            if (opts.download) {
                const a = document.createElement('a');
                a.href = url; a.download = opts.download; document.body.appendChild(a); a.click(); a.remove();
            } else {
                window.open(url, '_blank');
            }
            setTimeout(() => URL.revokeObjectURL(url), 60000);
        } catch (e) {
            toast('تعذّر الاتصال بالخادم لتجهيز التقرير', 'danger');
        }
    }

    // تأكيد حذف ثم تنفيذ
    async function confirmDelete(message, fn) {
        if (!window.confirm(message || 'تأكيد الحذف؟ لا يمكن التراجع.')) return;
        try { await fn(); } catch (err) { toast(err.message || 'تعذّر الحذف', 'danger'); }
    }

    // ===== أيقونات SVG خطّية (هوية مُتقِن) =====
    const ICON_PATHS = {
        home: '<path d="M3 11l9-8 9 8"/><path d="M5 10v9a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-9"/>',
        students: '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/><path d="M16 6.5a3 3 0 0 1 0 5.8"/><path d="M18 19a5 5 0 0 0-3-4.5"/>',
        teachers: '<circle cx="12" cy="7" r="3.4"/><path d="M5.5 20a6.5 6.5 0 0 1 13 0"/>',
        centers: '<path d="M3 21h18"/><path d="M5 21V8l7-4 7 4v13"/><path d="M9.5 21v-5h5v5"/>',
        attendance: '<rect x="4" y="5" width="16" height="16" rx="2.5"/><path d="M8 3v4M16 3v4M4 10h16"/><path d="M9 15l2 2 4-4"/>',
        memo: '<path d="M4 5.5A2 2 0 0 1 6 4h6v15H6a2 2 0 0 0-2 1.5z"/><path d="M20 5.5A2 2 0 0 0 18 4h-6v15h6a2 2 0 0 1 2 1.5z"/>',
        tests: '<path d="M12 3l8 4v5c0 4.5-3.2 7.7-8 9-4.8-1.3-8-4.5-8-9V7z"/><path d="M9 12l2 2 4-4"/>',
        logout: '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 16l-4-4 4-4M6 12h11"/>',
        bell: '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 8-3 8h18s-3-1-3-8"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
        edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        trash: '<path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>',
        plus: '<path d="M12 5v14M5 12h14"/>',
        search: '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/>',
        upload: '<path d="M12 16V4M8 8l4-4 4 4"/><path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
        report: '<path d="M4 19V5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v14"/><path d="M8 16v-4M12 16V8M16 16v-6"/>',
        requests: '<path d="M4 13h4l2 3h4l2-3h4"/><path d="M4 13l2-8h12l2 8v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/>',
    };
    function ic(name, size = 18, stroke = 'currentColor', sw = 1.8) {
        return `<svg viewBox="0 0 24 24" width="${size}" height="${size}" fill="none" stroke="${stroke}" stroke-width="${sw}" stroke-linecap="round" stroke-linejoin="round">${ICON_PATHS[name] || ''}</svg>`;
    }
    // النجمة الثُّمانية (مربعان متقاطعان) — أيقونة موحّدة للبطاقات الإحصائية
    function star(size = 22, color = 'currentColor', sw = 2.4) {
        return `<svg viewBox="0 0 48 48" width="${size}" height="${size}"><rect x="13" y="13" width="22" height="22" fill="none" stroke="${color}" stroke-width="${sw}"/><rect x="13" y="13" width="22" height="22" fill="none" stroke="${color}" stroke-width="${sw}" transform="rotate(45 24 24)"/></svg>`;
    }
    // زر إجراء أيقوني صغير (view/edit/del)
    function actionBtn(kind, attrs = '') {
        const map = { view: ['eye', '4,83,47', '#04532F'], edit: ['edit', '212,175,55', '#9A7A1E'], del: ['trash', '178,58,72', '#B23A48'] };
        const [icon, rgb, fg] = map[kind] || map.view;
        return `<button class="mq-act" ${attrs} style="background:rgba(${rgb},.1);color:${fg};">${ic(icon, 15)}</button>`;
    }

    window.UI = {
        toast, confirmDialog, confirmDelete, formModal, runAttendanceImport, attachAthmanSearch, openPdf,
        bindPasswordToggle, bindTableSearch, setFieldErrors,
        attendanceBadge, qualityBadge, resultBadge, badge, escapeHtml, initial, fmtDate, todayStr,
        ic, star, actionBtn, ICON_PATHS,
    };
})();
