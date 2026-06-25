@extends('pdf.layout')

@section('title', 'تقرير الإحصائيات العامة')
@section('subtitle', 'ملخّص النظام لمنصة مُتقِن')

@section('content')

    <h3 class="sec">إجماليات النظام</h3>
    <table class="stats">
        <tr>
            <td><div class="v">{{ $d['centers'] }}</div><div class="l">المراكز</div></td>
            <td><div class="v">{{ $d['teachers'] }}</div><div class="l">المعلمون</div></td>
            <td><div class="v">{{ $d['students'] }}</div><div class="l">الطلاب النشطون</div></td>
            <td><div class="v">{{ $d['parents'] }}</div><div class="l">أولياء الأمور</div></td>
        </tr>
    </table>

    <h3 class="sec">نشاط الفترة المحدّدة</h3>
    <table class="stats">
        <tr>
            <td><div class="v">{{ $d['testsCount'] }}</div><div class="l">الاختبارات</div></td>
            <td><div class="v ok">{{ $d['passed'] }}</div><div class="l">ناجحة</div></td>
            <td><div class="v no">{{ $d['failed'] }}</div><div class="l">راسبة</div></td>
            <td><div class="v">{{ $d['passRate'] }}%</div><div class="l">نسبة النجاح</div></td>
            <td><div class="v">{{ $d['memorizations'] }}</div><div class="l">حصص الحفظ</div></td>
        </tr>
    </table>

    <h3 class="sec">ملخّص</h3>
    <table class="data">
        <tbody>
            <tr><td>إجمالي المراكز</td><td>{{ $d['centers'] }}</td><td>إجمالي المعلمين</td><td>{{ $d['teachers'] }}</td></tr>
            <tr><td>الطلاب النشطون</td><td>{{ $d['students'] }}</td><td>أولياء الأمور</td><td>{{ $d['parents'] }}</td></tr>
            <tr><td>اختبارات الفترة</td><td>{{ $d['testsCount'] }}</td><td>نسبة النجاح العامة</td><td class="{{ $d['passRate'] >= 50 ? 'ok' : 'no' }}">{{ $d['passRate'] }}%</td></tr>
            <tr><td>حصص الحفظ المسجّلة</td><td colspan="3">{{ $d['memorizations'] }}</td></tr>
        </tbody>
    </table>

@endsection
