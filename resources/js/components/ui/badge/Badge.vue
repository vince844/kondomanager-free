<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import type { BadgeVariants } from "."
import { cn } from "@/lib/utils"
import { badgeVariants } from "."

const props = defineProps<{
  variant?: BadgeVariants["variant"]
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div :class="cn(badgeVariants({ variant }), props.class)">
    <slot />
  </div>
</template>
