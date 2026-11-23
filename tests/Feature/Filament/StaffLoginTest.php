<?php

it('renders the staff login with branding and subheading', function () {
    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('Staff panel — for BOC administrators, visa processors and finance.')
        ->assertSee('images/boc-logo.png');
});
