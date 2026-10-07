{{--
    Command palette (§18.2). Server-renders the full permitted index into
    window.erpNavIndex so ⌘K works instantly and offline of any API round-trip
    — and so the curated sidebar never withholds a capability: every deep page
    the user may open is one keystroke away.
--}}
<div class="erp-palette" id="erpPalette" hidden role="dialog" aria-modal="true" aria-label="Command palette">
    <div class="erp-palette-panel">
        <div class="erp-palette-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <label class="erp-visually-hidden" for="erpPaletteInput">Search the workspace</label>
            <input class="erp-palette-input" id="erpPaletteInput" type="text" autocomplete="off" spellcheck="false"
                   placeholder="Search pages, reports, and actions…" data-palette-input>
            <kbd>esc</kbd>
        </div>

        <div class="erp-palette-list" data-palette-list role="listbox" aria-label="Results"></div>

        <div class="erp-palette-foot">
            <span><kbd>↑</kbd><kbd>↓</kbd> navigate</span>
            <span><kbd>↵</kbd> open</span>
            <span class="ms-auto">{{ count($entries) }} destinations available to you</span>
        </div>
    </div>
</div>
