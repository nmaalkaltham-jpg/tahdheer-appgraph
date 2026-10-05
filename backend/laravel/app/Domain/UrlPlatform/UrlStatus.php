<?php

namespace App\Domain\UrlPlatform;

/**
 * دورة حياة الرابط — ثماني حالاتٍ لا خامسة بينها.
 *
 * الحيّة تحجز المسار (تفرّد القاعدة الجزئيّ يقرؤها بالاسم)، وغير الحيّة
 * تُبقي التاريخ: «مستبدَل» يعني أنّ للرابط خليفةً وتحويلًا، و«شاهد قبر»
 * يعني ٤١٠ صريحة — المورد كان ولن يعود.
 */
enum UrlStatus: string
{
    case Draft = 'draft';
    case Reserved = 'reserved';
    case Published = 'published';
    case Locked = 'locked';
    case Replaced = 'replaced';
    case Archived = 'archived';
    case Deleted = 'deleted';
    case Tombstoned = 'tombstoned';

    /** @return list<string> */
    public static function activeValues(): array
    {
        return [self::Draft->value, self::Reserved->value, self::Published->value, self::Locked->value];
    }

    public function isActive(): bool
    {
        return in_array($this->value, self::activeValues(), true);
    }

    public function isPublic(): bool
    {
        return $this === self::Published || $this === self::Locked;
    }

    /**
     * الانتقالات المسموحة — والباقي مرفوضٌ بالاسم لا بصمت.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Draft => [self::Reserved, self::Published, self::Deleted],
            self::Reserved => [self::Published, self::Draft, self::Deleted],
            self::Published => [self::Locked, self::Archived, self::Deleted, self::Tombstoned, self::Replaced],
            self::Locked => [self::Published, self::Archived, self::Tombstoned],
            self::Archived => [self::Published, self::Deleted, self::Tombstoned],
            self::Replaced, self::Deleted, self::Tombstoned => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->transitions(), true);
    }
}
