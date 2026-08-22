{{--
    Click-to-zoom viewer for image entries, injected at PanelsRenderHook::BODY_END.

    It attaches to any element carrying `data-lightbox`, and treats every `<a>`
    inside that element as one slide. Filament's ImageEntry already wraps each
    image in an `<a href>` once the entry is given a state-based `->url()`, so
    marking the entry's wrapper is the whole of the opt-in — no per-image markup
    and no custom Blade view to keep in step with Filament's own.

    The href stays a real link on purpose. If this script never runs — a JS
    error earlier on the page, or a browser that blocks it — clicking a receipt
    still opens the full-size file rather than doing nothing at all.

    Alpine is used through an inline `x-data` object rather than `Alpine.data()`.
    Filament bundles and boots Alpine itself, so registering a named component
    would mean racing its `alpine:init`; an object literal has no such ordering
    to get wrong.
--}}
<style>
    .fi-lightbox {
        position: fixed;
        inset: 0;
        z-index: 60;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: rgb(0 0 0 / 0.9);
        /* The browser must not treat a drag or a pinch on the image as a page
           gesture — scrolling, pull-to-refresh and back-swipe all have to yield
           to the pan/zoom handlers below. */
        touch-action: none;
        overscroll-behavior: contain;
    }

    .fi-lightbox-stage {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
    }

    .fi-lightbox-image {
        max-width: 100vw;
        max-height: 100dvh;
        /* Transforms are composited, so panning stays smooth on a phone in a
           way that changing width/height would not. */
        transform-origin: center center;
        user-select: none;
        -webkit-user-drag: none;
        will-change: transform;
    }

    .fi-lightbox-bar {
        position: absolute;
        inset-inline: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem;
        color: rgb(255 255 255 / 0.9);
    }

    .fi-lightbox-bar-top {
        top: 0;
        justify-content: space-between;
        background: linear-gradient(rgb(0 0 0 / 0.55), transparent);
    }

    .fi-lightbox-bar-bottom {
        bottom: 0;
        justify-content: center;
        background: linear-gradient(transparent, rgb(0 0 0 / 0.55));
    }

    .fi-lightbox-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 2.5rem;
        height: 2.5rem;
        padding-inline: 0.625rem;
        border-radius: 9999px;
        background-color: rgb(255 255 255 / 0.12);
        color: inherit;
        font-size: 0.875rem;
        line-height: 1;
        cursor: pointer;
    }

    .fi-lightbox-btn:hover {
        background-color: rgb(255 255 255 / 0.22);
    }

    .fi-lightbox-btn[disabled] {
        opacity: 0.35;
        cursor: default;
    }

    .fi-lightbox-count {
        font-size: 0.875rem;
        font-variant-numeric: tabular-nums;
    }

    /* The thumbnails that open this thing should say so before they are clicked. */
    [data-lightbox] a {
        cursor: zoom-in;
    }
</style>

<div
    x-cloak
    x-data="{
        isOpen: false,
        items: [],
        index: 0,
        scale: 1,
        x: 0,
        y: 0,
        pointers: new Map(),
        pinchDistance: 0,
        pinchScale: 1,
        panFrom: null,

        get item() {
            return this.items[this.index] ?? null
        },

        get isZoomed() {
            return this.scale > 1.01
        },

        open(detail) {
            this.items = detail.items
            this.index = detail.index
            this.reset()
            this.isOpen = true
            document.body.style.overflow = 'hidden'
        },

        close() {
            this.isOpen = false
            this.items = []
            this.pointers.clear()
            document.body.style.overflow = ''
        },

        reset() {
            this.scale = 1
            this.x = 0
            this.y = 0
        },

        go(step) {
            const next = this.index + step

            if (next < 0 || next >= this.items.length) {
                return
            }

            this.index = next
            this.reset()
        },

        zoomTo(scale, originX, originY) {
            const clamped = Math.min(Math.max(scale, 1), 8)

            if (originX !== undefined) {
                // Keep whatever sits under the cursor or the pinch centre
                // pinned there, rather than zooming towards the middle and
                // making the reader chase the thing they were looking at.
                const ratio = clamped / this.scale
                this.x = originX - ratio * (originX - this.x)
                this.y = originY - ratio * (originY - this.y)
            }

            this.scale = clamped

            if (clamped === 1) {
                this.x = 0
                this.y = 0
            }
        },

        onWheel(event) {
            const rect = event.currentTarget.getBoundingClientRect()

            this.zoomTo(
                this.scale * (1 - event.deltaY * 0.0015),
                event.clientX - rect.left - rect.width / 2,
                event.clientY - rect.top - rect.height / 2,
            )
        },

        onDoubleClick(event) {
            const rect = event.currentTarget.getBoundingClientRect()

            this.zoomTo(
                this.isZoomed ? 1 : 3,
                event.clientX - rect.left - rect.width / 2,
                event.clientY - rect.top - rect.height / 2,
            )
        },

        onPointerDown(event) {
            event.currentTarget.setPointerCapture(event.pointerId)
            this.pointers.set(event.pointerId, { x: event.clientX, y: event.clientY })

            if (this.pointers.size === 2) {
                this.pinchDistance = this.spread()
                this.pinchScale = this.scale
                this.panFrom = null

                return
            }

            if (this.isZoomed) {
                this.panFrom = { x: event.clientX - this.x, y: event.clientY - this.y }
            }
        },

        onPointerMove(event) {
            if (! this.pointers.has(event.pointerId)) {
                return
            }

            this.pointers.set(event.pointerId, { x: event.clientX, y: event.clientY })

            if (this.pointers.size === 2) {
                if (this.pinchDistance > 0) {
                    this.zoomTo(this.pinchScale * (this.spread() / this.pinchDistance))
                }

                return
            }

            if (this.panFrom) {
                this.x = event.clientX - this.panFrom.x
                this.y = event.clientY - this.panFrom.y
            }
        },

        onPointerUp(event) {
            this.pointers.delete(event.pointerId)
            this.panFrom = null

            if (this.pointers.size < 2) {
                this.pinchDistance = 0
            }
        },

        spread() {
            const [a, b] = [...this.pointers.values()]

            return Math.hypot(a.x - b.x, a.y - b.y)
        },
    }"
    x-on:open-lightbox.window="open($event.detail)"
    x-on:keydown.escape.window="isOpen && close()"
    x-on:keydown.arrow-left.window="isOpen && go(-1)"
    x-on:keydown.arrow-right.window="isOpen && go(1)"
>
    <template x-if="isOpen">
        <div class="fi-lightbox" role="dialog" aria-modal="true">
            {{-- The stage closes on a click, but only one that did not pan the
                 image — otherwise letting go of a drag would dismiss it. --}}
            <div
                class="fi-lightbox-stage"
                x-on:wheel.prevent="onWheel($event)"
                x-on:dblclick.prevent="onDoubleClick($event)"
                x-on:pointerdown.prevent="onPointerDown($event)"
                x-on:pointermove.prevent="onPointerMove($event)"
                x-on:pointerup="onPointerUp($event)"
                x-on:pointercancel="onPointerUp($event)"
                x-on:click.self="! isZoomed && close()"
            >
                <img
                    class="fi-lightbox-image"
                    x-bind:src="item?.url"
                    x-bind:alt="item?.alt"
                    x-bind:style="`transform: translate(${x}px, ${y}px) scale(${scale})`"
                    draggable="false"
                />
            </div>

            <div class="fi-lightbox-bar fi-lightbox-bar-top">
                <span class="fi-lightbox-count" x-show="items.length > 1">
                    <span x-text="index + 1"></span> / <span x-text="items.length"></span>
                </span>
                <span x-show="items.length <= 1"></span>

                <button type="button" class="fi-lightbox-btn" x-on:click="close()" aria-label="{{ __('Tutup') }}">
                    &times;
                </button>
            </div>

            <div class="fi-lightbox-bar fi-lightbox-bar-bottom">
                <button
                    type="button"
                    class="fi-lightbox-btn"
                    x-show="items.length > 1"
                    x-bind:disabled="index === 0"
                    x-on:click="go(-1)"
                    aria-label="Sebelumnya"
                >
                    &lsaquo;
                </button>

                <button type="button" class="fi-lightbox-btn" x-on:click="zoomTo(scale - 0.5, 0, 0)" aria-label="Perkecil">
                    &minus;
                </button>

                <button type="button" class="fi-lightbox-btn" x-on:click="reset()">
                    <span x-text="Math.round(scale * 100) + '%'"></span>
                </button>

                <button type="button" class="fi-lightbox-btn" x-on:click="zoomTo(scale + 0.5, 0, 0)" aria-label="Perbesar">
                    +
                </button>

                <button
                    type="button"
                    class="fi-lightbox-btn"
                    x-show="items.length > 1"
                    x-bind:disabled="index === items.length - 1"
                    x-on:click="go(1)"
                    aria-label="Berikutnya"
                >
                    &rsaquo;
                </button>
            </div>
        </div>
    </template>
</div>

<script>
    // Delegated from the document rather than bound per image: Livewire swaps
    // the entry's DOM on every re-render, so a listener attached to the links
    // themselves would survive the first update and not the second.
    document.addEventListener('click', (event) => {
        const link = event.target.closest('[data-lightbox] a')

        if (! link) {
            return
        }

        // A modifier means the reader asked for a tab, not a viewer.
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return
        }

        event.preventDefault()

        const links = [...link.closest('[data-lightbox]').querySelectorAll('a')]

        window.dispatchEvent(new CustomEvent('open-lightbox', {
            detail: {
                index: links.indexOf(link),
                items: links.map((anchor) => ({
                    url: anchor.getAttribute('href'),
                    alt: anchor.querySelector('img')?.getAttribute('alt') ?? '',
                })),
            },
        }))
    })
</script>
