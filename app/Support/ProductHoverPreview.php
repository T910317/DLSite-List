<?php

namespace App\Support;

use App\Enums\ProductContributorRole;
use App\Enums\ProductField;
use App\Enums\ProductFormat;
use App\Enums\UiLanguage;
use App\Models\GenreGroup;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Builds the Index cover hover card from saved data only, one work at a time,
 * so hidden table columns never force page-wide relationship loading.
 */
final readonly class ProductHoverPreview
{
    public const DESCRIPTION_EXCERPT_LENGTH = 200;

    public function __construct(private ProductIndexResults $results) {}

    /**
     * @return array{
     *     id: string,
     *     title: string,
     *     alternateTitle: ?string,
     *     makers: list<string>,
     *     formats: list<string>,
     *     tags: list<string>,
     *     description: ?string,
     *     images: list<string>
     * }
     */
    public function build(string $productId, ProductIndexSettings $settings): array
    {
        $product = Product::query()->findOrFail($productId, [
            'id',
            'work_name',
            'work_name_english',
            'circle',
            'publisher',
            'brand',
            'product_format',
            'description',
            'description_english',
            'work_image',
            'sample_images',
        ]);

        $id = (string) $product->getKey();

        return [
            'id' => $id,
            'title' => (string) $product->work_name,
            'alternateTitle' => filled($product->work_name_english) && $product->work_name_english !== $product->work_name
                ? $product->work_name_english
                : null,
            'makers' => $this->makers($product),
            'formats' => array_values(ProductFormat::labelsFor($product->product_format ?? [])),
            'tags' => $this->tags($id, $settings),
            'description' => $this->descriptionExcerpt($product),
            // Preview pages: the saved cover first, then the saved sample images.
            'images' => array_values(array_map(
                fn(string $path): string => asset(Product::versionedImagePath($path)),
                array_filter(
                    [$product->work_image, ...($product->sample_images ?? [])],
                    fn(mixed $path): bool => is_string($path) && filled($path),
                ),
            )),
        ];
    }

    /**
     * @return list<string>
     */
    private function makers(Product $product): array
    {
        $circles = $this->results
            ->loadContributors([$product->getKey()], [ProductField::Circle->value])
            ->get($product->getKey())
            ?->get(ProductContributorRole::Circle->value)
            ?->pluck('name')
            ->all() ?? [];

        if ($circles === [] && filled($product->circle)) {
            $circles = [$product->circle];
        }

        return collect([...$circles, $product->brand, $product->publisher])
            ->filter(fn(?string $name): bool => filled($name))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function tags(string $productId, ProductIndexSettings $settings): array
    {
        return $this->results
            ->loadVisibleGenres(
                [$productId],
                $settings->indexGroupOrderingEnabled,
                hasHiddenGroups: GenreGroup::query()->hiddenOnIndex()->exists(),
                fetchedLanguage: $settings->fetchedTagLanguage,
            )
            ->get($productId)
            ?->pluck('title')
            ->unique()
            ->values()
            ->all() ?? [];
    }

    private function descriptionExcerpt(Product $product): ?string
    {
        // Pick the saved description that best matches the UI, but never translate or rewrite it.
        $candidates = UiLanguage::current() === UiLanguage::English
            ? [$product->description_english, $product->description]
            : [$product->description, $product->description_english];

        foreach ($candidates as $description) {
            $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $description)));

            if ($text !== '') {
                return Str::limit($text, self::DESCRIPTION_EXCERPT_LENGTH);
            }
        }

        return null;
    }
}
