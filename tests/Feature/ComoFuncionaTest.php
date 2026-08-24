<?php

namespace Tests\Feature;

use Tests\TestCase;

class ComoFuncionaTest extends TestCase
{
    public function test_the_guide_is_public(): void
    {
        $this->get('/como-funciona')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('ComoFunciona'));
    }

    public function test_the_mailbox_cta_is_absent_without_configuration(): void
    {
        config(['app.demo_mail_url' => null]);

        $this->get('/como-funciona')
            ->assertInertia(fn ($page) => $page->where('mailUrl', null));
    }
}
