// @vitest-environment jsdom

/**
 * «Chi ha avuto questa unità» — l'annullamento di un passaggio (1.11.0-beta.37, decisione 27).
 *
 * Il server dice se un passaggio si annulla e perché no (`annullabile`, la stessa regola di
 * `AnnullaPassaggioAction::motivoBlocco`); il pannello non ricalcola niente. Tre stati: annullabile (il comando, la nota
 * obbligatoria, gli avvisi prima della conferma), non annullabile (il perché si legge con un clic: un comando che sparisce
 * senza spiegazioni è la lezione della beta.35), annullato (resta in elenco, senza comandi).
 *
 * **Cosa resta scoperto**: l'invio vero della richiesta (lo prova `AnnullaPassaggioTest` lato server) e lo scorrimento
 * del pannello laterale, che qui è uno stub.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import TitolaritaSheet from './TitolaritaSheet.vue';

(globalThis as any).route = (n: string) => `/${n}`;

// `any`: il pannello riceve un `PassaggioRegistrato`, qui costruito per pezzi.
function passaggio(extra: Record<string, unknown> = {}): any {
    return {
        id: 7, tipo_passaggio: 'vendita', sottotipo: null, decorrenza: '2026-05-01', decorrenza_a_parole: '1 maggio 2026', registrato_il: '28 settembre 2026',
        uscente: 'Venditore Ugo', entrante: 'Acquirente Elsa', estremi_titolo: null, copia_autentica_il: null, copia_autentica_a_parole: null,
        copia_autentica_attesa: false, documento_url: null, pertinenze: [],
        conguaglio: { stato: 'proposto', importo: 548, importo_formattato: '€ 5,48', applicato: false, nota: null, nota_annullamento: null, annullato_il: null },
        obbligati: ['Venditore Ugo resta obbligato per le rate già emesse.'], ordinaria: null, nota: null,
        annullato: false, annullato_il: null, annullato_da: null, nota_annullamento: null,
        annullabile: { si: true, motivo: null, avvisi: [], effetti: ['Le righe di titolarità scritte dal passaggio tornano come prima.'] },
        ...extra,
    };
}

function monta(p: any, storico: Record<string, unknown> = {}) {
    return mount(TitolaritaSheet, {
        props: {
            open: true, unita: 'Interno 1', condominioId: 18, immobileId: 24,
            storico: { passaggi: 1, periodi_chiusi: 0, righe: [], gruppi: [{ diritto: 'Proprietà', righe: [] }], subentri: [p], ...storico },
        },
        global: {
            stubs: {
                Sheet: { template: '<div><slot /></div>' }, SheetContent: { template: '<div><slot /></div>' }, SheetHeader: { template: '<div><slot /></div>' },
                SheetTitle: { template: '<div><slot /></div>' }, SheetDescription: { template: '<div><slot /></div>' },
                Accordion: { template: '<div><slot /></div>' }, AccordionItem: { template: '<div><slot /></div>' },
                AccordionTrigger: { template: '<div><slot /></div>' }, AccordionContent: { template: '<div><slot /></div>' },
                BadgeRuolo: true,
                Input: { props: ['modelValue'], emits: ['update:modelValue'], template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' },
            },
            mocks: { route: (n: string) => `/${n}` },
        },
    });
}

describe('TitolaritaSheet — annullare un passaggio', () => {
    test('annullabile: il comando apre la nota, gli avvisi si leggono prima, e senza dieci caratteri non si conferma', async () => {
        const w = monta(passaggio({ annullabile: { si: true, motivo: null, avvisi: ['Il piano «Conguaglio luglio» è stato generato o ricalcolato dopo il passaggio: ricalcolalo di nuovo.'], effetti: ['Le righe di titolarità scritte dal passaggio tornano come prima.', 'Le quote di 8 rate passate a Acquirente Elsa tornano a Venditore Ugo, con le regole di prima.'] } }));
        const apri = w.findAll('button').find((b) => b.text() === 'Annulla il passaggio…');
        expect(apri).toBeDefined();
        await apri!.trigger('click');

        expect(w.text()).toContain('Il piano «Conguaglio luglio» è stato generato o ricalcolato dopo il passaggio');
        // Fase 1-bis A13: che cosa torna come prima lo dice il server, passaggio per passaggio; niente frase fissa.
        expect(w.text()).toContain('Le quote di 8 rate passate a Acquirente Elsa tornano a Venditore Ugo');
        expect(w.text()).not.toContain('il conguaglio tornano come prima');
        const conferma = () => w.findAll('button').find((b) => b.text().includes('Annulla il passaggio') && b.attributes('type') === 'submit')!;
        expect(conferma().attributes('disabled')).toBeDefined();

        const input = w.findAll('input').find((i) => (i.attributes('placeholder') ?? '').startsWith('Perché lo annulli'))!;
        await input.setValue('corto');
        expect(conferma().attributes('disabled')).toBeDefined();
        await input.setValue('Data del rogito sbagliata');
        expect(conferma().attributes('disabled')).toBeUndefined();
    });

    test('non annullabile: niente comando, e il perché si legge con un clic', async () => {
        const motivo = 'Dopo questo, su Interno 1, c\'è il passaggio del 1 settembre 2026 a Compratrice Bice: si annulla prima quello, poi questo.';
        const w = monta(passaggio({ annullabile: { si: false, motivo, avvisi: [] } }));
        expect(w.findAll('button').some((b) => b.text() === 'Annulla il passaggio…')).toBe(false);
        expect(w.text()).not.toContain(motivo);

        await w.findAll('button').find((b) => b.text().includes('non consentito'))!.trigger('click');
        expect(w.text()).toContain(motivo);
    });

    test('annullato: resta in elenco con la data, chi l\'ha annullato e la nota, senza comandi né vademecum, e non conta fra i passaggi registrati', () => {
        const w = monta(passaggio({ annullato: true, annullato_il: '28 settembre 2026', annullato_da: 'Amministratrice Ada', nota_annullamento: 'Data del rogito sbagliata', annullabile: { si: false, motivo: null, avvisi: [], effetti: [] } }));
        const testo = w.text();
        expect(testo).toContain('Annullato il 28 settembre 2026 da Amministratrice Ada: «Data del rogito sbagliata».');
        // Fase 1-bis A13 e A17: nessuna promessa fissa su rate e conguaglio, e il sottotitolo non nega il passaggio in elenco.
        expect(testo).not.toContain('sono tornati come prima');
        expect(testo).toContain('Nessun passaggio in vigore. Uno annullato, qui sotto.');
        expect(testo).not.toContain('Nessun passaggio registrato');
        expect(testo).not.toContain('Annulla il passaggio');
        expect(testo).not.toContain('annulla il conguaglio');
        expect(testo).not.toContain('Chi resta obbligato');
        expect(testo).not.toContain('Un passaggio registrato.');
    });

    test('unità rimasta senza titolari (un inizio locazione annullato): il passaggio annullato si vede, e niente dettaglio tecnico vuoto', () => {
        const w = monta(passaggio({ annullato: true, annullato_il: '28 settembre 2026', annullato_da: 'Amministratrice Ada', nota_annullamento: 'Data del rogito sbagliata', annullabile: { si: false, motivo: null, avvisi: [], effetti: [] } }),
            { passaggi: 0, gruppi: [], righe: [] });
        const testo = w.text();
        expect(testo).toContain('Nessun titolare registrato su questa unità.');
        expect(testo).toContain('Annullato il 28 settembre 2026 da Amministratrice Ada: «Data del rogito sbagliata».');
        expect(testo).not.toContain('Dettaglio tecnico');
    });

    test('rifiutato dal server perché lo stato è cambiato: lo storico ricaricato dice «non consentito», e il perché si apre da solo', async () => {
        let opzioni: Record<string, any> = {};
        const spia = vi.spyOn(router, 'delete').mockImplementation(((_url: string, o: Record<string, any>) => { opzioni = o; }) as any);
        const motivo = 'Dopo il passaggio è stata emessa a Acquirente Elsa la rata 5 del piano «Preventivo 2026».';
        const w = monta(passaggio());
        await w.findAll('button').find((b) => b.text() === 'Annulla il passaggio…')!.trigger('click');
        await w.findAll('input').find((i) => (i.attributes('placeholder') ?? '').startsWith('Perché lo annulli'))!.setValue('Data del rogito sbagliata');
        await w.find('form').trigger('submit');
        expect(spia).toHaveBeenCalledOnce();

        // Il redirect del 422 ricarica le props prima di onError (Inertia): il passaggio non è più annullabile.
        await w.setProps({ storico: { passaggi: 1, righe: [], gruppi: [{ diritto: 'Proprietà', righe: [] }], subentri: [passaggio({ annullabile: { si: false, motivo, avvisi: [], effetti: [] } })] } });
        opzioni.onError({ passaggio: motivo });
        await w.vm.$nextTick();
        expect(w.text()).toContain(motivo);
        spia.mockRestore();
    });
});

describe('TitolaritaSheet — la vendita o donazione con riserva d\'usufrutto (beta.38)', () => {
    // Testi T8: «o donazione», come il tipo di base — la donazione della nuda proprietà è il caso più frequente.
    test('lo storico la chiama con il suo nome, non «Vendita o donazione · riserva_usufrutto»', () => {
        const testo = monta(passaggio({ sottotipo: 'riserva_usufrutto' })).text();
        expect(testo).toContain('Vendita o donazione con riserva d\'usufrutto');
        expect(testo).not.toContain('riserva_usufrutto');
    });

    test('una vendita senza riserva resta «Vendita o donazione»', () => {
        const testo = monta(passaggio()).text();
        expect(testo).toContain('Vendita o donazione');
        expect(testo).not.toContain('riserva');
    });

    // Testi T7: la riga toccata dal passaggio si chiama come il passaggio, con la stessa funzione.
    function riga(subentro: Record<string, unknown>, id = 31): any {
        return {
            id, anagrafica: { id: 5, nome: 'Venditore Ugo', codice_fiscale: null }, tipologia: 'usufruttuario', diritto: 'Usufrutto', quota: 100, attivo: true,
            data_inizio: '2026-05-01', data_fine: null, in_corso: true, futuro: false, periodo: 'dal 1 maggio 2026 · in corso', durata: '4 mesi', note: null,
            subentro: { tipo_passaggio: 'vendita', sottotipo: null, decorrenza: '2026-05-01', estremi_titolo: 'atto notaio Verdi, rep. 777', copia_autentica_il: null,
                ruolo_nel_passaggio: 'continuazione', documento_url: null, nota_conguaglio: null, ...subentro },
        };
    }

    test('le righe toccate dalla riserva hanno il nome del passaggio, non «vendita o donazione»', () => {
        const testo = monta(passaggio(), { subentri: [], gruppi: [{ diritto: 'Usufrutto', righe: [riga({ sottotipo: 'riserva_usufrutto' })] }] }).text();
        expect(testo).toContain('Vendita o donazione con riserva d\'usufrutto, atto notaio Verdi, rep. 777');
        expect(testo).not.toContain('riserva_usufrutto');
    });

    test('la riga di una vendita senza riserva dice «Vendita o donazione», quella di una costituzione «Usufrutto · costituzione», come i loro passaggi', () => {
        const testo = monta(passaggio(), { subentri: [], gruppi: [{ diritto: 'Proprietà', righe: [riga({}), riga({ tipo_passaggio: 'usufrutto', sottotipo: 'costituzione', estremi_titolo: 'rep. 9' }, 32)] }] }).text();
        expect(testo).toContain('Vendita o donazione, atto notaio Verdi, rep. 777');
        expect(testo).toContain('Usufrutto · costituzione, rep. 9');
        expect(testo).not.toContain('riserva');
    });
});

describe('TitolaritaSheet — chi paga l\'ordinaria dal giorno dell\'atto (beta.41)', () => {
    // Rilievo A2: la scelta decide i conguagli dei passaggi dopo; si legge nello storico anche quando non si può più annullare.
    test('la scelta e le voci spostate si leggono, anche con il passaggio non più annullabile', () => {
        const testo = monta(passaggio({
            sottotipo: 'riserva_usufrutto',
            ordinaria: { scelta: 'usufruttuario', testo: 'Dal 1 maggio 2026 all\'usufruttuario (art. 1004 c.c.), la proposta di legge: questa voce è passata dal «Proprietario» all\'«Usufruttuario».', voci: ['Spese generali (Proprietà, Ordinaria 2026): prima Proprietario 100 %, dopo Usufruttuario 100 %'] },
            annullabile: { si: false, motivo: 'Un piano ha già assorbito il conguaglio.', avvisi: [], effetti: [] },
        })).text();
        expect(testo).toContain('Ordinaria: Dal 1 maggio 2026 all\'usufruttuario (art. 1004 c.c.), la proposta di legge');
        expect(testo).toContain('Spese generali (Proprietà, Ordinaria 2026): prima Proprietario 100 %, dopo Usufruttuario 100 %.');
    });

    test('senza la chiave nel registro (vendita piena, passaggi di prima) la riga non c\'è: la legge non si deduce dall\'assenza', () => {
        expect(monta(passaggio()).text()).not.toContain('Ordinaria:');
    });
});

/**
 * 1.11.0-beta.48 — «Conguaglio non scritto» nello storico di una successione (P2 del progetto, Fase 1 di
 * `docs/piano_esecutivo_beta48.md`).
 *
 * Nasce dalla decisione 72/73: nella successione e nel legato il conguaglio diventa una scelta, «Scrivi il conguaglio» o «Non
 * scriverlo: la posizione resta com'è», e con la seconda non si scrive nessuna riga in saldi e non si dichiara nessun accordo fra
 * gli eredi. Oggi lo storico conosce una sola uscita senza righe, la rinuncia: la scheda la legge dalla sola nota del conguaglio
 * (`nota_conguaglio` non vuota) e dice «l'amministratore ha rinunciato» o «regolato fra le parti». Un «non scritto» senza nota
 * sparirebbe, e con la nota direbbe una cosa falsa (che le parti abbiano regolato). Il server (`StoricoTitolarita`) manda quindi lo
 * stato `non_scritto` nel conguaglio del passaggio e, nella riga di ciascun titolare, `conguaglio_non_scritto`; la scheda li dice con
 * le parole del progetto: «Conguaglio non scritto», con la nota se c'è; nella riga, «conguaglio non scritto».
 *
 * Presidia: lo stato `non_scritto` nella scheda dei passaggi (senza «l'amministratore ha rinunciato» né «regolato fra le
 * parti», con la nota solo se c'è, senza il comando «annulla il conguaglio», perché non c'è niente di scritto da annullare) e
 * il campo `conguaglio_non_scritto` nella riga di un titolare (anche con la nota; la maiuscola della frase nella riga non è
 * fissata dal progetto, quindi si confronta senza badare alle maiuscole). Le cifre dei casi sono quelle del caso di riferimento
 * (€ 1.200,00 in dodici rate da € 100,00, Ugo muore il 1° maggio 2026, eredi Anna 33,34 %, Bruno e Carla 33,33 %).
 *
 * I controlli, verdi oggi, che devono restarlo: la rinuncia della vendita («l'amministratore ha rinunciato», con la nota) e la
 * riga con la sola nota («conguaglio regolato fra le parti»); il conguaglio scritto della successione, con le cifre per erede e
 * il comando per annullarlo («le parti hanno regolato diversamente»: lì un accordo si dichiara davvero, P4); una riga senza
 * conguaglio non dice niente.
 *
 * Cosa NON copre: le frasi del server (lo stato `non_scritto` e il campo della riga si costruiscono qui a mano; che
 * `StoricoTitolarita` li mandi lo prova il test PHP della .48); le frasi dell'arretrato; il pannello «Cosa cambierà» e il
 * modulo del passaggio; l'annullamento del passaggio con il conguaglio non scritto (lo prova `AnnullaPassaggioTest`).
 */
describe('TitolaritaSheet — il conguaglio non scritto della successione (beta.48)', () => {
    // Il conguaglio non scritto: nessuna riga in saldi, quindi niente importo; la nota è facoltativa (P1).
    function successioneNonScritta(conguaglio: Record<string, unknown> = {}, extra: Record<string, unknown> = {}): any {
        return passaggio({
            tipo_passaggio: 'successione', uscente: 'Ugo', entrante: 'Anna 33,34 %, Bruno 33,33 %, Carla 33,33 %',
            arretrato: { scelta: 'defunto', frase: '€ 400,00 resta a nome di Ugo («eredi di Ugo»): ne rispondono gli eredi, ogni erede per la sua quota (art. 754 c.c.).' },
            conguaglio: { stato: 'non_scritto', importo: 0, importo_formattato: '€ 0,00', per_entrante: [], applicato: false, nota: null, nota_annullamento: null, annullato_il: null, annullabile: true, ...conguaglio },
            ...extra,
        });
    }

    // Prova P2: «nello storico lo stato si chiama "non scritto" e la scheda dice "Conguaglio non scritto"».
    test('lo stato «non scritto» si dice «Conguaglio non scritto», non «l\'amministratore ha rinunciato» né «regolato fra le parti»', () => {
        const testo = monta(successioneNonScritta()).text();
        expect(testo).toContain('Successione');
        expect(testo).toContain('Conguaglio non scritto');
        expect(testo).not.toContain('ha rinunciato');
        expect(testo).not.toContain('regolato fra le parti');
    });

    // Prova P2: «con la nota se c'è».
    test('con la nota facoltativa la scheda la riporta accanto a «Conguaglio non scritto»', () => {
        const testo = monta(successioneNonScritta({ nota: 'Gli eredi dividono fra loro, come da accordi con il notaio' })).text();
        expect(testo).toContain('Conguaglio non scritto');
        expect(testo).toContain('Gli eredi dividono fra loro, come da accordi con il notaio');
        expect(testo).not.toContain('ha rinunciato');
        expect(testo).not.toContain('regolato fra le parti');
    });

    // Prova P2: la nota solo se c'è. Senza, niente virgolette vuote né segnaposto: la rinuncia di oggi stampa la nota solo con `&& nota`.
    test('senza nota la scheda non apre virgolette vuote né stampa «null»', () => {
        const testo = monta(successioneNonScritta({ nota: null })).text();
        expect(testo).toContain('Conguaglio non scritto');
        expect(testo).not.toContain('«»');
        // Con i confini di parola: «annullato» e «annulla» contengono «null».
        expect(testo).not.toMatch(/\bnull\b/);
        expect(testo).not.toMatch(/\bundefined\b/);
    });

    // Prova P2 (e P1: nessuna riga in saldi): non c'è niente di scritto da annullare, quindi il comando delle parti che «hanno regolato diversamente» non c'è.
    test('non c\'è niente di scritto da annullare: nessun comando «annulla il conguaglio»', () => {
        const w = monta(successioneNonScritta());
        expect(w.text()).toContain('Conguaglio non scritto');
        expect(w.findAll('button').some((b) => /annulla il conguaglio/i.test(b.text()))).toBe(false);
    });

    // CONTROLLO (verde oggi): la vendita con la rinuncia e la sua nota obbligatoria (P1: la casella degli altri tipi resta com'è).
    test('controllo: la rinuncia della vendita si dice come prima, «l\'amministratore ha rinunciato», con la nota', () => {
        const testo = monta(passaggio({ conguaglio: { stato: 'rinunciato', importo: 0, importo_formattato: '€ 0,00', applicato: false, nota: 'regolato fra le parti davanti al notaio', nota_annullamento: null, annullato_il: null } })).text();
        expect(testo).toContain('Conguaglio: l\'amministratore ha rinunciato');
        expect(testo).toContain('«regolato fra le parti davanti al notaio»');
        expect(testo).not.toContain('Conguaglio non scritto');
    });

    // CONTROLLO (verde oggi): il conguaglio scritto della successione. Le coppie del caso di riferimento, rifatte a mano:
    // Ugo emesso 4 × 100,00 = 400,00; sua quota di giorni 1.200,00 × 120 / 365 = 394,52 → coppia 400,00 − 394,52 = 5,48 a credito.
    // Eredi: 1.200,00 × 245 / 365 = 805,48 → Anna 33,34 % = 268,55, Bruno 33,33 % = 268,47, Carla = 805,48 − 268,55 − 268,47 = 268,46.
    // Anna ha otto bozze da 100,00 = 800,00: 800,00 − 268,55 = 531,45 a credito. Bruno 268,47 e Carla 268,46 a debito.
    // Somma delle coppie degli eredi: −531,45 + 268,47 + 268,46 = +5,48 (debito), uguale al credito di Ugo.
    test('controllo: con «Scrivi il conguaglio» la scheda dice le righe per erede e offre ancora il comando per annullarlo', () => {
        const w = monta(successioneNonScritta({
            stato: 'proposto', importo: 548, importo_formattato: '€ 5,48', applicato: false,
            per_entrante: ['€ 531,45 a credito di Anna', '€ 268,47 a debito di Bruno', '€ 268,46 a debito di Carla'],
        }));
        const testo = w.text();
        expect(testo).toContain('Conguaglio scritto nei saldi');
        expect(testo).toContain('€ 531,45 a credito di Anna');
        expect(testo).toContain('€ 268,47 a debito di Bruno');
        expect(testo).toContain('€ 268,46 a debito di Carla');
        expect(testo).toContain('a chi esce un credito di € 5,48');
        expect(testo).not.toContain('Conguaglio non scritto');
        expect(w.findAll('button').some((b) => /annulla il conguaglio/i.test(b.text()))).toBe(true);
    });

    // Riga di un erede: come quelle di `riga()` della .38, con il passaggio della successione.
    function rigaErede(subentro: Record<string, unknown>): any {
        return {
            id: 41, anagrafica: { id: 6, nome: 'Anna', codice_fiscale: null }, tipologia: 'proprietario', diritto: 'Proprietà', quota: 33.34, attivo: true,
            data_inizio: '2026-05-01', data_fine: null, in_corso: true, futuro: false, periodo: 'dal 1 maggio 2026 · in corso', durata: '5 mesi', note: null,
            subentro: { tipo_passaggio: 'successione', sottotipo: null, decorrenza: '2026-05-01', estremi_titolo: null, copia_autentica_il: null,
                ruolo_nel_passaggio: 'entrante', documento_url: null, nota_conguaglio: null, ...subentro },
        };
    }
    const montaRiga = (subentro: Record<string, unknown>) => monta(passaggio(), { subentri: [], gruppi: [{ diritto: 'Proprietà', righe: [rigaErede(subentro)] }] }).text();

    // Prova P2: «la riga di un titolare con conguaglio_non_scritto true e nota_conguaglio null dice "conguaglio non scritto"».
    test('la riga di un erede con il conguaglio non scritto e senza nota dice «conguaglio non scritto»', () => {
        const testo = montaRiga({ conguaglio_non_scritto: true, nota_conguaglio: null });
        expect(testo).toContain('Successione');
        expect(testo).toMatch(/conguaglio non scritto/i);
        expect(testo).not.toContain('regolato fra le parti');
        // La nota solo se c'è: niente virgolette vuote né segnaposto (come per la scheda del passaggio).
        expect(testo).not.toContain('«»');
        expect(testo).not.toMatch(/\bnull\b/);
        expect(testo).not.toMatch(/\bundefined\b/);
    });

    // Prova P2: «con la nota se c'è», anche nella riga; e un non scritto non dichiara un accordo (decisione 72), nemmeno con la nota.
    test('la riga con il conguaglio non scritto e la nota riporta la nota, e non dice che le parti hanno regolato', () => {
        const testo = montaRiga({ conguaglio_non_scritto: true, nota_conguaglio: 'Gli eredi dividono fra loro' });
        expect(testo).toMatch(/conguaglio non scritto/i);
        expect(testo).toContain('Gli eredi dividono fra loro');
        expect(testo).not.toContain('regolato fra le parti');
    });

    // CONTROLLO (verde oggi): la riga con la sola nota, cioè la rinuncia della vendita e dei passaggi di prima, si legge come oggi.
    test('controllo: la riga con la sola nota (la rinuncia di prima) dice ancora «conguaglio regolato fra le parti»', () => {
        const testo = montaRiga({ tipo_passaggio: 'vendita', conguaglio_non_scritto: false, nota_conguaglio: 'davanti al notaio, il giorno del rogito' });
        expect(testo).toContain('conguaglio regolato fra le parti: «davanti al notaio, il giorno del rogito»');
        expect(testo).not.toMatch(/conguaglio non scritto/i);
    });

    // CONTROLLO (verde oggi): senza il campo (dati di prima) e senza nota, la riga non dice niente del conguaglio.
    test('controllo: una riga senza conguaglio non scritto e senza nota non dice niente del conguaglio', () => {
        const testo = montaRiga({});
        expect(testo).not.toMatch(/conguaglio non scritto/i);
        expect(testo).not.toContain('regolato fra le parti');
    });
});

/**
 * 1.11.0-beta.48 — la strada «Non scriverlo» nella scheda dello storico, per il conguaglio già scritto (rilievo R3 della lente testi,
 * Fase 1-bis di `docs/piano_esecutivo_beta48.md`).
 *
 * Nasce dal caso di Fresco registrato con «Scrivi il conguaglio», quando poi gli eredi dicono che il conguaglio non lo vogliono. Nella
 * scheda dello storico c'è il pulsante «Le parti hanno regolato diversamente: annulla il conguaglio…», che dichiara un accordo che non
 * c'è (la decisione 73, punto 1, lo vuole, lì un accordo si dichiara davvero); la strada giusta — annullare il passaggio e
 * registrarlo di nuovo con «Non scriverlo» — la dice solo la guida. Il rilievo è stato declassato a basso (le due strade portano alle
 * stesse cifre; cambiano l'etichetta registrata e le frasi che seguono), ma la scheda deve dirla. La correzione è una riga sotto il pulsante,
 * nella sola successione (il legato compreso: stessa scelta, decisione 73.2), con il conguaglio «proposto», annullabile e non ancora
 * assorbito da un piano: «Se invece il conguaglio non andava scritto, annulla il passaggio e registralo di nuovo con «Non scriverlo».»
 * Il pulsante, il segnaposto e il messaggio dopo l'annullamento restano come sono.
 *
 * Presidia: la riga nella scheda di una successione e di un legato con il conguaglio scritto e annullabile. Le cifre del caso sono
 * quelle di Fresco (€ 1.200,00 in dodici rate da € 100,00, Ugo muore il 1° maggio 2026, eredi Anna 33,34 %, Bruno e Carla 33,33 %):
 * Anna € 531,45 a credito (le otto bozze da € 800,00 meno i suoi € 268,55), Bruno € 268,47 e Carla € 268,46 a debito, Ugo € 5,48 a
 * credito (−531,45 + 268,47 + 268,46 = +5,48); nel legato Leo € 5,48 a debito, Ugo € 5,48 a credito.
 *
 * Rossi oggi, perché la scheda non ha quella riga: i due test che la cercano (successione e legato). Verdi oggi, da restare tali: il
 * pulsante «annulla il conguaglio» accanto alla riga; la vendita, che non ha la strada «Non scriverlo» (lì c'è la casella, non la
 * scelta); la successione dove la riga non deve esserci — conguaglio già assorbito da un piano, passaggio annullato, conguaglio
 * non annullabile da solo (arretrato agli eredi: «Non scriverlo» lì il server lo rifiuta), conguaglio non scritto, conguaglio
 * annullato.
 *
 * Cosa NON copre: il pannello dei saldi (`SaldiDetailPanel.vue`, dove la stessa strada si dice con un'altra frase); la riga quando
 * il passaggio non è più annullabile (il verbale la vuole lo stesso, il progetto non fissa il testo accanto: lo decide chi corregge);
 * l'annullamento vero (lo prova `AnnullaPassaggioTest`); le parole prima della frase, che il progetto non fissa.
 */
describe('TitolaritaSheet — la strada «Non scriverlo» per il conguaglio già scritto (beta.48, R3 della Fase 1-bis)', () => {
    const STRADA = 'Se invece il conguaglio non andava scritto, annulla il passaggio e registralo di nuovo con «Non scriverlo».';

    // Il caso di Fresco registrato con «Scrivi il conguaglio»: tre eredi, righe per erede, annullabile, non ancora assorbito da un piano.
    function successioneScritta(conguaglio: Record<string, unknown> = {}, extra: Record<string, unknown> = {}): any {
        return passaggio({
            tipo_passaggio: 'successione', uscente: 'Ugo', entrante: 'Anna 33,34 %, Bruno 33,33 %, Carla 33,33 %',
            arretrato: { scelta: 'defunto', frase: '€ 394,52 resta a nome di Ugo («eredi di Ugo»): ne rispondono gli eredi, ogni erede per la sua quota (art. 754 c.c.).' },
            conguaglio: {
                stato: 'proposto', importo: 548, importo_formattato: '€ 5,48', applicato: false, nota: null, nota_annullamento: null, annullato_il: null, annullabile: true,
                per_entrante: ['€ 531,45 a credito di Anna', '€ 268,47 a debito di Bruno', '€ 268,46 a debito di Carla'], ...conguaglio,
            },
            ...extra,
        });
    }

    test('la successione con il conguaglio scritto e annullabile dice la strada: annulla il passaggio e registralo di nuovo con «Non scriverlo»', () => {
        expect(monta(successioneScritta()).text()).toContain(STRADA);
    });

    // Il legato è una successione (tipo_passaggio) con il sottotipo `legato`: stessa scelta, decisione 73.2. Un legatario solo: Leo € 5,48 a debito, Ugo a credito.
    test('il legato con il conguaglio scritto e annullabile la dice allo stesso modo', () => {
        const legato = successioneScritta({ per_entrante: [] }, { sottotipo: 'legato', entrante: 'Leo' });
        expect(monta(legato).text()).toContain(STRADA);
    });

    // CONTROLLO (verde oggi): la riga si aggiunge, non sostituisce. Il pulsante che dichiara l'accordo resta (decisione 73.1).
    test('controllo: accanto alla riga resta il pulsante «annulla il conguaglio», che dichiara un accordo fra le parti', () => {
        const w = monta(successioneScritta());
        expect(w.findAll('button').some((b) => b.text() === 'Le parti hanno regolato diversamente: annulla il conguaglio…')).toBe(true);
    });

    // CONTROLLO (verde oggi): nella vendita non c'è la scelta «Non scriverlo», c'è la casella con la nota obbligatoria (decisione 73.3).
    test('controllo: la vendita con il conguaglio scritto non dice la strada «Non scriverlo»', () => {
        const testo = monta(passaggio()).text();
        expect(testo).not.toContain(STRADA);
        expect(testo).not.toContain('Non scriverlo');
    });

    // CONTROLLO (verde oggi): se un piano ha già assorbito il conguaglio, il comando non c'è e annullare dallo storico non toglie niente.
    test('controllo: con il conguaglio già assorbito da un piano la riga non c\'è', () => {
        expect(monta(successioneScritta({ applicato: true })).text()).not.toContain(STRADA);
    });

    // CONTROLLO (verde oggi): un passaggio annullato resta in elenco senza comandi né strade.
    test('controllo: un passaggio annullato non dice la strada', () => {
        const annullata = successioneScritta({}, { annullato: true, annullato_il: '28 settembre 2026', annullato_da: 'Amministratrice Ada', nota_annullamento: 'Data del decesso sbagliata', annullabile: { si: false, motivo: null, avvisi: [], effetti: [] } });
        expect(monta(annullata).text()).not.toContain(STRADA);
    });

    // CONTROLLO (verde oggi): con l'arretrato agli eredi il conguaglio non si annulla da solo, e «Non scriverlo» lì il server lo rifiuta
    // («per non scrivere il conguaglio lascia l'arretrato a nome del defunto»): la scheda dice già un'altra strada.
    test('controllo: con l\'arretrato agli eredi (conguaglio non annullabile da solo) la riga non c\'è, e resta la frase di oggi', () => {
        const agliEredi = successioneScritta({ annullabile: false }, { arretrato: { scelta: 'eredi', frase: 'L\'arretrato passa agli eredi per quota.' } });
        const w = monta(agliEredi);
        expect(w.text()).not.toContain(STRADA);
        expect(w.text()).toContain('Per cambiare la scelta annulla il passaggio e registralo di nuovo.');
    });

    // CONTROLLO (verde oggi): il conguaglio non scritto non ha niente da annullare, e il conguaglio già annullato non ha più righe.
    test.each([
        ['non scritto', { stato: 'non_scritto', importo: 0, importo_formattato: '€ 0,00', per_entrante: [] }],
        ['annullato', { stato: 'annullato', annullato_il: '28 settembre 2026', nota_annullamento: 'Gli eredi non lo vogliono', per_entrante: [] }],
    ])('controllo: con il conguaglio %s la riga non c\'è', (_nome, conguaglio) => {
        expect(monta(successioneScritta(conguaglio)).text()).not.toContain(STRADA);
    });
});
