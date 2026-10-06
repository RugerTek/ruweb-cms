// This function is serialized into srcdoc before any public script executes.
// Keep it self-contained so every preview refresh installs a fresh guard.
function guardPreviewNavigation() {
    const clean = () => document.querySelectorAll('a,area').forEach(link => {
        for (const attribute of ['href', 'target', 'onclick', 'tabindex']) {
            link.removeAttribute(attribute);
        }
        link.setAttribute('aria-disabled', 'true');
        link.style.cursor = 'default';
    });

    new MutationObserver(clean).observe(document, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['href', 'target', 'onclick', 'tabindex'],
    });

    for (const type of ['click', 'auxclick', 'keydown']) {
        window.addEventListener(type, event => {
            const link = event.target.closest?.('a,area');
            if (link && (type !== 'keydown' || ['Enter', ' '].includes(event.key))) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }, true);
    }

    // Forms are visible for inspection, but submitting would leave the preview.
    window.addEventListener('submit', event => {
        event.preventDefault();
        event.stopImmediatePropagation();
    }, true);
    window.open = () => null;
    window.navigation?.addEventListener('navigate', event => {
        // Replacing srcdoc is the editor's normal refresh, not public navigation.
        if (event.cancelable && event.destination.url !== 'about:srcdoc') {
            event.preventDefault();
        }
    });
    clean();
}

export function prepareEditorPreview(html) {
    const guard = `<script>(${guardPreviewNavigation.toString()})();<\/script>`;
    return /<head[\s>]/i.test(html)
        ? html.replace(/<head([^>]*)>/i, '<head$1>' + guard)
        : guard + html;
}
