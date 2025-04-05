import { h } from 'vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { User } from '@/types/users';
import DropdownAction from '@/components/users/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/users/DataTableColumnHeader.vue';
import { ShieldCheck } from 'lucide-vue-next';

const roleClasses: Record<string, string> = {
  amministratore: "bg-red-400 text-white",
  collaboratore: "bg-purple-400 text-white",
  fornitore: "bg-green-400 text-white",
  utente: "bg-blue-400 text-white",
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
              { class: 'max-w-[500px] truncate font-medium' },
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
              h("span", { class: `px-2 py-1 rounded text-xs font-medium capitalize ${roleClasses[role] || "bg-gray-400 text-white"}` }, role)
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
            ? 'bg-red-400 text-white'
            : 'bg-green-400 text-white';

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