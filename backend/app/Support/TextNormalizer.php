<?php

namespace App\Support;

/**
 * Normalizes Persian/Arabic/Latin text so searches match regardless of
 * Arabic vs Persian letter forms, diacritics, ZWNJ or case.
 */
class TextNormalizer
{
    private const MAP = [
        'ي' => 'ی', 'ى' => 'ی', 'ئ' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه',
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ؤ' => 'و',
        '‌' => ' ', 'ـ' => '',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    public static function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $text = strtr($text, self::MAP);
        // Arabic diacritics (harakat) and tanween.
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);
        $text = mb_strtolower($text);
        $text = preg_replace('/[_\-.,;:!?()\[\]{}"\'«»|\/\\\\#@]+/u', ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** @return list<string> */
    public static function words(?string $text): array
    {
        $normalized = self::normalize($text);

        return $normalized === '' ? [] : array_values(array_unique(explode(' ', $normalized)));
    }
}
