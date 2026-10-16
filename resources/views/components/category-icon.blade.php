@props(['slug' => null])
@php
    /*
     * D-06 image placeholder: a self-hosted category icon. The slug is
     * resolved against a fixed allow-list (CategorySeeder slugs); anything
     * else, including empty or path-like values, becomes "other" (T-01-42).
     * The component name is built only from the resolved value.
     */
    $allowed = ['family', 'community', 'market', 'charity', 'music', 'sport', 'food-drink', 'school', 'arts-culture', 'other'];
    $resolved = in_array($slug, $allowed, true) ? $slug : 'other';
@endphp
<x-dynamic-component
    :component="'icons.category.'.$resolved"
    :attributes="$attributes->class(['size-6' => ! $attributes->has('class')])->merge(['data-category' => $resolved, 'aria-hidden' => 'true', 'focusable' => 'false'])"
/>
