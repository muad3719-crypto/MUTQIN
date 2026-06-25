<!DOCTYPE html>
<html lang="ar">
<head>
<meta charset="utf-8">
<style>
    body { font-family: dejavusans; color: #0A3D27; font-size: 12px; }
    .brand-head { width: 100%; background: #04532F; color: #FBF7DA; border-radius: 6px; }
    .brand-head td { padding: 14px 18px; vertical-align: middle; }
    .brand-title { font-size: 18px; font-weight: bold; color: #FBF7DA; }
    .brand-sub { font-size: 11px; color: #D4AF37; padding-top: 4px; }
    .brand-logo { font-size: 26px; font-weight: bold; color: #D4AF37; text-align: left; }
    .period { font-size: 11px; color: #3D6B52; margin: 12px 0 16px; }
    .period b { color: #04532F; }

    table.data { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 6px; }
    table.data thead th { background: #04532F; color: #D4AF37; padding: 8px 7px; text-align: right; }
    table.data tbody td { border-bottom: 1px solid #E0E0D0; padding: 6px 7px; text-align: right; }
    table.data tbody tr:nth-child(even) td { background: #FBF9EE; }

    .ok { color: #04532F; font-weight: bold; }
    .no { color: #B23A48; font-weight: bold; }
    .warn { color: #9A7A1E; font-weight: bold; }
    .muted { color: #7A8F82; }
    .amiri { font-family: dejavusans; }

    h3.sec { color: #04532F; font-size: 14px; margin: 18px 0 8px; border-right: 4px solid #D4AF37; padding-right: 8px; }

    /* بطاقات إحصائية عبر جدول */
    table.stats { width: 100%; border-collapse: separate; border-spacing: 8px; }
    table.stats td { background: #FBF9EE; border: 1px solid #E8E1CC; border-radius: 6px; padding: 12px 8px; text-align: center; }
    table.stats .v { font-size: 22px; font-weight: bold; color: #04532F; }
    table.stats .l { font-size: 10px; color: #7A8F82; padding-top: 4px; }

    .foot { margin-top: 22px; border-top: 1px solid #E0E0D0; padding-top: 7px; font-size: 9px; color: #9DB3A6; text-align: center; }
</style>
</head>
<body dir="rtl">

    <table class="brand-head">
        <tr>
            <td>
                <div class="brand-title">@yield('title')</div>
                <div class="brand-sub">@yield('subtitle')</div>
            </td>
            <td class="brand-logo">مُتقِن</td>
        </tr>
    </table>

    <div class="period">
        <b>الفترة:</b> {{ $periodLabel }}
    </div>

    @yield('content')

    <div class="foot">
        تقرير صادر من منصة مُتقِن لإدارة مراكز التحفيظ — تاريخ الإنشاء: {{ $generatedAt }}
    </div>

</body>
</html>
