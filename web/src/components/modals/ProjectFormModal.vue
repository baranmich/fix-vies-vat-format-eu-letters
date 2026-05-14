<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import ProjectForm from '@/pages/projects/ProjectForm.vue'
import type { Project } from '@/api/projects'

const props = defineProps<{ clientId: number }>()
const emit = defineEmits<{
  (e: 'created', project: Project): void
  (e: 'close'): void
}>()

const { t } = useI18n()

function onCreated(project: Project) {
  emit('created', project)
}

void props
</script>

<template>
  <Modal :title="t('project.new_title')" width-class="max-w-3xl" @close="emit('close')">
    <ProjectForm embedded :client-id="clientId" @created="onCreated" @cancel="emit('close')" />
  </Modal>
</template>
