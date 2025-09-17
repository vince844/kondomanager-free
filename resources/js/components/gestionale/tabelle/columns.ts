// columns.ts
import { h } from 'vue'
import { Link } from '@inertiajs/vue3';
import { usePermission } from "@/composables/permissions";
import DropdownAction from '@/components/gestionale/tabelle/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/tabelle/DataTableColumnHeader.vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Tabella } from '@/types/gestionale/tabelle'
import type { Building } from '@/types/buildings'

const { generateRoute } = usePermission();

export function getColumns(condominio: Building): ColumnDef<Tabella>[] {
  return [
    {
      accessorKey: 'nome',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Denominazione' }),
      cell: ({ row }) => {

        const tabella = row.original

        return h('div', { class: 'flex items-center space-x-2' }, [
          h(Link, {
            href: route(generateRoute('gestionale.immobili.show'), { condominio: condominio.id, immobile: tabella.id }),
            class: 'hover:text-zinc-500 font-bold transition-colors duration-150',
          }, () => tabella.nome)
        ]);
      }

    },
    {
      accessorKey: 'tipo',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Tipologia' }),
      cell: ({ row }) => h('div', { class: 'capitalize' }, row.getValue('tipo')),

    },
    {
      accessorKey: 'palazzina',
      header: ({ column }) =>

        h(DataTableColumnHeader, { column, title: 'Palazzina' }),

      cell: ({ row }) => {
        const tabella = row.original as Tabella
        const palazzina = tabella.palazzina?.name ?? '-'
        return h('div', { class: 'flex space-x-2' }, [
          h('span', { class: 'capitalize' }, palazzina),
        ])
      }
        
    },
    {
      accessorKey: 'scala',
      header: ({ column }) =>

        h(DataTableColumnHeader, { column, title: 'Scala' }),

      cell: ({ row }) => {
        const tabella = row.original as Tabella
        const scala = tabella.scala?.name ?? '-'
        return h('div', { class: 'flex space-x-2' }, [
          h('span', { class: 'capitalize' }, scala),
        ])
      }
        
    },
    {
      id: 'actions',
      enableHiding: false,
      cell: ({ row }) => {
        const tabella = row.original as Tabella
        return h('div', { class: 'relative' },
          h(DropdownAction, { tabella, condominio })
        )
      },
    },
  ]
}
