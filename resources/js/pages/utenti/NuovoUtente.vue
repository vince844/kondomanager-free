<script setup lang="ts">
  
import { Head, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import vSelect from "vue-select";
import { LoaderCircle, Info } from 'lucide-vue-next';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import { Separator } from '@/components/ui/separator';
import { Item, ItemContent, ItemDescription, ItemTitle } from '@/components/ui/item';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { trans } from 'laravel-vue-i18n';
import type { Anagrafica } from '@/types/anagrafiche';
import type { Permission } from '@/types/permissions';
import type { Role } from '@/types/roles';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
  roles: Role[];
  permissions: Permission[];
  anagrafiche: Anagrafica[];
}>();  

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Impostazioni', href: '/impostazioni' },
  { title: 'utenti', href: '/utenti' },
  { title: 'crea utente', href: '#' },
];

const form = useForm({
    name: '',
    email: '',
    roles: null as number | null,
    permissions: [] as Permission[],
    anagrafica: null as number | null,
});

// Computed per ottenere il ruolo selezionato
const selectedRole = computed(() => {
    if (!form.roles) return null;
    return props.roles.find(r => r.id === form.roles) ?? null;
});

// Computed per i permessi ereditati dal ruolo selezionato
const inheritedPermissions = computed(() => {
    const role = selectedRole.value;
    if (!role) return [];
    
    const perms = role.permissions || [];
    return Array.isArray(perms) ? perms : [];
});

// Computed per i permessi selezionabili nel dropdown
const selectablePermissions = computed(() => {
    const inheritedIds = inheritedPermissions.value.map(p => p.id);
    const selectedIds = form.permissions.map(p => p.id);

    return props.permissions.filter(
        permission =>
            !inheritedIds.includes(permission.id) &&
            !selectedIds.includes(permission.id)
    );
});

// Watch per rimuovere permessi che diventano ereditati quando cambia il ruolo
watch(
    () => form.roles,
    (newRoleId) => {
        const newRole = props.roles.find(r => r.id === newRoleId);
        if (newRole && newRole.permissions) {
            const newRolePermissionIds = newRole.permissions.map(p => p.id);
            // Rimuovi i permessi aggiuntivi che sono ora ereditati dal nuovo ruolo
            form.permissions = form.permissions.filter(
                perm => !newRolePermissionIds.includes(perm.id)
            );
        }
    }
);

const submit = () => {
    form.transform((data) => ({
        ...data,
        permissions: data.permissions.map(p => p.id)
    })).post(route("utenti.store"), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        }
    });
};
</script>

<template>
  <Head :title="trans('users.header.new_user_head')" />
  
  <AppLayout :breadcrumbs="breadcrumbs">
    <UtentiLayout>
      <form @submit.prevent="submit">
        <div>
          <h3 class="text-lg font-medium leading-6 text-gray-900">{{trans('users.header.new_user_title')}}</h3>
          <p class="mt-1 text-sm text-gray-500">
            {{trans('users.header.new_user_description')}}
          </p>
        </div>

        <Separator class="my-4" />

        <div class="py-4">
          <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
              <Label for="name">{{trans('users.label.name')}}</Label>
              <Input 
                id="name" 
                class="mt-1 block w-full"
                v-model="form.name" 
                @focus="form.clearErrors('name')"
                autocomplete="name" 
                :placeholder="trans('users.placeholder.name')" 
              />
              <InputError :message="form.errors.name" />
            </div>

            <div class="sm:col-span-3">
              <Label for="email">{{trans('users.label.email')}}</Label>
              <Input 
                id="email" 
                class="mt-1 block w-full"
                v-model="form.email" 
                @focus="form.clearErrors('email')"
                autocomplete="email" 
                :placeholder="trans('users.placeholder.email')" 
              />
              <InputError class="mt-2" :message="form.errors.email" />
            </div>
          </div>

          <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">

              <div class="flex items-center text-sm font-medium pb-2 gap-x-2">
                <Label for="role">{{trans('users.label.role')}}</Label>

                <HoverCard>
                    <HoverCardTrigger as-child>
                    <button type="button" class="cursor-pointer">
                        <Info class="w-4 h-4 text-muted-foreground" />
                    </button>
                    </HoverCardTrigger>
                    <HoverCardContent class="w-80">
                    <div class="flex justify-between space-x-4">
                        <div class="space-y-1">
                        <h4 class="text-sm font-semibold">
                            {{trans('users.label.role')}}
                        </h4>
                        <p class="text-sm">
                            {{trans('users.tooltip.role_line_1')}}
                        </p>
                        <p class="text-sm">
                            {{trans('users.tooltip.role_line_2')}}
                        </p>
                        </div>
                    </div>
                    </HoverCardContent>
                </HoverCard>
              </div>

              <v-select 
                :options="roles" 
                label="name" 
                v-model="form.roles"
                :reduce="(option: Role) => option.id"
                @update:modelValue="form.clearErrors('roles')"
                :placeholder="trans('users.placeholder.role')" 
              />

              <InputError class="mt-2" :message="form.errors.roles" />
            </div>

            <div class="sm:col-span-3">
              <div class="flex items-center text-sm font-medium pb-2 gap-x-2">
                <Label for="anagrafica">{{trans('users.label.resident')}}</Label>

                <HoverCard>
                    <HoverCardTrigger as-child>
                    <button type="button" class="cursor-pointer">
                        <Info class="w-4 h-4 text-muted-foreground" />
                    </button>
                    </HoverCardTrigger>
                    <HoverCardContent class="w-80">
                    <div class="flex justify-between space-x-4">
                        <div class="space-y-1">
                        <h4 class="text-sm font-semibold">
                            {{trans('users.label.resident')}}
                        </h4>
                        <p class="text-sm">
                            {{trans('users.tooltip.resident')}}
                        </p>
                        </div>
                    </div>
                    </HoverCardContent>
                </HoverCard>
              </div>
              <v-select 
                :options="anagrafiche" 
                label="nome" 
                v-model="form.anagrafica"
                :reduce="(option: Anagrafica) => option.id"
                @update:modelValue="form.clearErrors('anagrafica')" 
                :placeholder="trans('users.placeholder.resident')" 
              >
                <template #option="{ nome, indirizzo }">
                  <div class="flex flex-col">
                    <span class="font-medium">{{ nome }}</span>
                    <span class="text-sm text-gray-500">{{ indirizzo }}</span>
                  </div>
                </template>
                <template #selected-option="{ nome, indirizzo }">
                  <div class="flex items-center gap-2">
                    <span class="font-medium">{{ nome }}</span>
                    <span class="text-gray-500 text-sm">– {{ indirizzo }}</span>
                  </div>
                </template>
              </v-select>
              <InputError class="mt-2" :message="form.errors.anagrafica" />
            </div>
          </div>

          <div class="mt-6">
            <div class="flex items-center text-sm font-medium pb-2 gap-x-2">
              <Label for="permissions">{{trans('users.label.permissions')}}</Label>

              <HoverCard>
                <HoverCardTrigger as-child>
                <button type="button" class="cursor-pointer">
                    <Info class="w-4 h-4 text-muted-foreground" />
                </button>
                </HoverCardTrigger>
                <HoverCardContent class="w-80">
                <div class="flex justify-between space-x-4">
                    <div class="space-y-1">
                    <h4 class="text-sm font-semibold">
                        {{trans('users.label.permissions')}}
                    </h4>
                    <p class="text-sm">
                        {{trans('users.tooltip.permissions')}}
                    </p>
                    </div>
                </div>
                </HoverCardContent>
              </HoverCard>
            </div>
            <v-select 
              multiple
              :options="selectablePermissions" 
              label="name"
              :close-on-select="false"
              v-model="form.permissions"
              :placeholder="trans('users.placeholder.permissions')" 
            >
              <template #option="{ name, description }">
                <div class="flex flex-col">
                  <span class="font-medium">{{ name }}</span>
                  <span class="text-sm text-gray-500">{{ description }}</span>
                </div>
              </template>
            </v-select>
            <p class="mt-1 text-sm text-gray-500">
               {{trans('users.label.permission_notice')}}
            </p>
          </div>
        </div>

        <div class="pt-5">
          <Button :disabled="form.processing">
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            {{trans('users.actions.new_user')}}
          </Button>
        </div>
      </form>

      <!-- Mostra i permessi ereditati solo se è stato selezionato un ruolo -->
      <template v-if="form.roles">
        <Separator class="my-8" />

        <div>
          <div class="flex items-center gap-2 mb-4">
            <div>
              <h2 class="text-xl font-semibold">{{trans('users.header.permissions_title')}}</h2>

              <p class="text-sm text-muted-foreground">
                {{ trans('users.header.permissions_description_line_1') }}
                <span v-if="selectedRole" class="font-semibold">
                  {{ selectedRole.name }}
                </span>
                {{ trans('users.header.permissions_description_line_2') }}
              </p>

            </div>
          </div>

          <div v-if="!inheritedPermissions.length" class="text-center py-12 border rounded-lg bg-muted/20">
            {{trans('users.empty_state.inherited_permissions')}}
          </div>

          <div v-else class="flex flex-col gap-3">
            <Item 
              v-for="permission in inheritedPermissions" 
              :key="permission.id"
              variant="muted"
            >
              <ItemContent>
                <div class="flex items-center gap-2">
                  <ItemTitle>{{ permission.name }}</ItemTitle>
                </div>
                <ItemDescription>
                  {{ permission.description }}
                </ItemDescription>
              </ItemContent>
            </Item>
          </div>
        </div>
      </template>
    </UtentiLayout>
  </AppLayout> 
</template>

<style src="vue-select/dist/vue-select.css"></style>