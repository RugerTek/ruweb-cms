// Native details provides click, touch and keyboard opening without the admin bundle.
// Close the creation menu when focus leaves it, outside clicks or Escape.
export function initAdminBar() {
    document.querySelectorAll('[data-admin-bar-create]').forEach(menu => {
        document.addEventListener('click', event => {
            if (!menu.contains(event.target)) menu.open = false;
        });
        menu.addEventListener('focusout', event => {
            if (!menu.contains(event.relatedTarget)) menu.open = false;
        });
        menu.addEventListener('keydown', event => {
            if (event.key === 'Escape' && menu.open) {
                event.preventDefault();
                menu.open = false;
                menu.querySelector('summary').focus();
            }
        });
    });
}
