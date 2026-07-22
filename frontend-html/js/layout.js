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
            ['supervisors', 'مدراء المراكز', 'teachers', 'admin/managers.html'],
            ['students',  'جميع الطلاب', 'students', 'admin/students.html'],
            ['requests',  'الطلبات', 'requests', 'admin/requests.html'],
            ['reports',   'التقارير', 'report', 'admin/reports.html'],
        ],
        teacher: [
            ['dashboard',   'الرئيسية', 'home', 'teacher/dashboard.html'],
            ['profile',     'ملفّي الشخصي', 'teachers', 'teacher/profile.html'],
            ['students',    'طلابي', 'students', 'teacher/students.html'],
            ['requests',    'طلباتي', 'requests', 'teacher/requests.html'],
            ['attendance',  'الحضور والغياب', 'attendance', 'teacher/attendance.html'],
            ['memorization','تتبّع الحفظ', 'memo', 'teacher/memorization.html'],
            ['tests',       'الاختبارات الأسبوعية', 'tests', 'teacher/weekly-tests.html'],
            ['reports',     'التقارير', 'report', 'teacher/reports.html'],
        ],
        center_manager: [
            ['dashboard',  'الرئيسية', 'home', 'manager/dashboard.html'],
            ['teachers',   'محفّظو مركزي', 'teachers', 'manager/teachers.html'],
            ['students',   'طلاب مركزي', 'students', 'manager/students.html'],
            ['attendance', 'استيراد الحضور', 'attendance', 'manager/attendance.html'],
            ['attendance-review', 'مراجعة الحضور', 'report', 'manager/attendance-review.html'],
            ['reports',    'التقارير', 'report', 'manager/reports.html'],
            ['requests',   'طلبات النقل الداخلية', 'requests', 'manager/requests.html'],
        ],
        parent: [
            ['dashboard', 'أبنائي', 'students', 'parent/dashboard.html'],
        ],
    };

    const ROLE_LABEL = { admin: 'مدير النظام', teacher: 'معلم', parent: 'ولي أمر', center_manager: 'مدير المركز' };

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

        // حالة طيّ الشريط الجانبي محفوظة بين الصفحات (تُطبَّق قبل الرسم — لا وميض)
        const collapsed = localStorage.getItem('mutqin_sidebar_collapsed') === '1';

        shell.innerHTML = `
        <div class="mq-layout ${collapsed ? 'mq-collapsed' : ''}" id="mq-layout">
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
            <div class="mq-overlay" id="mq-overlay"></div>
            <main class="mq-main">
                <header class="mq-topbar">
                    <div style="display:flex;align-items:center;gap:14px;min-width:0;">
                        <button type="button" id="mq-sidebar-toggle" class="mq-sidebar-toggle" title="إظهار/إخفاء القائمة الجانبية">${UI.ic('menu', 20)}</button>
                        <h1 class="mq-page-title" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${UI.escapeHtml(title || '')}</h1>
                    </div>
                    <div class="mq-topbar-actions">
                        <span class="mq-date">${today}</span>
                        <div class="mq-notif" style="position:relative;">
                          <span class="mq-bell" id="mq-bell" title="الإشعارات" style="cursor:pointer;position:relative;display:inline-flex;">
                            ${UI.ic('bell', 19)}
                            <span id="mq-notif-badge" style="display:none;position:absolute;top:-7px;left:-7px;min-width:18px;height:18px;padding:0 4px;border-radius:9px;background:#B23A48;color:#fff;font-size:11px;font-weight:700;line-height:18px;text-align:center;">0</span>
                          </span>
                          <div id="mq-notif-dd" style="display:none;position:absolute;top:calc(100% + 10px);left:0;width:340px;max-height:440px;overflow:auto;background:#fff;border:1px solid rgba(4,83,47,.18);border-radius:14px;box-shadow:0 24px 60px -20px rgba(4,54,31,.5);z-index:1600;">
                            <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 15px;border-bottom:1px solid var(--line);position:sticky;top:0;background:#fff;">
                              <strong style="color:#04532F;font-size:14px;">الإشعارات</strong>
                              <button type="button" id="mq-notif-readall" style="background:none;border:none;color:#9A7A1E;font-weight:700;font-size:12px;cursor:pointer;">تعليم الكل كمقروء</button>
                            </div>
                            <div id="mq-notif-list"><div style="padding:20px;text-align:center;color:#9DB3A6;font-size:13px;">جارٍ التحميل...</div></div>
                          </div>
                        </div>
                    </div>
                </header>
                <div class="mq-content" id="page-content"></div>
            </main>
        </div>`;

        document.getElementById('mq-logout').addEventListener('click', () => {
            if (confirm('تسجيل الخروج من النظام؟')) Auth.logout();
        });

        // زر ☰ واعٍ بحجم الشاشة:
        //   موبايل (<768px): يفتح/يغلق درجاً منزلقاً فوق خلفية معتمة (بلا حفظ حالة)
        //   سطح المكتب/تابلت: طيّ/فتح محفوظ بين الصفحات كما كان
        const mobileMq = window.matchMedia('(max-width: 767.98px)');
        document.getElementById('mq-sidebar-toggle').addEventListener('click', () => {
            const layout = document.getElementById('mq-layout');
            if (mobileMq.matches) {
                layout.classList.toggle('mq-mobile-open');
                return;
            }
            const isCollapsed = layout.classList.toggle('mq-collapsed');
            localStorage.setItem('mutqin_sidebar_collapsed', isCollapsed ? '1' : '0');
        });
        document.getElementById('mq-overlay').addEventListener('click', () => {
            document.getElementById('mq-layout').classList.remove('mq-mobile-open');
        });
        // عند عبور نقطة التوقّف (تدوير الجهاز/تغيير الحجم) نغلق الدرج تفادياً لحالة عالقة
        mobileMq.addEventListener('change', () => {
            document.getElementById('mq-layout').classList.remove('mq-mobile-open');
        });

        wireNotifications();

        return document.getElementById('page-content');
    }

    // ===== الإشعارات داخل التطبيق (الجرس + القائمة + الشارة) =====
    function wireNotifications() {
        const bell = document.getElementById('mq-bell');
        const dd = document.getElementById('mq-notif-dd');
        const badge = document.getElementById('mq-notif-badge');
        const list = document.getElementById('mq-notif-list');
        if (!bell) return;

        const setBadge = (n) => {
            if (n > 0) { badge.textContent = n > 99 ? '99+' : n; badge.style.display = 'block'; }
            else badge.style.display = 'none';
        };

        function renderItems(items) {
            if (!items.length) {
                list.innerHTML = '<div style="padding:26px 20px;text-align:center;color:#9DB3A6;font-size:13px;">لا توجد إشعارات</div>';
                return;
            }
            list.innerHTML = items.map(n => `
                <div class="mq-notif-item" data-id="${UI.escapeHtml(n.id)}" data-link="${UI.escapeHtml(n.link || '')}" data-read="${n.is_read ? 1 : 0}"
                     style="display:flex;gap:10px;padding:12px 15px;border-bottom:1px solid var(--line);cursor:pointer;background:${n.is_read ? '#fff' : 'rgba(212,175,55,.10)'};">
                  <span class="dot" style="flex-shrink:0;width:8px;height:8px;border-radius:50%;margin-top:6px;background:${n.is_read ? 'transparent' : '#B23A48'};"></span>
                  <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;font-size:13px;color:#04361F;">${UI.escapeHtml(n.title)}</div>
                    <div style="font-size:12.5px;color:#3D6B52;margin:2px 0;">${UI.escapeHtml(n.body)}</div>
                    <div style="font-size:11px;color:#9DB3A6;">${UI.escapeHtml(n.created_ago)}</div>
                  </div>
                </div>`).join('');
            list.querySelectorAll('.mq-notif-item').forEach(el => el.addEventListener('click', () => onItemClick(el)));
        }

        async function load(silent) {
            try {
                const res = await API.get('/notifications');
                const d = res.data || {};
                setBadge(d.unread_count || 0);
                // في التحديث الدوري: لا نُعيد رسم القائمة إن كانت مفتوحة حتى لا نُشوّش المستخدم
                if (!silent || dd.style.display === 'none') renderItems(d.items || []);
            } catch (e) {
                if (!silent) list.innerHTML = '<div style="padding:20px;text-align:center;color:#B23A48;font-size:12.5px;">تعذّر تحميل الإشعارات</div>';
            }
        }

        async function onItemClick(el) {
            if (el.dataset.read !== '1') {
                try {
                    const res = await API.post('/notifications/' + el.dataset.id + '/read');
                    el.dataset.read = '1';
                    el.style.background = '#fff';
                    el.querySelector('.dot').style.background = 'transparent';
                    setBadge((res.data && res.data.unread_count) || 0);
                } catch (e) { /* تجاهل — الإشعار ثانوي */ }
            }
            if (el.dataset.link) location.href = window.MutqinConfig.APP_ROOT + el.dataset.link;
        }

        bell.addEventListener('click', (e) => {
            e.stopPropagation();
            dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
        });
        document.addEventListener('click', (e) => {
            if (!e.target.closest('#mq-notif-dd') && !e.target.closest('#mq-bell')) dd.style.display = 'none';
        });
        document.getElementById('mq-notif-readall').addEventListener('click', async (e) => {
            e.stopPropagation();
            try { await API.post('/notifications/read-all'); setBadge(0); load(); } catch (e) { /* تجاهل */ }
        });

        load();
        setInterval(() => load(true), 60000); // تحديث دوري خفيف للشارة (بلا real-time)
    }

    window.Layout = { mount };
})();
