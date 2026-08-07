<?php

it('serves the public compliance pages', function () {
    $this->get('/legal/privacy')->assertOk()->assertSee('Privacy Policy');
    $this->get('/legal/terms')->assertOk();
    $this->get('/legal/data-deletion')->assertOk();
    $this->get('/legal/support')->assertOk();
    $this->get('/legal/company')->assertOk();
});
