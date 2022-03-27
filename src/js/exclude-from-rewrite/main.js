let $slugFieldInputContainer,
  $slugFieldInput,
  $slugField,
  $toggleContainer,
  $toggleInput,
  lightSwitch;

const isPageSuitable = () => {
  const $fields = document.querySelectorAll('#slug-field');
  return $fields.length > 0;
};

const buildLightSwitch = () => {
  const hiddenFieldName = 'slugEqualsTitle_shouldRewrite';
  const $markup = `
  <div class="lightswitch fieldtoggle" tabindex="0" data-value="1" id="${hiddenFieldName}" data-target="${hiddenFieldName}-field" role="checkbox" aria-checked="false">
    <div class="lightswitch-container">
        <div class="label on"></div>
        <div class="handle"></div>
        <div class="label off"></div>
    </div>
    <input type="hidden" name="${hiddenFieldName}" value=""></div>
`;
  const $lightSwitch = document.createElement('div');
  $lightSwitch.innerHTML = $markup;
  $slugField.appendChild($lightSwitch);
  $toggleContainer = $slugField.querySelector(`#${hiddenFieldName}`);

  lightSwitch = new Craft.LightSwitch($toggleContainer);
  const width = $slugFieldInputContainer.getBoundingClientRect().width - $toggleContainer.getBoundingClientRect().width;
  const spaceRight = 10;
  $slugFieldInputContainer.style.width = `${width - spaceRight}px`;
  $slugFieldInputContainer.style.marginRight = `${spaceRight}px`;
  $toggleInput = document.querySelector(`[name=${hiddenFieldName}]`);
};

const switchToggle = (status) => {
  if (status) {
    lightSwitch.turnOn();
    $slugFieldInputContainer.classList.add('slugEqualsTitle-overwrite-enabled');
  } else {
    lightSwitch.turnOff();
    $slugFieldInputContainer.classList.remove('slugEqualsTitle-overwrite-enabled');
  }
};

const init = async () => {
  if (!isPageSuitable()) return;
  document.querySelector('body').classList.add('slugEqualsTitle');
  $slugField = document.querySelector('#slug-field.field');
  if (!$slugField) return;
  const isEnabled = document.querySelector('meta[name="slugEqualsTitleOverwriteEnabled"]').content === "true";
  $slugFieldInputContainer = $slugField.querySelector('.input');
  $slugFieldInput = $slugFieldInputContainer.querySelector('input');
  buildLightSwitch();
  switchToggle(isEnabled);

  $toggleContainer.addEventListener('click', () => {
    switchToggle($toggleInput.value === "1");
  });
};

export default {
  init,
};
