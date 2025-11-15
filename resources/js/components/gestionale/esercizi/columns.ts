// columns.ts
import { h } from 'vue'
import DropdownAction from '@/components/gestionale/esercizi/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/esercizi/DataTableColumnHeader.vue'
import { statusConstants } from '@/lib/gestionale/esercizi/constants';
import { useDateConverter } from '@/composables/useDateConverter';
import type { ColumnDef } from '@tanstack/vue-table'
import type { Esercizio } from '@/types/gestionale/esercizi'
import type { Building } from '@/types/buildings'

const { toItalian } = useDateConverter();

export function getColumns(condominio: Building): ColumnDef<Esercizio>[] {
  return [
    {
      accessorKey: 'nome',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Denominazione' }),
      cell: ({ row }) => h('div', { class: 'font-bold' }, row.getValue('nome')),

    },
    {
      accessorKey: 'descrizione',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Descrizione' }),
      cell: ({ row }) => h('div', row.getValue('descrizione')),

    },
    {
      accessorKey: 'data_inizio',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Inizio' }),
      cell: ({ row }) => h('div', toItalian(row.getValue('data_inizio'))),

    },
    {
      accessorKey: 'data_fine',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Fine' }),
      cell: ({ row }) => h('div', toItalian(row.getValue('data_fine'))),

    },
    {
      accessorKey: 'stato',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Stato' }),
      cell: ({ row }) => {
  
        const value = row.getValue('stato');
        const stato = statusConstants.find(p => p.value === value);
    
        if (!stato) return h('span', '–');
    
        return h('div', { class: 'flex items-center gap-2' }, [
          h(stato.icon, { class: `h-4 w-4 ${stato.colorClass}` }),
          h('span', stato.label)
        ]);
      }
    },
    {
      id: 'actions',
      enableHiding: false,
      cell: ({ row }) => {
        const esercizio = row.original as Esercizio
        return h('div', { class: 'relative' },
          h(DropdownAction, { esercizio, condominio })
        )
      },
    },
  ]
}
