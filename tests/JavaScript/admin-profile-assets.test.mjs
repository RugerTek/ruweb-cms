import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// Follow both static and lazy local imports: an Essential settings screen must
// never be able to fetch the specialized editor or TinyMCE chunks.
function dependencies(entry, visited = new Set()) {
    if (visited.has(entry.href)) return visited;
    visited.add(entry.href);
    const source = readFileSync(entry, 'utf8');
    const imports = source.matchAll(/(?:\bfrom\s*|\bimport\s*\(?\s*)['"]([^'"]+)['"]/g);
    for (const [, path] of imports) {
        if (path.startsWith('.') && path.endsWith('.js')) {
            dependencies(new URL(path, entry), visited);
        } else if (!path.startsWith('.')) {
            visited.add(path);
        }
    }
    return visited;
}

test('Essential operational dependency graph excludes every specialized editor and TinyMCE', () => {
    const graph = [...dependencies(new URL('../../resources/js/admin-essential.js', import.meta.url))];
    for (const dependency of graph) {
        assert.doesNotMatch(dependency, /(?:editor-(?:engine|controls|preview|recovery|runtime)|admin-editor-runtime|rich-text|tinymce)/);
    }
    assert.ok(graph.some(path => path.endsWith('/media-metadata.js')));
    assert.ok(graph.some(path => path.endsWith('/map-picker.js')));
});

test('Full retains rich text and visual editor controls', () => {
    const graph = [...dependencies(new URL('../../resources/js/admin.js', import.meta.url))];
    for (const module of ['admin-editor-runtime.js', 'editor-controls.js', 'rich-text.js']) {
        assert.ok(graph.some(path => path.endsWith('/' + module)), module);
    }
});
