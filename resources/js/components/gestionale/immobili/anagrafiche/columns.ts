import { h } from 'vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { AnagraficaWithPivot } from '@/types/anagrafiche'
import type { Building } from '@/types/buildings'
import type { Immobile } from '@/types/gestionale/immobili'
import DropdownAction from '@/components/gestionale/immobili/anagrafiche/DataTableRowActions.vue'
import { User, Calendar, Percent } from 'lucide-vue-next'
import { useDateConverter } from '@/composables/useDateConverter'

export const createColumns = (condominio: Building, immobile: Immobile): ColumnDef<AnagraficaWithPivot>[] => {
  
  // Inizializziamo la composable per le date
  const { toItalian, isEmptyDate } = useDateConverter();

  return [
    {
      accessorKey: 'nome',
      header: 'Soggetto',
      cell: ({ row }) => {
        const anagrafica = row.original as AnagraficaWithPivot
        
        return h('div', { class: 'flex items-center gap-3 py-1' }, [
          // Icona Profilo
          h('div', { 
              class: 'p-2 bg-slate-100 dark:bg-slate-800 rounded-lg text-slate-500 dark:text-slate-400 shadow-sm shrink-0' 
          }, [
              h(User, { class: 'w-4 h-4' })
          ]),
          
          // Contenitore Testi
          h('div', { class: 'flex flex-col min-w-0' }, [
              h('span', {
                  class: 'font-bold text-slate-900 dark:text-slate-100 truncate',
              }, anagrafica.nome.trim()),
              
              h('span', { 
                  class: 'text-[10px] text-slate-400 truncate uppercase tracking-widest mt-0.5' 
              }, anagrafica.codice_fiscale || 'CF NON INSERITO')
          ])
        ]);
      },
    },
    {
      accessorKey: 'tipologia',
      header: 'Ruolo',
      cell: ({ row }) => {
        const anagrafica = row.original as AnagraficaWithPivot
        const tipologia = anagrafica.pivot.tipologia

        // Stile base della "pillola"
        let colorClasses = 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
        
        switch (tipologia) {
          case 'proprietario':
            colorClasses = 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'
            break
          case 'inquilino':
            colorClasses = 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
            break
          case 'usufruttuario':
            colorClasses = 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400'
            break
        }

        // Semplice span con bordi arrotondati al posto del Badge
        return h('span', { 
          class: `px-2 py-1 rounded-md uppercase tracking-widest text-[10px] font-bold ${colorClasses}` 
        }, tipologia)
      },
    },
    {
      accessorKey: 'quota',
      header: 'Quota',
      cell: ({ row }) => {
        const anagrafica = row.original as AnagraficaWithPivot
        return h('div', { class: 'flex items-center gap-1.5' }, [
          h('span', { class: 'font-medium text-slate-700 dark:text-slate-300' }, anagrafica.pivot.quota),
          h(Percent, { class: 'w-3 h-3 text-slate-400' })
        ])
      },
    },
    {
      id: 'periodo',
      header: 'Competenza',
      cell: ({ row }) => {
        const anagrafica = row.original as AnagraficaWithPivot
        
        // Uso la composable per controllare ed eventualmente formattare
        const inizioRaw = anagrafica.pivot.data_inizio;
        const fineRaw = anagrafica.pivot.data_fine;
        
        const inizio = isEmptyDate(inizioRaw) ? '-' : toItalian(inizioRaw);
        const fine = isEmptyDate(fineRaw) ? '-' : toItalian(fineRaw);
        
        return h('div', { class: 'flex items-center gap-2 text-xs text-slate-600 dark:text-slate-400' }, [
          h(Calendar, { class: 'w-3.5 h-3.5 opacity-50' }),
          h('span', { class: 'font-medium' }, inizio),
          h('span', { class: 'opacity-50 mx-0.5' }, '→'),
          h('span', { class: fine !== '-' ? 'font-medium text-amber-600 dark:text-amber-400' : 'italic opacity-50' }, fine !== '-' ? fine : 'In corso')
        ])
      },
    },
    {
      id: 'actions',
      enableHiding: false,
      cell: ({ row }) => {
        const anagrafica = row.original as AnagraficaWithPivot
        return h('div', { class: 'flex justify-end pr-4' }, h(DropdownAction, {
          anagrafica,
          condominio,
          immobile
        }))
      },
    },
  ]
}