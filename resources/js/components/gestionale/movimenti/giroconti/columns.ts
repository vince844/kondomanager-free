import { h } from 'vue'
import { Link } from '@inertiajs/vue3'
import DataTableColumnHeader from '@/components/gestionale/movimenti/fatture/DataTableColumnHeader.vue'
import DropdownAction from './DataTableRowActions.vue'
import { ArrowRight, Calendar, FileText, Repeat2, RotateCcw } from 'lucide-vue-next'
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter'
import { usePermission } from '@/composables/permissions'
import type { ColumnDef } from '@tanstack/vue-table'

/** Riga del registro giroconti come serializzata da GirocontoController::index. */
export interface GirocontoRow {
  id: number
  numero_protocollo: string
  data: string | null
  causale: string
  is_storno: boolean
  origine: string
  destinazione: string
  /** Importo in CENTESIMI. */
  importo: number
  copertura_fattura: string | null
  copertura_fattura_id: number | null
  stornato: boolean
  stornabile: boolean
}

const { euro } = useCurrencyFormatter();

const badgeBase = 'inline-flex items-center gap-1.5 text-[10px] font-bold px-2 py-1 rounded-md uppercase tracking-wider whitespace-nowrap';

export const createColumns = (condominioId: number): ColumnDef<GirocontoRow>[] => {
  const { generateRoute } = usePermission();

  return [
    {
      accessorKey: 'numero_protocollo',
      header: ({ column }) => h(DataTableColumnHeader, { column: column as any, title: 'Protocollo' }),
      size: 110,
      cell: ({ row }) => {
        const giroconto = row.original;
        return h('span', {
          class: `font-bold text-xs whitespace-nowrap ${giroconto.stornato ? 'text-slate-400' : 'text-slate-900'}`
        }, giroconto.numero_protocollo);
      },
      enableSorting: false,
    },
    {
      accessorKey: 'data',
      header: ({ column }) => h(DataTableColumnHeader, { column: column as any, title: 'Data' }),
      size: 110,
      cell: ({ row }) => {
        return h('span', { class: 'text-xs font-bold text-slate-700 flex items-center gap-1.5 whitespace-nowrap' }, [
          h(Calendar, { class: 'w-3.5 h-3.5 text-slate-400 shrink-0' }),
          row.original.data || '-'
        ]);
      },
    },
    {
      id: 'movimento',
      header: ({ column }) => h(DataTableColumnHeader, { column: column as any, title: 'Origine → Destinazione' }),
      size: 230,
      cell: ({ row }) => {
        const giroconto = row.original;
        return h('div', { class: 'flex items-center gap-1.5 overflow-hidden' }, [
          h('span', { class: `text-xs font-semibold truncate ${giroconto.stornato ? 'text-slate-400' : 'text-slate-700'}` }, giroconto.origine),
          h(ArrowRight, { class: 'w-3.5 h-3.5 text-slate-400 shrink-0' }),
          h('span', { class: `text-xs font-semibold truncate ${giroconto.stornato ? 'text-slate-400' : 'text-slate-700'}` }, giroconto.destinazione),
        ]);
      },
      enableSorting: false,
    },
    {
      accessorKey: 'causale',
      header: ({ column }) => h(DataTableColumnHeader, { column: column as any, title: 'Causale' }),
      size: 220,
      cell: ({ row }) => {
        const giroconto = row.original;
        return h('span', {
          class: `block text-xs truncate ${giroconto.stornato ? 'text-slate-400' : 'text-slate-600'}`,
          title: giroconto.causale
        }, giroconto.causale || '-');
      },
      enableSorting: false,
    },
    {
      accessorKey: 'importo',
      header: ({ column }) => h(DataTableColumnHeader, { column: column as any, title: 'Importo' }),
      size: 110,
      cell: ({ row }) => {
        const giroconto = row.original;
        return h('div', { class: 'flex flex-col items-end' }, [
          h('span', {
            class: `font-black text-sm whitespace-nowrap ${giroconto.stornato ? 'text-slate-400' : 'text-slate-900'}`
          }, euro(giroconto.importo))
        ]);
      },
    },
    {
      accessorKey: 'is_storno',
      header: ({ column }) => h(DataTableColumnHeader, { column: column as any, title: 'Tipo' }),
      size: 150,
      cell: ({ row }) => {
        const giroconto = row.original;
        const badges = [];

        if (giroconto.is_storno) {
          badges.push(h('span', { class: `${badgeBase} bg-rose-50 text-rose-700 border border-rose-200` }, [
            h(RotateCcw, { class: 'w-3 h-3 shrink-0' }),
            'Storno'
          ]));
        } else {
          badges.push(h('span', { class: `${badgeBase} bg-violet-50 text-violet-700 border border-violet-200` }, [
            h(Repeat2, { class: 'w-3 h-3 shrink-0' }),
            'Giroconto'
          ]));
        }

        if (giroconto.stornato) {
          badges.push(h('span', { class: `${badgeBase} bg-amber-50 text-amber-700 border border-amber-200` }, [
            h(RotateCcw, { class: 'w-3 h-3 shrink-0' }),
            'Stornato'
          ]));
        }

        return h('div', { class: 'flex items-center gap-1 flex-wrap' }, badges);
      },
      enableSorting: false,
    },
    {
      accessorKey: 'copertura_fattura',
      header: ({ column }) => h(DataTableColumnHeader, { column: column as any, title: 'Copertura' }),
      size: 130,
      cell: ({ row }) => {
        const giroconto = row.original;

        if (!giroconto.copertura_fattura || !giroconto.copertura_fattura_id) {
          return h('span', { class: 'text-xs text-slate-400' }, '—');
        }

        return h(Link, {
          href: route(generateRoute('gestionale.fatture.show'), {
            condominio: condominioId,
            fattura: giroconto.copertura_fattura_id,
          }),
          class: 'inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-800 hover:underline whitespace-nowrap'
        }, () => [
          h(FileText, { class: 'w-3.5 h-3.5 shrink-0' }),
          `Ft. ${giroconto.copertura_fattura}`
        ]);
      },
      enableSorting: false,
    },
    {
      id: 'actions',
      enableHiding: false,
      size: 50,
      cell: ({ row }) => row.original.stornabile
        ? h(DropdownAction, {
            giroconto: row.original,
            condominioId: condominioId
          })
        : null,
    },
  ];
}
