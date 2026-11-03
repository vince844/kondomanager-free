// @vitest-environment jsdom

/**
 * Coda 404 — il caso della Coda, dal modulo alla modifica salvata: una pagina con un `useForm`, il router di Inertia
 * vero, la finestra della password vera.
 *
 * Il 27/10/2026 sulla demo: «Modifica ruolo» con tre permessi in più, la password scaduta, la conferma, e il ruolo
 * restava com'era. Qui lo stesso percorso: il modulo compilato → la PUT riceve il 423 → la finestra → la password →
 * la stessa PUT, con gli stessi dati → `onSuccess` una volta, il modulo ancora compilato.
 *
 * Il client HTTP è finto e risponde come il server (`ConfermaPasswordRecente`); il resto è quello dell'applicazione:
 * `conConferma` (lo stesso che monta `app.ts`), `useForm`, `router`, `FinestraConfermaPassword`.
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { HttpResponseError, http, router, type HttpRequestConfig, type HttpResponse } from '@inertiajs/core';
import { useForm } from '@inertiajs/vue3';

const post = vi.fn();

vi.mock('axios', () => ({ default: { post } }));
vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string, quanti: number) => `${chiave} ${quanti}`,
}));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const { annullaConferma } = await import('@/composables/useConfermaPassword');
const { conConferma } = await import('./clientHttpConConferma');
const FinestraConfermaPassword = (await import('@/components/FinestraConfermaPassword.vue')).default;

const richieste: HttpRequestConfig[] = [];
let risposte: Array<() => HttpResponse | HttpResponseError> = [];

const chiedeLaPassword = (): HttpResponseError =>
    new HttpResponseError('423', { status: 423, headers: {}, data: JSON.stringify({ message: 'conferma', conferma_password: true }) } as HttpResponse);
const riuscita = (): HttpResponse => ({
    status: 200,
    headers: { 'x-inertia': 'true' },
    data: JSON.stringify({ component: 'ruoli/ElencoRuoli', props: { errors: {} }, url: '/ruoli', version: '1', clearHistory: false, encryptHistory: false }),
});

const clientDelServer = {
    request: async (config: HttpRequestConfig): Promise<HttpResponse> => {
        richieste.push(config);
        const risposta = risposte.shift()!();
        if (risposta instanceof HttpResponseError) throw risposta;
        return risposta;
    },
};

const MODULO = { name: "Amministratore D'Àngelo", description: 'Demo «ordinaria»', permissions: [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15], accessAdmin: true };
const TRE_IN_PIU = [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18];

let onSuccess: ReturnType<typeof vi.fn>;
let form: any;
let montata: VueWrapper[] = [];

// La pagina di «Modifica ruolo» in piccolo: lo stesso `useForm(...).transform(...).put(...)` di `ModificaRuolo.vue`.
const PaginaDelRuolo = defineComponent({
    setup() {
        form = useForm({ ...MODULO });

        const salva = () =>
            form
                .transform((dati) => ({ ...dati, accessAdmin: Boolean(dati.accessAdmin) }))
                .put('/ruoli/5', { preserveScroll: true, onSuccess });

        return () => h('button', { id: 'salva', disabled: form.processing, onClick: salva }, 'Salva');
    },
});

const dialogo = () => document.body.querySelector('[role="dialog"]') as HTMLElement | null;
const campo = () => document.body.querySelector('input[type="password"]') as HTMLInputElement;
const pulsante = (testo: string) =>
    [...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === testo) as HTMLButtonElement;

async function scrivi(password: string) {
    campo().value = password;
    campo().dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

const attendi = () => new Promise((risolvi) => setTimeout(risolvi, 20));

async function premiSalva() {
    document.getElementById('salva')!.click();
    await attendi();
    await flushPromises();
}

beforeEach(async () => {
    richieste.length = 0;
    risposte = [];
    post.mockReset();
    annullaConferma();
    onSuccess = vi.fn();
    (HTMLDialogElement.prototype as unknown as { showModal: () => void }).showModal = vi.fn();
    http.setClient(conConferma(clientDelServer));
    await router.init({
        initialPage: { component: 'ruoli/ModificaRuolo', props: { errors: {} }, url: '/ruoli/5/edit', version: '1', clearHistory: false, encryptHistory: false } as never,
        resolveComponent: async () => ({}),
        swapComponent: async () => {},
    } as never);
    montata = [mount(PaginaDelRuolo, { attachTo: document.body }), mount(FinestraConfermaPassword, { attachTo: document.body })];
});

afterEach(() => {
    montata.forEach((m) => m.unmount());
    document.body.innerHTML = '';
});

describe('la conferma della password, dal modulo alla modifica salvata', () => {
    test('Coda 404 — il caso della demo: 423, finestra, password, la stessa PUT con i permessi scritti, onSuccess una volta', async () => {
        risposte = [chiedeLaPassword, riuscita];
        form.permissions = [...TRE_IN_PIU];

        await premiSalva();

        // La PUT è ferma sul 423: la finestra è aperta, il modulo sta ancora lavorando e conserva quello che è stato scritto.
        expect(richieste).toHaveLength(1);
        expect(richieste[0].method).toBe('put');
        expect(dialogo()).not.toBeNull();
        expect(form.processing).toBe(true);
        expect(form.permissions).toEqual(TRE_IN_PIU);
        expect(onSuccess).not.toHaveBeenCalled();

        post.mockResolvedValueOnce({ status: 200, data: { confermata: true } });
        await scrivi('la-mia-password');
        pulsante('auth.confirm_password_dialog.confirm').click();
        await attendi();
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/password.confirm', { password: 'la-mia-password' }, { headers: { Accept: 'application/json' } });
        // La stessa PUT, con gli stessi dati: i quindici permessi, non i dodici di prima.
        expect(richieste).toHaveLength(2);
        expect(richieste[1]).toBe(richieste[0]);
        expect(richieste[1].data).toEqual({ ...MODULO, permissions: TRE_IN_PIU });
        expect(onSuccess).toHaveBeenCalledTimes(1);
        expect(form.processing).toBe(false);
        expect(form.hasErrors).toBe(false);
        expect(dialogo()).toBeNull();
    });

    test('Coda 404 — una password sbagliata tiene aperta la finestra e non rifà la PUT; poi la giusta salva', async () => {
        risposte = [chiedeLaPassword, riuscita];
        form.permissions = [...TRE_IN_PIU];
        await premiSalva();

        post.mockRejectedValueOnce({ response: { status: 422, headers: {}, data: { errors: { password: ['La password non è corretta.'] } } } });
        await scrivi('sbagliata');
        pulsante('auth.confirm_password_dialog.confirm').click();
        await attendi();
        await flushPromises();

        expect(dialogo()!.textContent).toContain('La password non è corretta.');
        expect(richieste).toHaveLength(1);
        expect(form.processing).toBe(true);

        post.mockResolvedValueOnce({ status: 200, data: { confermata: true } });
        await scrivi('giusta');
        pulsante('auth.confirm_password_dialog.confirm').click();
        await attendi();
        await flushPromises();

        expect(richieste).toHaveLength(2);
        expect(onSuccess).toHaveBeenCalledTimes(1);
        expect(form.processing).toBe(false);
    });

    test('Coda 404 — «Annulla» non salva niente e non perde il modulo: processing torna falso, né onSuccess né errori, i dati restano', async () => {
        risposte = [chiedeLaPassword, riuscita];
        form.permissions = [...TRE_IN_PIU];
        await premiSalva();

        pulsante('auth.confirm_password_dialog.cancel').click();
        await attendi();
        await flushPromises();

        expect(richieste).toHaveLength(1);
        expect(onSuccess).not.toHaveBeenCalled();
        expect(form.processing).toBe(false);
        expect(form.hasErrors).toBe(false);
        expect(form.permissions).toEqual(TRE_IN_PIU);
        expect(form.name).toBe(MODULO.name);
        expect(dialogo()).toBeNull();

        // Il modulo si può inviare di nuovo: la finestra torna a chiedere la password.
        risposte = [chiedeLaPassword];
        await premiSalva();
        expect(dialogo()).not.toBeNull();
    });

    test('Coda 404 — con la conferma ancora valida la PUT passa al primo colpo, senza finestra', async () => {
        risposte = [riuscita];

        await premiSalva();

        expect(richieste).toHaveLength(1);
        expect(onSuccess).toHaveBeenCalledTimes(1);
        expect(dialogo()).toBeNull();
    });
});

describe('il collegamento in app.ts', () => {
    // `app.ts` avvia l'applicazione e non si può importare in un test: si controlla che le due righe che la legano
    // alla conferma della password ci siano, perché senza nessuna delle due il 423 tornerebbe a essere un errore grezzo.
    const app = readFileSync(resolve(process.cwd(), 'resources/js/app.ts'), 'utf8');

    test('Coda 404 — il client HTTP dell\'applicazione è quello che chiede la password e rifà la richiesta', () => {
        expect(app).toMatch(/createInertiaApp\(\{[^]*?\bhttp:\s*conConferma\(http\.getClient\(\)\)/);
    });

    test('Coda 404 — la finestra della password è montata accanto alla pagina, alla radice', () => {
        expect(app).toMatch(/render:\s*\(\)\s*=>\s*\[h\(App, props\),\s*h\(FinestraConfermaPassword\)\]/);
    });
});
