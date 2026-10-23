<?php

namespace App\Support;

use App\Models\Product;

/**
 * Library works that are editions of one work (the original, translations, language
 * editions and versions such as Windows / Mac), built from the stored DLsite links so the
 * Index can show them as one row. Loaded once per request, never per row.
 */
final readonly class EditionGroups
{
    private const LOOKUP_CHUNK = 1000;

    /**
     * @param  list<array{members: list<string>, languages: bool}>  $groups  members in row order
     * @param  array<string, int>  $groupOf  group index by library code
     * @param  array<string, string>  $labels  DLsite label by code (e.g. 日本語, Windows版)
     * @param  array<string, true>  $originals  codes another library work lists as its original
     */
    private function __construct(
        private array $groups = [],
        private array $groupOf = [],
        private array $labels = [],
        private array $originals = [],
    ) {}

    /**
     * Groups library works linked directly or through a shared edition (e.g. two
     * translations of one original). Works without another edition in the library stay ungrouped.
     */
    public static function load(): self
    {
        $links = Product::query()
            ->whereJsonLength('related_editions', '>', 0)
            ->orderBy('id')
            ->pluck('related_editions', 'id');

        if ($links->isEmpty()) {
            return new self;
        }

        $parents = [];
        $labels = [];
        $originals = [];
        $languageLinks = [];

        foreach ($links as $code => $editions) {
            $code = (string) $code;

            foreach (RelatedEditions::normalize($editions, $code) as $edition) {
                self::union($parents, $code, $edition['code']);
                $labels[$edition['code']] ??= $edition['label'];

                if ($edition['type'] === RelatedEditions::TYPE_ORIGINAL) {
                    $originals[$edition['code']] = true;
                }

                if ($edition['type'] !== RelatedEditions::TYPE_EDITION) {
                    $languageLinks[$code] = true;
                }
            }
        }

        $members = [];

        foreach (self::libraryCodes(array_keys($links->all()), array_keys($parents)) as $code) {
            $members[self::find($parents, $code)][] = $code;
        }

        $groups = [];
        $groupOf = [];

        foreach ($members as $codes) {
            if (count($codes) < 2) {
                continue;
            }

            usort($codes, fn(string $a, string $b): int => self::rowOrder($a, $originals) <=> self::rowOrder($b, $originals));

            foreach ($codes as $code) {
                $groupOf[$code] = count($groups);
            }

            $groups[] = [
                'members' => $codes,
                'languages' => array_intersect_key($languageLinks, array_flip($codes)) !== [],
            ];
        }

        return new self(
            $groups,
            $groupOf,
            array_filter($labels, fn(?string $label): bool => $label !== null),
            array_intersect_key($originals, $groupOf),
        );
    }

    public function isEmpty(): bool
    {
        return $this->groupOf === [];
    }

    /**
     * Every library work that shares an Index row with another edition.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map('strval', array_keys($this->groupOf));
    }

    /**
     * Library editions of the work $code belongs to, in row order; [] when ungrouped.
     *
     * @return list<string>
     */
    public function members(string $code): array
    {
        $group = $this->groupOf[$code] ?? null;

        return $group === null ? [] : $this->groups[$group]['members'];
    }

    /**
     * The row each matching grouped work is shown on: the first matching edition of its
     * work in row order (the original when it matches, otherwise the lowest code).
     *
     * @param  iterable<int|string>  $matchingCodes
     * @return array<string, string>
     */
    public function rowCodes(iterable $matchingCodes): array
    {
        $matching = [];

        foreach ($matchingCodes as $code) {
            $matching[(string) $code] = true;
        }

        $rows = [];

        foreach (array_keys($matching) as $code) {
            foreach ($this->members((string) $code) as $member) {
                if (isset($matching[$member])) {
                    $rows[(string) $code] = $member;

                    break;
                }
            }
        }

        return $rows;
    }

    /**
     * Index badge data for a row: whether the editions differ by language (otherwise only
     * by version) and every library edition with its display label.
     *
     * @return array{languages: bool, editions: list<array{code: string, label: ?string}>}|null
     */
    public function display(string $code): ?array
    {
        $group = $this->groupOf[$code] ?? null;

        if ($group === null) {
            return null;
        }

        return [
            'languages' => $this->groups[$group]['languages'],
            'editions' => array_map(fn(string $member): array => [
                'code' => $member,
                'label' => $this->label($member),
            ], $this->groups[$group]['members']),
        ];
    }

    private function label(string $code): ?string
    {
        $label = $this->labels[$code] ?? null;

        if ($label !== null) {
            return self::languageName($label) ?? $label;
        }

        return isset($this->originals[$code]) ? __('Original') : null;
    }

    /** Localized names for the languages DLsite labels editions with. */
    private static function languageName(string $label): ?string
    {
        return match ($label) {
            '日本語' => __('Japanese'),
            '英語' => __('English'),
            '簡体中文' => __('Simplified Chinese'),
            '繁体中文' => __('Traditional Chinese'),
            '韓国語' => __('Korean'),
            'スペイン語' => __('Spanish'),
            'ドイツ語' => __('German'),
            'フランス語' => __('French'),
            'インドネシア語' => __('Indonesian'),
            'イタリア語' => __('Italian'),
            'ポルトガル語' => __('Portuguese'),
            'スウェーデン語' => __('Swedish'),
            'タイ語' => __('Thai'),
            'ベトナム語' => __('Vietnamese'),
            default => null,
        };
    }

    /**
     * The original first, then the lowest code: DLsite numbers works in release order,
     * so the lowest code is usually the first release.
     *
     * @param  array<string, true>  $originals
     * @return array{0: bool, 1: int, 2: string}
     */
    private static function rowOrder(string $code, array $originals): array
    {
        return [! isset($originals[$code]), Product::codeNumberFromId($code) ?? PHP_INT_MAX, $code];
    }

    /**
     * @param  list<int|string>  $linkedCodes  library works that list editions
     * @param  list<int|string>  $codes  every code in those links
     * @return list<string>
     */
    private static function libraryCodes(array $linkedCodes, array $codes): array
    {
        $library = array_fill_keys(array_map('strval', $linkedCodes), true);
        $unknown = array_values(array_diff(array_map('strval', $codes), array_keys($library)));

        foreach (array_chunk($unknown, self::LOOKUP_CHUNK) as $chunk) {
            foreach (Product::query()->whereKey($chunk)->pluck('id') as $code) {
                $library[strtoupper((string) $code)] = true;
            }
        }

        return array_map('strval', array_keys($library));
    }

    /** @param  array<string, string>  $parents */
    private static function find(array &$parents, string $code): string
    {
        $root = $code;

        while (($parents[$root] ?? $root) !== $root) {
            $root = $parents[$root];
        }

        while ($code !== $root) {
            $next = $parents[$code];
            $parents[$code] = $root;
            $code = $next;
        }

        return $root;
    }

    /** @param  array<string, string>  $parents */
    private static function union(array &$parents, string $a, string $b): void
    {
        $parents[$a] ??= $a;
        $parents[$b] ??= $b;
        $rootA = self::find($parents, $a);
        $rootB = self::find($parents, $b);

        if ($rootA !== $rootB) {
            $parents[$rootB] = $rootA;
        }
    }
}
