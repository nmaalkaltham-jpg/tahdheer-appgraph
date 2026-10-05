<?php

namespace App\Domain\Admin;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Shared admin-API plumbing: Arabic validation messages (rule-level, so one
 * map covers every admin request) and camelCase pagination meta — Laravel's
 * paginator emits snake_case keys, which the API convention forbids.
 */
final class AdminValidation
{
    /** @var array<string, string> */
    public const MESSAGES = [
        'required' => 'هذا الحقل مطلوب.',
        'required_without' => 'يلزم إرسال أحد الحقول المطلوبة على الأقل.',
        'string' => 'يجب أن تكون القيمة نصًا.',
        'integer' => 'يجب أن تكون القيمة رقمًا صحيحًا.',
        'numeric' => 'يجب أن تكون القيمة رقمًا.',
        'boolean' => 'يجب أن تكون القيمة نعم أو لا.',
        'array' => 'يجب أن تكون القيمة قائمة.',
        'min' => 'القيمة أقل من الحد الأدنى المسموح.',
        'max' => 'القيمة تتجاوز الحد الأقصى المسموح.',
        'lt' => 'يجب أن تكون القيمة أقل من القيمة المرجعية.',
        'email' => 'صيغة البريد الإلكتروني غير صحيحة.',
        'in' => 'القيمة المُرسلة غير مسموح بها.',
        'enum' => 'القيمة المُرسلة غير مسموح بها.',
        'exists' => 'السجل المشار إليه غير موجود.',
        'unique' => 'القيمة مستخدمة من قبل.',
        'regex' => 'صيغة القيمة غير صحيحة.',
        'date' => 'صيغة التاريخ غير صحيحة.',
    ];

    /** @return array{page: int, perPage: int, lastPage: int, total: int} */
    public static function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'lastPage' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }
}
