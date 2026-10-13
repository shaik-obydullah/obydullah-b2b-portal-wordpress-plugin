window.addEventListener('load', function () {
    window.print();
});

document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-obbp-print]');
    if (trigger) {
        event.preventDefault();
        window.print();
    }
});
