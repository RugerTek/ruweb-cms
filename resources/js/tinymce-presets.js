/**
 * Named TinyMCE presets, shared across every rich-text field in the admin.
 *
 * Each preset is scoped to the public component it feeds: it exposes only the
 * formatting the design actually renders, and nothing else. Reuse a preset rather
 * than hand-tuning a new toolbar, so formatting stays consistent across projects.
 */

// Running text with no block structure: hero and section descriptions.
const shortDescription = {
    toolbar: 'bold italic link | removeformat',
    plugins: 'link autolink',
    valid_elements: 'strong/b,em/i,a[href|title|target|rel],br',
    block_formats: 'Paragraph=p',
    height: 160,
    statusbar: false,
};

// Full article body: headings, lists, quotes, media and code.
const postBody = {
    // A single toolbar string is required for sliding mode: an array would force
    // one fixed row per entry and stack four rows inside the narrow panel.
    // Most-used actions come first, the rest slide behind the "more" button.
    toolbar:
        'blocks | bold italic | link mediaimage | bullist numlist | blockquote | '
        + 'align | hr table media codesample charmap | removeformat | '
        + 'undo redo | searchreplace fullscreen',
    plugins: 'link autolink lists image media table code codesample searchreplace fullscreen wordcount quickbars autoresize visualblocks charmap anchor',
    block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4',
    valid_elements:
        'p[style],h2,h3,h4,strong/b,em/i,u,s,br,hr,' +
        'a[href|title|target|rel],ul,ol,li,blockquote[cite],' +
        'figure[class],figcaption,img[src|alt|title|width|height|loading|decoding|class|data-media-id],' +
        'iframe[src|width|height|allow|allowfullscreen|title|frameborder],' +
        'table[class],thead,tbody,tfoot,tr,th[colspan|rowspan|scope],td[colspan|rowspan],' +
        'pre[class],code[class]',
    statusbar: true,
    // The editor lives in a ~384px panel, so the toolbar collapses behind a
    // "more" button instead of stacking into four rows.
    toolbar_mode: 'sliding',
    min_height: 420,
    autoresize_bottom_margin: 24,
    quickbars_selection_toolbar: 'bold italic link blockquote',
    quickbars_insert_toolbar: false,
};

export const TINYMCE_PRESETS = {
    'short-description': shortDescription,
    'post-body': postBody,
};

export function resolvePreset(name) {
    return TINYMCE_PRESETS[name] || TINYMCE_PRESETS['short-description'];
}
