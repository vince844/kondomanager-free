// @vitest-environment jsdom

/**
 * Coda 261 — l'avvio del ripristino, fermato dal limite delle prove della password, lo dice sotto la password
 * dell'account, nella lingua della pagina.
 *
 * L'avvio chiede la password dell'account, e conta fra le prove della password attuale (sei al minuto e trenta
 * all'ora, con la conferma della password, il cambio della password e il profilo). Oltre il limite il server
 * risponde 429 con il messaggio del framework, in inglese, e con i secondi da aspettare in `Retry-After`. La
 * pagina metteva ogni errore che non fosse 422 sotto il campo dell'archivio, con quel messaggio.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { reactive } from 'vue';
import { flushPromises, shallowMount } from '@vue/test-utils';

const post = vi.fn();
const get = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    router: { visit: vi.fn(), delete: vi.fn(), post: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { flash: {} } }),
    useForm: (dati: Record<string, unknown>) =>
        reactive({ ...dati, errors: {}, processing: false, post: vi.fn(), reset: vi.fn(), data: () => ({ ...dati }) }),
}));

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string, quanti: number, valori?: Record<string, unknown>) => `${chiave} ${quanti} ${JSON.stringify(valori ?? {})}`,
}));
vi.mock('axios', () => ({ default: { post, get } }));

(globalThis as Record<string, unknown>).route = (nome: string, parametro?: string) => (parametro ? `/${nome}/${parametro}` : `/${nome}`);

const PaginaBackup = (await import('./impostazioniBackups.vue')).default;
const { annullaConferma, confermaPasswordAperta, confermaRiuscita } = await import('@/composables/useConfermaPassword');

type Stato = {
    askRestore: (backup: Record<string, unknown>) => void;
    submitRestore: () => Promise<void>;
    restoreErrors: Record<string, string>;
    restoreAccountPassword: string;
    restoreRunning: boolean;
};

const BACKUP = { uuid: 'b-1', status: 'completed', type: 'full', encrypted: false };

function monta(): Stato {
    const pagina = shallowMount(PaginaBackup, {
        props: {
            backups: { data: [BACKUP] },
            runningBackup: null,
            preflight: { encryption_supported: true, checks: [] },
            retention_keep_last: 5,
            backup_has_password: false,
        },
    });

    return (pagina.vm as unknown as { $: { setupState: Stato } }).$.setupState;
}

describe('impostazioniBackups — avvio del ripristino', () => {
    test('Coda 261 — il 429 delle prove della password va sotto la password dell\'account, con i minuti da aspettare', async () => {
        post.mockReset();
        post.mockRejectedValueOnce({ response: { status: 429, headers: { 'retry-after': '125' }, data: { message: 'Too Many Attempts.' } } });
        const stato = monta();
        stato.askRestore(BACKUP);
        stato.restoreAccountPassword = 'la-mia-password';

        await stato.submitRestore();

        expect(stato.restoreErrors).toEqual({ account_password: 'impostazioni.restore_error_too_many 3 {"count":"3"}' });
        expect(stato.restoreRunning).toBe(false);
    });

    test('Coda 261 — senza i secondi da aspettare il messaggio del 429 non promette un tempo', async () => {
        post.mockReset();
        post.mockRejectedValueOnce({ response: { status: 429, headers: {}, data: { message: 'Too Many Attempts.' } } });
        const stato = monta();
        stato.askRestore(BACKUP);

        await stato.submitRestore();

        expect(stato.restoreErrors).toEqual({ account_password: 'impostazioni.restore_error_too_many_later' });
    });

    test('un errore che non è 422 né 429 resta sotto l\'archivio, con il messaggio del server', async () => {
        post.mockReset();
        post.mockRejectedValueOnce({ response: { status: 500, headers: {}, data: { message: 'Archivio illeggibile.' } } });
        const stato = monta();
        stato.askRestore(BACKUP);

        await stato.submitRestore();

        expect(stato.restoreErrors).toEqual({ archive: 'Archivio illeggibile.' });
    });

    test('un 422 resta sui campi che il server indica', async () => {
        post.mockReset();
        post.mockRejectedValueOnce({ response: { status: 422, headers: {}, data: { errors: { account_password: ['La password del tuo account non è corretta.'] } } } });
        const stato = monta();
        stato.askRestore(BACKUP);

        await stato.submitRestore();

        expect(stato.restoreErrors).toEqual({ account_password: 'La password del tuo account non è corretta.' });
    });

    test('aperta e confermata senza toccare niente, la finestra manda i valori con cui si apre', async () => {
        post.mockReset();
        post.mockRejectedValueOnce({ response: { status: 422, headers: {}, data: { errors: {} } } });
        const stato = monta();
        stato.askRestore(BACKUP);

        await stato.submitRestore();

        expect(post).toHaveBeenCalledWith('/impostazioni.backups.restore.start/b-1', {
            account_password: '',
            password: null,
            safety_backup: true,
            adopt_app_key: false,
        });
    });
});

describe('impostazioniBackups — il ripristino fermo nel backup di sicurezza', () => {
    type StatoDelRipristino = Stato & { resumeRestore: () => Promise<void>; restoreToken: string | null };

    const FRASE = 'Il backup di sicurezza non è riuscito: il ripristino si è fermato prima di toccare il database.';

    function montaConLaFinestra() {
        const pagina = shallowMount(PaginaBackup, {
            props: {
                backups: { data: [BACKUP] },
                runningBackup: null,
                preflight: { encryption_supported: true, checks: [] },
                retention_keep_last: 5,
                backup_has_password: false,
            },
            // La finestra del ripristino sta dentro il layout: i suoi testi si leggono solo con il contenuto dei componenti.
            global: { renderStubDefaultSlot: true, mocks: { route: (globalThis as Record<string, unknown>).route } },
        });

        return { pagina, stato: (pagina.vm as unknown as { $: { setupState: StatoDelRipristino } }).$.setupState };
    }

    async function avviaEFermaIn(faseDelFallimento: string, errore: string) {
        post.mockReset();
        post.mockResolvedValueOnce({ data: { token: 't-1', uuid: 'r-1' } });
        post.mockResolvedValueOnce({ data: { restore: { uuid: 'r-1', phase: 'failed', failed_phase: faseDelFallimento, error: errore } } });
        const finestra = montaConLaFinestra();
        finestra.stato.askRestore(BACKUP);
        finestra.stato.restoreAccountPassword = 'la-mia-password';
        await finestra.stato.submitRestore();
        await flushPromises();

        return finestra;
    }

    test('fermo nel backup di sicurezza, la finestra dice la frase del server, con il perché e che cosa fare, al posto di quella generica', async () => {
        const { pagina } = await avviaEFermaIn('safety_backup', FRASE);

        expect(pagina.find('h2 + p').text()).toBe(FRASE);
        expect(pagina.text()).not.toContain('impostazioni.restore_recovery.failed_body');
    });

    test('fermo in un\'altra fase, la finestra resta sulla frase generica: l\'errore sta nei dettagli tecnici', async () => {
        const { pagina } = await avviaEFermaIn('importing_database', 'SQLSTATE[HY000]: errore del database');

        expect(pagina.find('h2 + p').text()).toBe('impostazioni.restore_recovery.failed_body');
        expect(pagina.find('pre').text()).toContain('error: SQLSTATE[HY000]: errore del database');
    });

    test('«Riprendi» che si ferma di nuovo nel backup di sicurezza: la finestra dice di nuovo la frase, e il log ha l\'errore nuovo', async () => {
        const { pagina, stato } = await avviaEFermaIn('safety_backup', FRASE);
        post.mockResolvedValueOnce({ data: { restore: { uuid: 'r-1', phase: 'failed', failed_phase: 'safety_backup', error: `${FRASE} (di nuovo)` }, token: 't-2' } });

        await stato.resumeRestore();
        await flushPromises();

        expect(post).toHaveBeenLastCalledWith('/ripristino.resume', {}, { headers: { 'X-Restore-Token': 't-1' } });
        expect(stato.restoreToken).toBe('t-2');
        expect(pagina.find('h2 + p').text()).toBe(`${FRASE} (di nuovo)`);
        expect(pagina.find('pre').text()).toContain(`error: ${FRASE} (di nuovo)`);
    });

    test('«Riprendi» che passa il backup di sicurezza e si ferma più avanti: la finestra torna alla frase generica, e l\'errore nuovo va nei dettagli tecnici', async () => {
        const { pagina, stato } = await avviaEFermaIn('safety_backup', FRASE);
        post.mockResolvedValueOnce({ data: { restore: { uuid: 'r-1', phase: 'failed', failed_phase: 'extracting', error: 'La voce "altro/x" non può stare in un backup.' }, token: 't-2' } });

        await stato.resumeRestore();
        await flushPromises();

        expect(pagina.find('h2 + p').text()).toBe('impostazioni.restore_recovery.failed_body');
        expect(pagina.find('pre').text()).toContain('failed_phase: extracting');
        expect(pagina.find('pre').text()).toContain('error: La voce "altro/x" non può stare in un backup.');
    });

    // La risposta di «Riprendi» porta ancora la fase del fallimento di prima (com'era scritta negli stati di prima):
    // la finestra non la usa per un passo che non risponde.
    for (const [nome, rifiuto] of [
        ['un passo che muore con un errore del server', { response: { status: 500, data: { message: 'Server Error' } } }],
        ['la rete che cade', { message: 'Network Error' }],
        ['il token rifiutato', { response: { status: 403, data: { message: 'Token di ripristino mancante, errato o scaduto.' } } }],
    ] as const) {
        test(`dopo «Riprendi» l'import è partito e il passo dopo non risponde (${nome}): la finestra torna alla frase generica, e il log dice la fase in cui si è fermata`, async () => {
            const { pagina, stato } = await avviaEFermaIn('safety_backup', FRASE);
            post.mockResolvedValueOnce({ data: { restore: { uuid: 'r-1', phase: 'importing_database', failed_phase: 'safety_backup', error: null }, token: 't-2' } });
            post.mockRejectedValueOnce(rifiuto);

            await stato.resumeRestore();
            await flushPromises();

            expect(post).toHaveBeenCalledTimes(4);
            expect(pagina.find('h2 + p').text()).toBe('impostazioni.restore_recovery.failed_body');
            expect(pagina.find('pre').text()).toContain('failed_phase: importing_database');
            expect(pagina.text()).toContain('impostazioni.restore_recovery.unlock_note');
            expect(pagina.text()).not.toContain('impostazioni.restore_recovery.database_untouched');
        });
    }

    test('«Riprendi» rifà il backup di sicurezza e il passo dopo non risponde: la finestra non sa fin dove è arrivato il ripristino, e resta sulla frase generica e sulla nota generica', async () => {
        const { pagina, stato } = await avviaEFermaIn('safety_backup', FRASE);
        post.mockResolvedValueOnce({ data: { restore: { uuid: 'r-1', phase: 'safety_backup', failed_phase: null, error: null }, token: 't-2' } });
        post.mockRejectedValueOnce({ message: 'Network Error' });

        await stato.resumeRestore();
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(4);
        expect(pagina.find('h2 + p').text()).toBe('impostazioni.restore_recovery.failed_body');
        expect(pagina.find('pre').text()).toContain('failed_phase: safety_backup');
        expect(pagina.text()).toContain('impostazioni.restore_recovery.unlock_note');
        expect(pagina.text()).not.toContain('impostazioni.restore_recovery.database_untouched');
    });

    test('fermo nel backup di sicurezza, sotto «Sblocca» la nota dice che il ripristino non ha toccato il database, non che potrebbe restare incompleto', async () => {
        const { pagina } = await avviaEFermaIn('safety_backup', FRASE);

        expect(pagina.find('h2 + p').text()).toBe(FRASE);
        expect(pagina.text()).toContain('impostazioni.restore_recovery.database_untouched');
        expect(pagina.text()).not.toContain('impostazioni.restore_recovery.unlock_note');
    });

    test('fermo in un\'altra fase, sotto «Sblocca» la nota resta quella che dice che il database potrebbe restare incompleto', async () => {
        const { pagina } = await avviaEFermaIn('importing_database', 'SQLSTATE[HY000]: errore del database');

        expect(pagina.text()).toContain('impostazioni.restore_recovery.unlock_note');
        expect(pagina.text()).not.toContain('impostazioni.restore_recovery.database_untouched');
    });

    test('«Riprendi» che passa il backup di sicurezza e si ferma più avanti: sotto «Sblocca» torna la nota che dice che il database potrebbe restare incompleto', async () => {
        const { pagina, stato } = await avviaEFermaIn('safety_backup', FRASE);
        post.mockResolvedValueOnce({ data: { restore: { uuid: 'r-1', phase: 'failed', failed_phase: 'importing_database', error: 'SQLSTATE[HY000]: errore del database' }, token: 't-2' } });

        await stato.resumeRestore();
        await flushPromises();

        expect(pagina.text()).toContain('impostazioni.restore_recovery.unlock_note');
        expect(pagina.text()).not.toContain('impostazioni.restore_recovery.database_untouched');
    });

    // Una sola richiesta di «Riprendi» rifà il backup di sicurezza, estrae, verifica e toglie il database. Se la risposta non
    // arriva, o arriva un errore del server, il ripristino può essere andato oltre: la finestra non lo sa, e torna alle frasi
    // generiche. La fase in cui si era fermato resta nel log.
    for (const [nome, rifiuto] of [
        ['un errore del server (500)', { response: { status: 500, data: { message: 'Server Error' } } }],
        ['la rete che cade', { message: 'Network Error' }],
        ['il proxy che non riceve la risposta (502)', { response: { status: 502, data: '<html>502 Bad Gateway</html>' } }],
        ['il proxy che smette di aspettare (504)', { response: { status: 504, data: '<html>504 Gateway Time-out</html>' } }],
    ] as const) {
        test(`fermo nel backup di sicurezza, «Riprendi» senza risposta (${nome}): la frase e la nota sotto «Sblocca» non dicono più che il database non è stato toccato`, async () => {
            const { pagina, stato } = await avviaEFermaIn('safety_backup', FRASE);
            expect(pagina.text()).toContain('impostazioni.restore_recovery.database_untouched');
            post.mockRejectedValueOnce(rifiuto);

            await stato.resumeRestore();
            await flushPromises();

            expect(post).toHaveBeenCalledTimes(3);
            expect(post).toHaveBeenLastCalledWith('/ripristino.resume', {}, { headers: { 'X-Restore-Token': 't-1' } });
            expect(pagina.find('h2 + p').text()).toBe('impostazioni.restore_recovery.failed_body');
            expect(pagina.text()).toContain('impostazioni.restore_recovery.unlock_note');
            expect(pagina.text()).not.toContain('impostazioni.restore_recovery.database_untouched');
            expect(pagina.find('pre').text()).toContain('failed_phase: safety_backup');
        });
    }

    // Un 4xx di «Riprendi» viene dall'autorizzazione, prima di ogni azione: il ripristino è fermo dove era.
    for (const [nome, rifiuto] of [
        ['la password o il token rifiutati (422)', { response: { status: 422, data: { message: 'Password non corretta.' } } }],
        ['la password che non si può verificare (409)', { response: { status: 409, data: { message: 'La password non si può verificare.' } } }],
        ['troppe prove (429)', { response: { status: 429, data: { message: 'Too Many Attempts.' } } }],
        ['la modalità ripristino spenta (404)', { response: { status: 404, data: { message: 'Not Found' } } }],
    ] as const) {
        test(`fermo nel backup di sicurezza, «Riprendi» rifiutato prima di agire (${nome}): la frase del server e la nota del database non toccato restano`, async () => {
            const { pagina, stato } = await avviaEFermaIn('safety_backup', FRASE);
            post.mockRejectedValueOnce(rifiuto);

            await stato.resumeRestore();
            await flushPromises();

            expect(post).toHaveBeenCalledTimes(3);
            expect(pagina.find('h2 + p').text()).toBe(FRASE);
            expect(pagina.text()).toContain('impostazioni.restore_recovery.database_untouched');
            expect(pagina.text()).not.toContain('impostazioni.restore_recovery.unlock_note');
        });
    }
});

describe('impostazioniBackups — lo scarico di un backup', () => {
    type StatoDelloScarico = Stato & { scarica: (backup: Record<string, unknown>) => Promise<void> };

    const INDIRIZZO = '/impostazioni.backups.download/b-1';
    let indirizzoAperto: string | null = null;
    let posizionePrima: PropertyDescriptor | undefined;

    function montaPerLoScarico(): StatoDelloScarico {
        const pagina = shallowMount(PaginaBackup, {
            props: {
                backups: { data: [BACKUP] },
                runningBackup: null,
                preflight: { encryption_supported: true, checks: [] },
                retention_keep_last: 5,
                backup_has_password: false,
            },
        });

        return (pagina.vm as unknown as { $: { setupState: StatoDelloScarico } }).$.setupState;
    }

    beforeEach(() => {
        get.mockReset();
        annullaConferma();
        indirizzoAperto = null;
        // La navigazione verso il file: l'indirizzo che il browser aprirebbe.
        posizionePrima = Object.getOwnPropertyDescriptor(window, 'location');
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { set href(valore: string) { indirizzoAperto = valore; } },
        });
    });

    afterEach(() => {
        if (posizionePrima) Object.defineProperty(window, 'location', posizionePrima);
    });

    test('Coda 404 — con la password già ridigitata lo scarico va all\'indirizzo del file, senza finestra', async () => {
        get.mockResolvedValueOnce({ data: { confermata: true } });

        await montaPerLoScarico().scarica(BACKUP);

        expect(get).toHaveBeenCalledWith('/password.confirm.stato');
        expect(indirizzoAperto).toBe(INDIRIZZO);
        expect(confermaPasswordAperta.value).toBe(false);
    });

    test('Coda 404 — senza la password ridigitata apre la finestra e non scarica; dopo la conferma va all\'indirizzo del file', async () => {
        get.mockResolvedValueOnce({ data: { confermata: false } });

        await montaPerLoScarico().scarica(BACKUP);

        expect(confermaPasswordAperta.value).toBe(true);
        expect(indirizzoAperto).toBeNull();

        confermaRiuscita();

        expect(indirizzoAperto).toBe(INDIRIZZO);
    });

    test('Coda 404 — una risposta che non dice «confermata» non vale come conferma: si apre la finestra, non si scarica', async () => {
        get.mockResolvedValueOnce({ data: {} });

        await montaPerLoScarico().scarica(BACKUP);

        expect(confermaPasswordAperta.value).toBe(true);
        expect(indirizzoAperto).toBeNull();
    });

    test('Coda 404 — con «Annulla» lo scarico non parte', async () => {
        get.mockResolvedValueOnce({ data: { confermata: false } });
        await montaPerLoScarico().scarica(BACKUP);

        annullaConferma();
        confermaRiuscita();

        expect(indirizzoAperto).toBeNull();
    });

    test('Coda 404 — se lo stato non si legge lo scarico va comunque all\'indirizzo: ci pensa il server', async () => {
        get.mockRejectedValueOnce(new Error('Network Error'));

        await montaPerLoScarico().scarica(BACKUP);

        expect(indirizzoAperto).toBe(INDIRIZZO);
        expect(confermaPasswordAperta.value).toBe(false);
    });
});
