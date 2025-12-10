import { h } from 'vue'
import { Link } from '@inertiajs/vue3';
import DropdownAction from '@/components/fornitori/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/fornitori/DataTableColumnHeader.vue';
import { usePermission } from "@/composables/permissions";
import type { ColumnDef } from '@tanstack/vue-table'
import type { Fornitore } from '@/types/fornitori';

const { generateRoute } = usePermission();

export const columns: ColumnDef<Fornitore>[] = [
  {
    accessorKey: 'ragione_sociale',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Ragione sociale' }), 

    cell: ({ row }) => {

      const fornitore = row.original

      return h('div', { class: 'flex items-center space-x-2' }, [
          h(Link, {
            href: route(generateRoute('fornitori.show'), { fornitore: fornitore.id }),
            class: 'hover:text-zinc-500 font-bold transition-colors duration-150',
          }, () => fornitore.ragione_sociale)
        ]);
    }
  },
  {
    accessorKey: 'indirizzo',
    header: ({ column }) =>
      h(DataTableColumnHeader, { column, title: 'Indirizzo' }),

    cell: ({ row }) => {
      const f = row.original as Fornitore

      // Prendi i dati (o stringa vuota)
      const indirizzo = f.indirizzo?.trim() || ''
      const cap = f.cap?.trim() || ''
      const comune = f.comune?.trim() || ''
      const provincia = f.provincia?.trim() || ''

      // Costruisci la parte "CAP Comune"
      const capComune = [cap, comune].filter(Boolean).join(' ')

      // Costruisci la parte "provincia" con parentesi
      const prov = provincia ? `(${provincia})` : ''

      // Costruisci l'indirizzo completo senza buchi
      const dettagli = [indirizzo, capComune, prov]
        .filter(Boolean)   // rimuove stringhe vuote
        .join(', ')        // unisce correttamente

      return h('div', { class: 'flex space-x-2' }, [
        h('span', dettagli || '')
      ])
    },
  },
  {
    accessorKey: 'partita_iva',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Partita IVA' }), 
    cell: ({ row }) => h('div', { class: 'uppercase' }, row.getValue('partita_iva')),
  },
  {
    accessorKey: 'codice_fiscale',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Codice fiscale' }), 
    cell: ({ row }) => h('div', { class: 'uppercase' }, row.getValue('codice_fiscale')),
  },
  {
    accessorKey: 'referenti',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Referenti' }),
  
    cell: ({ row }) => {

      const anagrafiche = row.original.referenti;
    
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
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const fornitore = row.original
      return h('div', { class: 'relative' }, h(DropdownAction, {
        fornitore,
      }))
    },
  }
]