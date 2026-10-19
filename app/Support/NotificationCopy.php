<?php

namespace App\Support;

final class NotificationCopy
{
    /**
     * @param  array<string, scalar|null>  $replace
     * @return array{event: string, title_ar: string, title_en: string, body_ar: string, body_en: string}
     */
    public static function make(string $event, array $replace = []): array
    {
        $template = self::templates()[$event];

        return [
            'event' => $event,
            'title_ar' => self::fill($template['title_ar'], $replace),
            'title_en' => self::fill($template['title_en'], $replace),
            'body_ar' => self::fill($template['body_ar'], $replace),
            'body_en' => self::fill($template['body_en'], $replace),
        ];
    }

    /**
     * @param  array<string, scalar|null>  $replace
     */
    private static function fill(string $template, array $replace): string
    {
        $result = $template;
        foreach ($replace as $key => $value) {
            $result = str_replace(':'.$key, (string) ($value ?? ''), $result);
        }

        return $result;
    }

    /**
     * @return array<string, array{title_ar: string, title_en: string, body_ar: string, body_en: string}>
     */
    private static function templates(): array
    {
        return [
            'shipment.published' => [
                'title_ar' => 'طلب شحن جديد',
                'title_en' => 'New shipment request',
                'body_ar' => 'نُشر الطلب :reference من :pickup إلى :delivery.',
                'body_en' => 'Request :reference was published from :pickup to :delivery.',
            ],
            'shipment.published_admin' => [
                'title_ar' => 'نُشر طلب شحن',
                'title_en' => 'Shipment request published',
                'body_ar' => 'نُشر الطلب :reference من :pickup إلى :delivery.',
                'body_en' => 'Request :reference was published from :pickup to :delivery.',
            ],
            'shipment.cancelled' => [
                'title_ar' => 'أُلغي طلب الشحن',
                'title_en' => 'Shipment request cancelled',
                'body_ar' => 'أُلغي الطلب :reference بعد تقديم عرضكم.',
                'body_en' => 'Request :reference was cancelled after your quotation.',
            ],
            'quotation.received' => [
                'title_ar' => 'عرض سعر جديد',
                'title_en' => 'New quotation',
                'body_ar' => 'وصل عرض سعر على الطلب :reference.',
                'body_en' => 'A quotation arrived for request :reference.',
            ],
            'quotation.received_admin' => [
                'title_ar' => 'وصل عرض مزود',
                'title_en' => 'Provider quotation received',
                'body_ar' => 'وصل عرض سعر على الطلب :reference.',
                'body_en' => 'A provider quotation arrived for request :reference.',
            ],
            'quotation.withdrawn' => [
                'title_ar' => 'سُحب عرض سعر',
                'title_en' => 'Quotation withdrawn',
                'body_ar' => 'سحب المزود عرضه على الطلب :reference.',
                'body_en' => 'The provider withdrew their quotation for request :reference.',
            ],
            'quotation.on_behalf' => [
                'title_ar' => 'أُدخل عرض باسمكم',
                'title_en' => 'Quotation entered for you',
                'body_ar' => 'أدخلت المنصة عرضاً باسم شركتكم على الطلب :reference.',
                'body_en' => 'The platform entered a quotation in your company name for request :reference.',
            ],
            'quotation.rejected' => [
                'title_ar' => 'رُفض عرضكم',
                'title_en' => 'Quotation not selected',
                'body_ar' => 'رُسي الطلب :reference على عرض آخر.',
                'body_en' => 'Request :reference was awarded to another quotation.',
            ],
            'platform_offer.published' => [
                'title_ar' => 'صدر عرض المنصة',
                'title_en' => 'Platform offer issued',
                'body_ar' => 'أصبح عرض المنصة للطلب :reference جاهزاً للمراجعة.',
                'body_en' => 'The platform offer for request :reference is ready to review.',
            ],
            'platform_offer.withdrawn' => [
                'title_ar' => 'سُحب عرض المنصة',
                'title_en' => 'Platform offer withdrawn',
                'body_ar' => 'لم يعد عرض المنصة للطلب :reference متاحاً.',
                'body_en' => 'The platform offer for request :reference is no longer available.',
            ],
            'platform_offer.based_on_quote' => [
                'title_ar' => 'بُني عرض العميل على عرضكم',
                'title_en' => 'Customer offer uses your quotation',
                'body_ar' => 'اختارت المنصة عرضكم على الطلب :reference أساساً لعرض العميل. لم تُرسي المهمة بعد.',
                'body_en' => 'The platform used your quotation for request :reference as the customer offer. The job is not awarded yet.',
            ],
            'platform_offer.basis_withdrawn' => [
                'title_ar' => 'لم يُعتمد عرضكم',
                'title_en' => 'Your quotation was not kept',
                'body_ar' => 'سُحب عرض المنصة المبني على عرضكم للطلب :reference قبل القبول.',
                'body_en' => 'The platform offer based on your quotation for request :reference was withdrawn before acceptance.',
            ],
            'job.created' => [
                'title_ar' => 'أُنشئت المهمة',
                'title_en' => 'Job created',
                'body_ar' => 'اعتُمد الطلب :reference وأُنشئت المهمة :job.',
                'body_en' => 'Request :reference was awarded and job :job was created.',
            ],
            'job.awarded' => [
                'title_ar' => 'رُسيت عليكم مهمة',
                'title_en' => 'Job awarded to you',
                'body_ar' => 'أُنشئت المهمة :job للطلب :reference. عيّنوا السائق والشاحنة.',
                'body_en' => 'Job :job was created for request :reference. Assign the driver and truck.',
            ],
            'payment.failed' => [
                'title_ar' => 'فشل الدفع',
                'title_en' => 'Payment failed',
                'body_ar' => 'لم يكتمل دفع الطلب :reference.',
                'body_en' => 'Payment for request :reference did not complete.',
            ],
            'trip.assigned' => [
                'title_ar' => 'عُيّنت رحلة',
                'title_en' => 'Trip assigned',
                'body_ar' => 'عُيّنت الرحلة :reference للسائق :driver.',
                'body_en' => 'Trip :reference was assigned to :driver.',
            ],
            'trip.assigned_driver' => [
                'title_ar' => 'رحلة جديدة',
                'title_en' => 'New trip',
                'body_ar' => 'عُيّنت لك الرحلة :reference من :pickup إلى :delivery.',
                'body_en' => 'Trip :reference is assigned to you, from :pickup to :delivery.',
            ],
            'trip.reassigned' => [
                'title_ar' => 'أُعيد تعيين رحلتك',
                'title_en' => 'Your trip was reassigned',
                'body_ar' => 'نُقلت الرحلة :reference إلى سائق آخر.',
                'body_en' => 'Trip :reference was moved to another driver.',
            ],
            'trip.in_transit' => [
                'title_ar' => 'الرحلة في الطريق',
                'title_en' => 'Trip is on the way',
                'body_ar' => 'انطلقت الرحلة :reference.',
                'body_en' => 'Trip :reference is now in transit.',
            ],
            'trip.completed' => [
                'title_ar' => 'اكتملت الرحلة',
                'title_en' => 'Trip completed',
                'body_ar' => 'اكتملت الرحلة :reference وسُجّل إثبات التسليم.',
                'body_en' => 'Trip :reference was completed and proof of delivery was recorded.',
            ],
            'trip.completed_provider' => [
                'title_ar' => 'اكتملت رحلة',
                'title_en' => 'Trip completed',
                'body_ar' => 'اكتملت الرحلة :reference.',
                'body_en' => 'Trip :reference was completed.',
            ],
            'job.completed' => [
                'title_ar' => 'اكتملت المهمة',
                'title_en' => 'Job completed',
                'body_ar' => 'اكتملت المهمة :job.',
                'body_en' => 'Job :job was completed.',
            ],
            'job.completed_provider' => [
                'title_ar' => 'اكتملت المهمة',
                'title_en' => 'Job completed',
                'body_ar' => 'اكتملت المهمة :job وتحرر الرصيد المعلّق في المحفظة.',
                'body_en' => 'Job :job was completed and the pending wallet balance was released.',
            ],
            'invoice.due' => [
                'title_ar' => 'فاتورة مستحقة',
                'title_en' => 'Invoice is due',
                'body_ar' => 'أصبحت الفاتورة :reference مستحقة.',
                'body_en' => 'Invoice :reference is now due.',
            ],
            'trip.cancelled' => [
                'title_ar' => 'أُلغيت رحلة',
                'title_en' => 'Trip cancelled',
                'body_ar' => 'أُلغيت الرحلة :reference.',
                'body_en' => 'Trip :reference was cancelled.',
            ],
            'trip.cancelled_driver' => [
                'title_ar' => 'أُلغيت رحلتك',
                'title_en' => 'Your trip was cancelled',
                'body_ar' => 'أُلغيت الرحلة :reference.',
                'body_en' => 'Trip :reference was cancelled.',
            ],
            'provider.registered' => [
                'title_ar' => 'مزود بانتظار التفعيل',
                'title_en' => 'Provider awaiting activation',
                'body_ar' => 'سجّلت شركة :name وتنتظر المراجعة.',
                'body_en' => ':name registered and is waiting for review.',
            ],
            'payment.receipt_uploaded' => [
                'title_ar' => 'إيصال تحويل بانتظار التأكيد',
                'title_en' => 'Transfer receipt awaiting confirmation',
                'body_ar' => 'رُفع إيصال للدفعة :reference.',
                'body_en' => 'A receipt was uploaded for payment :reference.',
            ],
            'settlement.requested' => [
                'title_ar' => 'طلب سحب من المحفظة',
                'title_en' => 'Wallet withdrawal requested',
                'body_ar' => 'طلبت :name سحب :amount.',
                'body_en' => ':name requested a withdrawal of :amount.',
            ],
            'settlement.completed' => [
                'title_ar' => 'اكتمل الصرف',
                'title_en' => 'Payout completed',
                'body_ar' => 'اكتمل صرف التسوية :reference.',
                'body_en' => 'Settlement :reference was paid out.',
            ],
            'organization.activated' => [
                'title_ar' => 'تم تفعيل الشركة',
                'title_en' => 'Company activated',
                'body_ar' => 'أصبح حساب :name مفعّلاً.',
                'body_en' => ':name is now active.',
            ],
            'organization.rejected' => [
                'title_ar' => 'رُفض حساب الشركة',
                'title_en' => 'Company rejected',
                'body_ar' => 'رُفض حساب :name.',
                'body_en' => ':name was rejected.',
            ],
            'organization.suspended' => [
                'title_ar' => 'أُوقف حساب الشركة',
                'title_en' => 'Company suspended',
                'body_ar' => 'أُوقف حساب :name.',
                'body_en' => ':name was suspended.',
            ],
        ];
    }
}
