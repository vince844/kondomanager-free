// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { h } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import ContatoriElenco from '@/components/commenti/ContatoriElenco.vue';
import DropdownAction from '@/components/segnalazioni/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/segnalazioni/DataTableColumnHeader.vue';
import { priorityConstants, statoConstants } from '@/lib/segnalazioni/constants';
import { colorePubblicazione, etichettaPrivacy, etichettaStatoPubblicazione, statoPubblicazione } from '@/lib/pubblicazione';
import { usePermission } from "@/composables/permissions";
import { Lock, ShieldCheck, UserX } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import type { ColumnDef } from '@tanstack/vue-table';
import type { Segnalazione } from '@/types/segnalazioni';

const { hasPermission, generateRoute } = usePermission();

export const colonneNascoste = { assegnata_a: false };

export const columns: ColumnDef<Segnalazione>[] = [
  {
    accessorKey: 'subject',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('segnalazioni.table.title') }), 
    cell: ({ row, table }) => {
      const segnalazione = row.original

      const toggleApproval = () => {
          router.put(route(generateRoute('segnalazioni.toggle-approval'), { id: segnalazione.id }), {}, {
            preserveScroll: true,
            only: ['stats', 'segnalazioni', 'flash'],
            onSuccess: () => {
              // Manually update the specific item
              segnalazione.is_approved = !segnalazione.is_approved;
              segnalazione.is_published = segnalazione.is_approved;

                 // Update the row data in the table
              table.options.meta?.updateData(row.index, {
                ...segnalazione,
                is_published: segnalazione.is_approved
              });

            }
          });
      };
      
      const tooltip = segnalazione.is_approved
        ? trans('segnalazioni.table.approved_tooltip')
        : trans('segnalazioni.table.unapproved_tooltip');
      
      const shieldIcon = hasPermission(['Approva segnalazioni'])
        ? h('div', {
            class: 'cursor-pointer mt-0.5',
            title: tooltip,
            onClick: toggleApproval,
          }, [
            h(ShieldCheck, {
              class: segnalazione.is_approved ? 'w-4 h-4 text-green-500' : 'w-4 h-4 text-red-500',
            }),
          ])
        : null;

      const nonAttivo = segnalazione.assegnata_a?.attivo === false
        ? h('span', {
            'data-non-attivo': '',
            title: trans('segnalazioni.assignment.inactive_short'),
            class: 'inline-flex items-center text-amber-600 dark:text-amber-400 ml-1',
          }, [
            h(UserX, { class: 'w-3 h-3 shrink-0' }),
          ])
        : null;

      const sottoTitoloChildren: any[] = [
        h('span', {
          'data-inviata': '',
          title: segnalazione.creata_il,
        }, segnalazione.created_at),
      ];

      if (segnalazione.assegnata_a) {
        sottoTitoloChildren.push(
          h('span', { class: 'text-muted-foreground/60' }, '•'),
          h('span', {
            'data-assegnata-a': '',
            class: 'inline-flex items-center gap-1',
          }, [
            h('span', `${trans('segnalazioni.assignment.label').toLowerCase()} ${segnalazione.assegnata_a.name}`),
            nonAttivo,
          ])
        );
      }

      // Decisione 453: sotto il titolo, in una riga piccola e non in una colonna nuova, quanti commenti e allegati ha; sopra, il titolo con la data
      // e chi la segue (decisione 304).
      return h('div', {
        'data-cella-titolo': '',
        class: 'min-w-[11rem] flex flex-col gap-1',
      }, [
        h('div', { class: 'flex items-start gap-2' }, [
          shieldIcon,
          h('div', { class: 'flex-1 min-w-0' }, [
            h(Link, {
              href: route(generateRoute('segnalazioni.show'), { id: segnalazione.id }),
              title: segnalazione.subject,
              class: 'line-clamp-3 hover:text-zinc-500 font-bold transition-colors duration-150',
            }, () => segnalazione.subject),
            h('div', {
              'data-sotto-titolo': '',
              class: 'text-xs text-muted-foreground flex items-center gap-1.5 mt-0.5',
            }, sottoTitoloChildren),
          ]),
        ]),
        h(ContatoriElenco, { contatori: segnalazione.contatori, mostraInAttesa: true }),
      ]);
    }
  },
  {
    accessorKey: 'condominio',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('segnalazioni.table.buildings') }),
  
    cell: ({ row }) => {
      const condomini = row.original.condominio;
  
      if (!condomini || !condomini.option || !condomini.full) {
        return '—';
      }
  
      // Extract the condominio option and full details
      const condominio = condomini.option;
      const fullDetails = condomini.full;
  
      // Create initials from the condominio's label for the avatar
      const initials = condominio.label
        .split(' ')
        .map((word: string) => word[0]?.toUpperCase()) 
        .join('')
        .slice(0, 2);
  
      // Create the Tooltip and Badge for the condominio
      const avatars = [
        h('div', {
          key: `condominio-avatar`,
          title: fullDetails.nome || condominio.label,
          class: `
            absolute w-8 h-8 rounded-full bg-gray-200 text-gray-800 dark:bg-slate-700 dark:text-slate-200 text-xs font-bold
            flex items-center justify-center border border-white shadow
          `,
          style: `
            z-index: 10;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
          `,
        }, initials)
      ];
  
      return h('div', {
        class: 'relative flex items-center h-10',
      }, avatars);
    },
  
    // MODIFICA QUI: Semplifichiamo la filterFn per evitare che nasconda le righe
    // già filtrate dal backend in caso di type mismatch
    filterFn: (row, id, value) => {
      if (!value || value.length === 0) return true;
      const condominioId = row.original.condominio?.option?.value;
      // Convertiamo in stringa per sicurezza in caso di mismatch tra Int e String
      return value.map(String).includes(String(condominioId));
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
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('segnalazioni.table.residents') }),
  
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
    accessorKey: 'stato',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('segnalazioni.table.status') }),
    cell: ({ row }) => {
      const status = statoConstants.find(s => s.value === row.getValue('stato'))
      
      if (!status) return null
      
      return h('div', { class: 'flex items-center gap-2 whitespace-nowrap' }, [
        h(status.icon, { class: `h-4 w-4 shrink-0 ${status.colorClass}` }),
        h('span', { class: 'whitespace-nowrap' }, trans(status.label))
      ])
    },
    filterFn: (row, id, value) => value.includes(row.getValue(id)),
  },
  {
    accessorKey: 'priority',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('segnalazioni.table.priority') }),
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
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('segnalazioni.table.visibility') }),
    // Coda 306: lo stato di pubblicazione viene da `is_published` e `is_approved` insieme («In attesa
    // di approvazione», «Bozza», «Pubblicata»); «Privata» è un'indicazione a parte, da `is_private`.
    // Le due etichette vengono da `@/lib/pubblicazione`, come nella scheda.
    cell: ({ row }) => {
      const segnalazione = row.original as unknown as Record<string, unknown>;
      const stato = statoPubblicazione(row.getValue('is_published'), segnalazione.is_approved);
      const privata = etichettaPrivacy('segnalazioni', segnalazione.is_private);

      return h('div', { class: 'flex items-center gap-2 whitespace-nowrap' }, [
        h('span', { class: `h-2 w-2 shrink-0 rounded-full ${colorePubblicazione(stato)}` }),
        h('span', { class: 'whitespace-nowrap' }, trans(etichettaStatoPubblicazione('segnalazioni', row.getValue('is_published'), segnalazione.is_approved))),
        privata
          ? h('span', { class: 'inline-flex items-center gap-1 text-xs text-slate-500 whitespace-nowrap', title: trans('segnalazioni.privacy.private_hint') }, [
              h(Lock, { class: 'h-3 w-3 shrink-0' }),
              h('span', { class: 'whitespace-nowrap' }, trans(privata)),
            ])
          : null,
      ]);
    },
    filterFn: (row, _id, value) => {
      if (!Array.isArray(value) || value.length === 0) return true;
      const segnalazione = row.original as unknown as Record<string, unknown>;
      const stato = statoPubblicazione(row.getValue('is_published'), segnalazione.is_approved);
      const isPrivate = Boolean(segnalazione.is_private);
      return value.includes(stato) || (isPrivate && value.includes('private'));
    },
  },
  {
    accessorKey: 'assegnata_a',
    header: () => trans('segnalazioni.assignment.label'),
    cell: () => null,
    enableSorting: false,
    filterFn: () => true,
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const segnalazione = row.original
      return h('div', { class: 'relative' }, h(DropdownAction, { segnalazione }))
    },
  },
]