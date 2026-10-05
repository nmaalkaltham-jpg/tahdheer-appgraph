<?php

namespace App\Support\OpenApi;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use ReflectionMethod;

/**
 * يقرأ قواعد التحقّق المكتوبة في وحدة التحكّم ويحوّلها إلى مخطّط JSON.
 *
 * ─── لماذا القراءة من الشيفرة لا من التوثيق ───
 *
 * أيّ عقدٍ يُكتب بيد الإنسان بجوار الشيفرة ينحرف عنها — والانحراف صامت:
 * الوثيقة تقول «اختياريّ» والخادم يردّ ٤٢٢. فالمصدر الوحيد الصادق لما
 * يقبله المسار هو مصفوفة `validate()` نفسها.
 *
 * ─── حدودٌ معلنة ───
 *
 * تُقرأ المصفوفات **الحرفيّة** وحدها. القاعدة المبنيّة وقت التشغيل
 * (متغيّر، استدعاء، دمج شرطيّ) تُترك ويُعلَّم المسار
 * `x-request-body-incomplete` — لأنّ إعلان الجهل أصدق من تخمين مخطّط.
 * وهذا ما يمنع الوثيقة من اختراع حقلٍ لا يقبله الخادم.
 */
class ValidationRuleReader
{
    /** @return array{schema: array<string,mixed>, complete: bool}|null */
    public function read(string $class, string $method): ?array
    {
        if (! class_exists(ParserFactory::class)) {
            return null;
        }

        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            return null;
        }

        $file = $reflection->getFileName();

        if ($file === false || ! is_file($file)) {
            return null;
        }

        $ast = (new ParserFactory)->createForHostVersion()->parse((string) file_get_contents($file));

        if ($ast === null) {
            return null;
        }

        $methodNode = null;

        foreach ((new NodeFinder)->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $node) {
            if ($node->name->toString() === $method) {
                $methodNode = $node;
                break;
            }
        }

        if ($methodNode === null) {
            return null;
        }

        $arrays = [];

        foreach ((new NodeFinder)->findInstanceOf($methodNode, Node\Expr\MethodCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier || $call->name->toString() !== 'validate') {
                continue;
            }

            $first = $call->args[0] ?? null;

            if ($first instanceof Node\Arg && $first->value instanceof Node\Expr\Array_) {
                $arrays[] = $first->value;
            }
        }

        if ($arrays === []) {
            return null;
        }

        $properties = [];
        $required = [];
        $complete = true;

        foreach ($arrays as $array) {
            foreach ($array->items as $item) {
                if ($item === null || ! $item->key instanceof Node\Scalar\String_) {
                    $complete = false;

                    continue;
                }

                $field = $item->key->value;

                /*
                 * `items.*.qty` تصف عنصر مصفوفة. تسطيحُها إلى حقلٍ باسمٍ
                 * فيه نجمة يُنتج مخطّطًا كاذبًا، فتُعلَّم ناقصةً بدل ذلك.
                 */
                if (str_contains($field, '*')) {
                    $complete = false;

                    continue;
                }

                $rules = $this->rulesOf($item->value, $complete);

                if ($rules === null) {
                    $complete = false;

                    continue;
                }

                $schema = $this->schemaFor($rules);
                $properties[$field] = $schema;

                /*
                 * `sometimes` تنفي `required` في العقد.
                 *
                 * في Laravel تعني `sometimes|required` «إن أُرسل الحقل فلا
                 * يكن فارغًا» — أي أنّه **اختياريّ** في جسم الطلب. وعدّه
                 * إلزاميًّا يُنتج عقدًا يكذب: العميل المولَّد يوجب حقلًا
                 * يقبل الخادمُ غيابَه، فيفشل `tsc` على تعديلٍ جزئيّ سليم
                 * (تبديل السعر وحده مثلًا) أو يُرسل المطوّر عنوانًا لا يريد
                 * تبديله لمجرّد إرضاء النوع.
                 */
                if (in_array('required', $rules, true) && ! in_array('sometimes', $rules, true)) {
                    $required[] = $field;
                }
            }
        }

        if ($properties === []) {
            return null;
        }

        ksort($properties);
        sort($required);

        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return ['schema' => $schema, 'complete' => $complete];
    }

    /**
     * يستخرج قائمة القواعد من قيمة المفتاح: نصٌّ بفواصل أو مصفوفة.
     *
     * @return list<string>|null
     */
    private function rulesOf(Node\Expr $value, bool &$complete): ?array
    {
        if ($value instanceof Node\Scalar\String_) {
            return explode('|', $value->value);
        }

        if (! $value instanceof Node\Expr\Array_) {
            return null;
        }

        $rules = [];

        foreach ($value->items as $entry) {
            if ($entry === null) {
                continue;
            }

            if ($entry->value instanceof Node\Scalar\String_) {
                $rules[] = $entry->value->value;

                continue;
            }

            // `Rule::in([...])` — القيم المسموحة تُقرأ حرفيًّا حين تكون حرفيّة.
            if ($entry->value instanceof Node\Expr\StaticCall
                && $entry->value->name instanceof Node\Identifier
                && $entry->value->name->toString() === 'in') {
                $arg = $entry->value->args[0] ?? null;

                if ($arg instanceof Node\Arg && $arg->value instanceof Node\Expr\Array_) {
                    $values = [];

                    foreach ($arg->value->items as $option) {
                        if ($option !== null && $option->value instanceof Node\Scalar\String_) {
                            $values[] = $option->value->value;
                        }
                    }

                    if ($values !== []) {
                        $rules[] = 'in:'.implode(',', $values);

                        continue;
                    }
                }
            }

            // قاعدةٌ لا تُقرأ ساكنةً: الحقل يبقى لكنّ الوثيقة تُعلن نقصها.
            $complete = false;
        }

        return $rules === [] ? null : $rules;
    }

    /**
     * @param  list<string>  $rules
     * @return array<string, mixed>
     */
    private function schemaFor(array $rules): array
    {
        $schema = [];

        foreach ($rules as $rule) {
            $name = str_contains($rule, ':') ? substr($rule, 0, strpos($rule, ':')) : $rule;
            $arg = str_contains($rule, ':') ? substr($rule, strpos($rule, ':') + 1) : null;

            match ($name) {
                'integer' => $schema['type'] = 'integer',
                'numeric' => $schema['type'] = 'number',
                'boolean' => $schema['type'] = 'boolean',
                'array' => $schema['type'] = 'array',
                'string' => $schema['type'] ??= 'string',
                'email' => [$schema['type'] = 'string', $schema['format'] = 'email'],
                'url' => [$schema['type'] = 'string', $schema['format'] = 'uri'],
                'date' => [$schema['type'] = 'string', $schema['format'] = 'date-time'],
                'uuid' => [$schema['type'] = 'string', $schema['format'] = 'uuid'],
                'nullable' => $schema['nullable'] = true,
                'in' => $arg === null ? null : $schema['enum'] = explode(',', $arg),
                default => null,
            };
        }

        foreach ($rules as $rule) {
            if (! str_contains($rule, ':')) {
                continue;
            }

            [$name, $arg] = explode(':', $rule, 2);
            $numeric = is_numeric($arg);
            $isString = ($schema['type'] ?? 'string') === 'string';

            if ($name === 'min' && $numeric) {
                $schema[$isString ? 'minLength' : 'minimum'] = (int) $arg;
            }

            if ($name === 'max' && $numeric) {
                $schema[$isString ? 'maxLength' : 'maximum'] = (int) $arg;
            }
        }

        $schema['type'] ??= 'string';
        ksort($schema);

        return $schema;
    }
}
