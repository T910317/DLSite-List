<?php

namespace App\Support\DLSite;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

final readonly class DLSiteProductImportResult
{
    /**
     * @param  Collection<int, Product>  $relatedWorks  Other editions already in the library.
     */
    public function __construct(
        public Product $product,
        public ?string $warning = null,
        public Collection $relatedWorks = new Collection,
    ) {}
}
