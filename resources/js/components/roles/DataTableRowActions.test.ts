// @vitest-environment jsdom

/**
 * «Elimina» nell'elenco dei ruoli dice prima dell'invio ciò che il server risponderebbe (Coda 258).
 *
 * Il ruolo dei nuovi iscritti non si elimina: il server rifiuta e dice di sceglierne prima un altro nelle
 * impostazioni generali. La riga lo sa dal server (`is_registration_role`, come `is_protected` per i ruoli di
 * sistema), e «Elimina» apre la finestra che lo dice, invece della conferma dell'eliminazione.
 *
 * La conferma dell'eliminazione dice che cosa succede agli utenti del ruolo come lo fa il server
 * (`RoleController::destroy`, `EliminareUnRuoloTest.php`): perdono quel ruolo, e «utente» lo riceve solo chi non ne
 * ha un altro. Il numero è `users_count` della riga, cioè tutti gli utenti del ruolo, anche quelli con due ruoli.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave e i suoi valori.
 */

import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';

const inviate = vi.hoisted(() => ({ eliminazioni: [] as unknown[] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: (...argomenti: unknown[]) => inviate.eliminazioni.push(argomenti) },
}));
vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string, valori?: Record<string, string>) => (valori ? `${chiave} ${JSON.stringify(valori)}` : chiave),
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const DataTableRowActions = (await import('./DataTableRowActions.vue')).default;

const finestra = { props: ['open'], template: '<div v-if="open" class="finestra"><slot /></div>' };
const passa = { template: '<div><slot /></div>' };

function monta(ruolo: Record<string, unknown>) {
    return mount(DataTableRowActions, {
        props: {
            role: {
                id: 7, name: 'Amministratore Demo', description: 'Il ruolo della demo', users_count: 40,
                is_protected: false, is_registration_role: false, permissions: [],
                ...ruolo,
            } as never,
        },
        global: {
            stubs: {
                DropdownMenu: passa, DropdownMenuContent: passa, DropdownMenuTrigger: passa, DropdownMenuLabel: passa,
                DropdownMenuItem: { template: '<div class="voce"><slot /></div>' },
                Button: { template: '<button><slot /></button>' },
                AlertDialog: finestra, AlertDialogContent: passa, AlertDialogHeader: passa, AlertDialogTitle: passa,
                AlertDialogDescription: passa, AlertDialogFooter: passa,
                AlertDialogCancel: { template: '<button class="annulla"><slot /></button>' },
                AlertDialogAction: { template: '<button class="azione"><slot /></button>' },
            },
        },
    });
}

async function premiElimina(riga: ReturnType<typeof monta>) {
    const elimina = riga.findAll('.voce').find((voce) => voce.text().includes('Elimina'));
    await elimina!.trigger('click');
    vi.advanceTimersByTime(250);
    await nextTick();
}

beforeEach(() => {
    vi.useFakeTimers();
    inviate.eliminazioni = [];
});

afterEach(() => {
    vi.useRealTimers();
});

test('«Elimina» sul ruolo dei nuovi iscritti apre la finestra che dice di sceglierne prima un altro nelle impostazioni generali, e non manda niente', async () => {
    const riga = monta({ is_registration_role: true });

    await premiElimina(riga);

    const aperte = riga.findAll('.finestra');
    expect(aperte).toHaveLength(1);
    expect(aperte[0].text()).toContain('ruoli.cannot_delete_registration_role {"role":"Amministratore Demo"}');
    expect(aperte[0].text()).not.toContain('utenti associati');

    // La finestra ha un solo pulsante, che la chiude.
    expect(aperte[0].findAll('.azione')).toHaveLength(1);
    await aperte[0].find('.azione').trigger('click');
    await nextTick();
    expect(riga.findAll('.finestra')).toHaveLength(0);
    expect(inviate.eliminazioni).toHaveLength(0);
});

test('«Elimina» su un altro ruolo apre la conferma di sempre, che manda l\'eliminazione', async () => {
    const riga = monta({ is_registration_role: false });

    await premiElimina(riga);

    const aperte = riga.findAll('.finestra');
    expect(aperte).toHaveLength(1);
    expect(aperte[0].text()).toContain('Sei sicuro di volere eliminare il ruolo');
    expect(aperte[0].text()).not.toContain('ruoli.cannot_delete_registration_role');

    await aperte[0].find('.azione').trigger('click');
    expect(inviate.eliminazioni).toHaveLength(1);
});

test('un ruolo di sistema che è anche il ruolo dei nuovi iscritti apre la finestra dei ruoli protetti', async () => {
    const riga = monta({ name: 'Utente', is_protected: true, is_registration_role: true });

    await premiElimina(riga);

    const aperte = riga.findAll('.finestra');
    expect(aperte).toHaveLength(1);
    expect(aperte[0].text()).toContain('Eliminazione ruolo protetto');
});

test('il nome del ruolo nella finestra è testo, non HTML', async () => {
    const riga = monta({ name: '<img src=x onerror=alert(1)>', is_registration_role: true });

    await premiElimina(riga);

    expect(riga.find('.finestra img').exists()).toBe(false);
    expect(riga.find('.finestra').text()).toContain('<img src=x onerror=alert(1)>');
});

// ─── La conferma dell'eliminazione dice che cosa succede agli utenti del ruolo (Coda 258) ─────────────

function testoDellaConferma(riga: ReturnType<typeof monta>): string {
    return riga.find('.finestra').text().replace(/\s+/g, ' ');
}

test('la conferma dice che gli utenti del ruolo lo perdono e che «utente» lo riceve solo chi non ne ha un altro', async () => {
    const riga = monta({ users_count: 40 });

    await premiElimina(riga);

    const testo = testoDellaConferma(riga);
    expect(testo).toContain('ha 40 utenti associati: lo perderanno, e chi non ha un altro ruolo riceverà il ruolo «utente».');
    expect(testo).not.toContain('verranno automaticamente');
});

test('con un utente solo la conferma lo dice al singolare, con la stessa regola', async () => {
    const riga = monta({ users_count: 1 });

    await premiElimina(riga);

    expect(testoDellaConferma(riga)).toContain('ha 1 utente associato: lo perderà, e se non ha un altro ruolo riceverà il ruolo «utente».');
});

test('senza utenti la conferma resta quella di prima', async () => {
    const riga = monta({ users_count: 0 });

    await premiElimina(riga);

    const testo = testoDellaConferma(riga);
    expect(testo).toContain('Questa azione non è reversibile.');
    expect(testo).not.toContain('utenti associati');
    expect(testo).not.toContain('utente associato');
});
