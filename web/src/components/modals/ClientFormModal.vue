<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import ClientForm from '@/pages/clients/ClientForm.vue'
import type { Client, ClientPayload } from '@/api/clients'

const props = withDefaults(defineProps<{ defaults?: Partial<ClientPayload> }>(), {
  defaults: () => ({}),
})
const emit = defineEmits<{
  (e: 'created', client: Client): void
  (e: 'close'): void
}>()

const { t } = useI18n()

function onCreated(client: Client) {
  emit('created', client)
}

void props
</script>

<template>
  <Modal :title="t('client.new_title')" width-class="max-w-3xl" @close="emit('close')">
    <ClientForm embedded :defaults="defaults" @created="onCreated" @cancel="emit('close')" />
  </Modal>
</template>
