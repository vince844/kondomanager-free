// @vitest-environment jsdom

/**
 * 1.11.0-beta.50 — il pannello «Cosa cambierà» alla fine di una locazione, montato (decisioni 77, 79 e 80; verbale
 * `docs/piano_esecutivo_beta50.md`).
 *
 * Alla fine di una locazione, senza un nuovo inquilino o con uno (il cambio d'inquilino), quando il pannello propone coppie ci sono
 * le due voci della successione della .48, «Scrivi il conguaglio» e «Non scriverlo», senza nessuna già scelta, al posto della casella
 * «Le parti hanno regolato il conguaglio fra loro». Con un coinquilino che resta il pannello non propone coppie: né la scelta né la
 * casella, e la frase della fermata (decisioni 79 e 80) arriva dal server fra le frasi del blocco 2.
 *
 * I JSON sono quelli dell'anteprima vera, ricavati con uno script PHP sugli scenari di `ScenariFineLocazione.php` (prefisso `fcl`: Ugo
 * proprietario dell'Interno 1, Ines inquilina dal 2019, «Spese generali» € 1.200,00 con il 30 % all'inquilino, le rate gennaio–giugno
 * a giornale, Ines esce il 1/7), poi alleggeriti delle chiavi che il pannello non legge. Le cifre: la parte dell'inquilino è € 360,00;
 * 36000 × 184 / 365 = 18147,95 → la coppia € 181,48 (Ines 17852, il centesimo al resto più grande); con Ugo al 60 % e Rita al 40 %
 * 18148 × 0,6 = 10888,8 e 18148 × 0,4 = 7259,2 → Ugo 10889, Rita 7259. Con Ines e Dora al 50 % la parte di Ines è € 180,00 e i suoi
 * giorni dopo l'uscita € 90,74 (18000 × 184 / 365 = 9073,97 → 9074).
 *
 * Presidia: le due voci senza preselezione e senza la casella, nella fine senza un nuovo inquilino e nel cambio d'inquilino; i testi
 * delle due voci per la locazione (chi paga al posto dell'inquilino o chi entra; la strada A′ con il prospetto degli oneri accessori);
 * il riquadro «da scegliere», «proposto», «non scritto» e il piede della tabella che segue la scelta; le frasi del calcolo che
 * spariscono con «Non scriverlo»; la tabella delle coppie per persona con due persone al posto dell'inquilino; il coinquilino senza
 * scelta né casella, con la frase della fermata. Controllo: la vendita tiene la casella.
 *
 * Cosa NON copre: il server (le frasi, le cifre, il cancello: i test PHP della .50); il modulo `PassaggioNew.vue` (la conferma spenta
 * finché la scelta manca, la scelta che si rifà quando cambia chi esce o chi entra, la scelta mandata con l'anteprima); il modo scuro
 * e le larghezze (verifica a video).
 */

import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';

import AnteprimaPassaggio from './AnteprimaPassaggio.vue';

type Scelta = 'scrivi' | 'non_scrivere' | null;

const CASELLA = 'Le parti hanno regolato il conguaglio fra loro';
const CALCOLO = 'Sulla gestione Ordinaria 2026: credito € 181,48 a Ines, debito € 181,48 a Ugo';

const emesse = (importo: number, formattato: string) => [1, 2, 3, 4, 5, 6].map((rata) => ({
    rata, scadenza: `2026-0${rata}-05`, importo, importo_formattato: formattato, intestatario: 'Ines', piano: 'Preventivo 2026', natura: 'ordinaria',
}));

/** La riga per gestione della coppia: 181 giorni a Ines, 184 dopo l'uscita, € 181,48. */
const perGestione = {
    gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: 1, immobile_nome: 'Interno 1', natura: 'ordinaria', gradino: ['gestione'],
    periodo: [{ dal: '2026-01-01', al: '2026-12-31' }], voce_per_voce: false, quote: 12, quota_pura: 36000, pregressi: 0,
    giorni_uscente: 181, giorni_entrante: 184, giorni_periodo: 365, importo: 18148, importo_formattato: '€ 181,48',
    importo_lordo: 18148, importo_lordo_formattato: '€ 181,48', bozze_passate: 0, bozze_passate_importo: 0, bozze_passate_formattato: '€ 0,00',
    non_risolte: 0, non_separabili: 0, mai_passate: 0, escluse: 0, esercizio_id: 1,
};

const coppia = (id: number, nome: string, importo: number, formattato: string) => ({
    gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: 1, esercizio_id: 1, importo, importo_formattato: formattato, per_nudo: null,
    anagrafica_entrante_id: id, entrante_nome: nome,
});

const comuni = {
    nudi: null,
    obbligati: { frasi: ['Le rate già emesse a Ines restano sue.'], copia_autentica_mancante: false },
    invarianti: { frasi: ['Millesimi: invariati.', 'Tabelle millesimali: invariate.'] },
    ordinaria: { applicabile: false, scelta: null, usufruttuario: null, nudo: null, voci: [], frasi: [], frasi_bloccate: [], frasi_altri_usufrutti: [], impronta: null, ereditata: null },
};

/**
 * La fine della locazione di Ines il 1/7 senza un nuovo inquilino, come la manda il server: la coppia va a chi paga al posto
 * dell'inquilino. `rita`: Ugo al 60 % e Rita al 40 %, una coppia per persona.
 */
function fineSenza(rita = false): any {
    const coppie = rita ? [coppia(1, 'Ugo', 10889, '€ 108,89'), coppia(5, 'Rita', 7259, '€ 72,59')] : [coppia(1, 'Ugo', 18148, '€ 181,48')];
    const chi = rita ? 'Ugo e Rita' : 'Ugo';

    return {
        ...comuni,
        riferimento: { uscente_fino_al: '2026-06-30', entrante_dal: '2026-07-01', frase: 'Ines risulterà inquilino fino al 30 giugno 2026 compreso.' },
        anagrafica: { frasi: ['Ines risulterà inquilino fino al 30 giugno 2026.'], pertinenze: [] },
        rate: {
            stato: 'calcolato', emesse: emesse(3000, '€ 30,00'), piani_distinti: 1, totale_emesso: 18000, totale_emesso_formattato: '€ 180,00',
            altre: { quote: 6, intestatari: ['Ugo'] }, morosita: { importo: 18000, importo_formattato: '€ 180,00', intestatario: 'Ines' },
            conguaglio: {
                stato: 'calcolato', anagrafica_uscente_id: 2, anagrafica_entrante_id: 1, quote: [], per_gestione: [perGestione], coppie,
                totale_entrante: 18148, totale_entrante_formattato: '€ 181,48', totale_entrante_assoluto_formattato: '€ 181,48',
                riassegnazione: [], pregressi: 0, non_risolte: [], frasi: [],
            },
            frasi: [
                `Le rate già emesse non si toccano. Se scrivi il conguaglio, i giorni dopo l'uscita di Ines (€ 181,48) passano a chi paga al posto dell'inquilino, ${chi}: le righe del conguaglio entrano nei saldi e sommano a zero, sulla gestione di ciascun piano, con la quota divisa in proporzione ai giorni. Se non lo scrivi, nei saldi non si scrive niente: i giorni dopo l'uscita restano nelle rate di Ines, e le parti li regolano fra loro con il prospetto degli oneri accessori.`,
                'Ines ha € 180,00 scaduti e non pagati. Restano suoi: il conguaglio si calcola sulla competenza, non sui pagamenti.',
            ],
            frasi_del_conguaglio: [
                `Sulla gestione Ordinaria 2026: credito € 181,48 a Ines, debito € 181,48 a ${chi} — la quota ordinaria (€ 360,00 su 12 quote) è divisa in proporzione ai giorni di competenza: 181 a Ines, 184 a ${chi}.`,
                ...(rita ? ['Sulla gestione Ordinaria 2026 il debito di € 181,48 si divide fra chi paga al posto dell\'inquilino nei giorni dopo l\'uscita, per quota e per giorni: € 108,89 a Ugo, € 72,59 a Rita.'] : []),
            ],
            scelta_del_conguaglio: true,
            fermata_coinquilino: [],
        },
        cancello: { richiesto: true, motivi: ['6 quote di rate già emesse a Ines su questa unità'], informazioni: [], avvisi: [] },
    };
}

/** Il cambio d'inquilino: Luca entra il 1/7, la coppia è fra Ines e Luca. */
function cambio(): any {
    const dati = fineSenza();
    dati.anagrafica.frasi = ['Ines risulterà inquilino fino al 30 giugno 2026.', 'Luca inquilino dal 1 luglio 2026, al 100 %.'];
    dati.rate.conguaglio.coppie = [coppia(3, 'Luca', 18148, '€ 181,48')];
    dati.rate.conguaglio.anagrafica_entrante_id = 3;
    dati.rate.frasi[0] = 'Le rate già emesse non si toccano. Se scrivi il conguaglio, le sue righe fra Ines e Luca, che entra come inquilino, entrano nei saldi e sommano a zero, sulla gestione di ciascun piano: la quota è divisa in proporzione ai giorni, e € 181,48 dei giorni dopo l\'uscita passano a Luca. Se non lo scrivi, nei saldi non si scrive niente: i giorni dopo l\'uscita restano nelle rate di Ines, e Luca glieli rimborsa con il prospetto degli oneri accessori.';
    dati.rate.frasi_del_conguaglio = ['Sulla gestione Ordinaria 2026: credito € 181,48 a Ines, debito € 181,48 a Luca — la quota ordinaria (€ 360,00 su 12 quote) è divisa in proporzione ai giorni di competenza: 181 a Ines, 184 a Luca.'];

    return dati;
}

/** La fermata del coinquilino (Ines e Dora al 50 %, Ines esce, Dora resta): nessuna coppia, la frase con la cifra e la persona. */
const FERMATA = 'La locazione continua con Dora: il programma non propone il conguaglio dei giorni dopo l\'uscita di Ines (€ 90,74 sulla gestione Ordinaria 2026), che restano nelle rate di Ines, e non sceglie chi li paga: lo decidono le parti. Se li paga Dora, o il proprietario, registra un saldo manuale dal Wallet sulla stessa gestione: una riga a credito di Ines e una a debito di chi li paga.';
function coinquilino(): any {
    const dati = fineSenza();
    dati.rate.emesse = emesse(1500, '€ 15,00');
    dati.rate.totale_emesso = 9000;
    dati.rate.totale_emesso_formattato = '€ 90,00';
    dati.rate.morosita = { importo: 9000, importo_formattato: '€ 90,00', intestatario: 'Ines' };
    dati.rate.conguaglio = null;
    dati.rate.frasi = [
        'Le rate già emesse non si toccano: le 6 quote intestate a Ines (€ 90,00) restano sue.',
        'Ines ha € 90,00 scaduti e non pagati. Restano suoi: il conguaglio si calcola sulla competenza, non sui pagamenti.',
        FERMATA,
    ];
    dati.rate.frasi_del_conguaglio = [];
    dati.rate.scelta_del_conguaglio = false;
    dati.rate.fermata_coinquilino = [{ immobile_id: 1, unita: 'Interno 1', gestione_id: 1, gestione: 'Ordinaria 2026', esercizio_id: 1, cifra: 9074, persona_id: 2, persona: 'Ines', restano: ['Dora'], wallet: true }];

    return dati;
}

function monta(dati: any, scelta: Scelta = null, senzaSuccessore = true, tipo = 'fine_locazione') {
    return mount(AnteprimaPassaggio, { props: { dati, inCorso: false, errore: false, mancante: [], tipo, senzaSuccessore, sceltaConguaglio: scelta } });
}

const radio = (w: ReturnType<typeof monta>) => w.findAll('input[type="radio"]').map((r) => r.element as HTMLInputElement);

describe('AnteprimaPassaggio — la fine di una locazione senza un nuovo inquilino (1.11.0-beta.50, decisione 77)', () => {
    test('le due voci ci sono e nessuna è già scelta; la casella di prima non c\'è', () => {
        const w = monta(fineSenza());
        expect(radio(w).map((r) => r.value)).toEqual(['scrivi', 'non_scrivere']);
        expect(radio(w).map((r) => r.checked)).toEqual([false, false]);
        expect(w.text()).toContain('Scrivi il conguaglio');
        expect(w.text()).toContain('Non scriverlo');
        expect(w.text()).not.toContain(CASELLA);
        expect(w.findAll('button[role="checkbox"]')).toHaveLength(0);
        expect(w.text()).toContain('Scegli se scrivere il conguaglio.');
    });

    test('prima della scelta il riquadro dice «conguaglio da scegliere», e il calcolo si vede: serve a scegliere', () => {
        const testo = monta(fineSenza()).text();
        expect(testo).toContain('conguaglio da scegliere');
        expect(testo).not.toContain('conguaglio proposto');
        expect(testo).toContain(CALCOLO);
    });

    test('le voci dicono la locazione: i giorni dopo l\'uscita a chi paga al posto dell\'inquilino, o nelle rate di chi esce con il prospetto', () => {
        const testo = monta(fineSenza()).text();
        expect(testo).toContain('I giorni dopo l\'uscita passano a chi paga al posto dell\'inquilino: le righe in saldi rimettono ognuno ai suoi giorni.');
        expect(testo).toContain('Nessuna riga in saldi: i giorni dopo l\'uscita restano nelle rate di chi esce, e le parti li regolano fra loro con il prospetto degli oneri accessori.');
        // Nessuna parola della successione.
        expect(testo).not.toMatch(/erede|defunto/);
    });

    test('il piede della tabella non parla di chi entra: nessuno entra, la coppia va a chi paga dopo l\'uscita', () => {
        const testo = monta(fineSenza()).text();
        expect(testo).toContain('Debito a chi paga dopo l\'uscita, credito a chi esce');
        expect(testo).not.toContain('Debito a chi entra');
    });

    test('con «Non scriverlo»: «conguaglio non scritto», nessuna riga in saldi, il calcolo sparisce e la nota facoltativa è della locazione', () => {
        const w = monta(fineSenza(), 'non_scrivere');
        const testo = w.text();
        expect(testo).toContain('conguaglio non scritto');
        expect(testo).toContain('Non scritte: nessuna riga in saldi');
        expect(testo).not.toContain(CALCOLO);
        expect(testo).not.toContain('Scegli se scrivere il conguaglio.');
        expect(w.find('input[placeholder^="Nota (facoltativa)"]').attributes('placeholder')).toContain('nessuno chiede i giorni dopo l\'uscita');
    });

    test('con «Scrivi»: «conguaglio proposto», il calcolo resta e il totale è la coppia, € 181,48', () => {
        const testo = monta(fineSenza(), 'scrivi').text();
        expect(testo).toContain('conguaglio proposto');
        expect(testo).toContain(CALCOLO);
        expect(testo).toContain('€ 181,48');
        expect(testo).not.toContain('conguaglio da scegliere');
    });

    test('la scelta passa davvero dal pannello al modulo: un clic su «Non scriverlo» e poi su «Scrivi»', async () => {
        const scelta = ref<Scelta>(null);
        const Genitore = defineComponent({
            setup: () => () => h(AnteprimaPassaggio, {
                dati: fineSenza(), inCorso: false, errore: false, mancante: [], tipo: 'fine_locazione', senzaSuccessore: true,
                sceltaConguaglio: scelta.value, 'onUpdate:sceltaConguaglio': (v: Scelta) => { scelta.value = v; },
            }),
        });
        const w = mount(Genitore);
        await w.findAll('input[type="radio"]')[1].setValue(true);
        expect(scelta.value).toBe('non_scrivere');
        expect(w.text()).toContain('conguaglio non scritto');
        await w.findAll('input[type="radio"]')[0].setValue(true);
        expect(scelta.value).toBe('scrivi');
        expect(w.text()).toContain('conguaglio proposto');
    });

    test('con due persone al posto dell\'inquilino una coppia ciascuna, con il nome: € 108,89 a Ugo e € 72,59 a Rita; una scelta sola', () => {
        const w = monta(fineSenza(true));
        const celle = w.findAll('td').map((td) => td.text());
        expect(celle.some((c) => c === 'a Ugo')).toBe(true);
        expect(celle.some((c) => c === 'a Rita')).toBe(true);
        expect(celle.some((c) => c.includes('€ 108,89'))).toBe(true);
        expect(celle.some((c) => c.includes('€ 72,59'))).toBe(true);
        expect(radio(w)).toHaveLength(2);
        expect(w.text()).toContain('a ognuno per la sua parte');
    });
});

describe('AnteprimaPassaggio — il cambio d\'inquilino (1.11.0-beta.50, decisione 77, punto 2)', () => {
    test('le due voci al posto della casella, nessuna già scelta', () => {
        const w = monta(cambio(), null, false);
        expect(radio(w).map((r) => r.checked)).toEqual([false, false]);
        expect(w.text()).not.toContain(CASELLA);
        expect(w.text()).toContain('conguaglio da scegliere');
    });

    test('le voci dicono chi entra: i giorni dopo l\'uscita passano a lui, o chi entra li rimborsa con il prospetto', () => {
        const testo = monta(cambio(), null, false).text();
        expect(testo).toContain('I giorni dopo l\'uscita passano a chi entra: le righe in saldi rimettono ognuno ai suoi giorni.');
        expect(testo).toContain('Nessuna riga in saldi: i giorni dopo l\'uscita restano nelle rate di chi esce, e chi entra glieli rimborsa con il prospetto degli oneri accessori.');
        expect(testo).not.toContain('chi paga al posto dell\'inquilino: le righe');
    });

    test('il piede resta «Debito a chi entra, credito a chi esce» con la coppia, e «Non scritte» con «Non scriverlo»', () => {
        expect(monta(cambio(), 'scrivi', false).text()).toContain('Debito a chi entra, credito a chi esce');
        expect(monta(cambio(), 'non_scrivere', false).text()).toContain('Non scritte: nessuna riga in saldi');
    });
});

describe('AnteprimaPassaggio — il coinquilino che resta (1.11.0-beta.50, decisioni 79 e 80)', () => {
    test('nessuna scelta e nessuna casella: il pannello non propone coppie', () => {
        const w = monta(coinquilino());
        expect(radio(w)).toHaveLength(0);
        expect(w.text()).not.toContain(CASELLA);
        expect(w.text()).not.toContain('Scegli se scrivere il conguaglio.');
        expect(w.text()).not.toMatch(/conguaglio (da scegliere|proposto|non scritto)/);
    });

    test('la frase della fermata si vede, con la cifra, la persona e la strada a mano', () => {
        expect(monta(coinquilino()).text()).toContain(FERMATA);
    });
});

describe('controllo: la vendita tiene la casella (decisione 73.3)', () => {
    test('con le coppie della vendita c\'è la casella, non le due voci', () => {
        const dati = cambio();
        dati.rate.scelta_del_conguaglio = false;
        const w = monta(dati, null, false, 'vendita');
        expect(radio(w)).toHaveLength(0);
        expect(w.text()).toContain(CASELLA);
    });
});

// ---------------------------------------------------------------------------------------------------------------------------------
// Aggiunti con il giro di mutazioni sulle correzioni della .50 (mutazioni sopravvissute ai test qui sopra).
// ---------------------------------------------------------------------------------------------------------------------------------

describe('AnteprimaPassaggio — le mutazioni sopravvissute (1.11.0-beta.50)', () => {
    test('M149 — la coppia rovesciata della fine senza un nuovo inquilino: il piede dice «Credito a chi paga dopo l\'uscita, debito a chi esce»', () => {
        const dati = fineSenza();
        dati.rate.conguaglio.totale_entrante = -18148;
        dati.rate.conguaglio.coppie = [coppia(1, 'Ugo', -18148, '€ -181,48')];
        expect(monta(dati, 'scrivi').text()).toContain('Credito a chi paga dopo l\'uscita, debito a chi esce');
    });

    test('M152 — con «Non scriverlo» la voce della fine senza un nuovo inquilino ha una frase sola, non anche «le rate restano a chi le ha»', () => {
        const testo = monta(fineSenza(), 'non_scrivere').text();
        expect(testo).toContain('Nessuna riga in saldi: i giorni dopo l\'uscita restano nelle rate di chi esce, e le parti li regolano fra loro');
        expect(testo).not.toContain('Nessuna riga in saldi: le rate restano a chi le ha.');
    });

    test('M155 — nella successione le voci restano quelle della successione, non quelle della locazione', () => {
        const dati = fineSenza();
        dati.rate.arretrato = { scelta: 'defunto', legato: false, resta: 0, resta_formattato: '€ 0,00', eredi: [], totale: 0, frase: 'Resta a nome del defunto la sua posizione.' };
        const testo = monta(dati, null, false, 'successione').text();
        expect(testo).toContain('Le righe in saldi rimettono ognuno ai suoi giorni');
        expect(testo).not.toContain('I giorni dopo l\'uscita passano');
    });

    test('M148 — montato senza il tipo, con l\'arretrato del defunto il pannello si comporta da successione: «Non scriverlo» mostra la frase senza conguaglio', () => {
        const dati = fineSenza();
        dati.rate.arretrato = { scelta: 'defunto', legato: false, resta: 0, resta_formattato: '€ 0,00', eredi: [], totale: 0,
            frase: 'CON IL CONGUAGLIO', frase_senza_conguaglio: 'SENZA IL CONGUAGLIO', frase_da_scegliere: 'DA SCEGLIERE' };
        const w = mount(AnteprimaPassaggio, { props: { dati, inCorso: false, errore: false, mancante: [], sceltaConguaglio: 'non_scrivere' } });
        expect(w.text()).toContain('SENZA IL CONGUAGLIO');
        expect(w.text()).not.toContain('CON IL CONGUAGLIO');
    });
});
