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
        } catch (e) {
            // تبقى القيم الافتراضية في حال فشل الاتصال
        }
    }

    function setStat(id, val) {
        const el = document.getElementById(id);
        if (el && val != null) el.textContent = ar(val) + '+';
    }

    // لو كان المستخدم مسجّلاً، غيّر زر "تسجيل الدخول" إلى "لوحتي"
    if (window.Auth && Auth.isLoggedIn()) {
        const u = Auth.getUser();
        document.querySelectorAll('[data-login-link]').forEach(a => {
            a.textContent = 'لوحة التحكم';
            a.href = Auth.dashboardFor(u.role);
        });
    }

    loadStats();
})();
