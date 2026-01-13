<script setup lang="ts">

import { Head, useForm, Link } from '@inertiajs/vue3';
import { watch, onMounted, computed, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import vSelect from "vue-select";
import { LoaderCircle, Trash2 } from 'lucide-vue-next';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import { Separator } from '@/components/ui/separator';
import { Item, ItemActions, ItemContent, ItemDescription, ItemTitle } from '@/components/ui/item';
import { trans } from 'laravel-vue-i18n';
import type { Anagrafica } from '@/types/anagrafiche';
import type { Permission } from '@/types/permissions';
import type { Role } from '@/types/roles';
import type { User } from '@/types/users';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
  user: User;
  permissions: Permission[];
  anagrafiche: Anagrafica[];
  roles: Role[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Impostazioni', href: '/impostazioni' },
  { title: 'utenti', href: '/utenti' },
  { title: 'modifica utente', href: '#' },
];

const form = useForm({
  name: props.user?.name,
  email: props.user?.email,
  roles: props.user?.roles[0]?.id,
  permissions: [] as Permission[],
  anagrafica: props.user?.anagrafica?.id,
});

const selectedRole = computed(() => {
  return props.roles.find(r => r.id === form.roles) ?? null;
});

const inheritedPermissions = computed(() => {
  const role = selectedRole.value;
  if (!role) return [];
  const perms = role.permissions || [];
  return Array.isArray(perms) ? perms : [];
});

const originalRolePermissionIds = computed(() => {
  return props.user?.roles?.[0]?.permissions?.map(p => p.id) || [];
});

const additionalPermissions = computed(() => {
  return props.user?.permissions?.filter(permission =>
    !originalRolePermissionIds.value.includes(permission.id)
  ) || [];
});

const selectablePermissions = computed(() => {
  const inheritedIds = inheritedPermissions.value.map(p => p.id);
  const selectedIds = form.permissions.map(p => p.id);
  return props.permissions.filter(
    permission =>
      !inheritedIds.includes(permission.id) &&
      !selectedIds.includes(permission.id)
  );
});

// Nuovo: traccia i permessi che erano aggiuntivi ma sono diventati ereditati
const previouslyAdditional = ref<Set<number>>(new Set());

onMounted(() => {
  form.permissions = [...additionalPermissions.value];

  // Opzionale: inizializza al caricamento se alcuni permessi diretti sono già nel ruolo corrente
  // (utile dopo refresh o se l'utente aveva già permessi "duplicati")
  const currentRole = selectedRole.value;
  if (currentRole) {
    const rolePermIds = currentRole.permissions.map(p => p.id);
    const directPermIds = props.user?.permissions?.map(p => p.id) || [];
    directPermIds.forEach(id => {
      if (rolePermIds.includes(id)) {
        previouslyAdditional.value.add(id);
      }
    });
  }
});

watch(
  () => props.user,
  () => {
    const rolePermIds = props.user?.roles?.[0]?.permissions?.map(p => p.id) || [];
    form.permissions = props.user?.permissions?.filter(p =>
      !rolePermIds.includes(p.id)
    ) || [];
  }
);

watch(
  () => form.roles,
  (newRoleId, oldRoleId) => {
    const newRole = props.roles.find(r => r.id === newRoleId);

    if (newRole && newRole.permissions) {
      const newRolePermissionIds = newRole.permissions.map(p => p.id);

      // Identifica i permessi che stanno per essere rimossi perché ora ereditati
      const removed = form.permissions.filter(perm =>
        newRolePermissionIds.includes(perm.id)
      );

      // Aggiungili al set dei "precedentemente aggiuntivi"
      removed.forEach(perm => {
        previouslyAdditional.value.add(perm.id);
      });

      // Rimuovi effettivamente i permessi aggiuntivi duplicati
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
  })).put(route("utenti.update", { id: props.user.id }), {
    preserveScroll: true
  });
};
</script>

<template>
  <Head :title="trans('users.header.edit_user_head')" />
  <AppLayout :breadcrumbs="breadcrumbs">
    <UtentiLayout>
      <form @submit.prevent="submit">
        <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
          <div class="sm:col-span-3">
            <Label for="name">{{ trans('users.label.name') }}</Label>
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
            <Label for="email">{{ trans('users.label.email') }}</Label>
            <Input
              id="email"
              class="mt-1 block w-full"
              v-model="form.email"
              @focus="form.clearErrors('email')"
              autocomplete="email"
              :placeholder="trans('users.placeholder.email')"
            />
            <InputError :message="form.errors.email" />
          </div>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
          <div class="sm:col-span-3">
            <Label for="ruolo">{{ trans('users.label.role') }}</Label>
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
            <Label for="anagrafica">{{ trans('users.label.resident') }}</Label>
            <v-select
              :options="anagrafiche"
              label="nome"
              v-model="form.anagrafica"
              :reduce="(option: Anagrafica) => option.id"
              :placeholder="trans('users.placeholder.resident')"
            >
              <template #option="{ nome, indirizzo }">
                <div class="flex flex-col">
                  <span class="font-medium">{{ nome }}</span>
                  <span class="text-sm text-gray-500">{{ indirizzo }}</span>
                </div>
              </template>
            </v-select>
            <InputError :message="form.errors.anagrafica" />
          </div>
        </div>

        <div class="mt-6">
          <Label>{{ trans('users.label.permissions') }}</Label>
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
            {{ trans('users.label.permission_notice') }}
          </p>
        </div>

        <div class="pt-5">
          <Button :disabled="form.processing">
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            {{trans('users.actions.edit_user')}}
          </Button>
        </div>
      </form>

      <Separator class="my-8" />

      <!-- Permessi aggiuntivi (lista) -->
      <div class="mb-8">
        <div class="flex items-center gap-2 mb-4">
          <div>
            <h2 class="text-xl font-semibold">{{ trans('users.header.additional_permissions_title') }}</h2>
            <p class="text-sm text-muted-foreground">
              {{ trans('users.header.additional_permissions_description') }}
            </p>
          </div>
        </div>
        <div v-if="!additionalPermissions.length" class="text-center py-12 border rounded-lg bg-muted/20">
          {{ trans('users.empty_state.additional_permissions') }}
        </div>
        <div v-else class="flex flex-col gap-3">
          <Item
            v-for="permission in additionalPermissions"
            :key="permission.id"
            variant="outline"
          >
            <ItemContent>
              <div class="flex items-center gap-2">
                <ItemTitle>{{ permission.name }}</ItemTitle>
              </div>
              <ItemDescription>{{ permission.description }}</ItemDescription>
            </ItemContent>
            <ItemActions>
              <Link
                :href="route('users.permissions.destroy', [user.id, permission.id])"
                method="delete"
                as="button"
                preserve-scroll
              >
                <Button variant="ghost" size="sm" class="text-destructive hover:text-destructive hover:bg-destructive/10">
                  <Trash2 class="h-4 w-4" />
                </Button>
              </Link>
            </ItemActions>
          </Item>
        </div>
      </div>

      <!-- Permessi ereditati dal ruolo (con badge!) -->
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
              <div class="flex items-center gap-3">
                <div class="flex items-center gap-2">
                  <ItemTitle>{{ permission.name }}</ItemTitle>
                  <span
                    v-if="previouslyAdditional.has(permission.id)"
                    class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300"
                  >
                    {{ trans('users.badge.previously_direct') }}
                  </span>
                </div>
              </div>
              <ItemDescription>
                {{ permission.description }}
              </ItemDescription>
            </ItemContent>
          </Item>
        </div>
      </div>
    </UtentiLayout>
  </AppLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>