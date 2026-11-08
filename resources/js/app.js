import './bootstrap';

// Flatpickr is only needed on the admin Sales report, so it is loaded (as a separate chunk) only there.
if (document.querySelector('[data-report-picker]')) {
    import('./report-picker');
}
