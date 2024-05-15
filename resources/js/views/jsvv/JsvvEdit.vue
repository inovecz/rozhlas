<script setup>
import {useRoute} from "vue-router";
import {computed, onMounted, ref} from "vue";
import {useToast} from "vue-toastification";
import JsvvAlarmService from "../../services/JsvvAlarmService.js";
import VueMultiselect from "vue-multiselect";
import router from "../../router.js";

const route = useRoute();
const toast = useToast();
const editingJsvvAlarmId = ref(route.params.id);
const audioGroupOptions = ref([]);
const selectedSequenceItems = ref([]);
const errorBag = ref({});
const jsvvAlarm = ref({
  id: null,
  name: '',
  button: null,
  mobile_button: null,
  sequence: null
});

onMounted(() => {
  getJsvvAudios();
})

function getJsvvAlarm() {
  JsvvAlarmService.getJsvvAlarm(editingJsvvAlarmId.value).then(response => {
    jsvvAlarm.value = response.data;
    if (jsvvAlarm.value.sequence) {
      const sequenceSymbols = jsvvAlarm.value.sequence.split('');
      const selectedItems = [];
      sequenceSymbols.forEach(symbol => {
        audioGroupOptions.value.forEach(group => {
          const foundAudio = group.audios.find(audio => audio.symbol === symbol);
          if (foundAudio) {
            selectedItems.push(foundAudio);
          }
        });
      });
      selectedSequenceItems.value = selectedItems;
    }
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
    }
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst data');
  });
}

function saveJsvvAlarm() {
  jsvvAlarm.value.sequence = selectedSequenceItems.value.map(item => item.symbol).join('');
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
</script>

<template>
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">{{ jsvvAlarm.id ? 'Úprava alarmu' : 'Nový alarm' }}</h1>
    <div class="content flex flex-col space-y-4">

      <div class="component-box">
        <div class="flex flex-col gap-4">
          <div class="flex flex-col gap-2">
            <div class="text-sm text-base-content">
              Název
            </div>
            <div>
              <input v-model="jsvvAlarm.name" type="text" placeholder="Zadejte název (min. 3 znaky)" class="input input-bordered w-full"/>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="flex flex-col gap-2">
              <div class="text-sm text-base-content">
                Tlačítko
              </div>
              <div>
                <select v-model="jsvvAlarm.button" class="select select-bordered w-full">
                  <option :value="null">Nepřiřazeno</option>
                  <option v-for="button of jsvvAlarm.available_buttons" :key="button" :value="button" :selected="jsvvAlarm.button === button">{{ button }}</option>
                </select>
              </div>
            </div>

            <div class="flex flex-col gap-2">
              <div class="text-sm text-base-content">
                Mobilní tlačítko
              </div>
              <div>
                <select v-model="jsvvAlarm.mobile_button" class="select select-bordered w-full">
                  <option :value="null">Nepřiřazeno</option>
                  <option v-for="mobile_button of jsvvAlarm.available_mobile_buttons" :key="mobile_button" :value="mobile_button" :selected="jsvvAlarm.mobile_button === mobile_button">{{ mobile_button }}</option>
                </select>
              </div>
            </div>

          </div>

          <div class="flex flex-col gap-2">
            <div class="flex justify-between text-sm text-base-content">
              <div :class="{'text-error': errorBag?.sequence}">
                Sekvence JSVV (pořadí dle výběru):
                <span v-if="selectedSequenceItems.length > 0" class="font-bold">{{ selectedSequenceItems.map(item => item.symbol).join('') }}</span>
              </div>
              <router-link :to="{ name: 'JSVVSettings' }">
                <div class="text-xxs text-primary"><span class="mdi mdi-cog mr-1"/>Nastavení zvuků JSVV</div>
              </router-link>

            </div>
            <div class="join lg:join-horizontal w-full">
              <VueMultiselect v-model="selectedSequenceItems" :options="audioGroupOptions" label="name" trackBy="symbol" :multiple="true"
                              group-values="audios" group-label="group_label" :group-select="false"
                              :close-on-select="false"
                              placeholder="Vyhledat skupinu" tagPlaceholder="" noOptions="Seznam je prázdný"
                              selectedLabel="Vybráno" selectLabel="Klikněte pro přidání" deselectLabel="Klikněte pro odebrání">
                <template #noResult>
                  <span>Zadaným parametrům neodpovídá žádná skupina</span>
                </template>
              </VueMultiselect>
            </div>
            <div>
              <span v-if="errorBag?.sequence" class="label-text-alt text-red-500"><span class="mdi mdi-alert-circle-outline mr-1"></span>{{ errorBag.sequence }}</span>
            </div>
          </div>

          <div class="lg:col-span-2 flex items-center justify-end space-x-5">
            <router-link :to="{ name: 'JSVV' }">
              <button class="underline">Zrušit</button>
            </router-link>
            <button @click="saveJsvvAlarm" class="btn btn-sm btn-primary" :disabled="cantSave">Uložit</button>
          </div>
        </div>
      </div>

    </div>
  </div>
</template>

<style scoped>

</style>