import { h } from 'vue'
import { router } from '@inertiajs/vue3';
import DropdownAction from '@/components/users/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/users/DataTableColumnHeader.vue';
import { ShieldCheck, Key, CheckCircle2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import type { ColumnDef } from '@tanstack/vue-table'
import type { User } from '@/types/users';

const roleClasses: Record<string, string> = {
  amministratore: "bg-red-100 text-red-800 hover:bg-red-200",
  collaboratore: "bg-purple-100 text-purple-800 hover:bg-purple-200",
  fornitore: "bg-green-100 text-green-800 hover:bg-green-200",
  utente: "bg-blue-100 text-blue-800 hover:bg-blue-200",
};

const defaultRoleClass = "inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-gray-500/10 ring-inset";

const statusClasses = {
  suspended: "inline-flex items-center rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-red-600/10 ring-inset",
  active: "inline-flex items-center rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-green-600/20 ring-inset",
  none: "inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-gray-500/10 ring-inset"
};

// Versione semplificata senza componenti complessi
const UserPermissionsDialog = {
  props: ['user'],
  setup(props: { user: User }) {
    const user = props.user;
    
    // Gestisci sia string[] che Permission[]
    const permissions = user.permissions || [];
    const permissionNames = permissions.map(p => 
      typeof p === 'string' ? p : p.name
    );
    
    const roles = user.roles || [];
    const roleNames = roles.map(r => 
      typeof r === 'string' ? r : r.name
    );
    
    return () => h(Dialog, {}, {
      default: () => [
        h(DialogTrigger, {}, () => 
          h(Button, {
            variant: 'outline',
            size: 'sm',
            class: 'h-6 gap-1 text-xs' 
          }, () => [
            h(Key, { class: 'h-2 w-2' }),
            h('span', permissionNames.length.toString()),
          ])
        ),
        
        h(DialogContent, { class: 'max-w-2xl max-h-[80vh]' }, () => [
          h(DialogHeader, {}, () => [
            h(DialogTitle, { class: 'flex items-center gap-2' }, () => [
              'Permessi utente'
            ]),
            h(DialogDescription, {}, () => [
              `Permessi assegnati a ${user.name}`,
              h('div', { class: 'flex items-center gap-2 mt-2 flex-wrap' }, [
                h('span', { 
                  class: 'inline-flex items-center rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 ring-1 ring-blue-600/20 ring-inset'
                }, `${permissionNames.length} permessi`),
                ...roleNames.map((roleName, index) => 
                  h('span', { 
                    key: `${roleName}-${index}`,
                    class: `px-2 py-1 rounded text-xs font-medium capitalize ${roleClasses[roleName] || defaultRoleClass}`
                  }, roleName)
                )
              ])
            ])
          ]),
          
          h('div', { class: 'mt-4 max-h-[60vh] overflow-y-auto space-y-2' },
            permissionNames.length > 0 
              ? permissionNames.map((permissionName, index) => 
                  h('div', {
                    key: `${permissionName}-${index}`,
                    class: 'flex items-center gap-2 p-3 rounded-lg border'
                  }, [
                    h(CheckCircle2, { class: 'h-4 w-4 text-green-500 flex-shrink-0' }),
                    h('span', { class: 'text-sm' }, permissionName)
                  ])
                )
              : h('p', { class: 'text-center text-muted-foreground py-8' },
                  'Nessun permesso assegnato'
                )
          )
        ])
      ]
    });
  }
};

export const columns: ColumnDef<User>[] = [
  {
    accessorKey: 'name',
    header: ({ column }) =>
      h(DataTableColumnHeader, { column, title: 'Nome e cognome' }),
    cell: ({ row, table }) => {
      const user = row.original;

      const toggleVerification = () => {
        router.put(route('utenti.toggle-verification', { user: user.id }), {}, {
          preserveScroll: true,
          only: ['users', 'flash'],
          onSuccess: () => {
            user.email_verified_at = user.email_verified_at ? null : new Date().toISOString();
            table.options.meta?.updateData(row.index, user);
          },
        });
      };

      const tooltip = user.email_verified_at
        ? 'Utente verificato - clicca per revocare verifica'
        : 'Utente non verificato - clicca per verificare';

      return h('div', { class: 'flex items-center space-x-2' }, [
        h('div', {
          class: 'cursor-pointer',
          title: tooltip,
          onClick: toggleVerification,
        }, [
          h(ShieldCheck, {
            class: user.email_verified_at ? 'w-4 h-4 text-green-500' : 'w-4 h-4 text-red-500',
          })
        ]),
        h('span', { class: 'font-bold' }, user.name),
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
          h("span", { 
            key: role,
            class: `px-2 py-1 rounded text-xs font-medium capitalize ${roleClasses[role] || defaultRoleClass}` 
          }, role)
        )
      );
    }
  },
  {
    accessorKey: 'permissions',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Permessi' }), 
    cell: ({ getValue, row }) => {
      const permissions = getValue() as string[];
      const user = row.original;
      const total = permissions?.length || 0;
      
      if (total === 0) {
        return h('span', { 
          class: statusClasses.none
        }, 'Nessuno');
      }
      
      return h(UserPermissionsDialog, { user });
    }
  },
  {
    accessorKey: 'suspended_at',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Stato' }), 
    cell: ({ getValue }) => {
      const status = getValue() as string | null;
      const label = status ? 'Sospeso' : 'Attivo';
      const badgeClass = status ? statusClasses.suspended : statusClasses.active;

      return h('div', { class: 'flex gap-2' }, [
        h('span', { class: badgeClass }, label)
      ]); 
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