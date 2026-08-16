// columns.ts
import { h } from 'vue'
import { TableProperties, ArrowRight, Home } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3'
import { usePermission } from "@/composables/permissions";
import DropdownAction from '@/components/gestionale/tabelle/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/tabelle/DataTableColumnHeader.vue'
import { typeConstants } from '@/lib/gestionale/tabelle/constants';
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
        const tabella = row.original as Tabella
        const desc = tabella.note;

        return h(Link, {
          prefetch: true,
          href: route(generateRoute('gestionale.tabelle.quote.index'), { condominio: condominio.id, tabella: tabella.id }),
          class: 'group flex items-start gap-3 py-1 outline-none'
        }, () => [
          // Icona laterale
          (() => {
            const tableType = typeConstants.find(p => p.value === tabella.tipo);
            const IconComponent = tableType?.icon || TableProperties;
            const textColor = tableType?.colorClass?.split(' ')[0] || 'text-indigo-500';
            
            return h('div', { 
                class: `p-2 bg-slate-50 dark:bg-slate-800/50 rounded-lg shadow-sm group-hover:bg-slate-100 dark:group-hover:bg-slate-800 transition-colors shrink-0 mt-0.5 ${textColor}` 
            }, [
                h(IconComponent, { class: 'w-4 h-4' })
            ]);
          })(),
          
          // Contenitore Testi
          h('div', { class: 'flex flex-col min-w-0' }, [
              // Riga 1: Titolo e Badge
              h('div', { class: 'flex items-center gap-2' }, [
                  h('span', {
                      class: 'font-bold text-sm text-slate-900 dark:text-slate-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors truncate',
                  }, tabella.nome),
                  h('span', {
                      class: 'text-[10px] uppercase font-bold tracking-wider px-1.5 py-0.5 rounded-md bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400 shrink-0'
                  }, tabella.quota || 'millesimi')
              ]),

              // Riga 2: Descrizione 
              desc ? h('span', {
                class: 'text-xs text-slate-500 dark:text-slate-400 truncate max-w-sm mt-0.5'
              }, desc) : null,

              // Riga 3: Action "Gestione Quote"
              h('span', { 
                  class: 'text-[10px] font-semibold text-slate-400 leading-none truncate uppercase tracking-widest flex items-center gap-1 group-hover:text-indigo-500 transition-colors mt-2' 
              }, [
                  'Gestione Quote',
                  h(ArrowRight, { class: 'w-3 h-3 text-indigo-400/60' })
              ])
          ])
        ]);
      },
    },
    {
      id: 'edificio',
      /**
       * ⚠️ **Non ordinabile: la cella monta due relazioni.** «Palazzina / Scala» non ha un ordine
       * finché non si decide se conta la palazzina o la scala — e sono due domande diverse.
       * Il server accetta solo le chiavi di `TabellaIndexRequest::COLONNE_ORDINABILI`, quindi
       * lasciarla cliccabile manderebbe in un errore di validazione al primo clic.
       */
      enableSorting: false,
      accessorFn: row => `${row.palazzina?.name ?? ''} ${row.scala?.name ?? ''}`,
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Palazzina / Scala' }),
      cell: ({ row }) => {
        const tabella = row.original as Tabella
        const palazzina = tabella.palazzina?.name
        const scala = tabella.scala?.name

        if (!palazzina && !scala) {
          return h('div', { class: 'flex space-x-2' }, [
            h('span', { class: 'inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-widest bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' }, 'Intero Condominio')
          ])
        }

        const label = [palazzina, scala].filter(Boolean).join(' - ')

        return h('div', { class: 'flex space-x-2' }, [
          h('span', { class: 'capitalize' }, label),
        ])
      }
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
      accessorKey: 'immobili_count',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Unità Associate' }),
      cell: ({ row }) => {
        const count = row.original.immobili_count || 0;
        return h('div', { class: 'flex items-center gap-1.5' }, [
          h('div', { class: 'flex items-center justify-center w-6 h-6 rounded bg-slate-100 dark:bg-slate-800 text-slate-500' }, [
            h(Home, { class: 'w-3.5 h-3.5' })
          ]),
          h('span', { class: 'text-sm font-medium text-slate-700 dark:text-slate-300' }, `${count} unità`)
        ]);
      }
    },
    {
      accessorKey: 'stato',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Stato' }),
      cell: ({ row }) => {
        const contiCount = row.original.conti_count || 0;
        const isInUso = contiCount > 0;
        
        return h('div', { class: 'flex items-center' }, [
          h('span', { 
            class: `inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium ${
              isInUso 
                ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800/50' 
                : 'bg-slate-50 text-slate-500 dark:bg-slate-800/50 dark:text-slate-400 border border-slate-200 dark:border-slate-700'
            }` 
          }, [
            h('span', { class: `w-1.5 h-1.5 rounded-full ${isInUso ? 'bg-emerald-500' : 'bg-slate-400'}` }),
            isInUso ? 'In uso' : 'Orfana'
          ])
        ]);
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
