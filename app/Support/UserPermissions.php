<?php

namespace App\Support;

class UserPermissions
{
    public static function groups(): array
    {
        return [
            'الموظفون' => ['manage_employees' => 'إدارة الموظفين والاستيراد', 'view_employee_sensitive' => 'عرض الرواتب والبيانات الحساسة'],
            'التصفيات' => ['view_transactions' => 'عرض التصفيات', 'create_transactions' => 'إنشاء التصفيات', 'edit_transactions' => 'تعديل المسودات', 'adjust_transaction_values' => 'تعديل القيم المحسوبة', 'submit_transactions' => 'إرسال للمراجعة', 'review_transactions' => 'مراجعة التصفيات', 'approve_transactions' => 'اعتماد التصفيات', 'approve_legal_review_transaction' => 'اعتماد التصفيات التي تتطلب مراجعة قانونية', 'reject_transactions' => 'رفض التصفيات', 'request_transaction_changes' => 'طلب التعديل', 'cancel_transactions' => 'إلغاء التصفيات قبل الترحيل', 'reopen_approved_transaction' => 'إعادة فتح المعتمد', 'export_transactions' => 'تصدير وطباعة التصفيات'],
            'السلف' => ['view_advances' => 'عرض السلف وتقاريرها', 'manage_advances' => 'إنشاء طلبات السلف وإدارة مرفقاتها', 'approve_advances' => 'اعتماد السلف', 'post_advance_movements' => 'تسجيل صرف وسداد السلف', 'reverse_advance_movements' => 'عكس حركات السلف'],
            'التسوية المالية' => ['review_financial_settlements' => 'المراجعة المالية', 'review_advance_legal' => 'مراجعة خصم السلف قانونيًا', 'post_settlements' => 'ترحيل التصفيات', 'pay_settlements' => 'تسجيل صرف التصفيات', 'reverse_settlements' => 'إلغاء مالي وعكس الصرف'],
        ];
    }

    public static function names(): array
    {
        return array_keys(array_merge(...array_values(self::groups())));
    }

    public static function defaults(string $role): array
    {
        return match ($role) {
            'admin' => self::names(),
            'hr' => ['manage_employees', 'view_employee_sensitive', 'view_advances', 'manage_advances', 'view_transactions', 'create_transactions', 'edit_transactions', 'submit_transactions', 'export_transactions'],
            'reviewer' => ['view_advances', 'review_financial_settlements', 'view_transactions', 'review_transactions', 'approve_transactions', 'reject_transactions', 'request_transaction_changes', 'export_transactions'],
            default => [],
        };
    }
}
