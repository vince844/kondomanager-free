<script setup lang="ts">

import { computed } from 'vue'
import { KeyRound, CheckCircle2 } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { trans } from 'laravel-vue-i18n'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'

interface Permission {
  name: string
  description?: string
}

interface Badge {
  label: string
  class: string
}

interface Props {
  permissions: Permission[] | string[]
  title: string
  subtitle?: string
  badges?: Badge[]
  entityName?: string
  user: string
  entityDescription?: string
}

const props = defineProps<Props>()

const permissionsCount = computed(() => props.permissions?.length || 0)

const getPermissionName = (permission: Permission | string): string => {
  return typeof permission === 'string' ? permission : permission.name
}

const getPermissionDescription = (permission: Permission | string): string | null => {
  return typeof permission === 'object' && permission.description ? permission.description : null
}
</script>

<template>
  <Dialog>
    <DialogTrigger as-child>
      <Button variant="outline" size="sm" class="h-6 gap-1 text-xs">
        <KeyRound class="h-2 w-2" />
        <span>{{ permissionsCount }}</span>
      </Button>
    </DialogTrigger>
    
    <DialogContent class="max-w-2xl max-h-[80vh]">
      <DialogHeader>
        <DialogTitle class="flex items-center gap-2">
          {{ trans(title) }}
        </DialogTitle>
        
        <DialogDescription>
          <!-- Per i ruoli: mostra nome e descrizione del ruolo -->
          <div v-if="entityName" class="space-y-2">
            <div class="font-semibold text-foreground capitalize">{{ entityName }}</div>
            <p v-if="entityDescription" class="text-sm text-muted-foreground">
              {{ entityDescription }}
            </p>
          </div>
          
          <!-- Per gli utenti: mostra solo il subtitle -->
          <div v-else-if="subtitle">
            {{ trans(subtitle, { name: user }) }}
          </div>
          
          <!-- Badge comuni (permessi count + badge personalizzati) -->
          <div class="flex items-center gap-2 mt-3 flex-wrap">
            <span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 ring-1 ring-blue-600/20 ring-inset">
              {{ trans('users.label.permissions_count', {count: String(permissionsCount)}) }}
            </span>
            
            <span 
              v-for="(badge, index) in badges" 
              :key="index"
              :class="badge.class"
            >
              {{ badge.label }}
            </span>
          </div>
        </DialogDescription>
      </DialogHeader>
      
      <!-- Lista dei permessi -->
      <div class="mt-4 max-h-[60vh] overflow-y-auto space-y-2">
        <div v-if="permissionsCount > 0" class="space-y-2">
          <div
            v-for="(permission, index) in permissions"
            :key="index"
            class="flex items-start gap-2 p-3 rounded-lg border"
          >
            <CheckCircle2 class="h-4 w-4 text-green-500 flex-shrink-0 mt-0.5" />
            
            <div class="flex flex-col gap-1">
              <span class="text-sm font-medium">
                {{ getPermissionName(permission) }}
              </span>
              
              <span 
                v-if="getPermissionDescription(permission)" 
                class="text-xs text-muted-foreground"
              >
                {{ getPermissionDescription(permission) }}
              </span>
            </div>
          </div>
        </div>
        
        <p v-else class="text-center text-muted-foreground py-8">
          {{ trans('users.empty_state.no_assigned_permissions') }}
        </p>
      </div>
    </DialogContent>
  </Dialog>
</template>