<script setup>
import {useRoute} from "vue-router";
import {computed, onMounted, ref, watch} from "vue";
import {useToast} from "vue-toastification";
import JsvvAlarmService from "../../services/JsvvAlarmService.js";
import VueMultiselect from "vue-multiselect";
import router from "../../router.js";
import PageContent from "../../components/custom/PageContent.vue";
import Box from "../../components/custom/Box.vue";
import Input from "../../components/forms/Input.vue";
import Select from "../../components/forms/Select.vue";
import CustomFormControl from "../../components/forms/CustomFormControl.vue";
import Button from "../../components/forms/Button.vue";
import {JSVV_BUTTON_DEFAULTS} from "../../constants/jsvvDefaults.js";

const route = useRoute();
const toast = useToast();
const editingJsvvAlarmId = ref(route.params.id);
const audioGroupOptions = ref([]);
const selectedSequenceItems = ref([]);
const defaultSequenceMap = new Map(JSVV_BUTTON_DEFAULTS.map(({button, sequence}) => [button, sequence.toUpperCase()]));
const defaultButtonsSet = new Set(JSVV_BUTTON_DEFAULTS.map(({button}) => button));
const errorBag = ref({});
const jsvvAlarm = ref({
  id: null,
  name: '',
  button: null,
  mobile_button: null,
  sequence: null
});

const audioBySymbol = computed(() => {
  const map = new Map();
  audioGroupOptions.value.forEach((group) => {
    group.audios.forEach((audio) => {
      map.set(audio.symbol, audio);
    });
  });
  return map;
});

onMounted(() => {
  getJsvvAudios();
})

watch(() => jsvvAlarm.value.button, (newButton, previousButton) => {
  syncMobileButtonDefault(newButton);
  applyDefaultSequenceIfEligible(newButton, previousButton);
});

watch(() => jsvvAlarm.value.mobile_button, (newButton, previousButton) => {
  syncButtonWithMobile(newButton);
  applyDefaultSequenceIfEligible(newButton, previousButton);
});

function getJsvvAlarm() {
  JsvvAlarmService.getJsvvAlarm(editingJsvvAlarmId.value).then(response => {
    jsvvAlarm.value = response.data;
    applySequence(jsvvAlarm.value.sequence);
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst data');
  });
}

function getJsvvAudios() {
  JsvvAlarmService.getJsvvAudios().then(response => {
    const audioGroups = [];
    response.forEach(jsvvAudio => {
      let existingGroup = audioGroups.find(group => group.group_name === jsvvAudio.group);
      if (!existingGroup) {
        existingGroup = {
          group_name: jsvvAudio.group,
          group_label: jsvvAudio.group_label,
          audios: [],
        };
        audioGroups.push(existingGroup);
      }
      existingGroup.audios.push(jsvvAudio);
    });
    audioGroupOptions.value = audioGroups;
    if (editingJsvvAlarmId.value) {
      getJsvvAlarm();
    } else {
      applyDefaultSequenceIfEligible(jsvvAlarm.value.button, null);
      applyDefaultSequenceIfEligible(jsvvAlarm.value.mobile_button, null);
    }
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst data');
  });
}

function saveJsvvAlarm() {
  const sequenceString = getCurrentSequenceString();
  jsvvAlarm.value.sequence = sequenceString || null;
  JsvvAlarmService.saveJsvvAlarm(jsvvAlarm.value).then(() => {
    toast.success('Alarm byl úspěšně upraven');
    router.push({name: 'JSVV'});
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se uložit změny');
  });
}

const cantSave = computed(() => {
  errorBag.value = {};
  let retVal = false;
  if (jsvvAlarm.value.name.length < 3) {
    retVal = true;
  }
  if (selectedSequenceItems.value.length > 4) {
    errorBag.value.sequence = 'Maximálně lze vybrat 4 položky';
    retVal = true;
  }
  return retVal;
});

function buildItemsFromSequence(sequence) {
  if (!sequence) {
    return [];
  }
  return sequence
      .toString()
      .toUpperCase()
      .split('')
      .filter((symbol) => symbol.length > 0)
      .map((symbol) => audioBySymbol.value.get(symbol))
      .filter(Boolean);
}

function applySequence(sequence) {
  selectedSequenceItems.value = buildItemsFromSequence(sequence);
}

function getCurrentSequenceString() {
  return selectedSequenceItems.value.map((item) => item.symbol).join('').toUpperCase();
}

function applyDefaultSequenceIfEligible(newButton, previousButton) {
  const buttonNumber = Number(newButton);
  if (!Number.isFinite(buttonNumber) || !defaultButtonsSet.has(buttonNumber)) {
    return;
  }
  if (audioBySymbol.value.size === 0) {
    return;
  }
  const targetSequence = defaultSequenceMap.get(buttonNumber);
  if (!targetSequence) {
    return;
  }
  const currentSequence = getCurrentSequenceString();
  const previousSequence = Number.isFinite(Number(previousButton))
      ? (defaultSequenceMap.get(Number(previousButton)) ?? '')
      : '';
  const shouldApply =
      currentSequence.length === 0 ||
      currentSequence === previousSequence;
  if (!shouldApply) {
    return;
  }
  applySequence(targetSequence);
}

function syncMobileButtonDefault(newButton) {
  if (jsvvAlarm.value.id) {
    return;
  }
  const parsed = Number(newButton);
  if (!Number.isFinite(parsed)) {
    return;
  }
  if (jsvvAlarm.value.mobile_button == null) {
    jsvvAlarm.value.mobile_button = parsed;
  }
}

function syncButtonWithMobile(newMobile) {
  if (jsvvAlarm.value.id) {
    return;
  }
  const parsed = Number(newMobile);
  if (!Number.isFinite(parsed)) {
    return;
  }
  if (jsvvAlarm.value.button == null) {
    jsvvAlarm.value.button = parsed;
  }
}
</script>

<template>
  <PageContent :label="jsvvAlarm.id ? 'Úprava alarmu' : 'Nový alarm'" back-route="JSVV">
    <Box label="Nastavení alarmu">
      <Input v-model="jsvvAlarm.name" label="Název" placeholder="Zadejte název (min. 3 znaky)"/>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <Select v-model="jsvvAlarm.button" label="Tlačítko" :options="jsvvAlarm.available_buttons"/>
        <Select v-model="jsvvAlarm.mobile_button" label="Mobilní tlačítko" :options="jsvvAlarm.available_mobile_buttons"/>
      </div>

      <CustomFormControl :label="'Sekvence JSVV (pořadí dle výběru):' + (selectedSequenceItems.length > 0 ? ' <span class=&quot;font-bold&quot;>'+ selectedSequenceItems.map(item => item.symbol).join('')+'</span>' : '')" :error="errorBag?.sequence">
        <VueMultiselect v-model="selectedSequenceItems" :options="audioGroupOptions" label="name" trackBy="symbol" :multiple="true"
                        group-values="audios" group-label="group_label" :group-select="false"
                        :close-on-select="false"
                        placeholder="Vyhledat skupinu" tagPlaceholder="" noOptions="Seznam je prázdný"
                        selectedLabel="Vybráno" selectLabel="Klikněte pro přidání" deselectLabel="Klikněte pro odebrání">
          <template #noResult>
            <span>Zadaným parametrům neodpovídá žádná skupina</span>
          </template>
        </VueMultiselect>
      </CustomFormControl>

      <div class="flex items-center justify-end space-x-2">
        <Button route-to="JSVV" data-class="btn-ghost" label="Zrušit" size="sm"/>
        <Button icon="mdi-content-save" label="Uložit" size="sm" @click="saveJsvvAlarm" :disabled="cantSave"/>
      </div>
    </Box>
  </PageContent>
</template>

<style scoped>
</style>
