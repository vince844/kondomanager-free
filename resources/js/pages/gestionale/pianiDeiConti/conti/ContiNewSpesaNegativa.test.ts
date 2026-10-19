// @vitest-environment jsdom

/**
 * La frase sotto i totali del piano dei conti per una voce di spesa scritta con il meno.
 *
 * I totali contano la voce come il calcolo delle quote, in positivo: la riga dice −€ 240,00 e il «Preventivo» la conta + € 240,00. La pagina lo dice
 * con una frase per voce, che la nomina. Se nessun piano la tiene, la frase dice anche come correggerla; se è in un piano, nomina il piano con un
 * collegamento alla sua pagina e non propone la correzione, che lì verrebbe rifiutata o lascerebbe il piano con le rate addebitate. In un piano la
 * frase non dice la cifra che il calcolo addebita: è quella della copia del piano, che può essere un'altra (la voce a −€ 240,00 e la copia a
 * −€ 40,00: il piano addebita € 40,00), e la dice la pagina del piano (`Beta53SpesaNegativaSpostaSpesaTest`, T297.43).
 *
 * La pagina riceve `speseNegative` dal server (id, nome, importo scritto in centesimi, piani che la addebitano, ciascuno con `avviso`, vero se
 * l'avviso della sua pagina nomina la voce, e i piani che ne bloccano l'importo, `bloccano`, con il capitolo se il piano tiene lui e i movimenti sulle
 * sue quote): qui è scritto a mano, e `Beta53SpesaNegativaTestiTest` (T297.15, T297.15-bis, T297.15-ter, T297.27, T297.52) prova che il server lo manda
 * così. La coda della frase rimanda solo alle pagine con l'avviso (`ContiNewSpesaNegativaCasiInPiu.test.ts`). Fuori da ogni piano la frase dice la
 * cifra del calcolo delle quote al condizionale, come la nota del selettore della creazione. Se nessun piano la addebita ma un piano approvato la
 * tiene, la voce non cambia importo, padre né tipo (`Beta53BloccoDellaVoceTest`): la frase non dice la cifra del calcolo accanto al nome di un piano che
 * non la addebita, nomina quel piano e dice la strada della bozza prima della correzione, con che cosa annullare prima delle emissioni: un incasso o un
 * credito usato o rimborsato sulle rate, o un passaggio di titolarità di prima della 1.11.0-beta.42 registrato dopo l'emissione (T297.56), se c'è; con
 * un passaggio di prima della 1.11.0-beta.37, che dal programma non si annulla, la frase non propone la bozza e dice che la voce resta com'è. Una voce
 * sotto un sottoconto, al terzo livello o più in basso (`terzo_livello`), si salva solo con un capitolo padre di primo livello: la frase lo dice prima
 * della correzione o della strada della bozza (T297.55).
 *
 * La pagina è quella vera: si stubbano la cornice, i modali, l'albero, la finestra dei movimenti e il dettaglio della voce; il collegamento rende il
 * suo indirizzo.
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 *
 * Cosa resta scoperto: il server (i test Pest citati); il colore e la larghezza a 375 px (si guardano a video); la distinta, che ha la sua nota
 * (`Beta53SpesaNegativaTotaliTest`).
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const indirizzo = (nome: string, parametri?: unknown) => `/${nome}?${JSON.stringify(parametri ?? {})}`;

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { delete: vi.fn(), post: vi.fn(), get: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string, q?: unknown) => `/${p}?${JSON.stringify(q ?? {})}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (c: string) => c }));
(globalThis as unknown as { route: unknown }).route = indirizzo;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const ContiNew = (await import('@/pages/gestionale/pianiDeiConti/conti/ContiNew.vue')).default;

let pagina: VueWrapper | null = null;

async function monta(extra: Record<string, unknown>) {
    pagina = mount(ContiNew, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never, esercizio: { id: 4, nome: '2026' } as never, esercizi: [], condomini: [],
            pianoConti: { id: 1, nome: 'Piano dei conti', stato: 'bozza' } as never, conti: [], fornitori: [], tabelle: [],
            totalePreventivo: 144000, totaleEntrate: 24000, totaleDaRipartire: 120000, totaleSopravvenienze: 0, totaleConsuntivo: 0, ...extra,
        } as never,
        global: {
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' }, ModalNuovoConto: true, ModalModificaConto: true, AlberoDeiConti: true, MovimentiVoceDialog: true,
                DettaglioConto: true, ConfirmDialog: true, ModalAssociaTabella: true, RipartoUsufruttoGuide: true, OperazioniContiGuide: true, GiaVersatoGuide: true,
                Head: { template: '<span />' }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
            },
            mocks: { route: indirizzo },
        },
    });
    await flushPromises();
}

const normalizza = (t: string | null | undefined) => (t ?? '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();
const testo = () => normalizza(document.body.textContent);
const collegamento = (nome: string) => [...document.body.querySelectorAll('a')].find((a) => normalizza(a.textContent) === nome) as HTMLAnchorElement | undefined;

const FRASE_FUORI_DAI_PIANI = 'La voce «Rimborso vecchio» ha un importo negativo (meno € 240,00): il calcolo delle quote la addebiterebbe in positivo, per € 240,00, e il preventivo la conta così. Se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.';
const FRASE_NEL_PIANO = 'La voce «Rimborso vecchio» ha un importo negativo (meno € 240,00): il preventivo la conta in positivo, per € 240,00. È nel piano «Ordinario 2026»: la pagina del piano dice che cosa fare.';

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('la frase della voce di spesa negativa sotto i totali', () => {
    test('fuori da ogni piano: nomina la voce, dice che il preventivo la conta in positivo e come correggerla', async () => {
        await monta({ speseNegative: [{ id: 3, nome: 'Rimborso vecchio', importo: -24000, piani: [] }] });

        expect(testo()).toContain(FRASE_FUORI_DAI_PIANI);
    });

    test('in un piano: nomina il piano con il collegamento alla sua pagina, e non propone la correzione', async () => {
        await monta({ speseNegative: [{ id: 3, nome: 'Rimborso vecchio', importo: -24000, piani: [{ id: 7, nome: 'Ordinario 2026', avviso: true }] }] });

        expect(testo()).toContain(FRASE_NEL_PIANO);
        expect(testo()).not.toContain('scegli «Entrata»');
        const a = collegamento('Ordinario 2026');
        expect(a, 'il nome del piano non porta alla sua pagina').toBeDefined();
        expect(a!.getAttribute('href')).toContain('piani-rate.show');
        expect(a!.getAttribute('href')).toContain('7');
    });

    test('in un piano: non dice la cifra che il calcolo delle quote addebita, che è quella della copia del piano', async () => {
        await monta({ speseNegative: [{ id: 3, nome: 'Rimborso vecchio', importo: -24000, piani: [{ id: 7, nome: 'Ordinario 2026', avviso: true }] }] });

        expect(testo()).toContain(FRASE_NEL_PIANO);
        expect(testo()).not.toContain('il calcolo delle quote');
        expect(testo()).not.toContain('la addebita in positivo, per € 240,00');
    });

    test('in due piani con l\'avviso: li nomina tutti e due, al plurale', async () => {
        await monta({ speseNegative: [{ id: 3, nome: 'Rimborso vecchio', importo: -24000, piani: [{ id: 7, nome: 'Ordinario 2026', avviso: true }, { id: 9, nome: 'Integrativo 2026', avviso: true }] }] });

        expect(testo()).toContain('il preventivo la conta in positivo, per € 240,00. È nei piani «Ordinario 2026» e «Integrativo 2026»: le pagine dei piani dicono che cosa fare.');
        expect(collegamento('Ordinario 2026')).toBeDefined();
        expect(collegamento('Integrativo 2026')).toBeDefined();
    });

    test('due voci: una frase ciascuna, ognuna con la sua strada', async () => {
        await monta({ speseNegative: [
            { id: 3, nome: 'Rimborso vecchio', importo: -24000, piani: [{ id: 7, nome: 'Ordinario 2026', avviso: true }] },
            { id: 5, nome: 'Rimborso gas', importo: -10000, piani: [] },
        ] });

        expect(testo()).toContain(FRASE_NEL_PIANO);
        expect(testo()).toContain('La voce «Rimborso gas» ha un importo negativo (meno € 100,00): il calcolo delle quote la addebiterebbe in positivo, per € 100,00, e il preventivo la conta così. Se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.');
    });

    test('fuori da ogni piano, con il capitolo in un piano approvato: nomina il piano con il collegamento e dice la strada della bozza prima della correzione', async () => {
        await monta({ speseNegative: [{ id: 3, nome: 'Rimborso', importo: -10000, piani: [], bloccano: [{ id: 7, nome: 'Ordinario 2026', capitolo: 'Manutenzione' }] }] });

        expect(testo()).toContain('La voce «Rimborso» ha un importo negativo (meno € 100,00): il preventivo la conta in positivo, per € 100,00. '
            + 'L\'importo, il padre e il tipo non si cambiano finché il suo capitolo «Manutenzione» è nel piano «Ordinario 2026», approvato: riporta prima il piano in bozza '
            + 'dalla sua pagina, poi correggi l\'importo; se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno. Riportandolo in bozza si azzerano il numero '
            + 'del verbale e la nota dell\'approvazione: annotali prima; con delle rate emesse, prima vanno annullate le emissioni.');
        expect(testo()).not.toContain('lascia l\'importo com\'è');
        const a = collegamento('Ordinario 2026');
        expect(a, 'il nome del piano non porta alla sua pagina').toBeDefined();
        expect(a!.getAttribute('href')).toContain('piani-rate.show');
        expect(a!.getAttribute('href')).toContain('7');
    });

    test('fuori da ogni piano, con la voce in due piani approvati: li nomina al plurale, ognuno con il collegamento', async () => {
        await monta({ speseNegative: [{ id: 3, nome: 'Rimborso vecchio', importo: -24000, piani: [], bloccano: [{ id: 7, nome: 'Ordinario 2026', capitolo: null }, { id: 9, nome: 'Integrativo 2026', capitolo: null }] }] });

        expect(testo()).toContain('il preventivo la conta in positivo, per € 240,00. L\'importo, il padre e il tipo non si cambiano finché la voce è nei piani «Ordinario 2026» '
            + 'e «Integrativo 2026», approvati: riporta prima i piani in bozza dalle loro pagine, poi correggi l\'importo; se è un provento, scegli «Entrata» e scrivi '
            + 'l\'importo senza il segno. Riportandoli in bozza si azzerano');
        expect(collegamento('Ordinario 2026')).toBeDefined();
        expect(collegamento('Integrativo 2026')).toBeDefined();
    });

    test('un piano approvato che tiene la voce o il suo capitolo senza addebitarla: la frase non dice la cifra del calcolo delle quote accanto al suo nome', async () => {
        await monta({ speseNegative: [
            { id: 3, nome: 'Rimborso vecchio', importo: -24000, piani: [], bloccano: [{ id: 7, nome: 'Ordinario 2026', capitolo: null }] },
            { id: 5, nome: 'Rimborso', importo: -10000, piani: [], bloccano: [{ id: 7, nome: 'Ordinario 2026', capitolo: 'Manutenzione' }] },
        ] });

        // `Beta53SpesaNegativaTestiTest` (T297.27): il server manda così il piano approvato che tiene la voce, o il suo capitolo, senza addebitarla.
        expect(testo()).toContain('La voce «Rimborso vecchio» ha un importo negativo (meno € 240,00): il preventivo la conta in positivo, per € 240,00. L\'importo, il padre e il tipo non si cambiano');
        expect(testo()).toContain('La voce «Rimborso» ha un importo negativo (meno € 100,00): il preventivo la conta in positivo, per € 100,00. L\'importo, il padre e il tipo non si cambiano');
        expect(testo()).not.toContain('il calcolo delle quote');
    });

    test('con un incasso sulle rate del piano che blocca la voce: dice di annullare prima l\'incasso, poi le emissioni', async () => {
        await monta({ speseNegative: [{ id: 3, nome: 'Rimborso', importo: -10000, piani: [], bloccano: [{ id: 7, nome: 'Ordinario 2026', capitolo: 'Manutenzione', movimenti: ['incasso'] }] }] });

        // `Beta53SpesaNegativaTestiTest` (T297.52): l'annullamento di un'emissione rifiuta una rata con un incasso, e la strada passa annullando prima l'incasso.
        expect(testo()).toContain('Riportandolo in bozza si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima; con delle rate emesse, '
            + 'prima vanno annullati gli incassi registrati sulle rate (da registrare di nuovo dopo), poi le emissioni.');
        expect(testo()).not.toContain('prima vanno annullate le emissioni');
    });

    test('con i movimenti su uno solo dei due piani: nomina quello, con il collegamento, e dice anche i crediti', async () => {
        await monta({ speseNegative: [{ id: 3, nome: 'Rimborso vecchio', importo: -24000, piani: [], bloccano: [
            { id: 7, nome: 'Ordinario 2026', capitolo: null, movimenti: [] }, { id: 9, nome: 'Integrativo 2026', capitolo: null, movimenti: ['incasso', 'credito'] },
        ] }] });

        expect(testo()).toContain('annotali prima; con delle rate emesse, prima vanno annullati gli incassi registrati e i crediti usati o rimborsati sulle rate di '
            + '«Integrativo 2026» (da registrare di nuovo dopo), poi le emissioni.');
        expect(pagina!.findAll('a').filter((a) => a.text() === 'Integrativo 2026')).toHaveLength(2);
    });

    test('T297.56 con un passaggio di prima della 1.11.0-beta.42 registrato dopo l\'emissione: dice di annullarlo dallo storico della sua unità, prima delle emissioni', async () => {
        await monta({ speseNegative: [{ id: 3, nome: 'Rimborso', importo: -10000, piani: [], bloccano: [{ id: 7, nome: 'Preventivo 2026', capitolo: null, movimenti: ['passaggio'] }] }] });

        // `Beta53SpesaNegativaTestiTest` (T297.56): l'annullamento dell'emissione rifiuta finché c'è il passaggio, e la strada passa annullandolo prima.
        expect(testo()).toContain('annotali prima; con delle rate emesse, prima vanno annullati, ciascuno dallo storico della sua unità, i passaggi di titolarità '
            + 'registrati dopo l\'emissione (da registrare di nuovo dopo), poi le emissioni; se dopo un passaggio sono state emesse altre rate, il suo annullamento '
            + 'chiede di annullare prima quelle.');
        expect(testo()).not.toContain('prima vanno annullate le emissioni');
    });

    test('T297.56 con un passaggio di prima della 1.11.0-beta.37, che dal programma non si annulla: non propone né la bozza né il provento, e dice che la voce resta com\'è', async () => {
        await monta({ speseNegative: [{ id: 3, nome: 'Rimborso', importo: -10000, piani: [], bloccano: [{ id: 7, nome: 'Preventivo 2026', capitolo: null, movimenti: ['passaggio_senza_registro'] }] }] });

        // `Beta53SpesaNegativaTestiTest` (T297.56): il passaggio non si annulla dal programma, l'emissione resta rifiutata, e la correzione e il provento si rifiutano.
        expect(testo()).toContain('il preventivo la conta in positivo, per € 100,00. L\'importo, il padre e il tipo non si cambiano finché la voce è nel piano «Preventivo 2026», '
            + 'approvato, e un passaggio di titolarità registrato prima della 1.11.0-beta.37, che dal programma non si annulla, ferma l\'annullamento delle emissioni del '
            + 'piano: la voce resta com\'è.');
        expect(testo()).not.toContain('bozza');
        expect(testo()).not.toContain('provento');
        expect(collegamento('Preventivo 2026')).toBeDefined();
    });

    test('T297.56 il passaggio di prima della 1.11.0-beta.37 su uno solo dei due piani: nomina quel piano, con il collegamento', async () => {
        await monta({ speseNegative: [{ id: 3, nome: 'Rimborso', importo: -10000, piani: [], bloccano: [
            { id: 7, nome: 'Preventivo 2026', capitolo: null, movimenti: ['passaggio_senza_registro'] }, { id: 9, nome: 'Integrativo 2026', capitolo: null, movimenti: ['incasso'] },
        ] }] });

        expect(testo()).toContain('nei piani «Preventivo 2026» e «Integrativo 2026», approvati, e un passaggio di titolarità registrato prima della 1.11.0-beta.37, che '
            + 'dal programma non si annulla, ferma l\'annullamento delle emissioni di «Preventivo 2026»: la voce resta com\'è.');
        expect(testo()).not.toContain('bozza');
        expect(pagina!.findAll('a').filter((a) => a.text() === 'Preventivo 2026')).toHaveLength(2);
    });

    test('T297.56 l\'incasso su un piano e il passaggio sull\'altro: nomina ciascuno con il suo piano, con il collegamento', async () => {
        await monta({ speseNegative: [{ id: 3, nome: 'Rimborso', importo: -10000, piani: [], bloccano: [
            { id: 7, nome: 'Preventivo 2026', capitolo: null, movimenti: ['passaggio'] }, { id: 9, nome: 'Integrativo 2026', capitolo: null, movimenti: ['incasso'] },
        ] }] });

        expect(testo()).toContain('con delle rate emesse, prima vanno annullati gli incassi registrati sulle rate di «Integrativo 2026» e, ciascuno dallo storico della sua '
            + 'unità, i passaggi di titolarità registrati dopo l\'emissione delle rate di «Preventivo 2026» (da registrare di nuovo dopo), poi le emissioni; se dopo un '
            + 'passaggio sono state emesse altre rate, il suo annullamento chiede di annullare prima quelle.');
        expect(pagina!.findAll('a').filter((a) => a.text() === 'Preventivo 2026')).toHaveLength(2);
        expect(pagina!.findAll('a').filter((a) => a.text() === 'Integrativo 2026')).toHaveLength(2);
    });

    test('T297.55 la voce al terzo livello fuori da ogni piano: dice di sceglierle prima un capitolo padre di primo livello, poi la correzione', async () => {
        await monta({ speseNegative: [{ id: 3, nome: 'Rimborso', importo: -10000, piani: [], bloccano: [], terzo_livello: true }] });

        // `Beta53SpesaNegativaTestiTest` (T297.55): con il padre di adesso la modifica della voce si rifiuta, con un capitolo padre di primo livello passa.
        expect(testo()).toContain('La voce «Rimborso» ha un importo negativo (meno € 100,00): il calcolo delle quote la addebiterebbe in positivo, per € 100,00, e il preventivo la conta così. '
            + 'È sotto un sottoconto, e il piano dei conti non lo prevede: scegli prima come suo capitolo padre una voce di primo livello, poi correggi l\'importo; '
            + 'se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.');
    });

    test('T297.55 la voce al terzo livello con un piano approvato che la blocca: dice del capitolo padre prima della strada della bozza', async () => {
        await monta({ speseNegative: [{ id: 3, nome: 'Rimborso', importo: -10000, piani: [], bloccano: [{ id: 7, nome: 'Ordinario 2026', capitolo: null, movimenti: [] }], terzo_livello: true }] });

        expect(testo()).toContain('il preventivo la conta in positivo, per € 100,00. È sotto un sottoconto, e il piano dei conti non lo prevede: ogni sua modifica chiede come capitolo padre una voce '
            + 'di primo livello. L\'importo, il padre e il tipo non si cambiano finché la voce è nel piano «Ordinario 2026», approvato: riporta prima il piano in bozza dalla '
            + 'sua pagina, poi scegli il capitolo padre e correggi l\'importo; se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.');
        expect(collegamento('Ordinario 2026')).toBeDefined();
    });

    test('controprova — T297.55 la voce di secondo livello non parla del capitolo padre', async () => {
        await monta({ speseNegative: [{ id: 3, nome: 'Rimborso vecchio', importo: -24000, piani: [], bloccano: [], terzo_livello: false }] });

        expect(testo()).toContain('e il preventivo la conta così. Se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.');
        expect(testo()).not.toContain('terzo livello');
        expect(testo()).not.toContain('sotto un sottoconto');
    });

    test('controprova — in un piano che la addebita la frase resta quella del piano, anche se lo stesso piano ne blocca l\'importo', async () => {
        await monta({ speseNegative: [{ id: 3, nome: 'Rimborso vecchio', importo: -24000, piani: [{ id: 7, nome: 'Ordinario 2026', avviso: true }], bloccano: [{ id: 7, nome: 'Ordinario 2026', capitolo: null }] }] });

        expect(testo()).toContain(FRASE_NEL_PIANO);
        expect(testo()).not.toContain('non si cambiano');
    });

    test('i capitoli sopra la voce in un piano approvato: la frase li nomina tutti, con la voce', async () => {
        await monta({ speseNegative: [{ id: 3, nome: 'Rimborso', importo: -10000, piani: [], bloccano: [
            { id: 7, nome: 'Preventivo B', capitolo: 'Manutenzione', movimenti: [] }, { id: 7, nome: 'Preventivo B', capitolo: 'Impianti', movimenti: [] },
            { id: 7, nome: 'Preventivo B', capitolo: null, movimenti: [] },
        ], terzo_livello: true }] });

        expect(testo()).toContain('L\'importo, il padre e il tipo non si cambiano finché la voce e i suoi capitoli «Manutenzione» e «Impianti» sono nel piano «Preventivo B», approvato: '
            + 'riporta prima il piano in bozza dalla sua pagina');
    });

    test('controprova — senza voci negative nessuna frase, anche se la proprietà manca', async () => {
        await monta({ speseNegative: [] });
        expect(testo()).not.toContain('importo negativo');
        pagina?.unmount();
        document.body.innerHTML = '';

        await monta({});
        expect(testo()).not.toContain('importo negativo');
        expect(testo()).toContain('Preventivo: € 1.440,00');
    });
});
