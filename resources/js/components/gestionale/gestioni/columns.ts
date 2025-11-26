// columns.ts
import { h } from 'vue'
import DropdownAction from '@/components/gestionale/gestioni/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/gestioni/DataTableColumnHeader.vue'
import { typeConstants } from '@/lib/gestionale/gestioni/constants';
import { useDateConverter } from '@/composables/useDateConverter';
import type { ColumnDef } from '@tanstack/vue-table'
import type { Gestione } from '@/types/gestionale/gestioni'
import type { Building } from '@/types/buildings'
import type { Esercizio } from '@/types/gestionale/esercizi';

const { toItalian } = useDateConverter();

export const createColumns = (condominio: Building, esercizio: Esercizio): ColumnDef<Gestione>[] => [
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
    accessorKey: 'tipo',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Tipologia' }),
    cell: ({ row }) => {

      const value = row.getValue('tipo');
      const stato = typeConstants.find(p => p.value === value);
  
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
      const gestione = row.original as Gestione
      return h('div', { class: 'relative' },
        h(DropdownAction, { gestione, condominio, esercizio })
      )
    },
  },
]
