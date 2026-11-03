/**
 * 1.11.0-beta.48 — la scelta sul conguaglio della successione: le regole della pagina (P5 del progetto, Fase 1 di
 * `docs/piano_esecutivo_beta48.md`).
 *
 * Nasce dalla risposta di Fresco (07/10/2026) e dalla decisione 72: il conguaglio fra il defunto e gli eredi, che oggi il
 * programma propone di partenza, va contro gli studi che hanno risposto, e per non scriverlo si spunta «Gli eredi hanno
 * regolato il conguaglio fra loro», cioè si dichiara un accordo che non c'è. Nella successione e nel legato (decisione 73) la
 * casella diventa una scelta senza preselezione, «Scrivi il conguaglio» o «Non scriverlo: la posizione resta com'è», e
 * ciò che la pagina deve sapere sta in due funzioni pure di questo file, le stesse per il modulo e per il pannello «Cosa cambierà».
 *
 * Presidia le due funzioni nuove: `sceltaConguaglioRichiesta(coppie, arretrato)` (la scelta si chiede solo con almeno una
 * coppia proposta e l'arretrato a nome del defunto: lo sa solo chi ha l'anteprima, quindi è una regola della pagina) e
 * `conguaglioNonScritto(coppie, arretrato, scelta)` (la scelta «non_scrivere» conta solo dove la scelta si chiede, come
 * `rinunciaEffettiva` per la spunta: il valore resta nel modulo, ma il server non lo riceve se non vale). Poi `fraseArretrato`
 * con il conguaglio non scritto: la frase senza la coppia, che a nome del defunto lascia tutta la sua posizione.
 *
 * Le cifre dei casi sono quelle del caso di riferimento (€ 1.200,00 in dodici rate da € 100,00, rate 1–4 a giornale, Ugo
 * muore il 1° maggio 2026): a nome di Ugo restano € 400,00 senza conguaglio e € 394,52 con (1.200,00 × 120 / 365 giorni).
 *
 * Verdi oggi e da restare tali: i test di `rinunciaEffettiva` e di `fraseArretrato` della .44 (la vendita con la rinuncia e la
 * nota obbligatoria non cambia) e il controllo sulla frase senza conguaglio. Rossi oggi, perché le due funzioni non esistono:
 * tutti gli altri di questo blocco («... is not a function»).
 *
 * Cosa NON copre: il server (la richiesta, l'azione, il registro: lo provano i test PHP della .48); il modulo e il pannello
 * montati (il bottone di conferma spento, il riquadro, la tabella delle coppie: nessun test di componente per
 * `PassaggioNew.vue` in questa beta); il segno dell'importo «€ 531,45 a credito», che arriva già formattato dal server.
 */
import { describe, expect, test } from 'vitest';
import { conguaglioNonScritto, fraseArretrato, rinunciaEffettiva, sceltaConguaglioRichiesta, type SceltaConguaglio } from './rinunciaConguaglio';

describe('rinunciaConguaglio — la rinuncia al conguaglio nella successione (1.11.0-beta.44, rilievi X8 e X11)', () => {
    test('la rinuncia vale solo con una coppia proposta e senza l\'arretrato agli eredi', () => {
        // Con l'arretrato agli eredi coppia e arretrato fanno un conto solo: il server la rifiuta, e il modulo non la manda.
        expect(rinunciaEffettiva(2, true, 'eredi')).toBe(false);
        expect(rinunciaEffettiva(2, true, 'defunto')).toBe(true);
        expect(rinunciaEffettiva(0, true, 'defunto')).toBe(false);
        expect(rinunciaEffettiva(2, false, null)).toBe(false);
        // Fuori dalla successione l'arretrato non c'è, e vale la regola della vendita.
        expect(rinunciaEffettiva(1, true, undefined)).toBe(true);
    });

    test('con la rinuncia la frase dell\'arretrato a nome del defunto è quella senza la coppia, che non si scrive', () => {
        const arretrato = { scelta: 'defunto' as const, frase: '€ 595,07 resta a nome di Ugo', frase_senza_conguaglio: '€ 1.200,00 resta a nome di Ugo' };
        expect(fraseArretrato(arretrato, false)).toBe('€ 595,07 resta a nome di Ugo');
        expect(fraseArretrato(arretrato, true)).toBe('€ 1.200,00 resta a nome di Ugo');
        expect(fraseArretrato({ scelta: 'eredi', frase: 'agli eredi' }, true)).toBe('agli eredi');
        expect(fraseArretrato(null, true)).toBeNull();
    });
});

describe('rinunciaConguaglio — quando la pagina chiede la scelta sul conguaglio (1.11.0-beta.48, P1 e P5)', () => {
    // Nel caso di riferimento le coppie sono quattro (Anna, Bruno, Carla, Ugo); il numero esatto non conta, conta che sia maggiore di zero.
    test('con delle coppie e l\'arretrato a nome del defunto la scelta si chiede (P5: due voci al posto della casella)', () => {
        expect(sceltaConguaglioRichiesta(4, 'defunto')).toBe(true);
        // Il legato ha la stessa scelta (decisione 73), anche con una coppia sola.
        expect(sceltaConguaglioRichiesta(1, 'defunto')).toBe(true);
    });

    test('senza coppie non c\'è niente da scrivere o da non scrivere: la scelta non si chiede (P1: solo se il pannello propone delle coppie)', () => {
        expect(sceltaConguaglioRichiesta(0, 'defunto')).toBe(false);
        expect(sceltaConguaglioRichiesta(0, 'eredi')).toBe(false);
        expect(sceltaConguaglioRichiesta(0, null)).toBe(false);
    });

    test('con l\'arretrato agli eredi la scelta non c\'è: coppia e arretrato fanno un conto solo, e il server rifiuta «non_scrivere» (P1)', () => {
        expect(sceltaConguaglioRichiesta(4, 'eredi')).toBe(false);
        expect(sceltaConguaglioRichiesta(1, 'eredi')).toBe(false);
    });

    test('fuori dalla successione l\'arretrato non c\'è e la scelta non si chiede: restano la casella e la nota obbligatoria di oggi (P1: vendita, usufrutto e locazione)', () => {
        expect(sceltaConguaglioRichiesta(1, null)).toBe(false);
        expect(sceltaConguaglioRichiesta(2, undefined)).toBe(false);
    });

    // CONTROLLO (verde oggi): la casella degli altri tipi (decisione 73, 3) vale come prima; le funzioni nuove non la toccano.
    test('controllo: con la casella spuntata, fuori dalla successione, la rinuncia vale ancora; con l\'arretrato agli eredi no (P1: la casella resta com\'è)', () => {
        expect(rinunciaEffettiva(2, true, undefined)).toBe(true);
        expect(rinunciaEffettiva(2, true, null)).toBe(true);
        expect(rinunciaEffettiva(2, true, 'eredi')).toBe(false);
        expect(rinunciaEffettiva(0, true, null)).toBe(false);
        expect(rinunciaEffettiva(2, false, null)).toBe(false);
    });
});

describe('rinunciaConguaglio — il conguaglio non scritto (1.11.0-beta.48, P1 e P5)', () => {
    test('«non_scrivere» con delle coppie e l\'arretrato a nome del defunto: il conguaglio non si scrive (P5: «Non scriverlo»)', () => {
        expect(conguaglioNonScritto(4, 'defunto', 'non_scrivere')).toBe(true);
        expect(conguaglioNonScritto(1, 'defunto', 'non_scrivere')).toBe(true);
    });

    test('«scrivi» non è «non scritto»: le righe in saldi si scrivono (P5: «Scrivi il conguaglio»)', () => {
        expect(conguaglioNonScritto(4, 'defunto', 'scrivi')).toBe(false);
    });

    test('con la scelta mancante il conguaglio non è «non scritto»: nessuna voce è già scelta, e il programma non sceglie al posto tuo (P1, P5)', () => {
        expect(conguaglioNonScritto(4, 'defunto', null)).toBe(false);
        expect(conguaglioNonScritto(4, 'defunto', undefined)).toBe(false);
    });

    test('senza coppie «non_scrivere» non vale: la scelta non si chiede (P1)', () => {
        expect(conguaglioNonScritto(0, 'defunto', 'non_scrivere')).toBe(false);
    });

    test('con l\'arretrato agli eredi «non_scrivere» non vale, ma resta nel modulo e torna valido se l\'arretrato torna a nome del defunto (P1, come la spunta della .44)', () => {
        expect(conguaglioNonScritto(4, 'eredi', 'non_scrivere')).toBe(false);
        expect(conguaglioNonScritto(4, 'defunto', 'non_scrivere')).toBe(true);
    });

    test('fuori dalla successione un «non_scrivere» rimasto nel modulo non vale: vale la casella (P1)', () => {
        expect(conguaglioNonScritto(1, null, 'non_scrivere')).toBe(false);
        expect(conguaglioNonScritto(1, undefined, 'non_scrivere')).toBe(false);
    });

    test('le due funzioni sono coerenti su tutte le combinazioni: non scritto vale solo dove la scelta si chiede, e solo con «non_scrivere» (P1, P5)', () => {
        for (const coppie of [0, 1, 4]) {
            for (const arretrato of [null, undefined, 'eredi', 'defunto']) {
                for (const scelta of [null, undefined, 'scrivi', 'non_scrivere'] as const) {
                    const atteso = sceltaConguaglioRichiesta(coppie, arretrato) && scelta === 'non_scrivere';
                    expect(conguaglioNonScritto(coppie, arretrato, scelta), `coppie ${coppie}, arretrato ${String(arretrato)}, scelta ${String(scelta)}`).toBe(atteso);
                }
            }
        }
    });
});

describe('rinunciaConguaglio — la frase dell\'arretrato con il conguaglio non scritto (1.11.0-beta.48, P5)', () => {
    // A nome di Ugo, con il conguaglio: emesso € 400,00 (rate 1–4 da € 100,00), meno la sua quota di giorni
    // 1.200,00 × 120 / 365 = 394,52 (dal 1° gennaio al 30 aprile: 31 + 28 + 31 + 30 = 120 giorni) → la coppia di Ugo è 400,00 − 394,52 = € 5,48 a credito.
    // Senza la coppia a suo nome resta tutta la sua posizione: € 400,00.
    const arretrato = { scelta: 'defunto' as const, frase: '€ 394,52 resta a nome di Ugo', frase_senza_conguaglio: '€ 400,00 resta a nome di Ugo' };

    // CONTROLLO (verde oggi): la funzione che la pagina userà con il conguaglio non scritto è questa, e resta com'è.
    test('controllo: con il conguaglio non scritto la frase è quella senza conguaglio, € 400,00 e non € 394,52 (P5: «la frase dell\'arretrato è quella senza conguaglio»)', () => {
        expect(fraseArretrato(arretrato, true)).toBe('€ 400,00 resta a nome di Ugo');
        expect(fraseArretrato(arretrato, false)).toBe('€ 394,52 resta a nome di Ugo');
        // Con l'arretrato agli eredi la frase è una sola, qualunque cosa dica il secondo argomento.
        expect(fraseArretrato({ scelta: 'eredi', frase: 'agli eredi' }, true)).toBe('agli eredi');
    });

    test('la pagina deriva il secondo argomento da `conguaglioNonScritto`: «non_scrivere» dà € 400,00 (P5)', () => {
        expect(fraseArretrato(arretrato, conguaglioNonScritto(4, 'defunto', 'non_scrivere'))).toBe('€ 400,00 resta a nome di Ugo');
    });

    // Cambiato nella Fase 1-bis (rilievo R1 della lente testi): fino alla .48 questo test fissava «finché la scelta manca, la frase è
    // quella con il conguaglio, € 394,52». Ma € 394,52 è la cifra di «Scrivi»: senza conguaglio a nome di Ugo restano € 400,00. Finché
    // la scelta manca nessuna voce è già scelta (decisione 72), e la frase non può descrivere l'esito di una sola: vedi, più sotto,
    // la frase con le due cifre.
    test('con «scrivi» la frase è quella con il conguaglio, € 394,52 (P5)', () => {
        expect(fraseArretrato(arretrato, conguaglioNonScritto(4, 'defunto', 'scrivi'))).toBe('€ 394,52 resta a nome di Ugo');
    });
});

/**
 * 1.11.0-beta.48 — la frase dell'arretrato prima della scelta: il terzo stato (rilievo R1 della lente testi, Fase 1-bis di
 * `docs/piano_esecutivo_beta48.md`).
 *
 * Nasce dal caso di Fresco senza nessuna delle due voci scelta. Il pannello e il modulo dicono «€ 394,52 resta a nome di Ugo»: ma
 * € 394,52 = € 400,00 − € 5,48 è la cifra di «Scrivi», e senza conguaglio la cifra è € 400,00. La regola a due stati di oggi
 * (`fraseArretrato(arretrato, rinuncia)`: con la rinuncia la frase senza conguaglio, altrimenti quella con) descrive la scelta
 * mancante come «Scrivi», cioè la preselezione che la decisione 72 toglie. Con le rate pagate è peggio: la frase afferma «€ 5,48 a
 * credito resta a nome di Ugo: è un credito dell'eredità», che esiste solo con «Scrivi» e con «Non scriverlo» sparisce. Il server
 * manda quindi una terza frase, `frase_da_scegliere`, con le due cifre («€ 400,00 senza conguaglio, € 394,52 se scrivi il
 * conguaglio»; con i crediti «€ 0,00 senza conguaglio, € 5,48 a credito se scrivi il conguaglio»), e `fraseArretrato` prende un terzo
 * argomento, `daScegliere` (falso se non c'è): vero, restituisce `frase_da_scegliere`.
 *
 * Presidia: `fraseArretrato(arretrato, rinuncia, daScegliere)` con le tre frasi (da scegliere, senza conguaglio, con conguaglio) sul
 * caso di riferimento e sul caso delle rate pagate; il terzo argomento omesso vale falso.
 *
 * Le cifre del caso: a nome di Ugo restano 4 × € 100,00 = € 400,00 senza conguaglio; con il conguaglio € 1.200,00 × 120 / 365 =
 * € 394,52 (dal 1° gennaio al 30 aprile: 31 + 28 + 31 + 30 = 120 giorni), cioè € 400,00 − € 5,48. Con le quattro rate pagate la
 * posizione senza conguaglio è € 0,00 e la coppia di Ugo, € 5,48, è un credito.
 *
 * Rossi oggi, perché `fraseArretrato` ignora il terzo argomento e restituisce `frase`: i test che chiedono `frase_da_scegliere`.
 * Verdi oggi, da restare tali: le frasi con e senza conguaglio, il terzo argomento omesso o falso, l'arretrato agli eredi (la
 * scelta non c'è e la frase è una sola) e la rinuncia con il credito (`frase_senza_conguaglio` nulla: nessun riquadro).
 *
 * Cosa NON copre: come il server costruisce `frase_da_scegliere` (lo prova il test PHP sull'anteprima); dove la pagina deriva
 * `daScegliere` (scelta richiesta e scelta mancante: `AnteprimaPassaggio.test.ts` lo prova montando il pannello); la frase quando
 * il server non manda la chiave (un server di prima della .48 non c'è: pagina e server si pubblicano insieme).
 */
describe('rinunciaConguaglio — la frase dell\'arretrato prima della scelta (1.11.0-beta.48, R1 della Fase 1-bis)', () => {
    const FRASE_DA_SCEGLIERE = 'L\'arretrato di Ugo resta a suo nome: € 400,00 senza conguaglio, € 394,52 se scrivi il conguaglio';
    const arretrato = {
        scelta: 'defunto' as const,
        frase: '€ 394,52 resta a nome di Ugo',
        frase_senza_conguaglio: '€ 400,00 resta a nome di Ugo',
        frase_da_scegliere: FRASE_DA_SCEGLIERE,
    };

    // Le tre condizioni che la pagina incrocia: la scelta si chiede (coppie e arretrato a nome del defunto) e non è ancora stata fatta.
    const daScegliere = (coppie: number, arretratoScelta: string | null | undefined, scelta: SceltaConguaglio | null | undefined): boolean =>
        sceltaConguaglioRichiesta(coppie, arretratoScelta) && (scelta === null || scelta === undefined);

    test('con la scelta da fare la frase è quella con le due cifre, € 400,00 senza conguaglio e € 394,52 se scrivi il conguaglio (R1)', () => {
        expect(fraseArretrato(arretrato, false, true)).toBe(FRASE_DA_SCEGLIERE);
    });

    test('la pagina deriva il terzo argomento dalla scelta richiesta e mancante: nel caso di Fresco senza nessuna voce scelta è vero (R1)', () => {
        const nonScritto = conguaglioNonScritto(4, 'defunto', null);
        expect(daScegliere(4, 'defunto', null)).toBe(true);
        expect(fraseArretrato(arretrato, nonScritto, daScegliere(4, 'defunto', null))).toBe(FRASE_DA_SCEGLIERE);
    });

    test('la frase da scegliere non è la frase del solo «Scrivi»: non dice che a nome di Ugo restano € 394,52 e basta (R1)', () => {
        expect(fraseArretrato(arretrato, false, true)).not.toBe('€ 394,52 resta a nome di Ugo');
    });

    test('la frase da scegliere non è nemmeno quella del solo «Non scriverlo»: non dice che a nome di Ugo restano € 400,00 e basta (R1)', () => {
        expect(fraseArretrato(arretrato, false, true)).not.toBe('€ 400,00 resta a nome di Ugo');
    });

    test('scelta fatta: «scrivi» dà € 394,52 e «non_scrivere» dà € 400,00, e la frase da scegliere non c\'è più (R1)', () => {
        expect(daScegliere(4, 'defunto', 'scrivi')).toBe(false);
        expect(daScegliere(4, 'defunto', 'non_scrivere')).toBe(false);
        expect(fraseArretrato(arretrato, conguaglioNonScritto(4, 'defunto', 'scrivi'), daScegliere(4, 'defunto', 'scrivi'))).toBe('€ 394,52 resta a nome di Ugo');
        expect(fraseArretrato(arretrato, conguaglioNonScritto(4, 'defunto', 'non_scrivere'), daScegliere(4, 'defunto', 'non_scrivere'))).toBe('€ 400,00 resta a nome di Ugo');
    });

    test('senza il terzo argomento, o con il terzo falso, la regola è quella di prima: con la rinuncia € 400,00, senza € 394,52', () => {
        expect(fraseArretrato(arretrato, false)).toBe('€ 394,52 resta a nome di Ugo');
        expect(fraseArretrato(arretrato, true)).toBe('€ 400,00 resta a nome di Ugo');
        expect(fraseArretrato(arretrato, false, false)).toBe('€ 394,52 resta a nome di Ugo');
        expect(fraseArretrato(arretrato, true, false)).toBe('€ 400,00 resta a nome di Ugo');
    });

    test('la scelta non si chiede senza coppie né con l\'arretrato agli eredi, o fuori dalla successione: il terzo argomento è falso (R1)', () => {
        expect(daScegliere(0, 'defunto', null)).toBe(false);
        expect(daScegliere(4, 'eredi', null)).toBe(false);
        expect(daScegliere(1, null, null)).toBe(false);
        expect(daScegliere(1, undefined, undefined)).toBe(false);
    });

    // Con `daScegliere` vero l'arretrato agli eredi non si prova: la pagina non lo passa mai (la scelta non si chiede) e il progetto non
    // dice cosa farebbe la funzione, quindi nessuna asserzione lo fissa.
    test('controllo: con l\'arretrato agli eredi la frase è una sola, qualunque cosa dica il secondo argomento', () => {
        const agliEredi = { scelta: 'eredi' as const, frase: 'L\'arretrato passa agli eredi per quota' };
        expect(fraseArretrato(agliEredi, false, false)).toBe('L\'arretrato passa agli eredi per quota');
        expect(fraseArretrato(agliEredi, true, false)).toBe('L\'arretrato passa agli eredi per quota');
    });

    test('controllo: senza arretrato (la vendita) non c\'è nessuna frase, anche con il terzo argomento', () => {
        expect(fraseArretrato(null, false, true)).toBeNull();
        expect(fraseArretrato(undefined, false, true)).toBeNull();
    });

    // Le quattro rate emesse sono pagate: a nome di Ugo, senza conguaglio, non resta niente (4 × € 100,00 pagati → € 0,00), e con il
    // conguaglio la sua coppia, € 5,48, è un credito dell'eredità. La frase senza conguaglio non c'è: nessun riquadro.
    describe('con le rate emesse pagate (un credito che esiste solo con «Scrivi»)', () => {
        const FRASE_DA_SCEGLIERE_CREDITO = 'La posizione di Ugo resta a suo nome: € 0,00 senza conguaglio, € 5,48 a credito se scrivi il conguaglio';
        const pagate = {
            scelta: 'defunto' as const,
            frase: '€ 5,48 a credito resta a nome di Ugo: è un credito dell\'eredità, che lo studio regola con gli eredi.',
            frase_senza_conguaglio: null,
            frase_da_scegliere: FRASE_DA_SCEGLIERE_CREDITO,
        };

        test('prima della scelta la frase dice le due cifre, € 0,00 senza conguaglio e € 5,48 a credito se scrivi il conguaglio (R1)', () => {
            expect(fraseArretrato(pagate, false, true)).toBe(FRASE_DA_SCEGLIERE_CREDITO);
        });

        test('prima della scelta non afferma il credito come se fosse già deciso: non è la frase di «Scrivi» (R1)', () => {
            expect(fraseArretrato(pagate, false, true)).not.toBe(pagate.frase);
        });

        test('controllo: con «scrivi» il credito dell\'eredità si dice, con «Non scriverlo» il riquadro non c\'è', () => {
            expect(fraseArretrato(pagate, false)).toBe(pagate.frase);
            expect(fraseArretrato(pagate, true)).toBeNull();
        });
    });
});
