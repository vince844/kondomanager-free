import { h } from 'vue'
import DataTableColumnHeader from '@/components/gestionale/movimenti/fatture/DataTableColumnHeader.vue'
import DataTableRowActions from './DataTableRowActions.vue'
import { Calendar, AlertTriangle } from 'lucide-vue-next'
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter'
import type { ColumnDef } from '@tanstack/vue-table'

/** Riga del Libro Giornale come serializzata da ScritturaContabileController::index. */
export interface ScritturaRow {
  id: number
  numero_protocollo: string
  data_registrazione: string | null
  causale: string
  descrizione: string | null
  tipo_movimento: string
  tipo_movimento_label: string
  stato: string
  gestione: { id: number; nome: string } | null
  /** Importo in CENTESIMI (totale dare della scrittura). */
  importo: number
  is_quadrata: boolean
}

const { euro } = useCurrencyFormatter();

const badgeBase = 'inline-flex items-center gap-1.5 text-[10px] font-bold px-2 py-1 rounded-md uppercase tracking-wider whitespace-nowrap';

/**
 * Macro-gruppo del tipo movimento, solo per la scelta del colore badge.
 * Rispecchia i commenti di raggruppamento in App\Enums\TipoMovimentoContabile.
 */
const CATEGORIA_PER_TIPO: Record<string, 'passivo' | 'attivo' | 'prima_nota' | 'fiscale' | 'tecnico'> = {
  fattura_acquisto: 'passivo',
  nota_credito_fornitore: 'passivo',
  pagamento_fornitore: 'passivo',
  storno_fattura: 'passivo',
  storno_pagamento_fornitore: 'passivo',
  emissione_rata: 'attivo',
  incasso_rata: 'attivo',
  storno_credito: 'attivo',
  stralcio_credito: 'attivo',
  rimborso_condomino: 'attivo',
  incasso_diverso: 'attivo',
  rimborso_assicurativo: 'attivo',
  regolazione_immediata: 'prima_nota',
  storno_regolazione_immediata: 'prima_nota',
  pagamento_f24: 'fiscale',
  storno_pagamento_f24: 'fiscale',
  apertura: 'tecnico',
  chiusura: 'tecnico',
  giroconto: 'tecnico',
  storno_giroconto: 'tecnico',
  rettifica: 'tecnico',
  accantonamento: 'tecnico',
  riparto: 'tecnico',
  riconciliazione_bancaria: 'tecnico',
};

const COLORI_CATEGORIA: Record<string, string> = {
  passivo: 'bg-rose-50 text-rose-700 border border-rose-200',
  attivo: 'bg-emerald-50 text-emerald-700 border border-emerald-200',
  prima_nota: 'bg-amber-50 text-amber-700 border border-amber-200',
  fiscale: 'bg-indigo-50 text-indigo-700 border border-indigo-200',
  tecnico: 'bg-violet-50 text-violet-700 border border-violet-200',
};

const COLORI_STATO: Record<string, string> = {
  bozza: 'bg-slate-100 text-slate-600 border border-slate-200',
  registrata: 'bg-blue-50 text-blue-700 border border-blue-200',
  riconciliata: 'bg-emerald-50 text-emerald-700 border border-emerald-200',
  annullata: 'bg-rose-50 text-rose-700 border border-rose-200',
};

// 'annullata' è il valore reale in DB, ma nel resto del gestionale (incassi,
// pagamenti, fatture, giroconti) l'etichetta per un movimento invertito è
// sempre "Stornata/o": qui si allinea la stessa convenzione.
export const STATO_LABELS: Record<string, string> = { annullata: 'Stornata' };

export const createColumns = (condominioId: number, esercizioId: number): ColumnDef<ScritturaRow>[] => {
  return [
    {
      accessorKey: 'numero_protocollo',
      header: ({ column }) => h(DataTableColumnHeader, { column: column as any, title: 'Protocollo' }),
      size: 100,
      cell: ({ row }) => h('span', { class: 'font-bold text-xs whitespace-nowrap text-slate-900' }, row.original.numero_protocollo),
      enableSorting: false,
    },
    {
      accessorKey: 'data_registrazione',
      header: ({ column }) => h(DataTableColumnHeader, { column: column as any, title: 'Data' }),
      size: 110,
      cell: ({ row }) => h('span', { class: 'text-xs font-bold text-slate-700 flex items-center gap-1.5 whitespace-nowrap' }, [
        h(Calendar, { class: 'w-3.5 h-3.5 text-slate-400 shrink-0' }),
        row.original.data_registrazione || '-'
      ]),
      // Niente ordinamento server-side su questa pagina: un sort client-side riordinerebbe
      // solo le righe della pagina corrente, non l'intero Libro Giornale filtrato.
      enableSorting: false,
    },
    {
      id: 'gestione',
      header: ({ column }) => h(DataTableColumnHeader, { column: column as any, title: 'Gestione' }),
      size: 130,
      cell: ({ row }) => {
        const gestione = row.original.gestione;
        if (!gestione) return h('span', { class: 'text-xs text-slate-400' }, '—');
        return h('span', { class: 'text-xs font-semibold text-slate-600 truncate block' }, gestione.nome);
      },
      enableSorting: false,
    },
    {
      accessorKey: 'causale',
      header: ({ column }) => h(DataTableColumnHeader, { column: column as any, title: 'Causale' }),
      size: 260,
      cell: ({ row }) => {
        const scrittura = row.original;
        return h('span', {
          class: 'block text-xs truncate text-slate-600',
          title: scrittura.descrizione || scrittura.causale,
        }, scrittura.causale || '-');
      },
      enableSorting: false,
    },
    {
      accessorKey: 'tipo_movimento',
      header: ({ column }) => h(DataTableColumnHeader, { column: column as any, title: 'Tipo movimento' }),
      size: 170,
      cell: ({ row }) => {
        const scrittura = row.original;
        const categoria = CATEGORIA_PER_TIPO[scrittura.tipo_movimento] ?? 'tecnico';
        return h('span', { class: `${badgeBase} ${COLORI_CATEGORIA[categoria]}` }, scrittura.tipo_movimento_label);
      },
      enableSorting: false,
    },
    {
      accessorKey: 'importo',
      header: ({ column }) => h('div', { class: 'text-right w-full flex justify-end items-center' }, h(DataTableColumnHeader, { column: column as any, title: 'Importo' })),
      size: 120,
      // Niente ordinamento server-side: vedi il commento sulla colonna 'data_registrazione'.
      enableSorting: false,
      cell: ({ row }) => {
        const scrittura = row.original;
        return h('div', { class: 'flex items-center justify-end gap-1.5' }, [
          !scrittura.is_quadrata
            ? h(AlertTriangle, { class: 'w-3.5 h-3.5 text-amber-500 shrink-0', title: 'Scrittura non quadrata' })
            : null,
          h('span', { class: 'font-black text-sm whitespace-nowrap text-slate-900' }, euro(scrittura.importo)),
        ]);
      },
      enableSorting: false,
    },
    {
      accessorKey: 'stato',
      header: ({ column }) => h(DataTableColumnHeader, { column: column as any, title: 'Stato' }),
      size: 120,
      cell: ({ row }) => {
        const stato = row.original.stato;
        return h('span', { class: `${badgeBase} ${COLORI_STATO[stato] ?? COLORI_STATO.bozza}` }, STATO_LABELS[stato] ?? stato);
      },
      enableSorting: false,
    },
    {
      id: 'actions',
      enableHiding: false,
      size: 50,
      cell: ({ row }) => h(DataTableRowActions, {
        scrittura: row.original,
        condominioId,
        esercizioId,
      }),
    },
  ];
}
