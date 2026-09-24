let busy = false;

function element() {
    let modal = document.getElementById('o8ActionDialog');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.id = 'o8ActionDialog';
    modal.className = 'modal fade';
    modal.tabIndex = -1;
    modal.setAttribute('aria-labelledby', 'o8ActionDialogTitle');
    modal.innerHTML = '<div class="modal-dialog modal-dialog-centered"><form class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="o8ActionDialogTitle"></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button></div><div class="modal-body"><p class="mb-0" id="o8ActionDialogMessage"></p><label class="form-label mt-3 mb-0" id="o8ActionDialogInputWrap" hidden>Name<input class="form-control mt-1" id="o8ActionDialogInput" maxlength="190" required></label></div><div class="modal-footer"><button type="button" class="btn btn-surface" data-bs-dismiss="modal">Abbrechen</button><button type="button" class="btn btn-outline-danger" id="o8ActionDialogAlternative" hidden></button><button type="submit" class="btn btn-primary" id="o8ActionDialogConfirm">Bestätigen</button></div></form></div>';
    document.body.append(modal);
    return modal;
}

function once(target, name) {
    return new Promise(resolve => target.addEventListener(name, resolve, {once:true}));
}

async function ask({title, message, label, destructive = false, input = false, confirmValue = true, alternative = null, cancelLabel = 'Abbrechen'}) {
    if (busy) return input ? null : false;
    busy = true;
    const modal = element();
    const paused = [...document.querySelectorAll('.modal.show')].find(item => item !== modal);
    if (paused) {
        const hidden = once(paused, 'hidden.bs.modal');
        bootstrap.Modal.getOrCreateInstance(paused).hide();
        await hidden;
    }
    modal.querySelector('#o8ActionDialogTitle').textContent = title;
    modal.querySelector('#o8ActionDialogMessage').textContent = message;
    modal.querySelector('.modal-footer [data-bs-dismiss="modal"]').textContent = cancelLabel;
    const field = modal.querySelector('#o8ActionDialogInput');
    field.value = '';
    field.disabled = !input;
    modal.querySelector('#o8ActionDialogInputWrap').hidden = !input;
    const confirm = modal.querySelector('#o8ActionDialogConfirm');
    confirm.textContent = label;
    confirm.classList.toggle('btn-danger', destructive);
    confirm.classList.toggle('btn-primary', !destructive);
    const alternativeButton = modal.querySelector('#o8ActionDialogAlternative');
    alternativeButton.hidden = !alternative;
    if (alternative) {
        alternativeButton.textContent = alternative.label;
        alternativeButton.classList.toggle('btn-danger', Boolean(alternative.destructive));
        alternativeButton.classList.toggle('btn-outline-danger', !alternative.destructive);
    }
    let answer = input ? null : false;
    const submit = event => {
        event.preventDefault();
        answer = input ? field.value.trim() : confirmValue;
        bootstrap.Modal.getInstance(modal).hide();
    };
    const chooseAlternative = () => {
        answer = alternative.value;
        bootstrap.Modal.getInstance(modal).hide();
    };
    modal.querySelector('form').addEventListener('submit', submit);
    if (alternative) alternativeButton.addEventListener('click', chooseAlternative);
    bootstrap.Modal.getOrCreateInstance(modal).show();
    if (input) modal.addEventListener('shown.bs.modal', () => field.focus(), {once:true});
    await once(modal, 'hidden.bs.modal');
    modal.querySelector('form').removeEventListener('submit', submit);
    if (alternative) alternativeButton.removeEventListener('click', chooseAlternative);
    if (paused) {
        const shown = once(paused, 'shown.bs.modal');
        bootstrap.Modal.getOrCreateInstance(paused).show();
        await shown;
    }
    busy = false;
    return answer;
}

export const confirmDialog = (title, message, label = 'Bestätigen', destructive = false, cancelLabel = 'Abbrechen') => ask({title,message,label,destructive,cancelLabel});
export const inputDialog = (title, message, label = 'Anlegen') => ask({title,message,label,input:true});
export const choiceDialog = (title, message, primaryLabel, primaryValue, alternativeLabel, alternativeValue) => ask({title,message,label:primaryLabel,confirmValue:primaryValue,alternative:{label:alternativeLabel,value:alternativeValue}});
