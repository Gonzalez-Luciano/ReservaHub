<?php

namespace Tests\Concerns;

trait WithDatabaseSessions
{
    protected function setUpWithDatabaseSessions(): void
    {
        config()->set('session.driver', 'database');
    }
}
