import { h } from 'vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Invito } from '@/types/inviti';
import type { Building } from '@/types/buildings';
import DropdownAction from '@/components/inviti/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/inviti/DataTableColumnHeader.vue';
import { Badge }  from '@/components/ui/badge';

export const columns: ColumnDef<Invito>[] = [
      {
        accessorKey: 'email',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Indirizzo email' }),
        cell: ({ row }) => {
          const label = new Date(row.original.created_at).toLocaleDateString('it-IT') 
    
          return h('div', { class: 'flex space-x-2' }, [
            label ? h(Badge, { variant: 'outline', class: 'rounded-md' }, () => label) : null,
            h('span', { class: 'lowercase font-bold' }, row.getValue('email')),
          ])
        }, 
      },
      {
        accessorKey: 'condomini',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Condomini associati' }), 
        cell: ({ getValue }) => {

          const condomini = getValue() as Building[];

          return h('div', { class: 'flex space-x-2' }, [
            condomini.map((condominio) =>
              condominio ? h(Badge, { variant: 'outline', class: 'rounded-md font-medium' }, () => condominio.nome) : null,      
            )
                      
          ])
        }
      },
      {
        accessorKey: 'accepted_at',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Stato' }),
        cell: ({ row }) => {
          
          // Access the original row data
          const original = row.original; 

          // Extract 'accepted_at' and 'expires_at'
          const acceptedAt = original.accepted_at;
          const expiredAt = original.expires_at

          // Current date to compare with
          const currentDate = new Date();
       
          // Determine if accepted, expired, or not accepted
          let statusLabel = '';
          let badgeClass = '';
    
          if (expiredAt && new Date(expiredAt) < currentDate) {
            // If expired date is in the past, show "Scaduto"
            statusLabel = 'Scaduto';
            badgeClass = 'inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-gray-500/10 ring-inset'; 
          } else if (acceptedAt) {
            // If accepted_at exists, show "Accettato"
            statusLabel = 'Accettato';
            badgeClass = 'inline-flex items-center rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-green-600/20 ring-inset'; 
          } else {
            // If neither, show "Non accettato"
            statusLabel = 'Non accettato';
            badgeClass = 'inline-flex items-center rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-red-600/10 ring-inset';
          } 
    
          return h(
            'div',
            { class: 'flex gap-2' },
            [
              h(
                'span',
                {
                  class: `px-2 py-1 rounded text-xs font-medium ${badgeClass}`,
                },
                statusLabel
              ),
            ]
          );
        },
      },
      {
        id: 'actions',
        enableHiding: false,
        cell: ({ row }) => {
          const invito = row.original
          return h('div', { class: 'relative' }, h(DropdownAction, {
            invito,
          }))
        },
      }
]