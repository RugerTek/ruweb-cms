export async function refreshRecoveryMedia(selector, input, render, fetchItem) {
    const id=String(input?.value || '');
    const sequence=Number(selector.dataset.previewSequence || 0)+1;
    selector.dataset.previewSequence=String(sequence);
    render(selector,null);
    if (input) input.value=id;
    if (!id) return;
    try {
        const media=await fetchItem(id);
        if (selector.dataset.previewSequence!==String(sequence) || String(input.value)!==id) return;
        render(selector,media); input.value=id;
    } catch (_) {}
}
