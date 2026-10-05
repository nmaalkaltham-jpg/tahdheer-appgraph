<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Assets\Exceptions\StorageUnavailable;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\ProductFile;
use App\Domain\Catalog\ProductPreviews;
use App\Domain\Downloads\DownloadDelivery;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * خدمة ملفّ العيّنة المجّانيّة.
 *
 * لماذا وُجد هذا المسار: المزوّد المحلّي لا يصدر رابطًا مُعنوَنًا بالمفتاح
 * بالتصميم، ويشترط رمز منحة — والعيّنة العامّة لا منحة لها ولا يصحّ أن تكون.
 * فبدونه تبقى العيّنات غيرَ قابلةٍ للخدمة على كلّ نشرٍ بتخزينٍ محلّيّ: تُرفع
 * ولا تُفتح، وتظهر الصفحة فارغةً بلا سببٍ ظاهر.
 *
 * وحدود ما يُخدَم هنا **ثلاثة شروطٍ تُفحص كلّها**، لا واحدًا:
 *   ١· الأصل ليس مدفوعًا (`isPaidPrivate`) — الحدّ نفسه الذي يحرسه
 *      `DownloadDelivery::publicUrl`، ولا يُستثنى منه شيء.
 *   ٢· مرئيّته `public_sample` صراحةً — لا «ليس مدفوعًا» وكفى.
 *   ٣· وهو مرتبطٌ بمنتجٍ بدور `sample`. وهذا الثالث هو ما يمنع تحويل المسار
 *      إلى خادمِ أصولٍ عامٍّ لكلّ ما مرئيّته `public_sample` في النظام —
 *      وسائط التقييمات مثلًا لها مسارها ولا شأن لها بهذا.
 *
 * والتعداد غير مُقلق بحكم التعريف: ما يُخدَم هنا عيّنةٌ نُشرت ليراها أيّ
 * زائر. ومع ذلك يبقى الجواب ٤٠٤ لكلّ ما لا يستوفي الشروط — لا ٤٠٣ يؤكّد
 * وجود أصلٍ مدفوعٍ بهذا المعرّف.
 */
class SampleFileController extends Controller
{
    public function __invoke(int $assetId, DownloadDelivery $delivery): StreamedResponse
    {
        $asset = Asset::query()
            ->whereKey($assetId)
            ->where('visibility', 'public_sample')
            ->first();

        if ($asset === null || $asset->isPaidPrivate()) {
            abort(404);
        }

        $linked = ProductFile::query()
            ->where('asset_id', $asset->id)
            ->where('role', ProductPreviews::ROLE)
            ->exists();

        if (! $linked) {
            abort(404);
        }

        try {
            return $delivery->stream($asset);
        } catch (StorageUnavailable $e) {
            /*
             * الأصل مسجَّل والملفّ غائب عن التخزين — غيابٌ لا خطأ خادم — لكن
             * حين يكون السبب MISSING_OBJECT وحده. كلّ سببٍ آخر عطلُ تخزين
             * («تعذّر السؤال» ليست «غير موجود») فجوابه ٥٠٣ لا ٤٠٤ يكذب.
             */
            if ($e->reason === StorageUnavailable::MISSING_OBJECT) {
                abort(404);
            }

            abort(503);
        }
    }
}
