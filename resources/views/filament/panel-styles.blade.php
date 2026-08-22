{{--
    Panel CSS, injected through PanelsRenderHook::STYLES_AFTER.

    It cannot live in resources/css/app.css: Filament ships its own compiled
    stylesheet and does not go through the app's Vite build, so a rule written
    there never reaches a panel page. The two alternatives both cost more than
    they are worth for a handful of rules — a custom Vite theme means owning
    Filament's CSS build across upgrades, and a registered FilamentAsset needs
    `php artisan filament:assets` to have run, which is exactly the kind of
    deploy step whose absence fails silently. A render hook needs neither.

    STYLES_AFTER rather than HEAD_END: it renders after Filament's own
    stylesheet, so these rules win on source order without `!important`.
--}}
<style>
    /*
     * Tables scroll sideways on narrow screens.
     *
     * Filament already sets `overflow-x: auto` on .fi-ta-content-ctn, so the
     * container itself scrolls. Two things it does not do, and both are why a
     * table reads as "cut off" rather than "scrollable" on a phone:
     *
     *  1. Nothing stops the table squeezing itself into the viewport. Cells
     *     wrap until it fits, so frequently there is nothing to scroll at all —
     *     the columns simply become unreadably narrow. The min-width below is
     *     the floor that turns squeezing back into overflow.
     *
     *  2. Overlay scrollbars stay invisible until a scroll is already in
     *     progress, so nothing on screen says more columns exist off to the
     *     side. The bar is given a height and a colour so it is always there.
     *
     * Scoped below `lg` because that is where Filament stops showing the
     * filter panel inline and the viewport stops being wide enough for a full
     * cash book row.
     */
    @media (max-width: 1023px) {
        .fi-ta-content-ctn {
            /* A sideways swipe on the table must not become the browser's
               back gesture once the table hits its end. */
            overscroll-behavior-x: contain;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: var(--gray-400) transparent;
        }

        .fi-ta-content-ctn::-webkit-scrollbar {
            height: 0.5rem;
        }

        .fi-ta-content-ctn::-webkit-scrollbar-track {
            background-color: transparent;
        }

        .fi-ta-content-ctn::-webkit-scrollbar-thumb {
            background-color: var(--gray-400);
            border-radius: 9999px;
        }

        /* Filament's dark mode is a `.dark` class on an ancestor, not a media
           query, so the panel's own toggle has to be honoured explicitly. */
        .dark .fi-ta-content-ctn {
            scrollbar-color: var(--gray-600) transparent;
        }

        .dark .fi-ta-content-ctn::-webkit-scrollbar-thumb {
            background-color: var(--gray-600);
        }

        /*
         * TODO(tuning): 48rem is a guess at "one cash book row, readable".
         * Per-table overrides go on the resource's page wrapper if a screen
         * needs a different floor — a room list is narrower than a sale.
         */
        .fi-ta-table {
            min-width: var(--fi-ta-mobile-min-width, 48rem);
        }
    }
</style>
