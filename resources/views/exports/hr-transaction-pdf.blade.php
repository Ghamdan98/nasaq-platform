<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm 14mm 18mm; }
        body { font-family: dejavusans; direction: rtl; color: #172033; font-size: 10pt; line-height: 1.55; }
        h1 { color: #173e75; font-size: 20pt; margin: 0 0 4mm; }
        h2 { color: #173e75; font-size: 13pt; border-bottom: 1px solid #bfcee0; padding-bottom: 2mm; margin: 7mm 0 3mm; }
        h3 { font-size: 11pt; margin: 4mm 0 2mm; }
        .muted { color: #64748b; font-size: 8pt; }
        .header { border-bottom: 2px solid #1d4ed8; padding-bottom: 4mm; margin-bottom: 5mm; }
        .brand { font-size: 9pt; color: #64748b; }
        .status { display: inline-block; padding: 2mm 4mm; border: 1px solid #cbd5e1; background: #f1f5f9; font-weight: bold; }
        .unapproved { border: 2px solid #c2410c; color: #c2410c; background: #fff7ed; text-align: center; font-size: 14pt; font-weight: bold; padding: 3mm; margin: 4mm 0; }
        .approved { color: #166534; border-color: #86efac; background: #f0fdf4; }
        table { width: 100%; border-collapse: collapse; margin: 2mm 0 4mm; }
        th, td { border: 1px solid #cbd5e1; padding: 2mm; vertical-align: top; }
        th { background: #eaf1fb; color: #173e75; font-weight: bold; }
        .info td:first-child { width: 30%; font-weight: bold; background: #f8fafc; }
        .money { direction: ltr; text-align: left; white-space: nowrap; }
        .total { page-break-inside: avoid; background: #eff6ff; padding: 4mm; margin-top: 4mm; }
        .net { color: #173e75; font-size: 15pt; font-weight: bold; }
        .warning { border-right: 3px solid #d97706; background: #fffbeb; padding: 2mm 3mm; margin: 2mm 0; }
        .legal { page-break-inside: avoid; }
        .signatures { page-break-inside: avoid; margin-top: 9mm; }
        .signatures td { height: 26mm; width: 25%; }
        .footer { position: fixed; bottom: -10mm; left: 0; right: 0; color: #64748b; border-top: 1px solid #cbd5e1; padding-top: 2mm; font-size: 8pt; }
        .keep { page-break-inside: avoid; }
    </style>
    @include('exports.branding-style')
</head>
<body>
@php
    $transaction = $data['transaction'];
    $isApproved = $transaction->status === 'approved';
    $statusLabels = [
        'draft' => 'مسودة',
        'calculated' => 'محسوبة',
        'pending_review' => 'بانتظار المراجعة',
        'approved' => 'معتمدة',
        'rejected' => 'مرفوضة',
        'cancelled' => 'ملغاة',
        'changes_requested' => 'مطلوب تعديل',
    ];
    $reviewLabels = [
        'valid' => 'سليمة مبدئياً',
        'warning' => 'تحتاج مراجعة',
        'requires_legal_review' => 'تحتاج مراجعة نظامية',
        'blocked' => 'غير قابلة للاعتماد',
    ];
    $name = $transaction->transaction_type === 'leave_settlement' ? 'تصفية إجازة' : 'مخالصة نهاية خدمة';
    $currency = fn ($amount) => app(\App\Services\SystemSettingsService::class)->formatMoney($amount);
@endphp

<div class="header">
    <table style="border:0;margin:0">
        <tr>
            <td style="border:0;padding:0">
                <div class="brand">{{ $data['branding']['name'] ?? '' }} — {{ $data['branding']['organization'] ?? '' }}</div>
                @if($data['branding']['logo_path'] ?? null)<img src="{{ $data['branding']['logo_path'] }}" style="height:16mm" alt="logo">@endif
                <h1>{{ $name }}</h1>
            </td>
            <td style="border:0;padding:0;text-align:left">
                <strong>{{ $transaction->reference_number }}</strong><br>
                <span class="muted">الإصدار {{ $transaction->version_number }} | {{ app(\App\Services\SystemSettingsService::class)->formatDate($transaction->transaction_date) }}</span>
            </td>
        </tr>
    </table>
    <span class="status {{ $isApproved ? 'approved' : '' }}">{{ $isApproved ? 'معتمدة' : ($statusLabels[$transaction->status] ?? 'غير معتمدة') }}</span>
    <span class="status">{{ $reviewLabels[$transaction->review_status] ?? $transaction->review_status }}</span>
</div>

@unless($isApproved)
    <div class="unapproved">غير معتمدة — {{ $statusLabels[$transaction->status] ?? $transaction->status }}</div>
@endunless

<h2>بيانات الموظف والعقد</h2>
<table class="info">
    <tr><td>الاسم</td><td>{{ $data['employee']['full_name'] ?? '—' }}</td><td>رقم الموظف</td><td>{{ $data['employee']['employee_number'] ?? '—' }}</td></tr>
    <tr><td>المسمى الوظيفي</td><td>{{ $data['employee']['job_title'] ?? '—' }}</td><td>الإدارة</td><td>{{ $data['employee']['department'] ?? '—' }}</td></tr>
    <tr><td>نوع العقد</td><td>{{ $data['contract']['contract_type'] ?? '—' }}</td><td>تاريخ المباشرة</td><td>{{ $data['contract']['start_date'] ?? $data['employee']['hire_date'] ?? '—' }}</td></tr>
    <tr><td>تاريخ نهاية العقد</td><td>{{ $data['contract']['end_date'] ?? '—' }}</td><td>الراتب الأساسي المسجل</td><td>{{ isset($data['contract']['basic_salary']) ? $currency($data['contract']['basic_salary']) : '—' }}</td></tr>
</table>

@if($transaction->transaction_type === 'final_settlement')
    <h2>بيانات الخدمة</h2>
    <table class="info">
        <tr><td>سبب انتهاء العلاقة</td><td>{{ $data['final_details']?->termination_type ?? ($data['calculation']['termination']['type'] ?? '—') }}</td><td>آخر يوم عمل</td><td>{{ $data['final_details']?->last_working_date?->format('Y-m-d') ?? ($data['input']['last_working_date'] ?? '—') }}</td></tr>
        <tr><td>مدة الخدمة</td><td>{{ isset($data['calculation']['service_period']) ? ($data['calculation']['service_period']['years'] ?? 0).' سنة، '.($data['calculation']['service_period']['months'] ?? 0).' شهر، '.($data['calculation']['service_period']['days'] ?? 0).' يوم' : '—' }}</td><td>الأجر المستخدم</td><td>{{ isset($data['salary']['gross_monthly_wage']) ? $currency($data['salary']['gross_monthly_wage']) : '—' }}</td></tr>
    </table>
@else
    <h2>بيانات تصفية الإجازة</h2>
    <table class="info">
        <tr><td>نوع معاملة الإجازة</td><td>{{ $data['leave_details']?->leave_transaction_type ?? ($data['calculation']['leave_transaction_type'] ?? '—') }}</td><td>الفترة</td><td>{{ $data['leave_details']?->leave_period_from?->format('Y-m-d') ?? '—' }} — {{ $data['leave_details']?->leave_period_to?->format('Y-m-d') ?? '—' }}</td></tr>
        <tr><td>الرصيد الافتتاحي</td><td>{{ $data['leave_details']?->opening_balance ?? '—' }}</td><td>المستحق</td><td>{{ $data['leave_details']?->accrued_days ?? '—' }}</td></tr>
        <tr><td>المستخدم</td><td>{{ $data['leave_details']?->used_days ?? '—' }}</td><td>التعديلات</td><td>{{ $data['leave_details']?->adjustment_days ?? '—' }}</td></tr>
        <tr><td>الرصيد المتاح</td><td>{{ $data['leave_details']?->available_days ?? ($data['calculation']['available_days'] ?? '—') }}</td><td>الأيام المصروفة / المستحقة</td><td>{{ $data['leave_details']?->days_to_compensate ?? ($data['calculation']['days_to_compensate'] ?? '—') }}</td></tr>
        <tr><td>الرصيد المتبقي</td><td>{{ $data['leave_details']?->remaining_balance ?? '—' }}</td><td>مصدر الرصيد</td><td>{{ $data['leave_details']?->balance_source ?? '—' }}</td></tr>
        <tr><td>الأجر المستخدم</td><td>{{ $data['leave_details']?->calculation_wage ? $currency($data['leave_details']->calculation_wage) : '—' }}</td><td>قيمة اليوم</td><td>{{ $data['leave_details']?->daily_rate ? $currency($data['leave_details']->daily_rate) : '—' }}</td></tr>
    </table>
@endif

<h2>المستحقات</h2>
<table>
    <thead><tr><th>البند</th><th>الكمية</th><th>القيمة المحسوبة</th><th>القيمة المعتمدة</th><th>الفرق</th></tr></thead>
    <tbody>
    @forelse($data['items'] as $item)
        <tr>
            <td>{{ $item->name_ar ?? $item->name ?? $item->code }}@if(is_array($item->calculation_details_json) && !empty($item->calculation_details_json['reason']))<div>{{ $item->calculation_details_json['reason'] }}</div>@endif</td>
            <td>{{ $item->quantity ?? '—' }}</td>
            <td class="money">{{ $currency($item->calculated_amount) }}</td>
            <td class="money">{{ $currency($item->approved_amount) }}</td>
            <td class="money">{{ $currency($item->difference_amount) }}</td>
        </tr>
    @empty
        <tr><td colspan="5">لا توجد مستحقات.</td></tr>
    @endforelse
    </tbody>
</table>

<h2>الخصومات</h2>
<table>
    <thead><tr><th>البند</th><th>الكمية</th><th>القيمة المحسوبة</th><th>القيمة المعتمدة</th><th>الفرق</th></tr></thead>
    <tbody>
    @forelse($data['deductions'] as $item)
        <tr><td>{{ $item->name_ar ?? $item->name ?? $item->code }}@if(is_array($item->calculation_details_json) && !empty($item->calculation_details_json['reason']))<div>{{ $item->calculation_details_json['reason'] }}</div>@endif</td><td>{{ $item->quantity ?? '—' }}</td><td class="money">{{ $currency($item->calculated_amount) }}</td><td class="money">{{ $currency($item->approved_amount) }}</td><td class="money">{{ $currency($item->difference_amount) }}</td></tr>
    @empty
        <tr><td colspan="5">لا توجد خصومات.</td></tr>
    @endforelse
    </tbody>
</table>

<div class="total keep">
    <table style="margin:0;border:0">
        <tr><td style="border:0">إجمالي المستحقات</td><td class="money" style="border:0">{{ $currency($transaction->gross_amount) }}</td></tr>
        <tr><td style="border:0">إجمالي الخصومات</td><td class="money" style="border:0">{{ $currency($transaction->total_deductions) }}</td></tr>
        <tr><td class="net" style="border:0">صافي المستحق</td><td class="net money" style="border:0">{{ $currency($transaction->net_amount) }}</td></tr>
    </table>
</div>

@if($data['has_adjustments'])
    <h2>التعديلات اليدوية</h2>
    <p class="warning">تحتوي هذه المعاملة على قيم تم تعديلها يدوياً.</p>
    <table>
        <thead><tr><th>البند</th><th>القيمة المحسوبة</th><th>القيمة المعتمدة</th><th>الفرق</th><th>سبب التعديل</th></tr></thead>
        <tbody>
        @foreach($data['items']->concat($data['deductions'])->where('was_manually_adjusted', true) as $item)
            <tr><td>{{ $item->name_ar ?? $item->name ?? $item->code }}</td><td class="money">{{ $currency($item->calculated_amount) }}</td><td class="money">{{ $currency($item->approved_amount) }}</td><td class="money">{{ $currency($item->difference_amount) }}</td><td>{{ $item->adjustment_reason }}</td></tr>
        @endforeach
        </tbody>
    </table>
@endif

<h2>التقييم النظامي</h2>
<p><strong>{{ $reviewLabels[$transaction->review_status] ?? $transaction->review_status }}</strong></p>
@foreach($data['warnings'] as $warning)
    <div class="warning">{{ is_array($warning) ? ($warning['message_ar'] ?? $warning['message'] ?? $warning['description'] ?? json_encode($warning, JSON_UNESCAPED_UNICODE)) : $warning }}</div>
@endforeach

@if(count($data['legal_references']))
    <div class="legal">
        <h2>السند النظامي</h2>
        <table>
            <thead><tr><th>المادة</th><th>العنوان / الرمز</th><th>شرح مختصر</th><th>تاريخ السريان</th><th>المصدر</th></tr></thead>
            <tbody>
            @foreach($data['legal_references'] as $reference)
                @if(is_array($reference))
                    <tr><td>{{ $reference['article_number'] ?? $reference['article'] ?? '—' }}</td><td>{{ $reference['title'] ?? $reference['rule_code'] ?? '—' }}</td><td>{{ $reference['summary'] ?? $reference['description'] ?? '—' }}</td><td>{{ $reference['effective_from'] ?? $reference['effective_date'] ?? '—' }}</td><td>{{ $reference['source_name'] ?? $reference['source'] ?? '—' }}</td></tr>
                @endif
            @endforeach
            </tbody>
        </table>
    </div>
@endif

@if($isApproved)
    <h2>بيانات الاعتماد</h2>
    <table class="info">
        <tr><td>أنشأها</td><td>{{ $data['created_by_name'] ?? '—' }}</td><td>راجعها</td><td>{{ $data['reviewed_by_name'] ?? '—' }}</td></tr>
        <tr><td>اعتمدها</td><td>{{ $data['approved_by_name'] ?? '—' }}</td><td>تاريخ الاعتماد</td><td>{{ $transaction->approved_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
    </table>
@endif

<h2>التسوية المالية والسلف</h2>
<p>المرحلة المالية: {{ $transaction->financial_stage }} | قرار السلف: {{ $transaction->advance_decision_json['choice'] ?? '—' }}</p>
<p>سبب القرار: {{ $transaction->advance_decision_json['reason'] ?? '—' }} | معالجة المديونية المتبقية: {{ $transaction->advance_decision_json['remaining_debt_reason'] ?? '—' }}</p>
<table><thead><tr><th>السلفة</th><th>المبلغ</th><th>حالة التخصيص</th></tr></thead><tbody>
@foreach($data['advance_allocations'] ?? [] as $allocation)<tr><td>{{ $allocation['advance']['reference_number'] ?? $allocation['advance_id'] }}</td><td>{{ $allocation['allocated_amount'] }}</td><td>{{ $allocation['status'] }}</td></tr>@endforeach
</tbody></table>
<p>الترحيل: {{ $transaction->posted_at?->format('Y-m-d H:i:s') ?? 'لم يُرحّل' }} | توثيق الدفع: {{ $transaction->paid_at?->format('Y-m-d H:i:s') ?? 'لم يوثق دفعه' }}</p>
@foreach($data['settlement_payments'] ?? [] as $payment)<p>مستند {{ $payment['reversal_of_id'] ? 'عكس الصرف' : 'الصرف' }}: {{ $payment['reference_number'] }} — {{ $payment['payment_date'] }} — {{ $payment['amount'] }}</p>@endforeach

<table class="signatures">
    <tr><th>إعداد الموارد البشرية</th><th>المراجعة</th><th>الاعتماد</th><th>الموظف</th></tr>
    <tr>
        @foreach(range(1, 4) as $column)<td>الاسم:<br><br>التوقيع:<br><br>التاريخ:</td>@endforeach
    </tr>
</table>

<div class="footer">{{ $transaction->reference_number }} | الإصدار {{ $transaction->version_number }} | {{ $data['branding']['name'] ?? '' }} | {{ $data['branding']['settings']['reports.footer'] ?? '' }}</div>
</body>
</html>
