// @vitest-environment jsdom

/**
 * Le carte dello studio, nelle impostazioni delle stampe: l'elenco delle carte (la predefinita non ha «Elimina»), la carta nuova e la modifica, e
 * «Quale carta per quale documento» con le cinque righe e «La stessa per tutti», scelte con il menu di `vue-select` delle altre pagine.
 *
 * Aperta e mandata senza toccare niente, la scelta per gruppo rimanda quello che ha ricevuto; una carta aperta e salvata senza toccare niente
 * rimanda i suoi dati. «La stessa per tutti» mette la stessa carta nelle cinque righe. Le props nuove dopo un salvataggio (la scelta salvata, una
 * carta che non c'è più) arrivano nei menu. Un rifiuto della scelta si legge sotto la riga del suo gruppo; il messaggio di una richiesta delle carte
 * esce dentro la sezione delle carte, non in cima alla pagina.
 *
 * `vue-select` si sostituisce con un menu finto, che dice le sue scelte e quella fatta, e sceglie con `update:modelValue`.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

const post = vi.fn();
const put = vi.fn();
const elimina = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    router: { post: (...a: unknown[]) => post(...a), put: (...a: unknown[]) => put(...a), delete: (...a: unknown[]) => elimina(...a) },
}));
vi.mock('vue-select', () => ({
    default: {
        name: 'VSelectFinto',
        props: ['options', 'modelValue', 'reduce', 'label', 'clearable', 'searchable'],
        emits: ['update:modelValue'],
        computed: {
            scelta(): string {
                const self = this as unknown as { options: Record<string, string>[]; modelValue: unknown; reduce?: (o: unknown) => unknown; label?: string };
                const o = (self.options ?? []).find((x) => (self.reduce ? self.reduce(x) : x) === self.modelValue);
                return o ? o[self.label ?? 'label'] : '';
            },
        },
        template: '<div class="vselect-finto"><span class="vselect-scelta">{{ scelta }}</span></div>',
    },
}));
vi.mock('@/components/Alert.vue', () => ({ default: { name: 'Alert', props: ['message', 'type'], template: '<div class="alert-finto" :data-tipo="type">{{ message }}</div>' } }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p?: Record<string, unknown>) => (p === undefined ? `/${nome}` : `/${nome}/${Object.values(p).join('/')}`);

const CarteDelloStudio = (await import('./CarteDelloStudio.vue')).default;

const aCampi = { carta_tipo: null, carta_pagine: null, margini: null, carta_illeggibile: null };
const carte = [
    { id: 1, nome: 'Carta dello studio', aspetto: 'campi', mostra_logo: true, colore: null, predefinita: true, usata_da: [], ...aCampi },
    { id: 4, nome: 'Carta «rossa» & <b>', aspetto: 'campi', mostra_logo: false, colore: '#7a1f1f', predefinita: false, usata_da: ['Lettere ai condòmini'], ...aCampi },
];
/** Una carta con la carta intestata in PDF di due pagine, e i suoi margini. */
const intestata = { id: 7, nome: 'Carta della tipografia', aspetto: 'carta', mostra_logo: true, colore: null, predefinita: false, usata_da: [],
    carta_tipo: 'pdf', carta_pagine: 2, margini: { alto: 45, basso: 25, sinistro: 20.5, destro: 18 }, carta_illeggibile: null };
const gruppi = [
    { valore: 'lettere', nome: 'Lettere ai condòmini', esempi: 'promemoria, sollecito, diffida, messa in mora' },
    { valore: 'ricevute_e_avvisi', nome: 'Ricevute e avvisi di compensazione', esempi: 'la ricevuta dell\'incasso, l\'avviso di compensazione' },
    { valore: 'rendiconto_e_assemblea', nome: 'Rendiconto e assemblea', esempi: 'il fascicolo e le sue parti, i riparti, il preventivo' },
    { valore: 'per_la_persona', nome: 'Estratti conto e prospetti per la persona', esempi: 'l\'estratto conto, il prospetto degli oneri accessori' },
    { valore: 'registri_e_elenchi', nome: 'Registri ed elenchi dello studio', esempi: 'il libro giornale, il libro mastro' },
];
const GRUPPI = gruppi.map((g) => g.valore);

let w: VueWrapper | null = null;

/** Il menu finto dentro un elemento della pagina. */
const menu = (wrapper: VueWrapper, dove: string) => wrapper.find(dove).findComponent({ name: 'VSelectFinto' });
/** Le etichette delle scelte di un menu. */
const etichette = (m: ReturnType<typeof menu>) => (m.props('options') as { etichetta: string }[]).map((o) => o.etichetta);
function monta(cartaPerGruppo: Record<string, number | null> = { lettere: 4, ricevute_e_avvisi: null, rendiconto_e_assemblea: null, per_la_persona: null, registri_e_elenchi: null },
    leCarte: unknown[] = carte) {
    w = mount(CarteDelloStudio, { attachTo: document.body, props: { carte: leCarte as never, cartaPerGruppo, gruppi, limiteCarta: '8 MB' } });

    return w;
}

beforeEach(() => {
    post.mockClear();
    put.mockClear();
    elimina.mockClear();
});
afterEach(() => {
    w?.unmount();
    w = null;
});

describe('Le carte dello studio', () => {
    test('l\'elenco: ogni carta con il nome, il logo, il colore e chi la usa; «Prova la carta» apre la prova; la predefinita non ha «Elimina»', () => {
        const sezione = monta().find('[data-testid="carte-dello-studio"]');
        expect(sezione.text()).toContain('Le carte dello studio');
        const predefinita = sezione.find('[data-testid="carta-1"]');
        const rossa = sezione.find('[data-testid="carta-4"]');
        expect(predefinita.text()).toContain('Carta dello studio');
        expect(predefinita.text()).toContain('Predefinita');
        expect(predefinita.text()).toContain('I colori di sempre');
        expect(predefinita.find('[data-testid="elimina-carta"]').exists()).toBe(false);
        expect(rossa.text()).toContain('Carta «rossa» & <b>');
        expect(rossa.text()).toContain('Senza il logo');
        expect(rossa.text()).toContain('#7a1f1f');
        expect(rossa.text()).toContain('Usata per: Lettere ai condòmini');
        expect(rossa.find('[data-testid="prova-carta"]').attributes('href')).toBe('/impostazioni.stampe.carte.prova/4');
        expect(rossa.find('[data-testid="prova-carta"]').attributes('target')).toBe('_blank');
        expect(sezione.text()).not.toMatch(/modell/i);
    });

    test('una carta nuova: il nome, il logo, il colore; si manda alla rotta delle carte', async () => {
        const wrapper = monta();
        await wrapper.find('[data-testid="nuova-carta"]').trigger('click');
        expect(wrapper.find('[data-testid="modulo-carta"]').text()).toContain('senza, ogni stampa ha i colori di sempre');
        expect(wrapper.text()).not.toContain('di oggi');
        await wrapper.find('[data-testid="modulo-nome"]').setValue('Carta verde');
        await wrapper.find('[data-testid="modulo-logo"]').setValue(false);
        await wrapper.find('[data-testid="modulo-con-colore"]').setValue(true);
        await wrapper.find('[data-testid="modulo-colore"]').setValue('#14532d');
        await wrapper.find('[data-testid="salva-carta"]').trigger('click');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/impostazioni.stampe.carte.store');
        expect(post.mock.calls[0][1]).toEqual({ nome: 'Carta verde', mostra_logo: false, colore: '#14532d', predefinita: false, aspetto: 'campi' });
    });

    test('una carta aperta e salvata senza toccare niente rimanda i suoi dati; «I colori di sempre» manda il colore nullo', async () => {
        const wrapper = monta();
        await wrapper.find('[data-testid="carta-4"] [data-testid="modifica-carta"]').trigger('click');
        await wrapper.find('[data-testid="salva-carta"]').trigger('click');
        expect(put.mock.calls[0][0]).toBe('/impostazioni.stampe.carte.update/4');
        expect(put.mock.calls[0][1]).toEqual({ nome: 'Carta «rossa» & <b>', mostra_logo: false, colore: '#7a1f1f', predefinita: false, aspetto: 'campi' });

        await wrapper.find('[data-testid="carta-4"] [data-testid="modifica-carta"]').trigger('click');
        await wrapper.find('[data-testid="modulo-con-colore"]').setValue(false);
        await wrapper.find('[data-testid="salva-carta"]').trigger('click');
        expect(put.mock.calls[1][1]).toEqual({ nome: 'Carta «rossa» & <b>', mostra_logo: false, colore: null, predefinita: false, aspetto: 'campi' });
    });

    test('«Elimina» chiede la conferma con una frase sola, e poi elimina', async () => {
        const conferma = vi.spyOn(window, 'confirm').mockReturnValue(true);
        const wrapper = monta();
        await wrapper.find('[data-testid="carta-4"] [data-testid="elimina-carta"]').trigger('click');

        expect(conferma).toHaveBeenCalledWith('Eliminare la carta «Carta «rossa» & <b>»?');
        expect(elimina.mock.calls[0][0]).toBe('/impostazioni.stampe.carte.destroy/4');
        conferma.mockRestore();
    });
});

describe('Quale carta per quale documento', () => {
    test('cinque righe con i loro esempi, più «La stessa per tutti»', () => {
        const sezione = monta().find('[data-testid="carta-per-documento"]');
        expect(sezione.text()).toContain('Quale carta per quale documento');
        expect(sezione.text()).toContain('La stessa per tutti');
        for (const g of gruppi) {
            const riga = sezione.find(`[data-testid="gruppo-${g.valore}"]`);
            expect(riga.text()).toContain(g.nome);
            expect(riga.text()).toContain(g.esempi);
        }
        // «modello» solo nel nome del modello F24, mai per la carta.
        expect(sezione.text().replace(/modello F24/g, '')).not.toMatch(/modell/i);
    });

    test('aperta e mandata senza toccare niente, rimanda la scelta che ha ricevuto', async () => {
        const ricevuta = { lettere: 4, ricevute_e_avvisi: null, rendiconto_e_assemblea: 1, per_la_persona: null, registri_e_elenchi: null };
        const wrapper = monta({ ...ricevuta });
        await wrapper.find('[data-testid="salva-carte-per-documento"]').trigger('click');

        expect(put.mock.calls[0][0]).toBe('/impostazioni.stampe.carte.gruppi');
        expect(put.mock.calls[0][1]).toEqual({ carta_per_gruppo: ricevuta });
    });

    test('«La stessa per tutti» mette la stessa carta nelle cinque righe; con righe diverse non ne dice nessuna', async () => {
        const wrapper = monta();
        const tutti = menu(wrapper, '[data-testid="stessa-per-tutti"]');
        expect(tutti.props('modelValue')).toBe('');
        expect(tutti.text()).toBe('Una carta per ogni gruppo');

        tutti.vm.$emit('update:modelValue', '4');
        await wrapper.vm.$nextTick();
        for (const g of GRUPPI) {
            expect(menu(wrapper, `[data-testid="gruppo-${g}"]`).props('modelValue')).toBe('4');
        }
        await wrapper.find('[data-testid="salva-carte-per-documento"]').trigger('click');
        expect(put.mock.calls[0][1]).toEqual({ carta_per_gruppo: Object.fromEntries(GRUPPI.map((g) => [g, 4])) });

        tutti.vm.$emit('update:modelValue', 'predefinita');
        await wrapper.vm.$nextTick();
        await wrapper.find('[data-testid="salva-carte-per-documento"]').trigger('click');
        expect(put.mock.calls[1][1]).toEqual({ carta_per_gruppo: Object.fromEntries(GRUPPI.map((g) => [g, null])) });
    });

    test('i menu sono quelli di vue-select, come nelle altre pagine: ogni riga ha la carta predefinita con il suo nome intero e le carte; il menu dice la carta scelta', () => {
        const wrapper = monta();
        expect(wrapper.find('select').exists()).toBe(false);
        for (const g of GRUPPI) {
            const m = menu(wrapper, `[data-testid="gruppo-${g}"]`);
            expect(m.exists()).toBe(true);
            expect(etichette(m)).toEqual(['La carta predefinita («Carta dello studio»)', 'Carta dello studio', 'Carta «rossa» & <b>']);
            expect(m.props('clearable')).toBe(false);
        }
        expect(menu(wrapper, '[data-testid="gruppo-lettere"]').text()).toBe('Carta «rossa» & <b>');
        expect(menu(wrapper, '[data-testid="gruppo-ricevute_e_avvisi"]').text()).toBe('La carta predefinita («Carta dello studio»)');
        expect(etichette(menu(wrapper, '[data-testid="stessa-per-tutti"]'))).toEqual(['Una carta per ogni gruppo', 'La carta predefinita («Carta dello studio»)', 'Carta dello studio', 'Carta «rossa» & <b>']);
    });

    test('scelta una carta in una riga, la riga la manda; le altre restano come sono', async () => {
        const wrapper = monta();
        menu(wrapper, '[data-testid="gruppo-registri_e_elenchi"]').vm.$emit('update:modelValue', '1');
        await wrapper.vm.$nextTick();
        await wrapper.find('[data-testid="salva-carte-per-documento"]').trigger('click');

        expect(put.mock.calls[0][1]).toEqual({ carta_per_gruppo: { lettere: 4, ricevute_e_avvisi: null, rendiconto_e_assemblea: null, per_la_persona: null, registri_e_elenchi: 1 } });
    });

    test('le props nuove dopo un salvataggio arrivano nei menu: la scelta salvata, e una carta che non c\'è più', async () => {
        const wrapper = monta();
        await wrapper.setProps({
            carte: [carte[0]],
            cartaPerGruppo: { lettere: null, ricevute_e_avvisi: null, rendiconto_e_assemblea: 1, per_la_persona: null, registri_e_elenchi: null },
        });

        expect(menu(wrapper, '[data-testid="gruppo-lettere"]').props('modelValue')).toBe('predefinita');
        expect(menu(wrapper, '[data-testid="gruppo-rendiconto_e_assemblea"]').props('modelValue')).toBe('1');
        expect(etichette(menu(wrapper, '[data-testid="gruppo-lettere"]'))).toEqual(['La carta predefinita («Carta dello studio»)', 'Carta dello studio']);
        expect(wrapper.find('[data-testid="carta-4"]').exists()).toBe(false);
    });

    test('il rifiuto della scelta si legge sotto la riga del suo gruppo, e quello di tutta la scelta sopra le righe', async () => {
        const wrapper = monta();
        await wrapper.find('[data-testid="salva-carte-per-documento"]').trigger('click');
        const opzioni = put.mock.calls[0][2] as { onError: (e: Record<string, string>) => void };
        opzioni.onError({
            'carta_per_gruppo.lettere': 'La carta scelta per «Lettere ai condòmini» non c\'è più: scegline un\'altra.',
            carta_per_gruppo: 'La scelta delle carte vale per i cinque gruppi di documenti, e solo per loro.',
        });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="gruppo-lettere"]').text()).toContain('La carta scelta per «Lettere ai condòmini» non c\'è più: scegline un\'altra.');
        expect(wrapper.find('[data-testid="gruppo-ricevute_e_avvisi"]').text()).not.toContain('non c\'è più');
        expect(wrapper.find('[data-testid="errore-carte-per-documento"]').text()).toBe('La scelta delle carte vale per i cinque gruppi di documenti, e solo per loro.');
    });
});

describe('I messaggi delle carte', () => {
    test('il messaggio di «Elimina» esce dentro la sezione delle carte, e la pagina sa che la richiesta è delle carte', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const wrapper = monta();
        await wrapper.find('[data-testid="carta-4"] [data-testid="elimina-carta"]').trigger('click');
        const opzioni = elimina.mock.calls[0][1] as { onSuccess: (p: unknown) => void };
        const rifiuto = 'La carta «Carta «rossa» & <b>» si usa per: Lettere ai condòmini. Scegli un\'altra carta per questi documenti, poi eliminala.';
        opzioni.onSuccess({ props: { flash: { message: { type: 'error', message: rifiuto } } } });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="carte-dello-studio"] .alert-finto').text()).toBe(rifiuto);
        expect(wrapper.find('[data-testid="carte-dello-studio"] .alert-finto').attributes('data-tipo')).toBe('error');
        expect(wrapper.emitted('richiesta')).toHaveLength(1);
    });

    test('il messaggio di «Salva la scelta delle carte» esce sotto le righe dei gruppi', async () => {
        const wrapper = monta();
        await wrapper.find('[data-testid="salva-carte-per-documento"]').trigger('click');
        (put.mock.calls[0][2] as { onSuccess: (p: unknown) => void }).onSuccess({ props: { flash: { message: { type: 'success', message: 'Scelta delle carte salvata: ogni gruppo di documenti esce con la sua carta.' } } } });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="carta-per-documento"] .alert-finto').text()).toBe('Scelta delle carte salvata: ogni gruppo di documenti esce con la sua carta.');
        expect(wrapper.find('[data-testid="carte-dello-studio"] .alert-finto').exists()).toBe(false);
        expect(wrapper.emitted('richiesta')).toHaveLength(1);
    });

    test('«Salva la carta»: il messaggio dentro la sezione delle carte', async () => {
        const wrapper = monta();
        await wrapper.find('[data-testid="nuova-carta"]').trigger('click');
        await wrapper.find('[data-testid="modulo-nome"]').setValue('Carta verde');
        await wrapper.find('[data-testid="salva-carta"]').trigger('click');
        (post.mock.calls[0][2] as { onSuccess: (p: unknown) => void }).onSuccess({ props: { flash: { message: { type: 'success', message: 'Carta «Carta verde» salvata.' } } } });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="carte-dello-studio"] .alert-finto').text()).toBe('Carta «Carta verde» salvata.');
        expect(wrapper.find('[data-testid="modulo-carta"]').exists()).toBe(false);
    });
});

describe('La carta intestata', () => {
    const conIntestata = () => monta(undefined, [...carte, intestata]);

    test('nell\'elenco una carta intestata dice che cos\'è: il PDF e le sue pagine, o l\'immagine; una che non si legge lo dice', async () => {
        const wrapper = conIntestata();
        expect(wrapper.find('[data-testid="carta-7"]').text()).toContain('Carta intestata: PDF di 2 pagine');
        expect(wrapper.find('[data-testid="carta-1"]').text()).toContain('A campi');
        await wrapper.setProps({ carte: [...carte, { ...intestata, carta_tipo: 'immagine', carta_pagine: 1, carta_illeggibile: 'La carta intestata di questa carta non si legge: caricala di nuovo.' }] });
        expect(wrapper.find('[data-testid="carta-7"]').text()).toContain('Carta intestata: immagine A4');
        expect(wrapper.find('[data-testid="carta-7"] [data-testid="carta-illeggibile"]').text()).toBe('La carta intestata di questa carta non si legge: caricala di nuovo.');
    });

    test('una carta nuova con la carta intestata: il file (PDF o JPG) e i quattro margini; si manda come modulo con il file', async () => {
        const wrapper = conIntestata();
        await wrapper.find('[data-testid="nuova-carta"]').trigger('click');
        expect(wrapper.find('[data-testid="modulo-carta-intestata"]').exists()).toBe(false);
        await wrapper.find('[data-testid="modulo-nome"]').setValue('Carta della tipografia 2');
        await wrapper.find('[data-testid="aspetto-carta"]').setValue(true);
        expect(wrapper.find('input[data-testid="modulo-file-carta"]').attributes('accept')).toBe('application/pdf,image/jpeg');
        expect(wrapper.find('[data-testid="modulo-carta-intestata"]').text()).toContain("o un'immagine A4 in JPG larga almeno 1.240 pixel");
        expect(wrapper.find('[data-testid="modulo-carta-intestata"]').text()).not.toContain('PNG');
        const file = new File(['%PDF'], 'carta.pdf', { type: 'application/pdf' });
        const input = wrapper.find('input[data-testid="modulo-file-carta"]');
        Object.defineProperty(input.element, 'files', { value: [file] });
        await input.trigger('change');
        for (const [lato, valore] of [['alto', '50'], ['basso', '30'], ['sinistro', '22'], ['destro', '15']]) {
            await wrapper.find(`[data-testid="margine-${lato}"]`).setValue(valore);
        }
        await wrapper.find('[data-testid="salva-carta"]').trigger('click');

        expect(post.mock.calls[0][0]).toBe('/impostazioni.stampe.carte.store');
        expect(post.mock.calls[0][1]).toEqual({ nome: 'Carta della tipografia 2', mostra_logo: true, colore: null, predefinita: false, aspetto: 'carta', carta: file,
            margine_alto: 50, margine_basso: 30, margine_sinistro: 22, margine_destro: 15 });
        expect(post.mock.calls[0][2]).toMatchObject({ forceFormData: true });
    });

    test('una carta intestata aperta e salvata senza toccare niente rimanda i suoi dati e i margini, senza un file', async () => {
        const wrapper = conIntestata();
        await wrapper.find('[data-testid="carta-7"] [data-testid="modifica-carta"]').trigger('click');
        expect(wrapper.find('[data-testid="modulo-carta-intestata"]').text()).toContain('PDF di 2 pagine');
        await wrapper.find('[data-testid="salva-carta"]').trigger('click');

        expect(put.mock.calls[0][0]).toBe('/impostazioni.stampe.carte.update/7');
        expect(put.mock.calls[0][1]).toEqual({ nome: 'Carta della tipografia', mostra_logo: true, colore: null, predefinita: false, aspetto: 'carta',
            margine_alto: 45, margine_basso: 25, margine_sinistro: 20.5, margine_destro: 18 });
        expect(post).not.toHaveBeenCalled();
    });

    test('una carta intestata cambiata con un file nuovo si manda come modulo con il file, per la rotta della carta', async () => {
        const wrapper = conIntestata();
        await wrapper.find('[data-testid="carta-7"] [data-testid="modifica-carta"]').trigger('click');
        const file = new File(['jpg'], 'carta.jpg', { type: 'image/jpeg' });
        const input = wrapper.find('input[data-testid="modulo-file-carta"]');
        Object.defineProperty(input.element, 'files', { value: [file] });
        await input.trigger('change');
        await wrapper.find('[data-testid="salva-carta"]').trigger('click');

        expect(post.mock.calls[0][0]).toBe('/impostazioni.stampe.carte.update/7');
        expect(post.mock.calls[0][1]).toMatchObject({ _method: 'put', aspetto: 'carta', carta: file, margine_sinistro: 20.5 });
        expect(post.mock.calls[0][2]).toMatchObject({ forceFormData: true });
    });

    test('una carta tornata a campi che ha il file: riaperta con «Carta intestata», il modulo dice che la carta è caricata e ha i suoi margini', async () => {
        const wrapper = monta(undefined, [...carte, { ...intestata, aspetto: 'campi' }]);
        expect(wrapper.find('[data-testid="carta-7"]').text()).toContain('A campi');
        await wrapper.find('[data-testid="carta-7"] [data-testid="modifica-carta"]').trigger('click');
        await wrapper.find('[data-testid="aspetto-carta"]').setValue(true);

        expect(wrapper.find('[data-testid="modulo-carta-intestata"]').text()).toContain('Caricata: PDF di 2 pagine');
        await wrapper.find('[data-testid="salva-carta"]').trigger('click');
        expect(put.mock.calls[0][1]).toEqual({ nome: 'Carta della tipografia', mostra_logo: true, colore: null, predefinita: false, aspetto: 'carta',
            margine_alto: 45, margine_basso: 25, margine_sinistro: 20.5, margine_destro: 18 });
    });

    test('un margine cancellato resta vuoto: la richiesta lo manda nullo, e lo dice il server', async () => {
        const wrapper = conIntestata();
        await wrapper.find('[data-testid="carta-7"] [data-testid="modifica-carta"]').trigger('click');
        await wrapper.find('[data-testid="margine-alto"]').setValue('');
        await wrapper.find('[data-testid="salva-carta"]').trigger('click');

        expect(put.mock.calls[0][1]).toMatchObject({ margine_alto: null, margine_basso: 25, margine_sinistro: 20.5, margine_destro: 18 });
    });

    test('dopo il caricamento la carta chiede «Prova la carta»', async () => {
        const wrapper = conIntestata();
        await wrapper.find('[data-testid="carta-7"] [data-testid="modifica-carta"]').trigger('click');
        const input = wrapper.find('input[data-testid="modulo-file-carta"]');
        Object.defineProperty(input.element, 'files', { value: [new File(['%PDF'], 'carta.pdf', { type: 'application/pdf' })] });
        await input.trigger('change');
        await wrapper.find('[data-testid="salva-carta"]').trigger('click');
        (post.mock.calls[0][2] as { onSuccess: (p: unknown) => void }).onSuccess({ props: { flash: { message: { type: 'success', message: 'Carta salvata.' } } } });
        await wrapper.vm.$nextTick();

        const riga = wrapper.find('[data-testid="carta-7"] [data-testid="da-provare"]');
        expect(riga.text()).toContain('Prova la carta');
        expect(riga.find('a').attributes('href')).toBe('/impostazioni.stampe.carte.prova/7');
        expect(wrapper.find('[data-testid="carta-1"] [data-testid="da-provare"]').exists()).toBe(false);
    });

    test('dopo il caricamento di una carta già usata (la predefinita, o scelta per un gruppo) la riga dice che le stampe escono già così; per una che nessuno usa, «prima di usarla»', async () => {
        const usata = { ...intestata, usata_da: ['Lettere ai condòmini'] };
        // La predefinita: nessun gruppo la sceglie, e la usano tutti quelli senza una scelta.
        const laPredefinita = { ...intestata, predefinita: true, usata_da: [] };
        for (const [carta, attesa, mai] of [[usata, 'le stampe che la usano escono già così', 'prima di usarla'], [laPredefinita, 'le stampe che la usano escono già così', 'prima di usarla'],
            [intestata, 'prima di usarla', 'escono già così']] as const) {
            post.mockClear();
            const wrapper = monta(undefined, [...carte, carta]);
            await wrapper.find('[data-testid="carta-7"] [data-testid="modifica-carta"]').trigger('click');
            const input = wrapper.find('input[data-testid="modulo-file-carta"]');
            Object.defineProperty(input.element, 'files', { value: [new File(['%PDF'], 'carta.pdf', { type: 'application/pdf' })] });
            await input.trigger('change');
            await wrapper.find('[data-testid="salva-carta"]').trigger('click');
            (post.mock.calls[0][2] as { onSuccess: (p: unknown) => void }).onSuccess({ props: { flash: { message: { type: 'success', message: 'Carta salvata.' } } } });
            await wrapper.vm.$nextTick();

            const riga = wrapper.find('[data-testid="carta-7"] [data-testid="da-provare"]').text();
            expect(riga).toContain(attesa);
            expect(riga).not.toContain(mai);
            wrapper.unmount();
            w = null;
        }
    });
});

describe('La carta intestata: che cosa dice il modulo, e il file che si toglie', () => {
    const conIntestata = () => monta(undefined, [...carte, intestata]);

    test('il modulo della carta intestata dice il limite del file e quelli dei margini, e che sulle stampe orizzontali e quando la carta non si legge lo studio lo scrive il programma, e senza il nome non c\'è', async () => {
        const wrapper = conIntestata();
        await wrapper.find('[data-testid="nuova-carta"]').trigger('click');
        await wrapper.find('[data-testid="aspetto-carta"]').setValue(true);
        const modulo = wrapper.find('[data-testid="modulo-carta-intestata"]').text();

        expect(modulo).toContain('fino a 8 MB');
        expect(modulo).toContain('I margini sinistro e destro fino a 50 mm; l\'alto e il basso insieme fino a 150 mm.');
        expect(modulo).toContain('Sulle stampe orizzontali, e quando la carta non si legge, lo studio lo scrive il programma con i dati di «Lo studio, in testa alle stampe»: senza il nome non c\'è.');
    });

    test('nell\'elenco una carta intestata con il logo dice che il logo esce sulle stampe orizzontali; una a campi no', () => {
        const wrapper = conIntestata();
        expect(wrapper.find('[data-testid="carta-7"]').text()).toContain('Con il logo (sulle orizzontali)');
        expect(wrapper.find('[data-testid="carta-1"]').text()).toContain('Con il logo');
        expect(wrapper.find('[data-testid="carta-1"]').text()).not.toContain('sulle orizzontali');
    });

    test('una carta con il file della carta intestata che va a campi: «Togli la carta intestata» lo chiede al server; senza la spunta il file resta; con la carta intestata, o senza un file, la spunta non c\'è', async () => {
        const wrapper = conIntestata();
        await wrapper.find('[data-testid="carta-7"] [data-testid="modifica-carta"]').trigger('click');
        expect(wrapper.find('[data-testid="togli-carta-intestata"]').exists()).toBe(false);
        await wrapper.find('[data-testid="aspetto-campi"]').setValue(true);
        const togli = wrapper.find('[data-testid="togli-carta-intestata"]');
        expect(togli.text()).toContain('Togli la carta intestata caricata (PDF di 2 pagine)');

        await wrapper.find('[data-testid="salva-carta"]').trigger('click');
        expect(put.mock.calls[0][1]).toEqual({ nome: 'Carta della tipografia', mostra_logo: true, colore: null, predefinita: false, aspetto: 'campi' });

        await wrapper.find('[data-testid="carta-7"] [data-testid="modifica-carta"]').trigger('click');
        await wrapper.find('[data-testid="aspetto-campi"]').setValue(true);
        await wrapper.find('[data-testid="togli-carta-intestata"] input').setValue(true);
        await wrapper.find('[data-testid="salva-carta"]').trigger('click');
        expect(put.mock.calls[1][1]).toEqual({ nome: 'Carta della tipografia', mostra_logo: true, colore: null, predefinita: false, aspetto: 'campi', togli_carta_intestata: true });

        // Tornata alla carta intestata dopo la spunta, la richiesta di togliere il file non parte.
        await wrapper.find('[data-testid="carta-7"] [data-testid="modifica-carta"]').trigger('click');
        await wrapper.find('[data-testid="aspetto-campi"]').setValue(true);
        await wrapper.find('[data-testid="togli-carta-intestata"] input').setValue(true);
        await wrapper.find('[data-testid="aspetto-carta"]').setValue(true);
        await wrapper.find('[data-testid="salva-carta"]').trigger('click');
        expect(put.mock.calls[2][1]).not.toHaveProperty('togli_carta_intestata');

        await wrapper.find('[data-testid="carta-4"] [data-testid="modifica-carta"]').trigger('click');
        expect(wrapper.find('[data-testid="togli-carta-intestata"]').exists()).toBe(false);
    });
});

describe('I menu delle carte nel tema scuro', () => {
    test('i menu hanno il fondo del tema, come quelli delle altre pagine: bianco, e scuro nel tema scuro', () => {
        const wrapper = monta();
        for (const dove of ['[data-testid="stessa-per-tutti"]', '[data-testid="gruppo-lettere"]']) {
            const classi = menu(wrapper, dove).classes();
            expect(classi).toContain('carta-menu');
            expect(classi).toContain('dark:bg-slate-950');
            expect(classi).not.toContain('dark:bg-transparent');
        }
    });
});
