/**
 * Lightweight WYSIWYG for terms HTML (headings, colors, basic formatting).
 * Uses document.execCommand so it works without a TipTap/Quill dependency.
 * Pair with Alpine x-modelable + Livewire wire:model.
 */
export function initializeHtmlEditor() {
    window.Alpine.data('htmlEditor', () => ({
        html: '',
        color: '#111827',
        init() {
            this.$nextTick(() => {
                if (this.$refs.surface && this.html) {
                    this.$refs.surface.innerHTML = this.html;
                }
            });

            this.$watch('html', (value) => {
                if (! this.$refs.surface || document.activeElement === this.$refs.surface) {
                    return;
                }
                const next = value || '';
                if (this.$refs.surface.innerHTML !== next) {
                    this.$refs.surface.innerHTML = next;
                }
            });
        },
        sync() {
            this.html = this.$refs.surface?.innerHTML ?? '';
        },
        command(name, value = null) {
            this.$refs.surface?.focus();
            document.execCommand(name, false, value);
            this.sync();
        },
        block(tag) {
            this.command('formatBlock', tag);
        },
        applyColor() {
            this.command('foreColor', this.color);
        },
        createLink() {
            const url = window.prompt('URL');
            if (! url) {
                return;
            }
            this.command('createLink', url);
        },
    }));
}
