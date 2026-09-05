<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'make:module', description: 'Vygenerovat kostru nového modulu')]
final class MakeModuleCommand extends Command
{
    public function __construct(private readonly string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = Str::studly((string) $input->getArgument('name'));
        $id = strtolower($name);
        $root = $this->basePath . '/modules/' . $name;

        if (is_dir($root)) {
            $output->writeln('<error>Modul už existuje.</error>');

            return Command::FAILURE;
        }

        $dirs = [
            'Application',
            'Application/Widgets',
            'Domain',
            'Infrastructure',
            'Http',
            'Admin',
            'Database/Migrations',
            'Resources/views',
            'Tests',
        ];

        foreach ($dirs as $dir) {
            mkdir($root . '/' . $dir, 0775, true);
        }

        file_put_contents($root . '/module.php', <<<PHP
<?php

declare(strict_types=1);

return [
    'id' => '{$id}',
    'name' => '{$name}',
    'version' => '0.1.0',
    'description' => '',
    'class' => Modules\\\\{$name}\\\\{$name}Module::class,
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [],
    'provides' => [],
    'capabilities' => [],
    'permissions' => [],
];
PHP);

        file_put_contents($root . '/' . $name . 'Module.php', <<<PHP
<?php

declare(strict_types=1);

namespace Modules\\\\{$name};

use Gereq\\\\Module\\\\ModuleContext;
use Gereq\\\\Module\\\\ModuleInterface;

final class {$name}Module implements ModuleInterface
{
    public function id(): string
    {
        return '{$id}';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function register(ModuleContext \$context): void
    {
    }

    public function boot(ModuleContext \$context): void
    {
        // Dashboard: \$context->dashboard()->addWidget(new \Modules\\\\{$name}\\\\Application\\\\Widgets\\\\ExampleWidget(...));
    }
}
PHP);

        $output->writeln('Modul vytvořen v modules/' . $name);

        return Command::SUCCESS;
    }
}
