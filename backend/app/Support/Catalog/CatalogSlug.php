<?php

namespace App\Support\Catalog;

use Illuminate\Support\Str;

/** Slugs for categories and services: generated from the name once, at creation. */
class CatalogSlug
{
    public static function from(string $name): string
    {
        return Str::slug(str_replace('&', ' and ', $name));
    }
}
