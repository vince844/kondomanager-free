// @vitest-environment jsdom

/**
 * Le impostazioni delle stampe: lo studio in testa alle stampe, con la partita IVA, il codice fiscale, il sito e il logo accanto ai dati di prima.
 *
 * Aperta e mandata senza toccare niente, la pagina rimanda i dati come li ha ricevuti, anche con i caratteri scomodi (le virgolette, la «e»
 * commerciale, il minore, gli spazi doppi della nota legale): nessun file, nessuna richiesta di togliere la firma o il logo. «Rimuovi» del logo
 * chiede di toglierlo.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';
import { router } from '@inertiajs/core';

// La pagina di Inertia: un oggetto reattivo, le cui props dopo una richiesta sono un oggetto nuovo (la pagina resta montata).
const pagina = vi.hoisted(() => ({ value: { props: {} as Record<string, unknown> } }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => pagina.value,
}));
vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const ImpostazioniStampe = (await import('./impostazioniStampe.vue')).default;

const studio = {
    nome: 'Studio «Bianchi» & Figli <s.r.l.>',
    indirizzo: "Via dell'Ateneo 1/A, 20121 Milano",
    email: 'studio@bianchi.it',
    pec: 'studio@pec.bianchi.it',
    telefono: '02  1234567',
    comune: '',
    partita_iva: '01234567890',
    codice_fiscale: 'BNCLRA80A01F205X',
    sito: 'www.studiobianchi.it',
};

/** Le props della pagina come le manda il server. */
function props(altro: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        flash: {},
        nota_legale_stampe: 'Riga uno\nRiga  due, con due spazi',
        firma_stampe_url: '/storage/settings/signatures/firma.png',
        logo_stampe_url: '/storage/settings/logo/logo.png',
        limiteFirma: '2 MB',
        studio: { ...studio },
        firma_da_ricaricare: null,
        ...altro,
    };
}

/** Dopo una richiesta, come fa Inertia: le props nuove al posto delle vecchie, la pagina resta montata. */
async function arrivano(altro: Record<string, unknown>) {
    pagina.value.props = props(altro);
    await nextTick();
}

function monta(altro: Record<string, unknown> = {}) {
    pagina.value = reactive({ props: props(altro) });

    return shallowMount(ImpostazioniStampe, {
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

function invia(wrapper: ReturnType<typeof monta>) {
    (wrapper.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();

    return vi.mocked(router.post).mock.calls.at(-1)!;
}

beforeEach(() => {
    vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
    vi.mocked(router.post).mockClear();
});

describe('impostazioniStampe — lo studio in testa alle stampe', () => {
    test('la sezione dello studio ha la partita IVA, il codice fiscale, il sito e il logo, e dice dove va lo studio', () => {
        const wrapper = monta();
        const sezione = wrapper.find('[data-testid="studio-delle-stampe"]');

        expect(sezione.text()).toContain('In testa a tutte le stampe, tranne il modello F24, con una carta a campi');
        expect(sezione.text()).toContain('con la carta intestata, lo studio è quello stampato sulla carta');
        expect(sezione.text()).toContain('Senza il nome dello studio la testata delle stampe a campi è quella del solo condominio.');
        expect(sezione.text()).not.toContain('restano come sono');
        expect(sezione.text()).toContain('Comune (accanto all\'indirizzo e nel luogo delle lettere; se è vuoto, vale quello del condominio)');
        for (const campo of ['studio_partita_iva', 'studio_codice_fiscale', 'studio_sito']) {
            expect(sezione.find(`[data-testid="${campo}"]`).exists()).toBe(true);
        }
        expect(wrapper.find('input#logo-stampe-upload').attributes('accept')).toBe('image/jpeg,image/png');
        expect(wrapper.find('[data-testid="anteprima-logo"]').attributes('src')).toBe('/storage/settings/logo/logo.png');
        expect(wrapper.text()).toContain('uno sfondo trasparente resta trasparente');
        expect(wrapper.text()).not.toContain('diventa bianco');
    });

    test('aperta e mandata senza toccare niente, rimanda i dati come li ha ricevuti', () => {
        const [indirizzo, corpo] = invia(monta());

        expect(indirizzo).toBe('/impostazioni.stampe.store');
        expect(corpo).toEqual({
            nota_legale_stampe: 'Riga uno\nRiga  due, con due spazi',
            firma_stampe: null,
            delete_firma_stampe: false,
            logo_stampe: null,
            delete_logo_stampe: false,
            studio_nome: studio.nome,
            studio_indirizzo: studio.indirizzo,
            studio_email: studio.email,
            studio_pec: studio.pec,
            studio_telefono: studio.telefono,
            studio_comune: '',
            studio_partita_iva: studio.partita_iva,
            studio_codice_fiscale: studio.codice_fiscale,
            studio_sito: studio.sito,
        });
    });

    test('«Rimuovi» del logo chiede di toglierlo, e l\'anteprima sparisce; la firma resta', async () => {
        const wrapper = monta();
        const rimuovi = wrapper.findAllComponents({ name: 'Button' }).find((b) => b.attributes('data-testid') === 'rimuovi-logo')!;
        rimuovi.vm.$emit('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="anteprima-logo"]').exists()).toBe(false);
        const [, corpo] = invia(wrapper);
        expect(corpo).toMatchObject({ delete_logo_stampe: true, logo_stampe: null, delete_firma_stampe: false });
    });
});

describe('impostazioniStampe — la firma caricata prima', () => {
    const riga = 'La firma ha lo sfondo trasparente: a ogni stampa il programma la legge tutta in memoria. Caricala di nuovo.';

    test('quando il server dice che la firma va caricata di nuovo, la pagina lo dice accanto alla firma, con il perché', () => {
        const wrapper = monta({ firma_da_ricaricare: riga });
        expect(wrapper.find('[data-testid="firma-da-ricaricare"]').text()).toBe(riga);
    });

    test('senza la richiesta del server, la riga non c\'è', () => {
        expect(monta().find('[data-testid="firma-da-ricaricare"]').exists()).toBe(false);
    });

    test('scelta una firma nuova, la riga sparisce', async () => {
        (URL as unknown as { createObjectURL: () => string }).createObjectURL = () => 'blob:firma';
        const wrapper = monta({ firma_da_ricaricare: riga });
        const input = wrapper.find('input#firma-stampe-upload');
        Object.defineProperty(input.element, 'files', { value: [new File(['x'], 'firma.png', { type: 'image/png' })] });
        await input.trigger('change');

        expect(wrapper.find('[data-testid="firma-da-ricaricare"]').exists()).toBe(false);
    });
});

describe('impostazioniStampe — le carte dello studio', () => {
    test('la pagina passa le carte, la scelta per gruppo e i gruppi alla sezione delle carte, fuori dal modulo delle impostazioni', () => {
        const carte = [{ id: 1, nome: 'Carta dello studio', aspetto: 'campi', mostra_logo: true, colore: null, predefinita: true, usata_da: [] }];
        const scelta = { lettere: null, ricevute_e_avvisi: null, rendiconto_e_assemblea: null, per_la_persona: null, registri_e_elenchi: null };
        const gruppi = [{ valore: 'lettere', nome: 'Lettere ai condòmini', esempi: 'promemoria, sollecito, diffida, messa in mora' }];
        const wrapper = monta({ carte, carta_per_gruppo: scelta, gruppi_carta: gruppi, limiteCarta: '8 MB' });
        const sezione = wrapper.findComponent({ name: 'CarteDelloStudio' });

        expect(sezione.exists()).toBe(true);
        expect(sezione.props()).toEqual({ carte, cartaPerGruppo: scelta, gruppi, limiteCarta: '8 MB' });
        expect(sezione.element.closest('form')).toBeNull();
    });
});

describe('impostazioniStampe — dopo un salvataggio, la pagina legge le props nuove', () => {
    const carta = (id: number, nome: string, predefinita = false) => ({ id, nome, aspetto: 'campi', mostra_logo: true, colore: null, predefinita, usata_da: [] as string[] });
    const scelta = { lettere: null, ricevute_e_avvisi: null, rendiconto_e_assemblea: null, per_la_persona: null, registri_e_elenchi: null };
    const gruppi = [{ valore: 'lettere', nome: 'Lettere ai condòmini', esempi: '' }];

    test('le carte: la carta salvata c\'è, quella eliminata no, «Predefinita» si sposta; la scelta per gruppo nuova arriva alla sezione', async () => {
        const wrapper = monta({ carte: [carta(1, 'Carta dello studio', true), carta(2, 'Carta vecchia')], carta_per_gruppo: scelta, gruppi_carta: gruppi });
        await arrivano({ carte: [carta(1, 'Carta dello studio'), carta(3, 'Carta blu', true)], carta_per_gruppo: { ...scelta, lettere: 3 }, gruppi_carta: gruppi });
        const sezione = wrapper.findComponent({ name: 'CarteDelloStudio' });

        expect((sezione.props('carte') as { id: number; predefinita: boolean }[]).map((c) => [c.id, c.predefinita])).toEqual([[1, false], [3, true]]);
        expect(sezione.props('cartaPerGruppo')).toEqual({ ...scelta, lettere: 3 });
    });

    test('il logo: salvato, l\'anteprima è quella del server e il file non si rimanda; tolto, l\'anteprima sparisce', async () => {
        (URL as unknown as { createObjectURL: () => string }).createObjectURL = () => 'blob:logo';
        const wrapper = monta();
        const input = wrapper.find('input#logo-stampe-upload');
        Object.defineProperty(input.element, 'files', { value: [new File(['x'], 'logo.png', { type: 'image/png' })] });
        await input.trigger('change');
        await arrivano({ logo_stampe_url: '/storage/settings/logo/nuovo.png' });

        expect(wrapper.find('[data-testid="anteprima-logo"]').attributes('src')).toBe('/storage/settings/logo/nuovo.png');
        expect(invia(wrapper)[1]).toMatchObject({ logo_stampe: null, delete_logo_stampe: false });

        await arrivano({ logo_stampe_url: null });
        expect(wrapper.find('[data-testid="anteprima-logo"]').exists()).toBe(false);
    });

    test('la firma: salvata, l\'anteprima è quella del server e il file non si rimanda; la riga della firma da ricaricare segue il server', async () => {
        (URL as unknown as { createObjectURL: () => string }).createObjectURL = () => 'blob:firma';
        const riga = 'La firma ha lo sfondo trasparente: a ogni stampa il programma la legge tutta in memoria. Caricala di nuovo.';
        const wrapper = monta();
        const input = wrapper.find('input#firma-stampe-upload');
        Object.defineProperty(input.element, 'files', { value: [new File(['x'], 'firma.png', { type: 'image/png' })] });
        await input.trigger('change');
        await arrivano({ firma_stampe_url: '/storage/settings/signatures/nuova.png' });

        expect(wrapper.find('img[src="/storage/settings/signatures/nuova.png"]').exists()).toBe(true);
        expect(invia(wrapper)[1]).toMatchObject({ firma_stampe: null, delete_firma_stampe: false });

        await arrivano({ firma_stampe_url: '/storage/settings/signatures/nuova.png', firma_da_ricaricare: riga });
        expect(wrapper.find('[data-testid="firma-da-ricaricare"]').text()).toBe(riga);
    });

    test('un rifiuto del server non cambia quello che si è scelto: il logo scelto resta da mandare', async () => {
        (URL as unknown as { createObjectURL: () => string }).createObjectURL = () => 'blob:logo';
        const wrapper = monta();
        const input = wrapper.find('input#logo-stampe-upload');
        const file = new File(['x'], 'logo.png', { type: 'image/png' });
        Object.defineProperty(input.element, 'files', { value: [file] });
        await input.trigger('change');
        await arrivano({ errors: { studio_partita_iva: 'La partita IVA ha 11 cifre.' } });

        expect(wrapper.find('[data-testid="anteprima-logo"]').attributes('src')).toBe('blob:logo');
        expect(invia(wrapper)[1]).toMatchObject({ logo_stampe: file });
    });

    test('il messaggio di una richiesta delle carte non esce in cima alla pagina; quello delle impostazioni sì', async () => {
        const wrapper = monta({ carte: [carta(1, 'Carta dello studio', true)], carta_per_gruppo: scelta, gruppi_carta: gruppi });
        wrapper.findComponent({ name: 'CarteDelloStudio' }).vm.$emit('richiesta');
        await arrivano({ carte: [carta(1, 'Carta dello studio', true)], carta_per_gruppo: scelta, gruppi_carta: gruppi, flash: { message: { type: 'success', message: 'Carta «Carta blu» salvata.' } } });
        expect(wrapper.findComponent({ name: 'Alert' }).exists()).toBe(false);

        invia(wrapper);
        await arrivano({ carte: [carta(1, 'Carta dello studio', true)], carta_per_gruppo: scelta, gruppi_carta: gruppi, flash: { message: { type: 'success', message: 'Impostazioni salvate.' } } });
        expect(wrapper.findComponent({ name: 'Alert' }).props('message')).toBe('Impostazioni salvate.');
    });
});

describe('impostazioniStampe — i campi dello studio dopo «Salva le impostazioni»', () => {
    type Opzioni = { onSuccess: (p: unknown) => unknown; onError: (e: unknown) => unknown };
    const scrivi = (wrapper: ReturnType<typeof monta>, campo: string, valore: string) =>
        wrapper.findAllComponents({ name: 'Input' }).find((c) => c.attributes('data-testid') === campo)!.vm.$emit('update:modelValue', valore);

    test('salvati, i campi dicono quello che il server ha salvato (il codice fiscale senza spazi e in maiuscolo); un rifiuto lascia quello che si è scritto', async () => {
        const wrapper = monta({ studio: { ...studio, codice_fiscale: '' } });
        scrivi(wrapper, 'studio_codice_fiscale', 'bnclra80a01f205x ');
        await nextTick();

        // Un rifiuto: le props di prima, con l'errore; il campo resta com'è scritto.
        const [, , rifiuto] = invia(wrapper) as unknown as [string, unknown, Opzioni];
        await arrivano({ studio: { ...studio, codice_fiscale: '' }, errors: { studio_partita_iva: 'La partita IVA ha 11 cifre.' } });
        await rifiuto.onError({ studio_partita_iva: 'La partita IVA ha 11 cifre.' });
        expect(invia(wrapper)[1]).toMatchObject({ studio_codice_fiscale: 'bnclra80a01f205x ' });

        // Salvato: le props nuove portano lo studio salvato, e la pagina lo rilegge.
        const [, , salvato] = invia(wrapper) as unknown as [string, unknown, Opzioni];
        await arrivano({ studio: { ...studio, codice_fiscale: 'BNCLRA80A01F205X', telefono: '02 1234567' } });
        await salvato.onSuccess(pagina.value);
        expect(invia(wrapper)[1]).toMatchObject({ studio_codice_fiscale: 'BNCLRA80A01F205X', studio_telefono: '02 1234567', studio_nome: studio.nome });
    });
});
