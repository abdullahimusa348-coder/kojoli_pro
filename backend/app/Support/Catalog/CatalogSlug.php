<?php

namespace App\Support\Catalog;

use Illuminate\Support\Str;

/** Slugs and codes for the catalog: generated from the name once, at creation, then locked. */
class CatalogSlug
{
    public static function from(string $name): string
    {
        return Str::slug(str_replace('&', ' and ', $name));
    }

    /** Product code: "<service-slug>-<name>", e.g. "data-mtn". Empty if the name has no letters or numbers. */
    public static function productCode(string $serviceSlug, string $name): string
    {
        $name = self::from($name);

        return $name === '' ? '' : $serviceSlug.'-'.$name;
    }

    /** Plan code: "<product-code>-<name>", e.g. "data-mtn-sme-1gb-30-days". */
    public static function planCode(string $productCode, string $name): string
    {
        $name = self::from($name);

        return $name === '' ? '' : $productCode.'-'.$name;
    }
}
