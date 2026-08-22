const NL = String.fromCharCode(10);
const res = $input.first().json;
const date = res.data.date;
const students = res.data.students || [];
const map = res.data.attendances || {};

const present = [], late = [], absent = [], missing = [];
for (const s of students) {
  const status = map[s.id];
  if (status === 'present') present.push(s);
  else if (status === 'late') late.push(s);
  else if (status === 'absent') absent.push(s);
  else missing.push(s);
}

const total = students.length;
const rate = total ? Math.round(((present.length + late.length) / total) * 100) : 0;
const line = (s) => '- ' + (s.display_code || '—') + ' — ' + s.name;

const lines = [];
lines.push('ملخّص الحضور ليوم ' + date);
lines.push('');
lines.push('إجمالي الطلاب النشطين: ' + total);
lines.push('حاضر: ' + present.length);
lines.push('متأخر: ' + late.length);
lines.push('غائب: ' + absent.length);
lines.push('لم يُسجَّل: ' + missing.length);
lines.push('نسبة الحضور: ' + rate + '%');
lines.push('');

if (absent.length) {
  lines.push('الغائبون:');
  absent.forEach((s) => lines.push(line(s)));
} else {
  lines.push('لا يوجد غياب اليوم.');
}

if (missing.length) {
  lines.push('');
  lines.push('لم تُسجَّل حضورهم:');
  missing.forEach((s) => lines.push(line(s)));
}

return [{
  json: {
    date,
    total,
    present: present.length,
    late: late.length,
    absent: absent.length,
    missing: missing.length,
    rate,
    needsAttention: absent.length + missing.length > 0,
    subject: 'تقرير الحضور — ' + date,
    body: lines.join(NL),
  },
}];
