// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { h } from 'vue';
import { Link } from '@inertiajs/vue3';
import DropdownAction from '@/components/comunicazioni/DataTableRowActions.vue';
import ScudoApprovazione from '@/components/comunicazioni/ScudoApprovazione.vue';
import ContatoriElenco from '@/components/commenti/ContatoriElenco.vue';
import DataTableColumnHeader from '@/components/comunicazioni/DataTableColumnHeader.vue';
import { priorityConstants } from '@/lib/comunicazioni/constants';
import { colorePubblicazione, etichettaPrivacy, etichettaStatoPubblicazione, giornoEOraProgrammati, statoPubblicazione } from '@/lib/pubblicazione';
import { usePermission } from "@/composables/permissions";
import { trans } from 'laravel-vue-i18n';
import { CheckCheck, Eye, Lock } from 'lucide-vue-next';
import { Permission }  from "@/enums/Permission";
import type { ColumnDef } from '@tanstack/vue-table';
import type { Comunicazione } from '@/types/comunicazioni';
import type { Building } from '@/types/buildings';

const { hasPermission,  generateRoute } = usePermission();

export const columns: ColumnDef<Comunicazione>[] = [
  {
    accessorKey: 'subject',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('comunicazioni.table.title') }),

    cell: ({ row, table }) => {

      const comunicazione = row.original;

      // P8 della lente P (Fase 1-bis della beta.12): lo scudo chiede conferma dove il clic ha conseguenze (una programmata perde la data, una non
      // approvata esce subito con le email): `ScudoApprovazione.vue`. La riga si aggiorna senza ricaricare l'elenco, come prima.
      const shieldIcon = hasPermission([Permission.APPROVE_COMUNICAZIONI])
        ? h(ScudoApprovazione, {
            comunicazione,
            aggiorna: (campi: Partial<Comunicazione>) => {
              Object.assign(comunicazione, campi);
              table.options.meta?.updateData(row.index, { ...comunicazione });
            },
          })
        : null;

      const linkTitolo = h('div', { class: 'flex items-center space-x-2' }, [
        shieldIcon,
        h(Link, {
          href: route(generateRoute('comunicazioni.show'), { id: comunicazione.id }),
          class: 'hover:text-zinc-500 font-bold transition-colors duration-150',
        }, () => comunicazione.subject)
      ]);

      // Coda 594, decisione 312 (11): «Aperture: 12» se ce n'è almeno una, «Prese visione: 8» se la presa visione è chiesta o se ce ne sono.
      const letture = comunicazione.letture;
      const mostraAperte = (letture?.aperte ?? 0) > 0;
      const mostraPrese = Boolean(comunicazione.chiedi_presa_visione) || (letture?.prese_visione ?? 0) > 0;

      const lettureFigli = [];
      if (mostraAperte) {
        lettureFigli.push(
          h('span', { 'data-letture-aperte': '', class: 'inline-flex items-center gap-1' }, [
            h(Eye, { class: 'w-3.5 h-3.5 shrink-0', 'aria-hidden': 'true' }),
            trans('comunicazioni.readings.list_opened', { count: letture?.aperte ?? 0 }),
          ])
        );
      }
      if (mostraPrese) {
        lettureFigli.push(
          h('span', { 'data-letture-prese-visione': '', class: 'inline-flex items-center gap-1' }, [
            h(CheckCheck, { class: 'w-3.5 h-3.5 shrink-0', 'aria-hidden': 'true' }),
            trans('comunicazioni.readings.list_acknowledged', { count: letture?.prese_visione ?? 0 }),
          ])
        );
      }

      const rigaLetture = lettureFigli.length > 0
        ? h('span', {
            'data-letture': '',
            class: 'inline-flex flex-wrap items-center gap-x-4 gap-y-1 text-gray-700 dark:text-slate-200',
          }, lettureFigli)
        : null;

      // Decisione 441 e nota E5 della decisione 313: sotto il titolo, in una riga piccola e non in una colonna nuova, quanti commenti e allegati ha e,
      // nella stessa riga, le letture. La riga parte dove parte il titolo, non sotto lo scudo (`ScudoApprovazione`: l'icona e lo spazio che la segue).
      const riga = comunicazione.contatori || rigaLetture
        ? h('div', {
            'data-riga-contatori': '',
            class: ['flex flex-wrap items-center gap-x-4 gap-y-1 text-xs tabular-nums', shieldIcon ? 'pl-6' : ''],
          }, [
            h(ContatoriElenco, { contatori: comunicazione.contatori, mostraInAttesa: true }),
            rigaLetture,
          ])
        : null;

      return h('div', { class: 'flex flex-col gap-1' }, [linkTitolo, riga]);
    }
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
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('comunicazioni.table.buildings') }),
  
    cell: ({ row }) => {
      const condomini = row.original.condomini;

      if (!condomini || !Array.isArray(condomini.options) || !Array.isArray(condomini.full) || !condomini.options.length) {
        return '—';
      }
    
      const maxAvatars = 3;
      const visibleCondomini = condomini.options.slice(0, maxAvatars);
      const remainingCount = condomini.options.length - maxAvatars;
    
      const avatars = visibleCondomini.map((option, index) => {
        const initials = option.label
          .split(' ')
          .map(word => word[0]?.toUpperCase())
          .join('')
          .slice(0, 2);
    
        const tooltip = condomini.full[index]?.nome || option.label;
    
        return h('div', {
          key: `${option.label}-${index}`,
          title: tooltip,
          class: `
            absolute w-8 h-8 rounded-full bg-gray-200 text-gray-800 text-xs font-bold
            flex items-center justify-center border border-white shadow
          `,
          style: `
            z-index: ${10 + index};
            left: ${index * 18}px;
            top: 50%;
            transform: translateY(-50%);
          `,
        }, initials);
      });
    
      if (remainingCount > 0) {
        avatars.push(
          h('div', {
            key: 'more-condomini',
            title: `+${remainingCount} altri condomini`,
            class: `
              absolute w-8 h-8 rounded-full bg-gray-300 text-gray-800 text-xs font-bold
              flex items-center justify-center border border-white shadow
            `,
            style: `
              z-index: 13;
              left: ${maxAvatars * 18}px;
              top: 50%;
              transform: translateY(-50%);
            `,
          }, `+${remainingCount}`)
        );
      }
    
      return h('div', {
        class: 'relative flex items-center h-10',
      }, avatars);
    },
  
    filterFn: (row, id, value) => {
      const condomini = row.original.condomini?.options ?? [];
      return condomini.some((condominio: Building) => value.includes(condominio.value));
    },
  },
  {
    accessorKey: 'anagrafiche',
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
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('comunicazioni.table.residents') }),
  
    cell: ({ row }) => {
      const anagrafiche = row.original.anagrafiche;
    
      if (!Array.isArray(anagrafiche) || anagrafiche.length === 0) {
        return '—';
      }
    
      const maxAvatars = 3;
      const visibleAnagrafiche = anagrafiche.slice(0, maxAvatars);
      const remainingCount = anagrafiche.length - maxAvatars;
    
      const avatars = visibleAnagrafiche.map((item, index) => {
        const initials = item.nome
          ?.split(' ')
          .map(word => word[0]?.toUpperCase())
          .join('')
          .slice(0, 2) || '?';
    
          const tooltip = item.nome || '—';
    
        return h('div', {
          key: `${item.nome}-${index}`,
          title: tooltip,
          class: `
            absolute w-8 h-8 rounded-full bg-gray-200 text-gray-800 text-xs font-bold
            flex items-center justify-center border border-white shadow
          `,
          style: `
            z-index: ${10 + index};
            left: ${index * 18}px;
            top: 50%;
            transform: translateY(-50%);
          `,
        }, initials);
      });
    
      if (remainingCount > 0) {
        avatars.push(
          h('div', {
            key: 'more-anagrafiche',
            title: `+${remainingCount} altre persone`,
            class: `
              absolute w-8 h-8 rounded-full bg-gray-300 text-gray-800 text-xs font-bold
              flex items-center justify-center border border-white shadow
            `,
            style: `
              z-index: ${10 + maxAvatars};
              left: ${maxAvatars * 18}px;
              top: 50%;
              transform: translateY(-50%);
            `,
          }, `+${remainingCount}`)
        );
      }
    
      return h('div', {
        class: 'relative flex items-center h-10',
      }, avatars);
    },

  },
  {
    accessorKey: 'priority',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('comunicazioni.table.priority') }),
    cell: ({ row }) => {
      const priority = priorityConstants.find(p => p.value === row.getValue('priority'))
      
      if (!priority) return null
      
      return h('div', { 
        class: `flex items-center gap-2 whitespace-nowrap`
      }, [
        h(priority.icon, { class: `h-4 w-4 shrink-0 ${priority.colorClass}` }),
        h('span', { class: 'whitespace-nowrap' }, trans(priority.label))
      ])
    },
    filterFn: (row, id, value) => value.includes(row.getValue(id)),
  },
  {
    accessorKey: 'is_published',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('comunicazioni.table.status') }),
    // Coda 306: lo stato di pubblicazione viene da `is_published` e `is_approved` insieme («In attesa
    // di approvazione», «Bozza», «Pubblicata»); «Privata» è un'indicazione a parte, da `is_private`.
    // Le due etichette vengono da `@/lib/pubblicazione`, come nella scheda.
    cell: ({ row }) => {
      const comunicazione = row.original as unknown as Record<string, unknown>;
      const stato = statoPubblicazione(row.getValue('is_published'), comunicazione.is_approved, comunicazione.data_programmata);
      const privata = etichettaPrivacy('comunicazioni', comunicazione.is_private);
      const programmata = stato === 'scheduled' ? giornoEOraProgrammati(comunicazione.data_programmata) : null;

      return h('div', { class: 'flex items-center gap-2 whitespace-nowrap' }, [
        h('span', { class: `h-2 w-2 shrink-0 rounded-full ${colorePubblicazione(stato)}` }),
        h('span', { class: 'whitespace-nowrap' }, trans(etichettaStatoPubblicazione('comunicazioni', row.getValue('is_published'), comunicazione.is_approved, comunicazione.data_programmata))),
        programmata
          ? h('span', {
              'data-programmata-per': '',
              class: 'text-xs text-muted-foreground whitespace-nowrap',
            }, `${programmata.date} ${programmata.time}`)
          : null,
        privata
          ? h('span', { class: 'inline-flex items-center gap-1 text-xs text-slate-500 whitespace-nowrap', title: trans('comunicazioni.privacy.private_hint') }, [
              h(Lock, { class: 'h-3 w-3 shrink-0' }),
              h('span', { class: 'whitespace-nowrap' }, trans(privata)),
            ])
          : null,
      ]);
    },
    filterFn: (row, _id, value) => {
      if (!Array.isArray(value) || value.length === 0) return true;
      const comunicazione = row.original as unknown as Record<string, unknown>;
      const stato = statoPubblicazione(row.getValue('is_published'), comunicazione.is_approved, comunicazione.data_programmata);
      const isPrivate = Boolean(comunicazione.is_private);
      return value.includes(stato) || (isPrivate && value.includes('private'));
    },
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const comunicazione = row.original
      return h('div', { class: 'relative' }, h(DropdownAction, { comunicazione }))
    },
  },
]