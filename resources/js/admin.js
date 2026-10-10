// Full profile: specialized runtime plus shared operational controls.
import './admin-editor-runtime.js';
import './admin-common.js';

import { initTitleEditors, initLinkComboboxes } from './editor-controls.js';

initTitleEditors();
initLinkComboboxes();

// Rich text is a Full interface capability, even on shared settings screens.
if (document.querySelector('[data-rich-text]')) {
    import('./rich-text.js').then((module) => module.initRichText());
}
