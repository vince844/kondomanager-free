import { h } from 'vue';
import DataTableColumnHeader from '@/components/gestionale/movimenti/fatture/DataTableColumnHeader.vue';
import DataTableRowActions from './DataTableRowActions.vue';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { testoScadenza, urgenzaScadenza } from '@/lib/gestionale/f24/prospetto';
import type { ColumnDef } from '@tanstack/vue-table';

const { euro } = useCurrencyFormatter();

/**
 * Le colonne dello scadenzario F24.
 *
 * Prima questa pagina era una lista di schede fatte a mano. È stata portata a `DataTable`
 * per la stessa ragione per cui esistono le altre: **la discontinuità si paga**. Un elenco
 * che si comporta diversamente da tutti gli altri costringe a reimparare dove si ordina,
 * dove si filtra e dove si clicca, e il guadagno estetico non ripaga quel costo.
 *
 * L'urgenza, che nella versione a schede era una barra colorata sul bordo, qui è una
 * **colonna**: si legge lo stesso, si può ordinare, e non serve un componente su misura.
 */

const dataIt = (d: string | null) =>
    d ? new Date(`${String(d).slice(0, 10)}T00:00:00`).toLocaleDateString('it-IT') : '—';

const coloriUrgenza: Record<string, string> = {
    scaduta: 'bg-rose-50 text-rose-700 ring-1 ring-rose-200',
    urgente: 'bg-amber-50 text-amber-800 ring-1 ring-amber-200',
    prossima: 'bg-slate-50 text-slate-600 ring-1 ring-slate-200',
    lontana: 'bg-slate-50 text-slate-500 ring-1 ring-slate-200',
};

const etichettaPlafond = (p: string) =>
    p === 'soglia_500_tre_finestre' ? 'Appalti 4%'
        : p === 'soglia_100_annuale' ? 'Professionisti'
            : 'Mensile';

const badgeStato: Record<string, { testo: string; classe: string }> = {
    bozza: { testo: 'Bozza', classe: 'bg-slate-100 text-slate-600' },
    confermata: { testo: 'Confermata', classe: 'bg-blue-50 text-blue-700 ring-1 ring-blue-200' },
    versata: { testo: 'Versata', classe: 'bg-emerald-600 text-white' },
    stornata: { testo: 'Stornata', classe: 'bg-rose-100 text-rose-700' },
    annullata: { testo: 'Annullata', classe: 'bg-slate-100 text-slate-400' },
};

export const createColumns = (condominioId: number, oggi: Date): ColumnDef<any>[] => [
    {
        accessorKey: 'data_scadenza',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Scadenza' }),
        // 240 e non 200: il conto alla rovescia sta ora **accanto** alla data invece che
        // sotto, e «Scaduta da 104 giorni» è la stringa più lunga che testoScadenza produce.
        size: 240,
        cell: ({ row }) => {
            const d = row.original;
            const aperta = d.stato === 'bozza' || d.stato === 'confermata';
            const urgenza = urgenzaScadenza(d.data_scadenza, oggi);

            // ⚠️ **Su una riga sola, come tutte le altre celle.** Impilando data e conto
            // alla rovescia questa cella era alta il doppio delle vicine, e siccome le
            // celle a una riga si centrano in verticale, la data finiva più in alto di
            // «Appalti 4%», «€ 17,80» e «Bozza»: la riga si leggeva a scalini. `flex-wrap`
            // resta come rete, così su una colonna stretta va a capo invece di sforare.
            return h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                h('span', { class: 'text-sm font-bold text-slate-900' }, dataIt(d.data_scadenza)),
                // Il conto alla rovescia serve solo finché c'è qualcosa da fare: su una
                // delega già versata direbbe «scaduta da 77 giorni» di un debito estinto.
                aperta
                    ? h('span', {
                        class: `w-fit whitespace-nowrap rounded-md px-2 py-0.5 text-[10px] font-semibold ${coloriUrgenza[urgenza]}`,
                    }, testoScadenza(d.data_scadenza, oggi))
                    : h('span', { class: 'text-[11px] text-slate-400' },
                        d.data_versamento ? `versata il ${dataIt(d.data_versamento)}` : '—'),
            ]);
        },
    },
    {
        accessorKey: 'plafond',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Tipo' }),
        size: 150,
        cell: ({ row }) => h('span', {
            class: 'rounded-md bg-slate-100 px-2 py-1 text-[11px] font-medium text-slate-700',
        }, etichettaPlafond(row.original.plafond)),
    },
    {
        accessorKey: 'righe',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Codici tributo' }),
        size: 200,
        enableSorting: false,
        cell: ({ row }) => {
            const righe = row.original.righe ?? [];
            const codici = [...new Set(righe.map((r: any) => r.codice_tributo))];

            // ⚠️ **Il conteggio delle righe si scrive solo quando aggiunge qualcosa.**
            // Prima stava sempre, su una riga sua sotto il codice, e questo rendeva la cella
            // alta due righe mentre «Tipo», «Importo» e «Stato» ne occupano una: il codice
            // finiva più in alto di tutto il resto della riga, che è il difetto che Vincenzo
            // ha visto a video. Ma non basta toglierlo, perché non è sempre ridondante: una
            // delega può portare **due righe con lo stesso codice** e mesi diversi — la
            // soglia del plafond accumula più mesi su una sola scadenza — e in quel caso
            // contare i badge non dice quante righe avrà il modello. Quindi: si scrive
            // quando i due numeri divergono, e in linea, così la cella resta alta una riga.
            const contaRighe = righe.length > codici.length
                ? `${righe.length} righe`
                : null;

            // Niente `font-mono`: per incolonnare le cifre basta `tabular-nums`, che non
            // cambia il carattere dell'interfaccia. I badge vanno a capo perché 1019 e 1020
            // finiscono insieme quando si versa per un professionista e per una società
            // nello stesso mese.
            return h('div', { class: 'flex flex-wrap items-center gap-1' }, [
                ...(codici.length
                    ? codici.map((codice) => h('span', {
                        key: codice,
                        class: 'rounded-md bg-slate-100 px-2 py-1 text-[11px] font-medium tabular-nums text-slate-700',
                    }, codice))
                    : [h('span', { class: 'text-sm text-slate-400' }, '—')]),
                contaRighe ? h('span', { class: 'text-[11px] text-slate-400' }, contaRighe) : null,
            ]);
        },
    },
    {
        accessorKey: 'totale_debito',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Importo' }),
        size: 140,
        cell: ({ row }) => h('span', {
            class: `text-sm font-black ${['stornata', 'annullata'].includes(row.original.stato) ? 'text-slate-400 line-through' : 'text-slate-900'}`,
        }, euro(row.original.totale_debito)),
    },
    {
        accessorKey: 'stato',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Stato' }),
        size: 130,
        cell: ({ row }) => {
            const s = badgeStato[row.original.stato] ?? { testo: row.original.stato, classe: 'bg-slate-100 text-slate-600' };

            return h('span', {
                class: `rounded-md px-2 py-1 text-[10px] font-black uppercase tracking-wider ${s.classe}`,
            }, s.testo);
        },
    },
    {
        id: 'azioni',
        size: 60,
        enableSorting: false,
        cell: ({ row }) => h(DataTableRowActions, {
            delega: row.original,
            condominioId,
        }),
    },
];
