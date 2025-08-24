// columns.ts
import { h } from 'vue'
import { Link } from '@inertiajs/vue3';
import { usePermission } from "@/composables/permissions";
import DropdownAction from '@/components/gestionale/immobili/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/immobili/DataTableColumnHeader.vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Immobile } from '@/types/gestionale/immobili'
import type { Building } from '@/types/buildings'
import type { TipologiaImmobile } from '@/types/gestionale/tipologie-immobili'

const { generateRoute } = usePermission();

export function getColumns(condominio: Building): ColumnDef<Immobile>[] {
  return [
    {
      accessorKey: 'nome',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Denominazione' }),
      cell: ({ row }) => {

        const immobile = row.original

        return h('div', { class: 'flex items-center space-x-2' }, [
          h(Link, {
            href: route(generateRoute('gestionale.immobili.show'), { condominio: condominio.id, immobile: immobile.id }),
            class: 'hover:text-zinc-500 font-bold transition-colors duration-150',
          }, () => immobile.nome)
        ]);
      }

    },
    {
      accessorKey: 'tipologia',
      header: ({ column }) =>
        
        h(DataTableColumnHeader, { column, title: 'Tipologia' }),

      cell: ({ row }) => {
        const immobile = row.original as Immobile
        const tipologia = immobile.tipologia ? immobile.tipologia.nome : '-'
        return h('div', { class: 'flex space-x-2' }, [
          h('span', { class: 'capitalize' }, tipologia),
        ])
      }
        
    },
    {
      accessorKey: 'palazzina',
      header: ({ column }) =>

        h(DataTableColumnHeader, { column, title: 'Palazzina' }),

      cell: ({ row }) => {
        const immobile = row.original as Immobile
        const palazzina = immobile.palazzina ? immobile.palazzina.name : '-'
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
        const immobile = row.original as Immobile
        const scala = immobile.scala ? immobile.scala.name : '-'
        return h('div', { class: 'flex space-x-2' }, [
          h('span', { class: 'capitalize' }, scala),
        ])
      }
        
    },
    {
      accessorKey: 'dettagli',
      header: ({ column }) =>
        h(DataTableColumnHeader, { column, title: 'Dettagli' }),

      cell: ({ row }) => {
        const immobile = row.original as Immobile
        const interno = immobile.interno ?? '-'
        const piano = immobile.piano ?? '-'
        const superficie = immobile.superficie ? immobile.superficie + ' mq' : '-'
        
        const dettagli = `Interno: ${interno} | Piano: ${piano} | Sup: ${superficie}`

        return h('div', { class: 'flex space-x-2' }, [
          h('span', { class: 'capitalize' }, dettagli),
        ])
      },
    },
    {
      id: 'actions',
      enableHiding: false,
      cell: ({ row }) => {
        const immobile = row.original as Immobile
        return h('div', { class: 'relative' },
          h(DropdownAction, { immobile, condominio })
        )
      },
    },
  ]
}
