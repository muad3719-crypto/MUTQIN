@extends('pdf.layout')

@section('title', 'تقرير أداء المعلمين')
@section('subtitle', 'مرتّب حسب الأداء (الأعلى أولاً)')

@section('content')

    <h3 class="sec">مقارنة أداء المعلمين</h3>
    <table class="data">
        <thead>
            <tr>
                <th>#</th><th>المعلم</th><th>المركز</th>
                <th>عدد الطلاب</th><th>نسبة حضور طلابه</th>
                <th>الاختبارات</th><th>نسبة النجاح</th>
            </tr>
        </thead>
        <tbody>
            @forelse($d['rows'] as $i => $r)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $r['teacher']->name }}</td>
                    <td>{{ $r['teacher']->center->name ?? '—' }}</td>
                    <td>{{ $r['studentsCount'] }}</td>
                    <td class="{{ $r['attendancePercent'] >= 75 ? 'ok' : ($r['attendancePercent'] >= 50 ? 'warn' : 'no') }}">{{ $r['attendancePercent'] }}%</td>
                    <td>{{ $r['testsCount'] }}</td>
                    <td class="{{ $r['passRate'] >= 50 ? 'ok' : 'no' }}">{{ $r['passRate'] }}%</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">لا يوجد معلمون</td></tr>
            @endforelse
        </tbody>
    </table>

@endsection
