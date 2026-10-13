document.addEventListener('DOMContentLoaded', function () {
    var forms = document.querySelectorAll('.obbp-add-form');
    forms.forEach(function (form) {
        form.addEventListener('submit', function () {
            var button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
                button.textContent = obbpPortal.adding || 'Adding…';
            }
        });
    });
});

document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-obbp-confirm]');
    if (trigger && !window.confirm(trigger.getAttribute('data-obbp-confirm'))) {
        event.preventDefault();
    }
});
