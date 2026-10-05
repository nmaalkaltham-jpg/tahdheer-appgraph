<?php

namespace App\Domain\Operations;

use App\Domain\Operations\Contracts\OperationReconciler;

/**
 * يربط نوع العمليّة بمن يعرف كيف يسأل عن أثرها.
 *
 * النوع بلا موفّق ليس خطأً برمجيًّا بل حقيقةٌ تُعلَن: عمليّاتُه تبقى
 * مجهولةً ولا تُعاد. وابتكارُ موفّقٍ افتراضيّ يقول «مؤكّد» أو «غائب» بلا
 * سؤالٍ حقيقيّ هو بالضبط الكذب الذي يمنعه هذا النظام.
 */
class ReconcilerRegistry
{
    /** @var array<string, OperationReconciler> */
    private array $byKind = [];

    /** @param iterable<OperationReconciler> $reconcilers */
    public function __construct(iterable $reconcilers = [])
    {
        foreach ($reconcilers as $reconciler) {
            $this->byKind[$reconciler->kind()] = $reconciler;
        }
    }

    public function for(string $kind): ?OperationReconciler
    {
        return $this->byKind[$kind] ?? null;
    }

    /** @return list<string> */
    public function kinds(): array
    {
        return array_keys($this->byKind);
    }
}
