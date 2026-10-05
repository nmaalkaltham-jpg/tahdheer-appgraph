<?php

namespace App\Console\Commands;

use App\Http\Middleware\AdminAuthenticate;
use App\Http\Middleware\AdminAuthorize;
use App\Support\OpenApi\ValidationRuleReader;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * يولّد وثيقة OpenAPI من الموجّه نفسه (§٥).
 *
 * ─── لماذا من الموجّه ───
 *
 * `@tahdheer/api-sdk` يرمي عند الاستيراد ويقول «أنا مولَّد من OpenAPI» —
 * ووثيقةُ OpenAPI لم تكن موجودة. أي أنّ العقد المُعلن لم يكن له مصدر،
 * فبقيت الحزمة عاطلةً وكُتبت النداءات بأيدٍ حرّة في كلّ تطبيق.
 *
 * الموجّه يعرف المسار والطريقة والوسائط المُحلَّة بعد ضمّ المجموعات، و
 * `validate()` تعرف جسم الطلب. لا شيء هنا يُكتب بيد إنسان، فلا شيء ينحرف.
 *
 * ─── لا اختراع ───
 *
 * مخطّطات الاستجابة **غير موصوفة** عمدًا: لا مصدر ساكن لها في هذا المشروع،
 * ووصفُها تخمينًا يجعل العميل يعتمد على حقولٍ قد لا تُرسَل. الوثيقة تصف ما
 * تعرفه وتسكت عمّا لا تعرفه — و`x-response-schema` = `undocumented` تجعل
 * السكوت صريحًا لا ضمنيًّا.
 *
 *   node scripts/openapi.mjs          يكتب docs/api/openapi.json
 *   node scripts/openapi.mjs --check  يفشل إن انحرف الملفّ المحفوظ
 */
class OpenApiGenerateCommand extends Command
{
    protected $signature = 'openapi:generate';

    protected $description = 'طباعة وثيقة OpenAPI المولَّدة من مسارات Laravel إلى المخرج القياسيّ';

    public function handle(ValidationRuleReader $reader): int
    {
        /*
         * تُطبع ولا تُكتب: الأمر يعمل داخل الحاوية حيث لا وجود لجذر المستودع،
         * فكتابةُ ملفٍّ هناك تضعه في مكانٍ لا يراه أحد. الكتابة والمقارنة
         * مسؤوليّة `scripts/openapi.mjs` على المضيف.
         */
        $this->output->writeln(json_encode(
            $this->build($reader),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function build(ValidationRuleReader $reader): array
    {
        $paths = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $uri = '/'.$route->uri();

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $paths[$uri][strtolower($method)] = $this->operation($route, $method, $reader);
            }
        }

        ksort($paths);

        foreach ($paths as &$methods) {
            ksort($methods);
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Tahdheer Commerce API',
                'version' => '1.0.0',
                'description' => 'مولَّدة من موجّه Laravel — لا تُحرَّر يدويًّا.',
            ],
            'servers' => [['url' => '/', 'description' => 'same-origin']],
            'components' => [
                'securitySchemes' => [
                    'adminSession' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'tahdheer_admin_session'],
                ],
                'schemas' => [
                    'Error' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'code' => ['type' => 'string'],
                        ],
                        'required' => ['message'],
                    ],
                ],
            ],
            'paths' => $paths,
        ];
    }

    /** @return array<string, mixed> */
    private function operation(Route $route, string $method, ValidationRuleReader $reader): array
    {
        $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));

        $permissions = [];

        foreach ($middleware as $entry) {
            if (str_starts_with($entry, AdminAuthorize::class.':')) {
                foreach (explode(',', substr($entry, strlen(AdminAuthorize::class) + 1)) as $permission) {
                    $permissions[] = $permission;
                }
            }
        }

        $authenticated = in_array(AdminAuthenticate::class, $middleware, true);

        $operation = [
            'operationId' => $this->operationId($route, $method),
            'tags' => [$this->tag($route)],
            'responses' => [
                '200' => [
                    'description' => 'نجاح',
                    'x-response-schema' => 'undocumented',
                ],
            ],
            'x-response-schema' => 'undocumented',
        ];

        $parameters = $this->parameters($route);

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($authenticated) {
            $operation['security'] = [['adminSession' => []]];
            $operation['responses']['401'] = $this->errorResponse('غير مصادَق');
        }

        /*
         * `x-permissions` تُكتب دائمًا — ولو فارغة.
         *
         * `gatherMiddleware()` يجمع سلسلة الوسائط كاملةً بما فيها وسائط
         * المجموعة، فالقائمة الفارغة **قياس**: «بُحث في السلسلة كلّها فلم
         * يوجد تخويل» — أي مسارٌ عامّ. وحذفُ المفتاح عندئذٍ يمحو هذا القياس،
         * فيقرؤه المستهلك «لم يُبحث».
         *
         * وقد افترق القارئان على المفتاح نفسه: `generate-sdk.mjs` يكتب
         * `?? []` فيقرأ الغياب «بلا صلاحيّة»، و`lib/apis.mjs` يكتب
         * `: UNKNOWN` فيقرأه «غير معروف». خمسٌ وخمسون عمليّة عامّة كانت
         * تُعرض في الورشة «الصلاحيّة غير معروفة» وهي مقيسةٌ عامّة. فيُكتب
         * القياس صريحًا ولا يُترك لعرفٍ يفترق قارئاه.
         *
         * و`403` تبقى مشروطة: مسارٌ بلا تخويل لا يردّ «صلاحية غير ممنوحة».
         */
        sort($permissions);
        $operation['x-permissions'] = $permissions;

        if ($permissions !== []) {
            $operation['responses']['403'] = $this->errorResponse('صلاحية غير ممنوحة');
        }

        [$class, $action] = $this->target($route);

        if ($class !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $body = $reader->read($class, $action);

            if ($body !== null) {
                $operation['requestBody'] = [
                    'required' => true,
                    'content' => ['application/json' => ['schema' => $body['schema']]],
                ];

                if (! $body['complete']) {
                    // قاعدةٌ لم تُقرأ ساكنةً — يُعلَن النقص ولا يُخمَّن.
                    $operation['x-request-body-incomplete'] = true;
                }

                $operation['responses']['422'] = $this->errorResponse('فشل التحقّق');
            }
        }

        ksort($operation['responses']);

        return $operation;
    }

    /** @return array<string, mixed> */
    private function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function parameters(Route $route): array
    {
        $parameters = [];
        $wheres = $route->wheres;

        foreach ($route->parameterNames() as $name) {
            $pattern = $wheres[$name] ?? null;
            $numeric = $pattern === '[0-9]+';

            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => ! str_contains($route->uri(), '{'.$name.'?}'),
                'schema' => $numeric
                    ? ['type' => 'integer']
                    : array_filter(['type' => 'string', 'pattern' => $pattern]),
            ];
        }

        return $parameters;
    }

    /** @return array{0: ?string, 1: string} */
    private function target(Route $route): array
    {
        $action = $route->getAction('controller');

        if (! is_string($action) || ! str_contains($action, '@')) {
            return [null, '__invoke'];
        }

        [$class, $method] = explode('@', $action, 2);

        return [class_exists($class) ? $class : null, $method];
    }

    private function operationId(Route $route, string $method): string
    {
        [$class, $action] = $this->target($route);

        if ($class !== null) {
            /*
             * الاسم المختصر وحده يتصادم: `Admin\ProductController` و
             * `Public\ProductController` كلاهما «product». والتصادم في
             * معرّف العمليّة يجعل العميل المولَّد يفقد نصف مساراته صامتًا،
             * فيُضمّ الفضاء الاسميّ بعد `Api\V1\` إلى المعرّف.
             */
            $suffix = str_replace('App\\Http\\Controllers\\Api\\V1\\', '', $class);
            $parts = explode('\\', $suffix);
            $short = array_pop($parts);
            $short = str_ends_with($short, 'Controller') ? substr($short, 0, -10) : $short;

            $prefix = implode('', array_map(ucfirst(...), $parts));

            return lcfirst($prefix.ucfirst($short).ucfirst($action));
        }

        // مسارٌ بلا وحدة تحكّم: المعرّف يُشتقّ من المسار فيبقى ثابتًا ومميّزًا.
        return strtolower($method).str_replace(' ', '', ucwords(preg_replace('/[^a-z0-9]+/i', ' ', $route->uri()) ?? ''));
    }

    private function tag(Route $route): string
    {
        $segments = explode('/', $route->uri());

        // api/v1/admin/orders/… ⇦ «admin»، وapi/v1/products/… ⇦ «products».
        return $segments[2] ?? 'api';
    }
}
