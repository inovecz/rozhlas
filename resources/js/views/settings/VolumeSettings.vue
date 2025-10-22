<script setup>
import {computed, onMounted, ref, watch} from "vue";
import {useToast} from "vue-toastification";
import PageContent from "../../components/custom/PageContent.vue";
import Box from "../../components/custom/Box.vue";
import Button from "../../components/forms/Button.vue";
import VolumeService from "../../services/VolumeService.js";

const toast = useToast();

const loading = ref(true);
const saving = ref(false);
const groups = ref([]);
const activeTab = ref(null);
const volumeSlider = {
  min: -12,
  max: 59.5,
  step: 0.5,
};

const load = async () => {
  loading.value = true;
  try {
    const response = await VolumeService.fetchSettings();
    groups.value = Array.isArray(response?.groups) ? response.groups : [];
    if (groups.value.length > 0) {
      const ids = groups.value.map(group => group.id);
      if (!ids.includes(activeTab.value)) {
        activeTab.value = ids.includes('playback') ? 'playback' : ids[0];
      }
    } else {
      activeTab.value = null;
    }
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se načíst nastavení hlasitosti');
  } finally {
    loading.value = false;
  }
};

watch(groups, (newGroups) => {
  if (!Array.isArray(newGroups) || newGroups.length === 0) {
    activeTab.value = null;
    return;
  }

  const ids = newGroups.map(group => group.id);
  if (!ids.includes(activeTab.value)) {
    activeTab.value = ids.includes('playback') ? 'playback' : ids[0];
  }
}, {immediate: true});

const orderedGroups = computed(() => {
  const priority = ['playback', 'outputs', 'inputs'];
  return [...groups.value].sort((a, b) => {
    const indexA = priority.indexOf(a.id);
    const indexB = priority.indexOf(b.id);
    if (indexA === -1 && indexB === -1) {
      return a.label.localeCompare(b.label);
    }
    if (indexA === -1) {
      return 1;
    }
    if (indexB === -1) {
      return -1;
    }
    return indexA - indexB;
  });
});

const activeGroup = computed(() => orderedGroups.value.find(group => group.id === activeTab.value) ?? null);

const updateItemValue = (groupId, item) => {
  if (typeof item.value === 'string') {
    item.value = Number(item.value);
  }
  if (!Number.isFinite(item.value)) {
    toast.error('Zadejte platnou číselnou hodnotu');
    item.value = item.default ?? 0;
  }
};

const resetItemToDefault = (item) => {
  item.value = item.default ?? 0;
};

const save = async () => {
  saving.value = true;
  try {
    const payload = groups.value.map(group => ({
      id: group.id,
      items: group.items.map(item => ({
        id: item.id,
        value: Number(item.value),
      })),
    }));
    const response = await VolumeService.saveSettings(payload);
    groups.value = Array.isArray(response?.groups) ? response.groups : groups.value;
    toast.success('Nastavení hlasitosti bylo uloženo');
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se uložit nastavení hlasitosti');
  } finally {
    saving.value = false;
  }
};

onMounted(load);
</script>

<template>
  <PageContent label="Nastavení hlasitosti">
    <div class="space-y-4">
      <div class="flex justify-end">
        <Button icon="mdi-content-save" :disabled="saving || loading" @click="save">
          Uložit změny
        </Button>
      </div>

      <Box label="Kanály">
        <div v-if="loading" class="text-sm text-gray-500">Načítám data…</div>
        <template v-else>
          <div v-if="orderedGroups.length" class="space-y-4">
            <div class="flex flex-wrap gap-2">
              <button
                  v-for="group in orderedGroups"
                  :key="group.id"
                  type="button"
                  class="btn btn-sm"
                  :class="group.id === activeTab ? 'btn-primary' : 'btn-ghost'"
                  @click="activeTab = group.id"
              >
                {{ group.label }}
              </button>
            </div>

            <div v-if="activeGroup" class="space-y-3">
              <div class="text-sm font-semibold text-gray-700">{{ activeGroup.label }}</div>
              <div class="space-y-2">
                <div
                    v-for="item in activeGroup.items"
                    :key="item.id"
                    class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border border-gray-200 rounded-md bg-white p-3 shadow-sm">
                  <div>
                    <div class="font-medium text-gray-900">{{ item.label }}</div>
                    <div class="text-xs text-gray-500">Výchozí: {{ item.default }}</div>
                  </div>
                  <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:min-w-[380px]">
                    <input
                        v-model.number="item.value"
                        type="range"
                        class="range range-sm w-full sm:w-72 md:w-96"
                        :min="volumeSlider.min"
                        :max="volumeSlider.max"
                        :step="volumeSlider.step"
                        @change="() => updateItemValue(activeGroup.id, item)"
                        :disabled="saving"
                    />
                    <div class="flex items-center gap-2 text-sm text-gray-700">
                      <span class="inline-block w-14 text-right">{{ Number(item.value).toFixed(1) }}</span>
                      <button
                          class="btn btn-ghost btn-xs"
                          type="button"
                          @click="resetItemToDefault(item)"
                          :disabled="saving"
                      >
                        Výchozí
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div v-else class="text-xs text-gray-500">Žádné položky nejsou k dispozici.</div>
        </template>
      </Box>
    </div>
  </PageContent>
</template>
