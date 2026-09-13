/**
 * Rich-text fields powered by TinyMCE.
 *
 * TinyMCE ships as a project dependency and is bundled through Vite: no remote
 * script, no API key. Every instance is scoped by a named preset so it exposes
 * only the formatting its public component renders.
 */
import tinymce from 'tinymce/tinymce';

// Core runtime.
import 'tinymce/models/dom';
import 'tinymce/themes/silver';
import 'tinymce/icons/default';

// Plugins referenced by the presets.
import 'tinymce/plugins/link';
import 'tinymce/plugins/autolink';
import 'tinymce/plugins/lists';
import 'tinymce/plugins/image';
import 'tinymce/plugins/media';
import 'tinymce/plugins/table';
import 'tinymce/plugins/code';
import 'tinymce/plugins/codesample';
import 'tinymce/plugins/searchreplace';
import 'tinymce/plugins/fullscreen';
import 'tinymce/plugins/wordcount';
import 'tinymce/plugins/quickbars';
import 'tinymce/plugins/autoresize';
import 'tinymce/plugins/visualblocks';
import 'tinymce/plugins/charmap';
import 'tinymce/plugins/anchor';

// Skin and editable-area styles, inlined so no asset paths need publishing.
import skinCss from 'tinymce/skins/ui/oxide-dark/skin.min.css?inline';
import uiContentCss from 'tinymce/skins/ui/oxide-dark/content.min.css?inline';
import bodyContentCss from 'tinymce/skins/content/dark/content.min.css?inline';

import { resolvePreset } from './tinymce-presets.js';

let skinInjected = false;

function injectSkin() {
    if (skinInjected) {
        return;
    }
    const style = document.createElement('style');
    style.dataset.tinymceSkin = 'oxide-dark';
    style.textContent = skinCss;
    document.head.appendChild(style);
    skinInjected = true;
}

// Matches the public `prose` rendering closely enough that the editor is a
// faithful preview of the article rather than a generic word processor.
const EDITOR_BODY_CSS = `
    body {
        background: #141414;
        color: #e5e7eb;
        font-family: Inter, ui-sans-serif, system-ui, sans-serif;
        font-size: 15px;
        line-height: 1.7;
        padding: 1.25rem 1.5rem;
        max-width: 46rem;
        margin: 0 auto;
    }
    h2, h3, h4 { color: #fff; font-weight: 600; line-height: 1.3; margin: 1.8em 0 .6em; }
    h2 { font-size: 1.6em; }
    h3 { font-size: 1.3em; }
    h4 { font-size: 1.1em; }
    p { margin: 0 0 1.1em; }
    a { color: #38bdf8; }
    blockquote {
        margin: 1.5em 0;
        padding: .25em 0 .25em 1.1em;
        border-left: 3px solid #0284c7;
        color: #cbd5e1;
        font-style: italic;
    }
    ul, ol { padding-left: 1.4em; margin: 0 0 1.1em; }
    li { margin: .35em 0; }
    hr { border: none; border-top: 1px solid rgba(255,255,255,.12); margin: 2em 0; }
    img { max-width: 100%; height: auto; border-radius: .5rem; }
    figure { margin: 1.5em 0; }
    figcaption { color: #94a3b8; font-size: .85em; text-align: center; margin-top: .5em; }
    table { border-collapse: collapse; width: 100%; margin: 1.5em 0; }
    th, td { border: 1px solid rgba(255,255,255,.12); padding: .5em .7em; }
    th { background: rgba(255,255,255,.04); }
    pre { background: #0f0f0f; border: 1px solid rgba(255,255,255,.08); border-radius: .5rem; padding: 1em; overflow-x: auto; }
    code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .9em; }
`;

/**
 * Open the shared Media Library and hand the chosen file back to TinyMCE, so
 * rich text never asks the client for a URL or exposes storage paths.
 */
function mediaLibraryFilePicker(callback, value, meta) {
    if (typeof window.openMediaLibrary !== 'function') {
        return;
    }

    window.openMediaLibrary({
        type: meta.filetype === 'image' ? 'image' : '',
        onSelect: (media) => {
            if (meta.filetype === 'image') {
                callback(media.url, {
                    alt: media.alt || media.title || '',
                    title: media.title || '',
                });
                return;
            }

            callback(media.url, { text: media.title || media.original_filename });
        },
    });
}

export function initRichText(root = document) {
    const fields = Array.from(root.querySelectorAll('[data-rich-text]'));

    if (fields.length === 0) {
        return;
    }

    injectSkin();

    fields.forEach((field) => {
        if (field.dataset.richTextReady === '1') {
            return;
        }
        field.dataset.richTextReady = '1';

        const preset = resolvePreset(field.dataset.richText);

        tinymce.init({
            target: field,
            // Self-hosted open-source build: no cloud account involved.
            license_key: 'gpl',
            skin: false,
            content_css: false,
            content_style: [uiContentCss, bodyContentCss, EDITOR_BODY_CSS].join('\n'),
            menubar: false,
            branding: false,
            promotion: false,
            resize: false,
            browser_spellcheck: true,
            contextmenu: 'link image table',
            convert_urls: false,
            file_picker_types: 'image media',
            file_picker_callback: mediaLibraryFilePicker,
            // Images always come from the library, never from pasted binaries.
            paste_data_images: false,
            automatic_uploads: false,
            image_advtab: false,
            image_description: true,
            image_dimensions: false,
            image_class_list: [{ title: 'Default', value: '' }],
            link_default_target: '',
            link_title: false,
            target_list: [
                { title: 'Same tab', value: '' },
                { title: 'New tab', value: '_blank' },
            ],
            ...preset,
            setup: (editor) => {
                // Replaces TinyMCE's URL-first image dialog: the button opens the
                // shared Media Library and inserts the chosen file straight away.
                editor.ui.registry.addButton('mediaimage', {
                    icon: 'image',
                    tooltip: editor.translate('Insert image'),
                    onAction: () => {
                        if (typeof window.openMediaLibrary !== 'function') {
                            return;
                        }

                        window.openMediaLibrary({
                            type: 'image',
                            onSelect: (media) => {
                                const alt = (media.alt || media.title || '').replace(/"/g, '&quot;');
                                const caption = media.title && media.title !== media.original_filename
                                    ? `<figcaption>${editor.dom.encode(media.title)}</figcaption>`
                                    : '';

                                editor.insertContent(
                                    `<figure class="post-image">` +
                                    `<img src="${media.url}" alt="${alt}" loading="lazy" decoding="async" data-media-id="${media.id}">` +
                                    `${caption}</figure><p></p>`
                                );
                                editor.focus();
                            },
                        });
                    },
                });

                // Keep the textarea authoritative so dirty state, preview refresh,
                // autosave and validation behave exactly as with a native control.
                const sync = () => {
                    editor.save();
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                };

                editor.on('Change KeyUp Undo Redo SetContent ExecCommand', sync);
                editor.on('blur', sync);
            },
        });
    });
}

window.initRichText = initRichText;
