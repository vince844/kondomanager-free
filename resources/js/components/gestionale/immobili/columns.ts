// components/gestionale/immobili/columns.ts
import { h } from 'vue'
import { Link } from '@inertiajs/vue3'
import { usePermission } from "@/composables/permissions"
import DropdownAction from '@/components/gestionale/immobili/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/immobili/DataTableColumnHeader.vue'
import { Home, ArrowRight, MapPin, FileSearch, Hash } from 'lucide-vue-next'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Immobile } from '@/types/gestionale/immobili'
import type { Building } from '@/types/buildings'

const { generateRoute } = usePermission();

export function getColumns(condominio: Building): ColumnDef<Immobile>[] {
  return [
    {
      accessorKey: 'nome',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Unità Immobiliare' }),
      cell: ({ row }) => {
        const immobile = row.original
        
        return h(Link, {
          href: route(generateRoute('gestionale.immobili.show'), { condominio: condominio.id, immobile: immobile.id }),
          class: 'group flex items-center gap-3 py-1 outline-none'
        }, () => [
          h('div', { 
              // Sostituito slate-100 con indigo-50
              class: 'p-2 bg-indigo-50 dark:bg-indigo-900/40 rounded-lg text-indigo-500 dark:text-indigo-400 shadow-sm group-hover:bg-indigo-100 dark:group-hover:bg-indigo-900/60 transition-colors shrink-0' 
          }, [
              h(Home, { class: 'w-4 h-4' })
          ]),
          
          h('div', { class: 'flex flex-col min-w-0' }, [
              h('div', { class: 'flex items-center gap-2 mb-0.5' }, [
                  h('span', { 
                      class: 'px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-tighter bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 rounded-md border border-indigo-100 dark:border-indigo-800' 
                  }, immobile.tipologia?.nome || 'U.I.'),

                  h('span', {
                      // Sostituito group-hover:text-primary con group-hover:text-indigo-600
                      class: 'font-bold text-slate-900 dark:text-slate-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors truncate',
                  }, immobile.nome),
              ]),
              h('span', { 
                  // Sostituito group-hover:text-slate-500 con group-hover:text-indigo-500
                  class: 'text-[10px] text-slate-400 leading-none truncate uppercase tracking-widest flex items-center gap-1 group-hover:text-indigo-500 transition-colors' 
              }, [
                  `Visualizza Interno ${immobile.interno || '-'}`,
                  // Sostituito text-primary/60 con text-indigo-500/60
                  h(ArrowRight, { class: 'w-3 h-3 animate-pulse text-indigo-500/60' })
              ])
          ])
        ]);
      }
    },
    {
      accessorKey: 'struttura',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Ubicazione' }),
      cell: ({ row }) => {
        const immobile = row.original
        return h('div', { class: 'flex flex-col text-xs space-y-1' }, [
          h('div', { class: 'flex items-center gap-1.5 text-slate-700 dark:text-slate-300 font-medium' }, [
            h(MapPin, { class: 'w-3 h-3 text-slate-400' }),
            h('span', `Pal. ${immobile.palazzina?.name ?? '-'} | Sc. ${immobile.scala?.name ?? '-'}`)
          ]),
          h('span', { class: 'text-[10px] text-slate-400 uppercase tracking-tight' }, `Piano: ${immobile.piano ?? '-'}`)
        ])
      }
    },
    {
      accessorKey: 'catasto',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Dati catastali' }),
      cell: ({ row }) => {
        const immobile = row.original
        if (!immobile.foglio_catasto && !immobile.particella_catasto) {
          return h('span', { class: 'text-xs text-slate-300 italic' }, 'Dati non inseriti')
        }

        return h('div', { class: 'flex flex-col text-[11px] space-y-1' }, [
          h('div', { class: 'flex items-center gap-1.5 text-slate-600 dark:text-slate-400' }, [
            h(FileSearch, { class: 'w-3 h-3 text-slate-400' }),
            h('span', { class: 'font-mono' }, `Foglio: ${immobile.foglio_catasto ?? '-'} | Particella: ${immobile.particella_catasto ?? '-'}`)
          ]),
          h('div', { class: 'flex items-center gap-1.5 text-slate-600 dark:text-slate-400' }, [
            h(Hash, { class: 'w-3 h-3 text-slate-400' }),
            h('span', { class: 'font-mono' }, `Subalterno: ${immobile.subalterno_catasto ?? '-'}`)
          ])
        ])
      }
    },
    {
      accessorKey: 'dati_tecnici',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Dati tecnici' }),
      cell: ({ row }) => {
        const immobile = row.original
        return h('div', { class: 'flex flex-col text-xs' }, [
          h('span', { class: 'font-semibold text-slate-700 dark:text-slate-300' }, immobile.superficie ? `${immobile.superficie} m²` : '-'),
          h('span', { class: 'text-[10px] text-slate-400' }, `${immobile.numero_vani ?? '-'} vani`)
        ])
      }
    },
    {
      id: 'actions',
      enableHiding: false,
      cell: ({ row }) => {
        const immobile = row.original
        return h('div', { class: 'flex justify-end pr-2' }, 
          h(DropdownAction, { immobile, condominio })
        )
      },
    },
  ]
}