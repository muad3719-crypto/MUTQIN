@extends('pdf.layout')

@section('title', 'تقرير الطالب')
@section('subtitle', $d['student']->name . ' — ' . ($d['student']->center->name ?? '—'))

@section('content')

    {{-- بطاقات ملخّص --}}
    <table class="stats">
        <tr>
            <td><div class="v">{{ $d['attendancePercent'] }}%</div><div class="l">نسبة الحضور</div></td>
            <td><div class="v">{{ $d['present'] }}</div><div class="l">حاضر</div></td>
            <td><div class="v">{{ $d['absent'] }}</div><div class="l">غائب</div></td>
            <td><div class="v">{{ $d['late'] }}</div><div class="l">متأخر</div></td>
            <td><div class="v">{{ $d['passRate'] }}%</div><div class="l">نسبة النجاح</div></td>
        </tr>
    </table>

    {{-- بيانات الطالب --}}
    <h3 class="sec">بيانات الطالب</h3>
    <table class="data">
        <tbody>
            <tr><td>الاسم</td><td>{{ $d['student']->name }}</td><td>العمر</td><td>{{ $d['student']->age ?? '—' }}</td></tr>
            <tr><td>المركز</td><td>{{ $d['student']->center->name ?? '—' }}</td><td>المعلم</td><td>{{ $d['student']->teacher->name ?? '—' }}</td></tr>
            <tr><td>ولي الأمر</td><td>{{ $d['student']->guardian_name ?? '—' }}</td><td>الهاتف</td><td>{{ $d['student']->guardian_phone ?? '—' }}</td></tr>
        </tbody>
    </table>

    {{-- جدول الحضور --}}
    <h3 class="sec">سجل الحضور ({{ $d['total'] }} يوم)</h3>
    <table class="data">
        <thead><tr><th>التاريخ</th><th>الحالة</th></tr></thead>
        <tbody>
            @forelse($d['attendances'] as $a)
                @php $map = ['present' => ['حاضر','ok'], 'absent' => ['غائب','no'], 'late' => ['متأخر','warn']]; $st = $map[$a->status] ?? [$a->status,'muted']; @endphp
                <tr><td>{{ \Carbon\Carbon::parse($a->date)->format('Y/m/d') }}</td><td class="{{ $st[1] }}">{{ $st[0] }}</td></tr>
            @empty
                <tr><td colspan="2" class="muted">لا توجد سجلات حضور لهذه الفترة</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- الاختبارات الأسبوعية والأثمان --}}
    <h3 class="sec">الاختبارات الأسبوعية والأثمان المختبَرة</h3>
    @forelse($d['tests'] as $t)
        <table class="data" style="margin-bottom:10px;">
            <thead>
                <tr>
                    <th style="width:18%;">تاريخ الاختبار</th>
                    <th>الأثمان المختبَرة</th>
                    <th style="width:16%;">النتيجة الكلية</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $t->exam_date ? \Carbon\Carbon::parse($t->exam_date)->format('Y/m/d') : '—' }}</td>
                    <td>
                        @forelse($t->questions as $q)
                            <div style="margin:2px 0;">
                                <span class="{{ $q->result === 'ناجح' ? 'ok' : 'no' }}">●</span>
                                {{ $q->eighth_start }}
                                <span class="{{ $q->result === 'ناجح' ? 'ok' : 'no' }}">({{ $q->result }})</span>
                                @if($q->mistake)<span class="muted"> — {{ $q->mistake }}</span>@endif
                            </div>
                        @empty
                            <span class="muted">لم تُسجَّل أثمان</span>
                        @endforelse
                    </td>
                    <td class="{{ $t->result === 'ناجح' ? 'ok' : 'no' }}">{{ $t->result }}</td>
                </tr>
            </tbody>
        </table>
    @empty
        <table class="data"><tbody><tr><td class="muted">لا توجد اختبارات لهذه الفترة</td></tr></tbody></table>
    @endforelse

@endsection
