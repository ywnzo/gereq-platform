<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Console\Serve;

use Gereq\Console\Serve\ServeDbBrowser;
use Gereq\Console\Serve\ServeDbSql;
use Gereq\Testing\TestCase;
use PDO;

final class ServeDbBrowserTest extends TestCase
{
    private string $dbPath = '';

    private ?string $prevConnection = null;

    private ?string $prevDatabase = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir() . '/gereq-serve-db-' . uniqid('', true) . '.sqlite';
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, name TEXT)');
        $pdo->exec("INSERT INTO users (email, name) VALUES ('a@b.c', 'Ada'), ('x@y.z', 'Bob')");
        $pdo->exec('CREATE TABLE empty_t (id INTEGER PRIMARY KEY)');

        $this->prevConnection = getenv('DB_CONNECTION') !== false ? (string) getenv('DB_CONNECTION') : null;
        $this->prevDatabase = getenv('DB_DATABASE') !== false ? (string) getenv('DB_DATABASE') : null;
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=' . $this->dbPath);
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $this->dbPath;
    }

    protected function tearDown(): void
    {
        if ($this->prevConnection === null) {
            putenv('DB_CONNECTION');
            unset($_ENV['DB_CONNECTION']);
        } else {
            putenv('DB_CONNECTION=' . $this->prevConnection);
            $_ENV['DB_CONNECTION'] = $this->prevConnection;
        }
        if ($this->prevDatabase === null) {
            putenv('DB_DATABASE');
            unset($_ENV['DB_DATABASE']);
        } else {
            putenv('DB_DATABASE=' . $this->prevDatabase);
            $_ENV['DB_DATABASE'] = $this->prevDatabase;
        }
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        parent::tearDown();
    }

    public function testListsTablesAndPagesRows(): void
    {
        $browser = new ServeDbBrowser(sys_get_temp_dir());
        self::assertNull($browser->error());
        self::assertSame('sqlite', $browser->driver());

        $tables = $browser->tables();
        self::assertContains('users', $tables);
        self::assertContains('empty_t', $tables);

        $cols = $browser->columns('users');
        self::assertSame(['id', 'email', 'name'], array_column($cols, 'name'));
        self::assertSame(['id'], $browser->primaryKeys('users'));

        $page = $browser->page('users', 1);
        self::assertSame(2, $page['total']);
        self::assertSame(['id', 'email', 'name'], $page['columns']);
        self::assertCount(2, $page['rows']);
        self::assertSame('Ada', $page['rows'][0][2]);
        self::assertArrayHasKey('email', $page['raw'][0]);
    }

    public function testInsertDeleteAndSql(): void
    {
        $browser = new ServeDbBrowser(sys_get_temp_dir());
        $ins = $browser->insertRow('users', ['email' => 'c@d.e', 'name' => 'Cy']);
        self::assertTrue($ins['ok'], $ins['message']);

        $page = $browser->page('users', 1);
        self::assertSame(3, $page['total']);

        $sel = $browser->execSql('SELECT name FROM users WHERE email = \'c@d.e\'');
        self::assertTrue($sel['ok']);
        self::assertSame('Cy', $sel['rows'][0][0]);

        $raw = null;
        foreach ($page['raw'] as $row) {
            if (($row['email'] ?? '') === 'c@d.e') {
                $raw = $row;
                break;
            }
        }
        self::assertNotNull($raw);
        $del = $browser->deleteRow('users', $raw);
        self::assertTrue($del['ok'], $del['message']);
        self::assertSame(2, $browser->page('users', 1)['total']);
    }

    public function testRejectsUnsafeTableName(): void
    {
        $browser = new ServeDbBrowser(sys_get_temp_dir());
        $page = $browser->page('users; DROP TABLE users', 1);
        self::assertSame(0, $page['total']);
        self::assertSame([], $page['columns']);
    }

    public function testSqlHighlightAndAutocomplete(): void
    {
        $hl = ServeDbSql::highlight('SELECT * FROM users WHERE id = 1');
        self::assertStringContainsString("\033[1;36mSELECT\033[0m", $hl);
        self::assertStringContainsString("\033[33m1\033[0m", $hl);

        $applied = ServeDbSql::applyAutocomplete('SELECT * FROM us', 16, ['users', 'modules'], ['id']);
        self::assertNotNull($applied);
        self::assertSame('SELECT * FROM users', $applied[0]);
    }
}
