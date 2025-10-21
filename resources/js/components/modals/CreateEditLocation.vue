<script setup>
import {computed, reactive, ref, watch} from 'vue';
import {Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot} from '@headlessui/vue';
import Button from "../forms/Button.vue";
import Input from "../forms/Input.vue";
import Select from "../forms/Select.vue";
import Checkbox from "../forms/Checkbox.vue";
import VueMultiselect from "vue-multiselect";
import "vue-multiselect/dist/vue-multiselect.css";

const componentOptions = [
  {value: 'RECEIVER', label: 'Přijímač'},
  {value: 'CHARGER', label: 'Nabíječ'},
  {value: 'BIDIRECTIONAL', label: 'Obousměr'},
  {value: 'ECOTECH', label: 'Ekotechnika'},
  {value: 'CURRENT_LOOP', label: 'Proudová smyčka'},
  {value: 'BAT_REP_TEST', label: 'BAT+REP Test'},
  {value: 'DIGITAL_INTERFACE', label: 'Digitální interface'},
  {value: 'DIGITAL_BIDIRECTIONAL', label: 'Digitální obousměr'},
];

const statusOptions = [
  {value: 'OK', label: 'V pořádku'},
  {value: 'WARNING', label: 'Varování'},
  {value: 'ERROR', label: 'Chyba'},
  {value: 'UNKNOWN', label: 'Neznámý stav'},
];

const errorBag = ref({});
const isOpen = ref(true);
const props = defineProps({
  locationGroups: {
    type: Array,
    default: () => [],
  },
  location: {
    type: Object,
    required: true,
  },
});
const emit = defineEmits(['confirm', 'cancel']);

const location = reactive({
  id: props.location?.id ?? null,
  name: props.location?.name ?? '',
  type: props.location?.type ?? 'NEST',
  location_group_id: props.location?.location_group_id ?? null,
  location_group_ids: Array.isArray(props.location?.location_group_ids) ? [...props.location.location_group_ids] : [],
  longitude: props.location?.longitude ?? 0,
  latitude: props.location?.latitude ?? 0,
  is_active: props.location?.is_active ?? true,
  modbus_address: props.location?.modbus_address ?? null,
  bidirectional_address: props.location?.bidirectional_address ?? null,
  private_receiver_address: props.location?.private_receiver_address ?? null,
  components: Array.isArray(props.location?.components) ? [...props.location.components] : [],
  status: props.location?.status ?? 'OK',
});

const isNest = computed(() => location.type === 'NEST');

const selectedGroups = ref([]);

const normalizeSelectedGroups = () => {
  if (selectedGroups.value && selectedGroups.value.length) {
    location.location_group_ids = selectedGroups.value.map((item) => item?.id ?? item).filter((value) => value !== null && value !== undefined);
  } else {
    location.location_group_ids = [];
  }
};

const initialiseSelectedGroups = () => {
  const map = new Map((props.locationGroups ?? []).map((group) => [group.id, group]));
  const source = Array.isArray(props.location?.assigned_location_groups)
      ? props.location.assigned_location_groups
      : (Array.isArray(props.location?.location_group_ids) ? props.location.location_group_ids : []);
  selectedGroups.value = (source ?? [])
      .map((entry) => {
        if (typeof entry === 'object' && entry !== null && 'id' in entry) {
          return map.get(entry.id) ?? entry;
        }
        return map.get(entry) ?? null;
      })
      .filter(Boolean);
  normalizeSelectedGroups();
};

initialiseSelectedGroups();

watch(() => props.locationGroups, () => {
  initialiseSelectedGroups();
});

const statusLabelMap = new Map(statusOptions.map(option => [option.value, option.label]));
const nestStatusLabel = computed(() => statusLabelMap.get(location.status) ?? statusLabelMap.get('UNKNOWN') ?? 'Neznámý stav');

const cantSave = computed(() => {
  errorBag.value = {};
  let invalid = false;

  if (!location.name || location.name.trim().length < 3) {
    errorBag.value.name = 'Název musí mít alespoň 3 znaky';
    invalid = true;
  }

  const validateAddress = (value, field, label) => {
    if (value === null || value === '' || typeof value === 'undefined') {
      return;
    }
    const numeric = Number(value);
    if (!Number.isInteger(numeric)) {
      errorBag.value[field] = `${label} musí být celé číslo.`;
      invalid = true;
    } else if (numeric < 0 || numeric > 65535) {
      errorBag.value[field] = `${label} musí být v rozsahu 0 až 65535.`;
      invalid = true;
    }
  };

  validateAddress(location.modbus_address, 'modbus_address', 'Modbus adresa');
  validateAddress(location.bidirectional_address, 'bidirectional_address', 'Adresa obousměru hnízda');
  validateAddress(location.private_receiver_address, 'private_receiver_address', 'Privátní adresa přijímače');

  return invalid;
});

const normalizeAddress = (value) => {
  if (typeof value === 'string') {
    value = value.trim();
  }
  if (value === '' || value === null || typeof value === 'undefined') {
    return null;
  }
  const numeric = Number(value);
  return Number.isFinite(numeric) ? Math.trunc(numeric) : null;
};

const normalizeComponents = () => Array.from(new Set((location.components ?? []).filter((item) => typeof item === 'string' && item.length > 0)));

const closeModalWith = (action) => {
  isOpen.value = false;
  setTimeout(() => {
    if (action === 'confirm') {
      normalizeSelectedGroups();
      location.modbus_address = normalizeAddress(location.modbus_address);
      location.bidirectional_address = normalizeAddress(location.bidirectional_address);
      location.private_receiver_address = normalizeAddress(location.private_receiver_address);
      location.components = normalizeComponents();
      emit('confirm', {...location});
    } else {
      emit('cancel');
    }
  }, 300);
};

watch(isNest, (value) => {
  if (value) {
    location.location_group_id = null;
  } else {
    location.components = [];
    selectedGroups.value = [];
    location.location_group_ids = [];
    location.status = 'OK';
  }
});
</script>

<template>
  <TransitionRoot appear :show="isOpen" as="template">
    <Dialog as="div" @close="closeModalWith('cancel')" class="relative z-10">
      <TransitionChild
          as="template"
          enter="duration-300 ease-out"
          enter-from="opacity-0"
          enter-to="opacity-100"
          leave="duration-200 ease-in"
          leave-from="opacity-100"
          leave-to="opacity-0"
      >
        <div class="fixed inset-0 bg-black/25 backdrop-blur-sm"/>
      </TransitionChild>

      <div class="fixed inset-0 overflow-y-auto z-[999]">
        <div class="flex min-h-full items-center justify-center p-4 text-center">
          <TransitionChild
              as="template"
              enter="duration-300 ease-out"
              enter-from="opacity-0 scale-95"
              enter-to="opacity-100 scale-100"
              leave="duration-200 ease-in"
              leave-from="opacity-100 scale-100"
              leave-to="opacity-0 scale-95">
            <DialogPanel class="w-full max-w-2xl flex flex-col gap-6 transform overflow-hidden rounded-2xl glass p-6 text-left align-middle shadow-xl transition-all">
              <DialogTitle as="h3" class="text-lg font-medium leading-6 text-primary">
                {{ location.id ? 'Úprava místa' : 'Nové místo' }}
              </DialogTitle>

              <div class="grid gap-4 md:grid-cols-2">
                <Input v-model="location.name" label="Název místa" placeholder="Zadejte název místa" size="sm" :error="errorBag?.name"/>
                <Select v-model="location.type" label="Zvolte typ místa" :options="[{value: 'CENTRAL', label: 'Centrála'}, {value: 'NEST', label: 'Hnízdo'}]" size="sm"/>
                <Select v-model="location.location_group_id" v-if="!isNest" label="Výchozí lokalita" option-label="name" option-key="id" :options="props.locationGroups" size="sm"/>
                <div v-if="isNest" class="flex flex-col gap-1">
                  <span class="text-xs font-semibold uppercase text-gray-500">Stav hnízda</span>
                  <span class="rounded border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">{{ nestStatusLabel }}</span>
                </div>

                <Input
                    v-model="location.modbus_address"
                    label="Modbus adresa (volitelné)"
                    type="number"
                    placeholder="Např. 22"
                    size="sm"
                    :error="errorBag?.modbus_address"/>
                <Input
                    v-if="isNest"
                    v-model="location.bidirectional_address"
                    label="Adresa obousměru hnízda (volitelné)"
                    type="number"
                    placeholder="Např. 100"
                    size="sm"
                    :error="errorBag?.bidirectional_address"/>
                <Input
                    v-if="isNest"
                    v-model="location.private_receiver_address"
                    label="Privátní adresa přijímače (volitelné)"
                    type="number"
                    placeholder="Např. 101"
                    size="sm"
                    :error="errorBag?.private_receiver_address"/>

                <VueMultiselect
                    v-if="isNest"
                    v-model="selectedGroups"
                    :options="props.locationGroups"
                    :multiple="true"
                    track-by="id"
                    label="name"
                    placeholder="Vyberte lokality"
                    select-label="Vybrat"
                    deselect-label="Zrušit"
                    class="md:col-span-2"
                    @close="normalizeSelectedGroups"
                    @remove="normalizeSelectedGroups"
                    @select="normalizeSelectedGroups"
                />

                <div v-if="isNest" class="md:col-span-2 space-y-2">
                  <span class="text-sm font-medium text-gray-700">Součásti hnízda</span>
                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <Checkbox
                        v-for="option in componentOptions"
                        :key="option.value"
                        v-model="location.components"
                        :value="option.value"
                        :label="option.label"
                    />
                  </div>
                </div>
              </div>

              <div class="flex items-center justify-end space-x-2">
                <Button data-class="btn-ghost" label="Zrušit" size="sm" @click="closeModalWith('cancel')"/>
                <Button v-if="location.id" icon="mdi-content-save" label="Uložit" size="sm" @click="closeModalWith('confirm')" :disabled="cantSave"/>
                <Button v-else icon="mdi-map-marker-plus" label="Nastavit pozici" size="sm" @click="closeModalWith('confirm')" :disabled="cantSave"/>
              </div>
            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>
