/* ============================================================
   الهيكل المشترك — مُتقِن
   يحقن الشريط الجانبي (بأيقونات SVG) + الشريط العلوي + التذييل حسب الدور.
   الاستخدام في أي صفحة محمية:
     const user = Auth.requireAuth(['admin']);
     Layout.mount({ user, active: 'dashboard', title: 'لوحة التحكم' });
   ويجب أن تحتوي الصفحة على <div id="app-shell"></div>
   ============================================================ */
(function () {
    const C = window.MutqinConfig;

    // قوائم التنقل لكل دور: [مفتاح، نص، أيقونة، الرابط]
    const NAV = {
        admin: [
            ['dashboard', 'الرئيسية', 'home', 'admin/dashboard.html'],
            ['teachers',  'المعلمون', 'teachers', 'admin/teachers.html'],
            ['centers',   'المراكز', 'centers', 'admin/centers.html'],
            ['students',  'جميع الطلاب', 'students', 'admin/students.html'],
            ['reports',   'التقارير', 'report', 'admin/reports.html'],
        ],
        teacher: [
            ['dashboard',   'الرئيسية', 'home', 'teacher/dashboard.html'],
            ['students',    'طلابي', 'students', 'teacher/students.html'],
            ['attendance',  'الحضور والغياب', 'attendance', 'teacher/attendance.html'],
            ['memorization','تتبّع الحفظ', 'memo', 'teacher/memorization.html'],
            ['tests',       'الاختبارات الأسبوعية', 'tests', 'teacher/weekly-tests.html'],
            ['reports',     'التقارير', 'report', 'teacher/reports.html'],
        ],
        parent: [
            ['dashboard', 'أبنائي', 'students', 'parent/dashboard.html'],
        ],
    };

    const ROLE_LABEL = { admin: 'مدير النظام', teacher: 'معلم', parent: 'ولي أمر' };

    // شعار النجمة (دائرة عاجية + مربعان ذهبيان)
    const LOGO = `
      <svg viewBox="0 0 200 200" width="46" height="46" aria-hidden="true">
        <circle cx="100" cy="100" r="56" fill="#FBF7DA"/>
        <rect x="46" y="46" width="108" height="108" fill="none" stroke="#D4AF37" stroke-width="4"/>
        <rect x="46" y="46" width="108" height="108" fill="none" stroke="#D4AF37" stroke-width="4" transform="rotate(45 100 100)"/>
        <text x="100" y="116" text-anchor="middle" font-family="Amiri,serif" font-size="52" font-weight="700" fill="#04532F">متقن</text>
      </svg>`;

    function mount({ user, active, title }) {
        const shell = document.getElementById('app-shell');
        if (!shell) return;

        const nav = (NAV[user.role] || []).map(([key, label, icon, href]) => `
            <a class="mq-nav-link ${key === active ? 'active' : ''}" href="${C.APP_ROOT + href}">
                ${UI.ic(icon, 19)}<span>${label}</span>
            </a>`).join('');

        const today = new Date().toLocaleDateString('ar-LY', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });

        shell.innerHTML = `
        <div class="mq-layout">
            <aside class="mq-sidebar">
                <div class="mq-brand">
                    ${LOGO}
                    <div class="mq-brand-text">
                        <div class="mq-brand-name">مُتقِن</div>
                        <div class="mq-brand-sub">إدارة مراكز التحفيظ</div>
                    </div>
                </div>
                <div class="mq-user">
                    <span class="mq-user-avatar">${UI.initial(user.name)}</span>
                    <div class="mq-user-info">
                        <strong>${UI.escapeHtml(user.name)}</strong>
                        <small>${ROLE_LABEL[user.role] || ''}</small>
                    </div>
                </div>
                <nav class="mq-nav">${nav}</nav>
                <button type="button" id="mq-logout" class="mq-logout">${UI.ic('logout', 19)}<span>تسجيل الخروج</span></button>
            </aside>
            <main class="mq-main">
                <header class="mq-topbar">
                    <h1 class="mq-page-title">${UI.escapeHtml(title || '')}</h1>
                    <div class="mq-topbar-actions">
                        <span class="mq-date">${today}</span>
                        <span class="mq-bell" title="الإشعارات">${UI.ic('bell', 19)}</span>
                    </div>
                </header>
                <div class="mq-content" id="page-content"></div>
            </main>
        </div>`;

        document.getElementById('mq-logout').addEventListener('click', () => {
            if (confirm('تسجيل الخروج من النظام؟')) Auth.logout();
        });

        return document.getElementById('page-content');
    }

    window.Layout = { mount };
})();
