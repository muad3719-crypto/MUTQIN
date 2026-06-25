@extends('pdf.layout')

@section('title', 'تقرير الطلاب المتعثّرين')
@section('subtitle', 'الحضور أقل من ' . $d['attThreshold'] . '% أو الرسوب ' . $d['failThreshold'] . ' مرات فأكثر')

@section('content')

    <table class="stats">
        <tr>
            <td><div class="v">{{ $d['rows']->count() }}</div><div class="l">عدد المتعثّرين</div></td>
            <td><div class="v">{{ $d['attThreshold'] }}%</div><div class="l">عتبة الحضور</div></td>
            <td><div class="v">{{ $d['failThreshold'] }}</div><div class="l">عتبة الرسوب</div></td>
        </tr>
    </table>

    <h3 class="sec">قائمة الطلاب المتعثّرين</h3>
    <table class="data">
        <thead>
            <tr><th>#</th><th>الطالب</th><th>المركز</th><th>المعلم</th><th>نسبة الحضور</th><th>مرات الرسوب</th><th>سبب التعثّر</th></tr>
        </thead>
        <tbody>
            @forelse($d['rows'] as $i => $r)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $r['student']->name }}</td>
                    <td>{{ $r['student']->center->name ?? '—' }}</td>
                    <td>{{ $r['student']->teacher->name ?? '—' }}</td>
                    <td class="{{ $r['attendancePercent'] >= 70 ? 'warn' : 'no' }}">{{ $r['attendancePercent'] }}%</td>
                    <td class="{{ $r['fails'] > 0 ? 'no' : 'muted' }}">{{ $r['fails'] }}</td>
                    <td class="no">{{ $r['reason'] }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="ok">لا يوجد طلاب متعثّرون لهذه الفترة — ما شاء الله</td></tr>
            @endforelse
        </tbody>
    </table>

@endsection
