<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Gereq\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'make:resource', description: 'Vygenerovat Admin Resource entitu')]
final class MakeResourceCommand extends Command
{
    public function __construct(private readonly string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED)
            ->addOption('module', 'm', InputOption::VALUE_REQUIRED, 'Název adresáře modulu', 'Customers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = Str::studly((string) $input->getArgument('name'));
        $module = Str::studly((string) $input->getOption('module'));
        $id = strtolower($name) . 's';
        $dir = $this->basePath . '/modules/' . $module . '/Domain';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $file = $dir . '/' . $name . '.php';
        file_put_contents($file, <<<PHP
<?php

declare(strict_types=1);

namespace Modules\\\\{$module}\\\\Domain;

use Doctrine\\\\ORM\\\\Mapping as ORM;
use Gereq\\\\Admin\\\\Resource\\\\AdminField;
use Gereq\\\\Admin\\\\Resource\\\\AdminResource;
use Ramsey\\\\Uuid\\\\Uuid;

#[ORM\\\\Entity]
#[ORM\\\\Table(name: '{$id}')]
#[AdminResource(name: '{$id}', label: '{$name}', permission: '{$id}')]
class {$name}
{
    #[ORM\\\\Id]
    #[ORM\\\\Column(length: 36)]
    private string \$id;

    #[ORM\\\\Column(length: 180)]
    #[AdminField(type: 'text', label: 'Název', searchable: true, sortable: true, required: true)]
    private string \$name = '';

    #[ORM\\\\Column(name: 'created_at')]
    private \\\\DateTimeImmutable \$createdAt;

    #[ORM\\\\Column(name: 'updated_at')]
    private \\\\DateTimeImmutable \$updatedAt;

    public function __construct()
    {
        \$this->id = Uuid::uuid7()->toString();
        \$this->createdAt = new \\\\DateTimeImmutable();
        \$this->updatedAt = new \\\\DateTimeImmutable();
    }

    public function id(): string
    {
        return \$this->id;
    }

    public function name(): string
    {
        return \$this->name;
    }

    public function setName(string \$name): void
    {
        \$this->name = \$name;
        \$this->updatedAt = new \\\\DateTimeImmutable();
    }
}
PHP);

        $output->writeln('Resource vytvořen: ' . $file);

        return Command::SUCCESS;
    }
}
