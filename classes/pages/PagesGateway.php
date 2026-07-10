<?php namespace Renick\TailorCompanion\Classes\Pages;

/**
 * PagesGateway is the only surface that touches RainLab.Pages classes.
 * Everything above it (serializers, transformers, writer, controllers) works
 * on the plain arrays defined here, so the whole feature can be unit-tested
 * with a fake gateway even when RainLab.Pages is not installed.
 *
 * All methods operate on the active theme of the current request.
 */
interface PagesGateway
{
    /**
     * layouts returns the raw layout templates usable for static pages
     * (layouts declaring the `staticPage` component).
     *
     * @return array<int, array{
     *     fileName: string,      // base file name without extension
     *     description: ?string,
     *     useContent: bool,      // staticPage component property (default true)
     *     markup: string,        // raw layout markup (syntax fields + placeholders)
     * }>
     */
    public function layouts(): array;

    /**
     * tree returns the nested page hierarchy in meta/static-pages.yaml order.
     * Only pages that exist on disk appear.
     *
     * @return array<int, array{fileName: string, children: array}>
     */
    public function tree(): array;

    /**
     * page returns the raw data of a single static page, or null when missing.
     *
     * @return ?array{
     *     fileName: string,      // base file name without extension
     *     viewBag: array,        // full INI view bag (implicit props + field values)
     *     markup: string,        // content section
     *     code: string,          // raw code section holding {% put %} blocks
     *     mtime: ?int,           // file modification unix timestamp
     *     contentHash: string,   // sha256 of the raw file contents
     * }
     */
    public function page(string $fileName): ?array;

    /**
     * updatePage saves changes to an existing page through the RainLab model
     * (validation and events included — never raw file writes) and returns the
     * fresh page data in the same shape as page().
     *
     * @param array $viewBag full replacement view bag
     * @param ?string $markup new content section, null = leave unchanged
     * @param ?string $code new code section ({% put %} blocks), null = leave unchanged
     */
    public function updatePage(string $fileName, array $viewBag, ?string $markup, ?string $code): array;

    /**
     * menus lists the theme's static menus.
     *
     * @return array<int, array{code: string, name: ?string, contentHash: string}>
     */
    public function menus(): array;

    /**
     * menu returns one menu with its raw item arrays, or null when missing.
     *
     * @return ?array{code: string, name: ?string, items: array}
     */
    public function menu(string $code): ?array;

    /**
     * themePath returns the absolute path of the theme static pages live in,
     * or null when none can be resolved. Used to resolve theme-relative
     * repeater form/groups references.
     */
    public function themePath(): ?string;
}
