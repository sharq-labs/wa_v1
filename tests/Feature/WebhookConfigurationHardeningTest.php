<?php

it('rejects Meta webhook verification when the server verify token is blank', function () {
    config(['meta.webhook_verify_token' => '']);

    $this->get('/api/webhooks/meta/whatsapp?hub_mode=subscribe&hub_challenge=12345')
        ->assertForbidden();

    $this->get('/api/webhooks/meta/whatsapp?hub_mode=subscribe&hub_verify_token=&hub_challenge=12345')
        ->assertForbidden();
});
