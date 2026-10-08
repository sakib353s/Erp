{{--
    Command palette (§18.2). Server-renders the full permitted index into
    window.erpNavIndex so ⌘K works instantly and offline of any API round-trip
    — and so the curated sidebar never withholds a capability: every deep page
    the user may open is one keystroke away.

    §16-49 gives it a second half: pages are matched from that in-page index,
    while business records (a document number, a barcode, a phone number) are
    looked up server-side through /search/quick, which filters by the reader's
    own branch and permissions. Records therefore arrive a moment after the
    page matches do, and only ever ones the reader may open.
--}}
<div class="erp-palette" id="erpPalette" hidden role="dialog" aria-modal="true" aria-label="Command palette"
     data-quick-url="{{ route('search.quick', [], false) }}">
    <div class="erp-palette-panel">
        <div class="erp-palette-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <label class="erp-visually-hidden" for="erpPaletteInput">Search the workspace</label>
            <input class="erp-palette-input" id="erpPaletteInput" type="text" autocomplete="off" spellcheck="false"
                   placeholder="Search records, pages, reports, and actions…" data-palette-input>
            <kbd>esc</kbd>
        </div>

        <div class="erp-palette-list" data-palette-list role="listbox" aria-label="Results"></div>

        <div class="erp-palette-foot">
            <span><kbd>↑</kbd><kbd>↓</kbd> navigate</span>
            <span><kbd>↵</kbd> open</span>
            <span class="ms-auto" data-palette-status aria-live="polite">{{ count($entries) }} destinations available to you</span>
        </div>
    </div>
</div>
