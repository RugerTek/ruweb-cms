import { installRepeaterRecovery } from './editor-recovery.js';
import { initEditorEngine } from './editor-engine.js';
import { initEditorResize } from './editor-controls.js';
import { initEditorFeedback } from './editor-feedback.js';

window.installRepeaterRecovery = installRepeaterRecovery;
// The bundler can evaluate shared UI first; attach all its pending repeaters now.
for (const args of window.pendingEditorRepeaterRecovery || []) installRepeaterRecovery(...args);
delete window.pendingEditorRepeaterRecovery;
window.initEditorEngine = (config) => {
    window.editorFeedback ||= initEditorFeedback(document.getElementById('editor-form'));
    initEditorEngine(config);
};
initEditorResize();
if (document.getElementById('editor-form') && !document.getElementById('preview-frame')) {
    window.editorFeedback = initEditorFeedback(document.getElementById('editor-form'));
}
