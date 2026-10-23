<?php

namespace App\Support;

use App\Models\Option;
use App\Models\Product;
use App\Support\Localization\ErrorMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Other editions of a work as reported by DLsite: the original, language editions,
 * other versions (e.g. Windows / Mac / Android) and translations.
 */
final class RelatedEditions
{
    public const TYPE_ORIGINAL = 'original';

    public const TYPE_LANGUAGE = 'language';

    public const TYPE_EDITION = 'edition';

    public const TYPE_TRANSLATION = 'translation';

    public const TYPES = [
        self::TYPE_ORIGINAL,
        self::TYPE_LANGUAGE,
        self::TYPE_EDITION,
        self::TYPE_TRANSLATION,
    ];

    public const CODE_PATTERN = '/\A(?:RJ|BJ|VJ)\d+\z/';

    public const MAX_EDITIONS = 200;

    public const WARNING_MESSAGE = 'Other editions of this work are already in your library: :works';

    private const MATCH_LIMIT = 20;

    /**
     * Reads stored links (`related_editions`) or derives them from the raw scraper
     * fields (`dlsite_editions`). Returns null when the data says nothing about editions.
     *
     * @return list<array{code: string, type: string, label: ?string}>|null
     */
    public static function fromWorkData(array $japanese, array $english, string $productId): ?array
    {
        foreach ([$japanese, $english] as $locale) {
            if (array_key_exists('related_editions', $locale)) {
                return $locale['related_editions'] === null
                    ? null
                    : self::normalize($locale['related_editions'], $productId);
            }
        }

        $raw = $japanese['dlsite_editions'] ?? $english['dlsite_editions'] ?? null;

        return is_array($raw) ? self::fromDlsite($raw, $productId) : null;
    }

    /** @return list<array{code: string, type: string, label: ?string}> */
    public static function fromDlsite(array $raw, string $productId): array
    {
        $translation = is_array($raw['translation_info'] ?? null) ? $raw['translation_info'] : [];
        $entries = [
            ['code' => $translation['original_workno'] ?? null, 'type' => self::TYPE_ORIGINAL],
        ];

        foreach (['language_editions' => self::TYPE_LANGUAGE, 'editions' => self::TYPE_EDITION] as $key => $type) {
            foreach (is_array($raw[$key] ?? null) ? $raw[$key] : [] as $edition) {
                if (is_array($edition)) {
                    $entries[] = ['code' => $edition['workno'] ?? null, 'type' => $type, 'label' => $edition['label'] ?? null];
                }
            }
        }

        $translations = is_array($translation['child_worknos'] ?? null) ? $translation['child_worknos'] : [];
        foreach ([$translation['parent_workno'] ?? null, ...$translations] as $code) {
            $entries[] = ['code' => $code, 'type' => self::TYPE_TRANSLATION];
        }

        return self::normalize($entries, $productId);
    }

    /**
     * Keeps valid entries once per code; a later duplicate only fills a missing label.
     *
     * @return list<array{code: string, type: string, label: ?string}>
     */
    public static function normalize(mixed $editions, ?string $productId = null): array
    {
        $normalized = [];
        $productId = $productId === null ? null : strtoupper($productId);

        foreach (is_array($editions) ? $editions : [] as $edition) {
            $code = is_array($edition) && is_string($edition['code'] ?? null)
                ? strtoupper(trim($edition['code']))
                : '';

            if (! preg_match(self::CODE_PATTERN, $code) || $code === $productId) {
                continue;
            }

            $label = is_string($edition['label'] ?? null) ? trim($edition['label']) : '';
            $label = $label === '' ? null : mb_substr($label, 0, 255);

            if (isset($normalized[$code])) {
                $normalized[$code]['label'] ??= $label;

                continue;
            }

            $normalized[$code] = [
                'code' => $code,
                'type' => in_array($edition['type'] ?? null, self::TYPES, true) ? $edition['type'] : self::TYPE_EDITION,
                'label' => $label,
            ];
        }

        return array_slice(array_values($normalized), 0, self::MAX_EDITIONS);
    }

    /** @return list<string> */
    public static function codes(mixed $editions): array
    {
        return array_column(self::normalize($editions), 'code');
    }

    /**
     * Library works that are another edition of $code: works it lists, works that list it,
     * and works sharing a listed edition (e.g. two translations of one original).
     *
     * @return Collection<int, Product>
     */
    public static function inLibrary(string $code, mixed $editions = null): Collection
    {
        $code = strtoupper($code);
        $codes = self::codes($editions);

        return Product::query()
            ->whereKeyNot($code)
            ->where(function (Builder $query) use ($code, $codes): void {
                $query->whereIn($query->qualifyColumn('id'), $codes);

                foreach ([$code, ...$codes] as $relatedCode) {
                    $query->orWhereJsonContains('related_editions', ['code' => $relatedCode]);
                }
            })
            ->orderBy('id')
            ->limit(self::MATCH_LIMIT)
            ->get(['id', 'work_name']);
    }

    /** @return list<array{code: string, title: string}> */
    public static function summaries(Collection $products): array
    {
        return $products
            ->map(fn(Product $product): array => [
                'code' => (string) $product->getKey(),
                'title' => (string) $product->work_name,
            ])
            ->values()
            ->all();
    }

    /** Stored (untranslated) warning used by Bulk Import items. */
    public static function warningMessage(Collection $products): ?string
    {
        if ($products->isEmpty()) {
            return null;
        }

        return str_replace(':works', implode(', ', $products->modelKeys()), self::WARNING_MESSAGE);
    }

    public static function displayWarningMessage(?string $message): ?string
    {
        $prefix = str_replace(':works', '', self::WARNING_MESSAGE);

        if ($message === null || ! str_starts_with($message, $prefix)) {
            return $message;
        }

        return ErrorMessage::format(self::WARNING_MESSAGE, [
            'works' => substr($message, strlen($prefix)),
        ]);
    }

    /**
     * Rows for the edit page: listed editions first, then library works that link to this one.
     *
     * @return list<array{code: string, type: ?string, label: ?string, title: ?string, url: string, in_library: bool}>
     */
    public static function displayRows(Product $product): array
    {
        $editions = self::normalize($product->related_editions, (string) $product->getKey());
        $library = self::inLibrary((string) $product->getKey(), $editions)->keyBy(fn(Product $work): string => (string) $work->getKey());
        $ageAppropriateLinks = Option::dlsiteAgeAppropriateLinksEnabled();
        $rows = [];

        foreach ($editions as $edition) {
            $rows[] = self::row($product, $edition['code'], $edition['type'], $edition['label'], $library->get($edition['code']), $ageAppropriateLinks);
        }

        $listed = array_flip(array_column($editions, 'code'));

        foreach ($library as $code => $work) {
            if (! isset($listed[$code])) {
                $rows[] = self::row($product, (string) $code, null, null, $work, $ageAppropriateLinks);
            }
        }

        return $rows;
    }

    public static function typeLabel(?string $type): ?string
    {
        return match ($type) {
            self::TYPE_ORIGINAL => __('Original'),
            self::TYPE_LANGUAGE => __('Language edition'),
            self::TYPE_EDITION => __('Other version'),
            self::TYPE_TRANSLATION => __('Translation'),
            default => null,
        };
    }

    /** One-line text for an edition, used by import review previews. */
    public static function describe(mixed $edition): string
    {
        if (! is_array($edition)) {
            return (string) $edition;
        }

        $details = array_filter([
            self::typeLabel(is_string($edition['type'] ?? null) ? $edition['type'] : null),
            is_string($edition['label'] ?? null) ? $edition['label'] : null,
        ]);

        return trim((string) ($edition['code'] ?? '') . ($details === [] ? '' : ' (' . implode(': ', $details) . ')'));
    }

    private static function row(
        Product $product,
        string $code,
        ?string $type,
        ?string $label,
        ?Product $work,
        bool $ageAppropriateLinks,
    ): array {
        // Editions outside the library link to DLsite on the same section as this work.
        $url = $work !== null
            ? route('products.edit', ['product' => $code], false)
            : (new Product([
                'id' => $code,
                'site_id' => $product->site_id,
                'age_category' => $product->age_category,
            ]))->dlsiteWorkUrl($ageAppropriateLinks, false);

        return [
            'code' => $code,
            'type' => $type,
            'label' => $label,
            'title' => $work?->work_name,
            'url' => $url,
            'in_library' => $work !== null,
        ];
    }
}
