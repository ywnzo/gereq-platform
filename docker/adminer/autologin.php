<?php

/**
 * Auto-login Adminer to the project PostgreSQL service.
 */
class AdminerAutoLogin
{
    private function server(): string
    {
        return getenv('ADMINER_SERVER') ?: 'postgres';
    }

    private function username(): string
    {
        return getenv('ADMINER_USERNAME') ?: 'gereq';
    }

    private function password(): string
    {
        return getenv('ADMINER_PASSWORD') ?: 'gereq';
    }

    private function databaseName(): string
    {
        return getenv('ADMINER_DB') ?: 'gereq';
    }

    function credentials()
    {
        return [$this->server(), $this->username(), $this->password()];
    }

    function database()
    {
        return $this->databaseName();
    }

    function login($login, $password)
    {
        return true;
    }

    function loginForm()
    {
        echo '<input type="hidden" name="auth[driver]" value="pgsql">' . "\n";
        echo '<input type="hidden" name="auth[server]" value="' . h($this->server()) . '">' . "\n";
        echo '<input type="hidden" name="auth[username]" value="' . h($this->username()) . '">' . "\n";
        echo '<input type="hidden" name="auth[password]" value="' . h($this->password()) . '">' . "\n";
        echo '<input type="hidden" name="auth[db]" value="' . h($this->databaseName()) . '">' . "\n";
        echo '<input type="hidden" name="auth[permanent]" value="1">' . "\n";
        echo '<p>Připojuji k PostgreSQL…</p>' . "\n";
        echo script('document.addEventListener("DOMContentLoaded",function(){var f=document.forms[0];if(f){f.submit();}});');

        return true;
    }
}

return new AdminerAutoLogin();
