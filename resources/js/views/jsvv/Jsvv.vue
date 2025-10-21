<script setup>
import {computed, nextTick, onMounted, reactive, ref, watch} from "vue";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import ModalDialog from "../../components/modals/ModalDialog.vue";
import {useToast} from "vue-toastification";
import router from "../../router.js";
import PageContent from "../../components/custom/PageContent.vue";
import Box from "../../components/custom/Box.vue";
import Button from "../../components/forms/Button.vue";
import JsvvAlarmService from "../../services/JsvvAlarmService.js";
import JsvvSequenceService from "../../services/JsvvSequenceService.js";
import {moveItemDown, moveItemUp} from "../../helper.js";
import {JSVV_BUTTON_DEFAULTS} from "../../constants/jsvvDefaults.js";

const toast = useToast();

const jsvvAlarms = ref([]);
const jsvvAudios = ref([]);
const loadingQuick = ref(false);
const sendingCustom = ref(false);

const quickAlarmsDefinition = JSVV_BUTTON_DEFAULTS.map((item) => ({
  button: item.button,
  label: item.label,
  defaultSequence: item.sequence,
  steps: item.steps,
}));

const showCustomBuilder = ref(false);
const customSequence = ref([]);
const builderFilters = reactive({
  search: '',
});

onMounted(() => {
  fetchJsvvAlarms();
  fetchJsvvAudios();
});

watch(showCustomBuilder, (visible) => {
  if (!visible) {
    builderFilters.search = '';
  } else {
    nextTick(() => {
      if (typeof document !== 'undefined') {
        document.getElementById('builder-search')?.focus();
      }
    });
  }
});

function fetchJsvvAlarms() {
  JsvvAlarmService.fetchJsvvAlarms()
      .then((response) => {
        jsvvAlarms.value = response.data;
      })
      .catch((error) => {
        console.error(error);
        toast.error('Nepodařilo se načíst poplachy');
      });
}

function fetchJsvvAudios() {
  JsvvAlarmService.getJsvvAudios()
      .then((response) => {
        jsvvAudios.value = response;
      })
      .catch((error) => {
        console.error(error);
        toast.error('Nepodařilo se načíst zvukové zdroje JSVV');
      });
}

const audioMap = computed(() => {
  const map = new Map();
  jsvvAudios.value.forEach((audio) => {
    map.set(String(audio.symbol), audio);
  });
  return map;
});

const groupedAudios = computed(() => {
  const order = ['SIREN', 'GONG', 'VERBAL', 'AUDIO'];
  const groupLabels = {
    SIREN: 'Sirény',
    GONG: 'Gongy',
    VERBAL: 'Verbální informace',
    AUDIO: 'Audiovstupy',
  };
  const map = new Map(order.map((key) => [key, {key, label: groupLabels[key], items: []}]));
  jsvvAudios.value.forEach((audio) => {
    const entry = map.get(audio.group);
    if (entry) {
      entry.items.push({
        symbol: audio.symbol,
        name: audio.name,
        group: audio.group,
        groupLabel: entry.label,
      });
    }
  });
  return order
      .map((key) => map.get(key))
      .filter((entry) => entry && entry.items.length > 0);
});

const filteredGroupedAudios = computed(() => {
  const query = builderFilters.search.trim().toLowerCase();
  if (!query) {
    return groupedAudios.value;
  }
  return groupedAudios.value
      .map((group) => ({
        ...group,
        items: group.items.filter((item) =>
            item.name.toLowerCase().includes(query) ||
            item.symbol.toLowerCase().includes(query)
        ),
      }))
      .filter((group) => group.items.length > 0);
});

const alarmByButton = computed(() => {
  const map = new Map();
  jsvvAlarms.value.forEach((alarm) => {
    if (alarm.button != null) {
      map.set(Number(alarm.button), alarm);
    }
  });
  return map;
});

const quickAlarms = computed(() => quickAlarmsDefinition.map((definition) => {
  const alarm = alarmByButton.value.get(definition.button) ?? null;
  const sequence = (alarm?.sequence || definition.defaultSequence || '').toUpperCase();
  return {
    ...definition,
    alarm,
    sequence,
    sequenceLabel: sequence || 'Nenastaveno',
  };
}));

const hasCustomSequence = computed(() => customSequence.value.length > 0);

function openCustomBuilder() {
  showCustomBuilder.value = true;
}

function closeCustomBuilder() {
  showCustomBuilder.value = false;
  clearCustomSequence();
}

function manageAlarm(alarm) {
  if (!alarm?.id) {
    toast.error('Tento poplach není nakonfigurován.');
    return;
  }
  router.push({name: 'EditJSVV', params: {id: alarm.id}});
}

function buildSequenceItems(alarm) {
  const rawSequence = alarm.sequence_json || alarm.sequence;
  const fallbackSymbol = alarm.slot ?? alarm.button ?? alarm.id ?? 1;

  const normaliseItem = (entry) => {
    if (entry == null) {
      return null;
    }
    if (typeof entry === 'object') {
      const symbol = entry.slot ?? entry.symbol ?? entry;
      const audio = audioMap.value.get(String(symbol));
      return {
        slot: String(symbol),
        category: entry.category ?? audio?.group ?? undefined,
        repeat: entry.repeat ?? 1,
      };
    }
    const symbol = String(entry);
    const audio = audioMap.value.get(symbol);
    return {
      slot: symbol,
      category: audio?.group ?? undefined,
      repeat: 1,
    };
  };

  if (Array.isArray(rawSequence)) {
    return rawSequence.map(normaliseItem).filter(Boolean);
  }

  if (typeof rawSequence === 'string') {
    try {
      const parsed = JSON.parse(rawSequence);
      if (Array.isArray(parsed)) {
        return parsed.map(normaliseItem).filter(Boolean);
      }
    } catch (error) {
      // fallback to plain string below
    }
    return rawSequence
        .split('')
        .map((symbol) => symbol.trim())
        .filter((symbol) => symbol.length > 0)
        .map(normaliseItem)
        .filter(Boolean);
  }

  return [normaliseItem(fallbackSymbol)].filter(Boolean);
}

const buildSequenceFromSymbols = (sequenceString) => {
  if (!sequenceString) {
    return [];
  }
  return sequenceString
      .toString()
      .split('')
      .map((symbol) => symbol.trim())
      .filter((symbol) => symbol.length > 0)
      .map((symbol) => {
        const audio = audioMap.value.get(symbol);
        return {
          slot: symbol,
          category: audio?.group ?? undefined,
          repeat: 1,
        };
      });
};

function notifySequenceTrigger(result) {
  const status = result?.status ?? null;
  const position = Number(result?.queue_position ?? result?.queuePosition ?? 0);
  if (status === 'not_found') {
    toast.error('Sekvenci se nepodařilo najít.');
    return;
  }
  if (status === 'failed') {
    const message = result?.error_message ?? 'Poplach se nepodařilo spustit.';
    toast.error(message);
    return;
  }
  if (status === 'running') {
    toast.success('Poplach byl spuštěn.');
    return;
  }
  if (status === 'queued') {
    if (Number.isFinite(position) && position > 1) {
      toast.success(`Poplach byl zařazen do fronty JSVV (pozice ${position}).`);
    } else {
      toast.success('Poplach byl zařazen do fronty JSVV.');
    }
    return;
  }
  toast.success('Požadavek na poplach byl přijat.');
}

async function triggerQuickAlarm(definition) {
  if (loadingQuick.value) {
    return;
  }
  const alarm = definition.alarm;
  const sequenceString = alarm?.sequence || definition.sequence || definition.defaultSequence;
  if (!sequenceString) {
    toast.error('Pro tento poplach není definována sekvence.');
    return;
  }
  const {reveal, onConfirm} = createConfirmDialog(ModalDialog, {
    title: definition.label,
    message: 'Alarm bude odeslán do ústředny.',
  });
  reveal();
  onConfirm(async () => {
    loadingQuick.value = true;
    try {
      const items = alarm ? buildSequenceItems(alarm) : buildSequenceFromSymbols(sequenceString);
      const sequence = await JsvvSequenceService.planSequence(items, {
        priority: alarm?.priority ?? 'P2',
        zones: alarm?.zones ?? [],
      });
      const sequenceId = sequence?.id ?? sequence?.sequence?.id;
      if (!sequenceId) {
        throw new Error('Sequence ID missing');
      }
      const triggerResult = await JsvvSequenceService.triggerSequence(sequenceId);
      notifySequenceTrigger(triggerResult);
    } catch (error) {
      console.error(error);
      toast.error('Nepodařilo se odeslat alarm');
    } finally {
      loadingQuick.value = false;
    }
  });
}

function addCustomItem(item) {
  customSequence.value.push({
    uid: `${item.symbol}-${Date.now()}-${Math.random()}`,
    symbol: item.symbol,
    label: item.name,
    group: item.group,
    groupLabel: item.groupLabel,
  });
}

function removeCustomItem(index) {
  customSequence.value.splice(index, 1);
}

function moveCustomItemUp(index) {
  if (index <= 0) {
    return;
  }
  moveItemUp(customSequence.value, index);
  customSequence.value = [...customSequence.value];
}

function moveCustomItemDown(index) {
  if (index >= customSequence.value.length - 1) {
    return;
  }
  moveItemDown(customSequence.value, index);
  customSequence.value = [...customSequence.value];
}

function clearCustomSequence() {
  customSequence.value = [];
}

async function sendCustomSequence() {
  if (!hasCustomSequence.value || sendingCustom.value) {
    toast.warning('Sestavte nejprve vlastní poplach.');
    return;
  }
  const items = customSequence.value.map((entry) => ({
    slot: entry.symbol,
    category: entry.group ?? undefined,
    repeat: 1,
  }));
  sendingCustom.value = true;
  try {
    const sequence = await JsvvSequenceService.planSequence(items, {});
    const sequenceId = sequence?.id ?? sequence?.sequence?.id;
    if (!sequenceId) {
      throw new Error('Sequence ID missing');
    }
    const triggerResult = await JsvvSequenceService.triggerSequence(sequenceId);
    notifySequenceTrigger(triggerResult);
    customSequence.value = [];
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se odeslat vlastní poplach');
  } finally {
    sendingCustom.value = false;
  }
}

function openProtocol() {
  router.push({name: 'Log'});
}
</script>

<template>
  <PageContent label="Poplach JSVV">
    <div class="space-y-6">
      <div class="flex flex-wrap gap-3">
        <Button icon="mdi-format-list-bulleted" variant="secondary" size="sm" @click="openProtocol">
          Protokol JSVV
        </Button>
        <Button icon="mdi-cog" size="sm" route-to="JSVVSettings">
          Nastavení JSVV
        </Button>
        <Button
            icon="mdi-bullhorn-variant"
            size="sm"
            :disabled="showCustomBuilder"
            @click="openCustomBuilder">
          Vlastní poplach
        </Button>
      </div>

      <Box label="Tlačítka JSVV">
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <div
              v-for="definition in quickAlarms"
              :key="definition.button"
              class="border border-gray-200 rounded-lg bg-white shadow-sm p-4 flex flex-col gap-3">
            <div class="flex items-start justify-between gap-2">
              <div>
                <div class="text-xs text-gray-500">Tlačítko {{ definition.button }}</div>
                <div class="font-semibold text-gray-800">{{ definition.label }}</div>
                <div class="text-xs text-gray-500 mt-1">
                  Sekvence: <span class="font-medium">{{ definition.sequenceLabel }}</span>
                </div>
                <ul v-if="definition.steps?.length" class="mt-2 text-xs text-gray-500 space-y-1 list-disc list-inside">
                  <li v-for="(step, stepIndex) in definition.steps" :key="stepIndex">{{ step }}</li>
                </ul>
              </div>
              <Button
                  icon="mdi-cog"
                  size="xs"
                  variant="ghost"
                  :disabled="!definition.alarm"
                  @click="manageAlarm(definition.alarm)">
                Nastavit
              </Button>
            </div>
            <Button
                icon="mdi-bullhorn"
                variant="primary"
                :disabled="loadingQuick || !definition.sequence"
                @click="triggerQuickAlarm(definition)">
              Spustit poplach
            </Button>
          </div>
        </div>
      </Box>

      <Box v-if="showCustomBuilder" label="Vlastní poplach">
        <div class="grid gap-6 lg:grid-cols-2">
          <div class="space-y-4">
            <div class="flex flex-col gap-2">
              <label class="text-sm font-medium text-gray-700" for="builder-search">Vyhledávání</label>
              <input
                  id="builder-search"
                  v-model="builderFilters.search"
                  type="text"
                  class="input input-bordered w-full"
                  placeholder="Hledat v seznamu zvuků"/>
            </div>
            <div class="space-y-4 max-h-[520px] overflow-y-auto pr-2">
              <div
                  v-for="group in filteredGroupedAudios"
                  :key="group.key"
                  class="border border-gray-200 rounded-lg">
                <div class="bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-700">
                  {{ group.label }}
                </div>
                <div class="divide-y divide-gray-100">
                  <button
                      v-for="item in group.items"
                      :key="item.symbol"
                      type="button"
                      class="w-full flex justify-between items-center px-3 py-2 text-left hover:bg-primary/5"
                      @click="addCustomItem(item)">
                    <div>
                      <div class="font-medium text-gray-800">{{ item.name }}</div>
                      <div class="text-xs text-gray-500">Symbol: {{ item.symbol }}</div>
                    </div>
                    <span class="mdi mdi-plus text-primary text-xl"></span>
                  </button>
                </div>
              </div>
              <div v-if="filteredGroupedAudios.length === 0" class="text-sm text-gray-500">
                Žádné zvuky neodpovídají hledání.
              </div>
            </div>
          </div>

          <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between">
              <div>
                <div class="text-sm  font-semibold text-gray-700">Sestava poplachu</div>
                <div class="text-xs text-gray-500">Kliknutím na zvuky vlevo je přidáte do této sekvence.</div>
              </div>
              <Button
                  size="xs"
                  variant="ghost"
                  icon="mdi-delete"
                  :disabled="!hasCustomSequence || sendingCustom"
                  @click="clearCustomSequence">
                Vyčistit
              </Button>
            </div>

            <div class="border border-dashed border-gray-300 rounded-lg min-h-[200px] p-3 space-y-2">
              <div v-if="!hasCustomSequence" class="text-sm text-gray-500">
                Dosud nebyly přidány žádné položky. Vyberte zvuk z panelu vlevo.
              </div>
              <div
                  v-for="(item, index) in customSequence"
                  :key="item.uid"
                  class="flex items-center justify-between gap-2 bg-white border border-gray-200 rounded px-3 py-2 shadow-sm">
                <div>
                  <div class="font-medium text-gray-800">{{ item.label }}</div>
                  <div class="text-xs text-gray-500">Symbol: {{ item.symbol }} · {{ item.groupLabel }}</div>
                </div>
                <div class="flex items-center gap-1">
                  <button
                      class="btn btn-xs btn-square"
                      :disabled="index === 0"
                      @click="moveCustomItemUp(index)">
                    <span class="mdi mdi-chevron-up"></span>
                  </button>
                  <button
                      class="btn btn-xs btn-square"
                      :disabled="index === customSequence.length - 1"
                      @click="moveCustomItemDown(index)">
                    <span class="mdi mdi-chevron-down"></span>
                  </button>
                  <button
                      class="btn btn-xs btn-square btn-error"
                      @click="removeCustomItem(index)">
                    <span class="mdi mdi-close"></span>
                  </button>
                </div>
              </div>
            </div>

            <div class="flex flex-wrap gap-3">
              <Button
                  icon="mdi-bullhorn-variant"
                  :disabled="!hasCustomSequence || sendingCustom"
                  @click="sendCustomSequence">
                Odeslat vlastní poplach
              </Button>
              <Button
                  icon="mdi-playlist-plus"
                  variant="ghost"
                  :disabled="!hasCustomSequence"
                  @click="() => toast.info('Sekvenci lze uložit v nastavení JSVV.')">
                Uložit jako předvolbu
              </Button>
              <Button
                  icon="mdi-close"
                  variant="ghost"
                  :disabled="sendingCustom"
                  @click="closeCustomBuilder">
                Zavřít
              </Button>
            </div>
          </div>
        </div>
      </Box>
    </div>
  </PageContent>
</template>
