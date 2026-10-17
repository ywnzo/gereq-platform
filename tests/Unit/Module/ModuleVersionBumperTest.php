<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Module;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Audit\Auditor;
use Gereq\Module\ModuleManager;
use Gereq\Module\ModuleVersionBumper;
use Gereq\Tests\Support\PassthroughTranslator;
use Gereq\Testing\TestCase;
use Gereq\User\PermissionRegistry;
use Symfony\Component\HttpFoundation\RequestStack;

final class ModuleVersionBumperTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $this->removeTree($this->tmp);
        }
        parent::tearDown();
    }

    public function testBumpPatchRewritesManifestAndClass(): void
    {
        $this->tmp = sys_get_temp_dir() . '/gereq-bump-' . bin2hex(random_bytes(4));
        $mod = $this->tmp . '/modules/Demo';
        mkdir($mod, 0775, true);
        file_put_contents($mod . '/module.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    'id' => 'demo',
    'name' => 'Demo',
    'version' => '1.2.3',
    'description' => 'demo',
    'class' => Modules\Demo\DemoModule::class,
];
PHP);
        file_put_contents($mod . '/DemoModule.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Modules\Demo;

use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;

final class DemoModule implements ModuleInterface
{
    public function id(): string
    {
        return 'demo';
    }

    public function version(): string
    {
        return '1.2.3';
    }

    public function register(ModuleContext $context): void
    {
    }

    public function boot(ModuleContext $context): void
    {
    }
}
PHP);

        $em = $this->createMock(EntityManagerInterface::class);
        $translator = new PassthroughTranslator();
        $auditor = new Auditor($em, new RequestStack());
        $manager = new ModuleManager(
            $this->tmp,
            $em,
            new PermissionRegistry(),
            $auditor,
            $translator,
        );
        // Discovery validates ModuleInterface::SUBMODULES — use validate false via rediscover after construct
        $manager->rediscover(false);

        $bumper = new ModuleVersionBumper($manager, $translator);
        $new = $bumper->bumpPatch('demo');
        self::assertSame('1.2.4', $new);

        $manifestRaw = (string) file_get_contents($mod . '/module.php');
        self::assertStringContainsString("'version' => '1.2.4'", $manifestRaw);
        $classRaw = (string) file_get_contents($mod . '/DemoModule.php');
        self::assertStringContainsString("return '1.2.4';", $classRaw);
        self::assertSame('1.2.4', $manager->manifest('demo')?->version);
    }

    private function removeTree(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
