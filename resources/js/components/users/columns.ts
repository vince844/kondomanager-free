import { h } from 'vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { User } from '@/types/users';
import DropdownAction from '@/components/users/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/users/DataTableColumnHeader.vue';
import { ShieldCheck } from 'lucide-vue-next';

const roleClasses: Record<string, string> = {
  amministratore: "inline-flex items-center rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-red-600/10 ring-inset",
  collaboratore: "inline-flex items-center rounded-md bg-purple-50 px-2 py-1 text-xs font-medium text-purple-700 ring-1 ring-purple-700/10 ring-inset",
  fornitore: "inline-flex items-center rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-green-600/20 ring-inset",
  utente: "inline-flex items-center rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 ring-1 ring-blue-700/10 ring-inset",
};

export const columns: ColumnDef<User>[] = [
      {
        accessorKey: 'name',
      
        header: ({ column }) =>
          h(DataTableColumnHeader, {
            column,
            title: 'Nome e cognome',
          }),
      
        cell: ({ row }) => {
          
          const verified = row.original.verified_at;
      
          return h('div', { class: 'flex items-center space-x-2' }, [
            h(
              ShieldCheck,
              {
                class: verified ? 'w-4 h-4 text-green-500' : 'w-4 h-4 text-red-500',
              }
            ),
            h(
              'span',
              { class: 'font-medium' },
              row.getValue('name')
            ),
          ]);
        },
      },
      {
        accessorKey: 'email',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Indirizzo email' }), 
        cell: ({ row }) => h('div', { class: 'lowercase' }, row.getValue('email')),
      },
      {
        accessorKey: 'roles',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Ruolo' }), 
        cell: ({ getValue }) => {
          const roles = getValue() as string[];
          return h(
            "div",
            { class: "flex gap-2" }, 
            roles.map((role) =>
              h("span", { class: `px-2 py-1 rounded text-xs font-medium capitalize ${roleClasses[role] || "inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-gray-500/10 ring-inset"}` }, role)
            )
          );
        }
        
      },
      {
        accessorKey: 'suspended_at',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Stato' }), 
        cell: ({ getValue }) => {

          const status = getValue() as string | null;

          const label = status ? 'Sospeso' : 'Attivo';
          const badgeClass = status
            ? 'inline-flex items-center rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-red-600/10 ring-inset'
            : 'inline-flex items-center rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-green-600/20 ring-inset';

            return h(
              'div',
              { class: 'flex gap-2' },
              [
                h(
                  'span',
                  {
                    class: `px-2 py-1 rounded text-xs font-medium ${badgeClass}`,
                  },
                  label
                )
              ]
            ); 
        }
      },
      {
        id: 'actions',
        enableHiding: false,
        cell: ({ row }) => {
          const user = row.original
          return h('div', { class: 'relative' }, h(DropdownAction, {
            user,
          }))
        },
      }
]