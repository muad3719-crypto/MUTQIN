@extends('pdf.layout')

@section('title', 'تقرير المركز الشامل')
@section('subtitle', isset($d['rows']) ? 'كل المراكز' : ($d['center']->name ?? ''))

@section('content')

@if(isset($d['rows']))
    {{-- كل المراكز --}}
    <h3 class="sec">ملخّص جميع المراكز</h3>
    <table class="data">
        <thead>
            <tr><th>المركز</th><th>المدينة</th><th>المعلمون</th><th>الطلاب</th><th>متوسط الحضور</th><th>الاختبارات</th><th>نسبة النجاح</th></tr>
        </thead>
        <tbody>
            @forelse($d['rows'] as $c)
                <tr>
                    <td>{{ $c['center']->name }}</td>
                    <td>{{ $c['center']->city ?? '—' }}</td>
                    <td>{{ $c['teachersCount'] }}</td>
                    <td>{{ $c['studentsCount'] }}</td>
                    <td class="{{ $c['avgAttendance'] >= 75 ? 'ok' : ($c['avgAttendance'] >= 50 ? 'warn' : 'no') }}">{{ $c['avgAttendance'] }}%</td>
                    <td>{{ $c['testsCount'] }}</td>
                    <td class="{{ $c['passRate'] >= 50 ? 'ok' : 'no' }}">{{ $c['passRate'] }}%</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">لا توجد مراكز</td></tr>
            @endforelse
        </tbody>
    </table>
@else
    {{-- مركز واحد --}}
    <table class="stats">
        <tr>
            <td><div class="v">{{ $d['teachersCount'] }}</div><div class="l">المعلمون</div></td>
            <td><div class="v">{{ $d['studentsCount'] }}</div><div class="l">الطلاب</div></td>
            <td><div class="v">{{ $d['avgAttendance'] }}%</div><div class="l">متوسط الحضور</div></td>
            <td><div class="v">{{ $d['testsCount'] }}</div><div class="l">الاختبارات</div></td>
            <td><div class="v">{{ $d['passRate'] }}%</div><div class="l">نسبة النجاح</div></td>
        </tr>
    </table>

    <h3 class="sec">تفاصيل المركز</h3>
    <table class="data">
        <tbody>
            <tr><td>اسم المركز</td><td>{{ $d['center']->name }}</td><td>المدينة</td><td>{{ $d['center']->city ?? '—' }}</td></tr>
            <tr><td>العنوان</td><td>{{ $d['center']->address ?? '—' }}</td><td>الهاتف</td><td>{{ $d['center']->phone ?? '—' }}</td></tr>
            <tr><td>عدد المعلمين</td><td>{{ $d['teachersCount'] }}</td><td>عدد الطلاب</td><td>{{ $d['studentsCount'] }}</td></tr>
            <tr><td>اختبارات ناجحة</td><td class="ok">{{ $d['passed'] }}</td><td>اختبارات راسبة</td><td class="no">{{ $d['failed'] }}</td></tr>
        </tbody>
    </table>
@endif

@endsection
