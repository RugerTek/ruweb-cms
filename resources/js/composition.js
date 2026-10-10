/** The registered accordion uses native details for keyboard and no-script support. */
export function initComposition(root = document) {
    root.querySelectorAll('[data-cms-accordion]').forEach(accordion => {
        if (accordion.dataset.initialized) return;
        accordion.dataset.initialized = 'true';
        const items = [...accordion.querySelectorAll('details')];
        items.forEach(item => item.addEventListener('toggle', () => {
            if (item.open) items.forEach(other => { if (other !== item) other.open = false; });
        }));
    });
}
