/*
 * ProxApps UI behaviour. Alpine comes with the standard Livewire bundle, so everything
 * here is registered on 'alpine:init' (this module runs before Livewire starts Alpine).
 */

// ---- theme toggle (same behaviour as DokuVault) ---------------------------------
function initThemeToggle() {
    const d = document.getElementById('theme-toggle-dark-icon');
    const l = document.getElementById('theme-toggle-light-icon');
    const btn = document.getElementById('theme-toggle');
    if (!btn || !d || !l || btn.dataset.ready) return;
    btn.dataset.ready = '1';
    if (document.documentElement.classList.contains('dark')) l.classList.remove('hidden');
    else d.classList.remove('hidden');
    btn.addEventListener('click', () => {
        d.classList.toggle('hidden');
        l.classList.toggle('hidden');
        const isDark = document.documentElement.classList.toggle('dark');
        try { localStorage.setItem('color-theme', isDark ? 'dark' : 'light'); } catch (e) { /* ignore */ }
    });
}
document.addEventListener('DOMContentLoaded', initThemeToggle);

async function copyToClipboard(value) {
    try {
        await navigator.clipboard.writeText(value);
    } catch (e) {
        const ta = document.createElement('textarea');
        ta.value = value;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
    }
}

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    // ---- page layout: desktop collapse + mobile drawer -----------------------------
    Alpine.data('seitenlayout', () => ({
        menuEin: (() => { try { return localStorage.getItem('menu-eingeklappt') === '1'; } catch (e) { return false; } })(),
        mobilOffen: false,
        menuUmschalten() {
            this.menuEin = !this.menuEin;
            try { localStorage.setItem('menu-eingeklappt', this.menuEin ? '1' : '0'); } catch (e) { /* ignore */ }
        },
        mobilUmschalten(state) {
            this.mobilOffen = state === undefined ? !this.mobilOffen : state;
            document.body.classList.toggle('overflow-hidden', this.mobilOffen);
        },
    }));

    // ---- copy to clipboard: $kopieren(text) sets `copied` on the surrounding x-data ----
    Alpine.magic('kopieren', (el) => async (value) => {
        await copyToClipboard(String(value ?? ''));
        const data = Alpine.$data(el);
        if (data && 'copied' in data) {
            data.copied = true;
            setTimeout(() => { data.copied = false; }, 1500);
        }
    });

    // ---- focus helper used by dialogs ---------------------------------------------------
    Alpine.magic('fokus', () => (target) => setTimeout(() => target?.focus(), 30));

    // ---- confirm dialog (DokuVault "Löschdialog", one instance in the layout) -----------
    // $store.confirm.ask({ title, text, button, tone: 'red'|'blue', ok: () => $wire.delete(1) })
    // or on a classic form: <form data-confirm="Text" data-confirm-title="…" data-confirm-button="…">
    Alpine.store('confirm', {
        open: false, title: '', text: '', button: 'Löschen', tone: 'red', onOk: null,
        ask(opts) {
            this.title = opts.title || 'Wirklich fortfahren?';
            this.text = opts.text || '';
            this.button = opts.button || 'Bestätigen';
            this.tone = opts.tone || 'red';
            this.onOk = opts.ok || null;
            this.open = true;
            setTimeout(() => document.getElementById('confirm-cancel')?.focus(), 30);
        },
        ok() {
            const fn = this.onOk;
            this.open = false;
            this.onOk = null;
            if (fn) fn();
        },
        cancel() { this.open = false; this.onOk = null; },
    });
    document.addEventListener('submit', (ev) => {
        const form = ev.target;
        if (form.dataset && form.dataset.confirm !== undefined && form.dataset.confirmed !== '1') {
            ev.preventDefault();
            Alpine.store('confirm').ask({
                title: form.dataset.confirmTitle,
                text: form.dataset.confirm,
                button: form.dataset.confirmButton,
                tone: form.dataset.confirmTone,
                ok: () => { form.dataset.confirmed = '1'; form.requestSubmit(); },
            });
        }
    }, true);

    // ---- toasts: session flash on load + Livewire `notice` events ------------------------
    // success hides after 4 s, errors stay until closed.
    Alpine.data('toast', (kind, initial) => ({
        kind,
        show: false,
        text: '',
        timer: null,
        init() {
            if (initial) this.zeigen(initial);
        },
        empfangen(detail) {
            const d = Array.isArray(detail) ? detail[0] : detail;
            if (!d || (d.kind || 'success') !== this.kind) return;
            this.zeigen(d.text);
        },
        zeigen(text) {
            this.text = text;
            this.show = true;
            clearTimeout(this.timer);
            if (this.kind === 'success') this.timer = setTimeout(() => { this.show = false; }, 4000);
        },
    }));

    // ---- live deployment log console --------------------------------------------------------
    Alpine.data('deploymentLog', (initial) => ({
        lines: initial || [],
        autoscroll: true,
        init() {
            this.$nextTick(() => this.scrollDown());
        },
        append(lines) {
            if (!Array.isArray(lines) || !lines.length) return;
            const lastId = this.lines.length ? this.lines[this.lines.length - 1].id : 0;
            for (const l of lines) if (l.id > lastId) this.lines.push(l);
            if (this.lines.length > 5000) this.lines.splice(0, this.lines.length - 5000);
            if (this.autoscroll) this.$nextTick(() => this.scrollDown());
        },
        scrollDown() {
            const c = this.$refs.console;
            if (c) c.scrollTop = c.scrollHeight;
        },
        onScroll() {
            const c = this.$refs.console;
            this.autoscroll = c.scrollHeight - c.scrollTop - c.clientHeight < 40;
        },
        lineClass(l) {
            return {
                step: 'text-cerulean-300 font-semibold pt-2',
                error: 'text-red-400 font-semibold',
                success: 'text-emerald-400 font-semibold',
                task: 'text-gray-400',
                out: 'text-gray-200',
            }[l.level] || 'text-gray-300';
        },
    }));
});
