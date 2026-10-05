<?php

declare(strict_types=1);

namespace App\Domain\Search\Analytics;

use App\Domain\Analytics\EventIngest;
use App\Domain\Analytics\VisitorIdentity;
use Illuminate\Http\Request;

/**
 * هويّةُ القياس للبحث — بصمتان (HMAC) لا معرّفان، وبموافقة القياس وحدها.
 *
 * القواعد نفسها في `EventIngest` و`OrderAttribution` لا قواعد جديدة:
 *   · إشارة GPC أو DNT ⇒ لا هويّة البتّة.
 *   · لا كعكة زائر، أو لا موافقة قياسٍ مسجّلة ⇒ لا هويّة.
 *   · وإلّا: HMAC(app.key) للزائر (سنة) وللجلسة (٣٠ دقيقة متجدّدة).
 *
 * لا عنوان IP ولا بريد ولا اسم يُخزَّن. والبصمات تُمحى بعد مدّة سياسة
 * `order_attributions` (analytics:prune).
 */
final class SearchIdentity
{
    public function __construct(
        private readonly VisitorIdentity $identity,
        private readonly EventIngest $ingest,
    ) {}

    /**
     * @return array{visitor: string, session: ?string}|null
     */
    public function of(Request $request): ?array
    {
        if ($this->ingest->refusesTracking($request)) {
            return null;
        }
        $visitor = $this->identity->read($request, VisitorIdentity::VISITOR_COOKIE);
        if ($visitor === null) {
            return null;
        }
        $visitorHash = $this->ingest->hash($visitor);
        if (! $this->ingest->hasAnalyticsConsent($visitorHash)) {
            return null;
        }
        $session = $this->identity->read($request, VisitorIdentity::SESSION_COOKIE);

        return ['visitor' => $visitorHash, 'session' => $session === null ? null : $this->ingest->hash($session)];
    }

    /** فئةُ الجهاز من وكيل المستخدم — ثلاث قيم، لا بصمة. */
    public function device(Request $request): string
    {
        $ua = mb_strtolower((string) $request->userAgent());

        return match (true) {
            str_contains($ua, 'ipad') || str_contains($ua, 'tablet') => 'tablet',
            str_contains($ua, 'mobi') || str_contains($ua, 'android') => 'mobile',
            default => 'desktop',
        };
    }
}
