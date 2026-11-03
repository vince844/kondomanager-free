import { describe, expect, test } from 'vitest';
import { proponiPosizioneRitenuta } from './posizioneRitenuta';

/**
 * ⚠️ **Questi test misurano una proposta, non una decisione.** La differenza non è retorica:
 * se un giorno qualcuno usasse questo modulo per impostare la ritenuta senza mostrarla, il
 * programma starebbe tirando a indovinare su un dato fiscale — che è esattamente ciò che la
 * beta.15 ha appena tolto dall'F24.
 */
describe('quando il documento dichiara il regime forfetario', () => {
    test('propone «non soggetto», e lo dichiara come fatto del file', () => {
        const p = proponiPosizioneRitenuta({ regime_forfetario: true });

        expect(p.soggetto).toBe(false);
        expect(p.tipo).toBeNull();
        expect(p.fondamento).toBe('dichiarato_dal_file');
        expect(p.spiegazione).toContain('RF19');
    });

    test('il forfetario batte gli indizi, anche tutti insieme', () => {
        // ⚠️ È il caso che tiene stretta la regola. Un forfetario può benissimo essere una
        // persona fisica con una cassa previdenziale: se vincesse l'indizio, si proporrebbe
        // «soggetto» per un fornitore su cui `RitenutaService` esce con
        // `MotivoEsclusioneRitenuta::FORFETARIO` senza nemmeno guardare la spunta.
        const p = proponiPosizioneRitenuta({
            regime_forfetario: true,
            e_persona_fisica: true,
            ha_cassa_previdenziale: true,
        });

        expect(p.soggetto).toBe(false);
    });
});

describe('quando il documento porta un indizio di prestazione professionale', () => {
    test('la cassa previdenziale propone il lavoro autonomo al 20%', () => {
        // È il decimo file di collaudo: geometra, cassa TC03, e nessun blocco DatiRitenuta.
        const p = proponiPosizioneRitenuta({ ha_cassa_previdenziale: true, e_persona_fisica: true });

        expect(p.soggetto).toBe(true);
        expect(p.tipo).toBe('lavoro_autonomo_20');
        expect(p.fondamento).toBe('indizio');
        expect(p.spiegazione).toContain('cassa previdenziale');
    });

    test('la sola persona fisica basta, e la spiegazione lo dice', () => {
        // Senza cassa la spiegazione deve cambiare: dire «cassa previdenziale» dove non c'è
        // renderebbe la proposta inverificabile — chi legge andrebbe a cercarla nel file.
        const p = proponiPosizioneRitenuta({ e_persona_fisica: true });

        expect(p.soggetto).toBe(true);
        expect(p.spiegazione).toContain('persona fisica');
        expect(p.spiegazione).not.toContain('cassa previdenziale');
    });

    test('l\'indizio non si spaccia mai per un fatto del file', () => {
        // ⚠️ Il fondamento è ciò che a schermo distingue «il documento lo dice» da «il
        // documento lo suggerisce», e le due frasi non possono somigliarsi.
        const p = proponiPosizioneRitenuta({ e_persona_fisica: true });

        expect(p.fondamento).not.toBe('dichiarato_dal_file');
    });
});

describe('quando il file non dice niente', () => {
    test('propone «non soggetto» ma avverte che il silenzio non è una risposta', () => {
        // ⚠️ È il punto di tutta la Coda 116: sei degli undici file veri non dichiarano nessuna
        // ritenuta, e l'assenza del blocco non significa che non sia dovuta. La proposta più
        // probabile resta «non soggetto» — la maggior parte dei fornitori di un condominio è
        // un'impresa che fattura in appalto sotto soglia o una fornitura — ma la frase deve
        // dire che la fattura non lo dichiara mai.
        const p = proponiPosizioneRitenuta({});

        expect(p.soggetto).toBe(false);
        expect(p.fondamento).toBe('nessun_segnale');
        // ⚠️ La frase NON deve chiudere con «la fattura non lo dichiara mai»: quella è già la
        // prima riga del riquadro a schermo, e ripeterla tre righe più giù era il difetto
        // trovato a video il 04/09/2026. Qui si controlla che l'informazione ci sia ancora —
        // la ritenuta è dovuta lo stesso — senza il doppione.
        expect(p.spiegazione).toContain('dovuta lo stesso');
        expect(p.spiegazione).not.toContain('non lo dichiara mai');
    });

    test('i segnali assenti valgono come falsi, non come indizi', () => {
        // Un `undefined` che diventasse «vero» proporrebbe una ritenuta a chiunque.
        const p = proponiPosizioneRitenuta({
            regime_forfetario: undefined,
            e_persona_fisica: undefined,
            ha_cassa_previdenziale: undefined,
        });

        expect(p.soggetto).toBe(false);
        expect(p.fondamento).toBe('nessun_segnale');
    });
});

// ⛔ **Qui c'erano cinque test sull'anteprima, e sono stati tolti il 04/09/2026.**
//
// Ricostruivano `fornitoreConLaRisposta` di `FatturaRegisterNew.vue` con una **replica** della
// sua logica, e la controprova lo ha smascherato: riportando il componente al difetto restavano
// tutti verdi. Una replica non protegge niente — protegge se stessa — ed è la forma più elegante
// di guardia inerte, perché il file di test ha il nome giusto e le asserzioni sembrano quelle.
//
// La protezione vera sta in `pages/gestionale/movimenti/fatture/FatturaRegisterNew.test.ts`,
// «la posizione sulla ritenuta chiesta al volo alimenta l'anteprima», dove il componente viene
// montato davvero: lì la controprova morde.
