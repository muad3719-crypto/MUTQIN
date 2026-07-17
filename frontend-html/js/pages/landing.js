/* ============================================================
   الصفحة الرئيسية العامة — جلب الإحصائيات من /public/stats
   ============================================================ */
(function () {
    // أرقام عربية هندية
    function ar(n) {
        return String(n ?? 0).replace(/\d/g, d => '٠١٢٣٤٥٦٧٨٩'[d]);
    }

    async function loadStats() {
        try {
            const res = await API.get('/public/stats');
            const s = res.data || {};
            setStat('stat-centers', s.centers);
            setStat('stat-users', s.users);
            setStat('stat-students', s.students);
            // شريط الثقة المصغّر في الـhero (نفس الأرقام)
            setStat('mstat-centers', s.centers);
            setStat('mstat-users', s.users);
            setStat('mstat-students', s.students);
        } catch (e) {
            // تبقى القيم الافتراضية في حال فشل الاتصال
        }
    }

    function setStat(id, val) {
        const el = document.getElementById(id);
        if (el && val != null) el.textContent = ar(val) + '+';
    }

    // قائمة الموبايل (☰): فتح/إغلاق، وتُغلق عند اختيار رابط
    const burger = document.getElementById('lnav-burger');
    const mobileNav = document.getElementById('lnav-mobile');
    if (burger && mobileNav) {
        burger.addEventListener('click', () => {
            const open = mobileNav.classList.toggle('open');
            burger.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        mobileNav.querySelectorAll('a').forEach(a => a.addEventListener('click', () => {
            mobileNav.classList.remove('open');
            burger.setAttribute('aria-expanded', 'false');
        }));
    }

    // لو كان المستخدم مسجّلاً، غيّر زر "تسجيل الدخول" إلى "لوحتي"
    if (window.Auth && Auth.isLoggedIn()) {
        const u = Auth.getUser();
        document.querySelectorAll('[data-login-link]').forEach(a => {
            a.textContent = 'لوحة التحكم';
            a.href = Auth.dashboardFor(u.role);
        });
    }

    // ===== الظهور التدريجي عند التمرير (scroll reveal) =====
    // تحسين تدريجي: الوسم بـ .reveal يتم هنا (لا في HTML) — فبلا JS أو مع
    // prefers-reduced-motion تبقى الصفحة ظاهرة كاملة بلا أي إخفاء.
    (function () {
        if (!('IntersectionObserver' in window)) return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        const targets = document.querySelectorAll(
            '.card-soft, .gallery-grid figure, .sec > div:first-child, #stats [id^="stat-"], .ayah > *'
        );
        const io = new IntersectionObserver((entries) => {
            entries.forEach(en => {
                if (!en.isIntersecting) return;
                en.target.classList.add('in');
                io.unobserve(en.target);
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });

        targets.forEach((el, i) => {
            el.classList.add('reveal');
            el.style.transitionDelay = ((i % 4) * 70) + 'ms'; // تدرّج خفيف داخل الصف الواحد
            io.observe(el);
        });
    })();

    loadStats();
})();
