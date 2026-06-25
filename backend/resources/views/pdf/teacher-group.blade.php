@extends('pdf.layout')

@section('title', 'تقرير كل طلابي')
@section('subtitle', 'المعلم: ' . $d['teacher']->name . ($d['teacher']->center ? ' — ' . $d['teacher']->center->name : ''))

@section('content')

    <table class="stats">
        <tr>
            <td><div class="v">{{ $d['studentsCount'] }}</div><div class="l">عدد الطلاب</div></td>
            <td><div class="v">{{ $d['avgAttendance'] }}%</div><div class="l">متوسط الحضور</div></td>
            <td><div class="v">{{ $d['avgPassRate'] }}%</div><div class="l">متوسط النجاح</div></td>
        </tr>
    </table>

    <h3 class="sec">تفصيل الطلاب</h3>
    <table class="data">
        <thead>
            <tr>
                <th>#</th><th>الطالب</th><th>المركز</th>
                <th>حاضر</th><th>غائب</th><th>متأخر</th>
                <th>نسبة الحضور</th><th>الاختبارات</th><th>نسبة النجاح</th>
            </tr>
        </thead>
        <tbody>
            @forelse($d['rows'] as $i => $r)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $r['student']->name }}</td>
                    <td>{{ $r['student']->center->name ?? '—' }}</td>
                    <td class="ok">{{ $r['present'] }}</td>
                    <td class="no">{{ $r['absent'] }}</td>
                    <td class="warn">{{ $r['late'] }}</td>
                    <td class="{{ $r['attendancePercent'] >= 75 ? 'ok' : ($r['attendancePercent'] >= 50 ? 'warn' : 'no') }}">{{ $r['attendancePercent'] }}%</td>
                    <td>{{ $r['tests'] }}</td>
                    <td class="{{ $r['passRate'] >= 50 ? 'ok' : 'no' }}">{{ $r['passRate'] }}%</td>
                </tr>
            @empty
                <tr><td colspan="9" class="muted">لا يوجد طلاب مرتبطون بك</td></tr>
            @endforelse
        </tbody>
    </table>

@endsection
