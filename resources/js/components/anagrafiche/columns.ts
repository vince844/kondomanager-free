import { h } from 'vue'
import { Link } from '@inertiajs/vue3'
import DropdownAction from '@/components/anagrafiche/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/anagrafiche/DataTableColumnHeader.vue'
import { usePermission } from "@/composables/permissions"
import { trans } from 'laravel-vue-i18n'
import AnagraficheStack from '@/components/AnagraficheStack.vue'
import { Badge } from '@/components/ui/badge'
import { User, MapPin, Mail, Smartphone, Phone, ArrowRight, PhoneOff } from 'lucide-vue-next' 
import type { ColumnDef } from '@tanstack/vue-table'
import type { Anagrafica } from '@/types/anagrafiche'
import type { Building } from '@/types/buildings'

const { generateRoute } = usePermission();

export const columns: ColumnDef<Anagrafica>[] = [
  {
    accessorKey: 'nome',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('anagrafiche.table.name') }), 
    
    cell: ({ row }) => {
      const anagrafica = row.original
      const codiceFiscale = anagrafica.codice_fiscale

      return h(Link, {
        href: route(generateRoute('anagrafiche.show'), { anagrafica: anagrafica.id }),
        class: 'group flex items-start gap-3 py-1 outline-none' 
      }, () => [
        // Icona Anagrafica
        h('div', { 
            class: 'p-2 bg-indigo-50 dark:bg-indigo-900/40 rounded-lg text-indigo-500 dark:text-indigo-400 shadow-sm group-hover:bg-indigo-100 dark:group-hover:bg-indigo-900/60 transition-colors shrink-0 mt-0.5' 
        }, [
            h(User, { class: 'w-4 h-4' })
        ]),
        
        // Contenitore Nome e CF
        h('div', { class: 'flex flex-col items-start gap-1 min-w-0' }, [
            h('span', {
                class: 'font-bold text-sm text-slate-900 dark:text-slate-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors truncate capitalize leading-tight',
            }, anagrafica.nome),
            
            codiceFiscale ? h('span', { 
                class: 'px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-tighter bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 rounded-md border border-indigo-100 dark:border-indigo-800' 
            }, codiceFiscale) : null,
            
            // Sottotitolo interattivo
      /*       h('span', { 
                class: 'text-[10px] text-slate-400 leading-none truncate uppercase tracking-widest flex items-center gap-1 group-hover:text-indigo-500 transition-colors mt-1.5' 
            }, [
                trans('anagrafiche.table.click_to_view') || 'Visualizza Scheda', 
                h(ArrowRight, { class: 'w-3 h-3 text-indigo-500/60' })
            ]) */
        ])
      ]);
    }
  },
  {
    id: 'contatti',
      /**
       * ⚠️ **Non ordinabile, e non è una mancanza.** La cella contiene un **elenco** di contatti,
       * non un valore: senza scegliere quale faccia da chiave, l'intestazione ordinava per
       * **quanti** contatti ci sono nella cella — che è ciò che la libreria fa quando il valore è
       * un array e nessuno dichiara un criterio.
       */
      enableSorting: false,
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('anagrafiche.table.contacts') || 'Contatti' }),
    cell: ({ row }) => {
      const a = row.original;
      
      // Logica "intelligente": prende il cellulare se c'è, altrimenti il telefono fisso
      const recapitoTelefonico = a.cellulare || a.telefono;
      const emailPrincipale = a.email || a.pec;

      if (!emailPrincipale && !recapitoTelefonico) {
        return h(Badge, { 
          variant: 'outline',
          class: 'text-slate-400 dark:text-slate-500 border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 shadow-none font-normal flex items-center gap-1.5 w-fit mt-0.5'
        }, () => [
          h(PhoneOff, { class: 'w-3 h-3' }),
          trans('anagrafiche.table.no_contacts')
        ])
      }

      return h('div', { class: 'flex flex-col gap-1.5 text-sm text-slate-600 dark:text-slate-400' }, [
        emailPrincipale ? h('div', { class: 'flex items-center gap-2' }, [
            h(Mail, { class: 'w-3.5 h-3.5 shrink-0 text-slate-400' }),
            h('span', { class: 'truncate max-w-[160px]' }, emailPrincipale)
        ]) : null,
        
        recapitoTelefonico ? h('div', { class: 'flex items-center gap-2' }, [
            // Usa l'icona Smartphone se è un cellulare, altrimenti il Phone classico
            h(a.cellulare ? Smartphone : Phone, { class: 'w-3.5 h-3.5 shrink-0 text-slate-400' }),
            h('span', { class: 'truncate text-xs' }, recapitoTelefonico)
        ]) : null,
      ]);
    }
  },
  {
    accessorKey: 'indirizzo',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('anagrafiche.table.address') }), 
    cell: ({ row }) => {
      // Dato che l'indirizzo è già completo nel DB, usiamo direttamente quello!
      const indirizzo = row.original.indirizzo?.trim()

      return h('div', { class: 'flex items-start gap-2 text-sm text-slate-600 dark:text-slate-400 font-medium' }, [
        h(MapPin, { class: 'w-3.5 h-3.5 shrink-0 text-slate-400 mt-0.5' }),
        h('span', { class: 'truncate max-w-[200px] capitalize whitespace-normal leading-tight' }, indirizzo || '—')
      ])
    },
  },
  {
    accessorKey: 'condomini',
      /**
       * ⚠️ **Non ordinabile, e non è una mancanza.** La cella contiene un **elenco** di soggetti,
       * non un valore: un'unità con «Esposito + Russo» non ha una posizione in un ordinamento
       * alfabetico finché qualcuno non decide *quale dei due* faccia da chiave.
       *
       * Finché quella decisione non è presa, l'intestazione ordinava per **quante** persone ci
       * sono nella cella — che è ciò che la libreria fa quando il valore è un array e nessuno
       * dichiara un criterio. Nessuno che clicca lì si aspetta quello: era un reperto aperto
       * della revisione della beta.52.
       *
       * Se un giorno serve, si sceglie la chiave (per esempio «il primo proprietario in ordine
       * alfabetico») e si ordina sul server. Una decisione presa, non un default ereditato.
       */
      enableSorting: false,
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('anagrafiche.table.buildings') }),
    
    cell: ({ row }) => {
        const condomini = row.original.condomini || [];
        
        const mappedCondomini = condomini.map(c => {
            // Qui manteniamo la concatenazione perché i Condomini HANNO i campi separati
            const capComune = [c.cap, c.comune].filter(Boolean).join(' ');
            const prov = c.provincia ? `(${c.provincia})` : '';
            const indirizzoCompleto = [c.indirizzo, capComune, prov].filter(Boolean).join(', ');

            return {
                id: c.id,
                nome: c.nome,
                indirizzo: indirizzoCompleto || undefined,
                url: route(generateRoute('gestionale.index'), { condominio: c.id })
            };
        });

        return h(AnagraficheStack, { anagrafiche: mappedCondomini });
    },

    filterFn: (row, id, value) => {
      const condomini = row.original.condomini ?? [];
      return condomini.some((condominio: Building) => value.includes(String(condominio.id)));
    }
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => h('div', { class: 'relative text-right pr-2' }, h(DropdownAction, { anagrafica: row.original })),
  }
]
