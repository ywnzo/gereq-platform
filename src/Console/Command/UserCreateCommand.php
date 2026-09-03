<?php

declare(strict_types=1);

namespace Gereq\Console\Command;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\PasswordHasher;
use Gereq\User\Entity\Role;
use Gereq\User\Entity\User;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'user:create', description: 'Vytvořit uživatele')]
final class UserCreateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PasswordHasher $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_OPTIONAL)
            ->addOption('email', null, InputOption::VALUE_OPTIONAL)
            ->addOption('password', null, InputOption::VALUE_OPTIONAL)
            ->addOption('role', null, InputOption::VALUE_OPTIONAL, 'administrator|manager|editor|viewer', 'administrator');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');
        if (!$helper instanceof \Symfony\Component\Console\Helper\QuestionHelper) {
            throw new \RuntimeException('Question helper není dostupný.');
        }
        $name = (string) ($input->getOption('name') ?: $helper->ask($input, $output, new Question('Jméno: ')));
        $email = (string) ($input->getOption('email') ?: $helper->ask($input, $output, new Question('E-mail: ')));
        $password = (string) $input->getOption('password');
        if ($password === '') {
            $question = new Question('Heslo: ');
            $question->setHidden(true);
            $password = (string) $helper->ask($input, $output, $question);
        }

        $user = new User($name, $email, $this->hasher->hash($password));
        $role = $this->em->getRepository(Role::class)->findOneBy(['name' => (string) $input->getOption('role')]);
        if ($role instanceof Role) {
            $user->assignRole($role);
        }

        $this->em->persist($user);
        $this->em->flush();
        $output->writeln('Uživatel ' . $user->email() . ' byl vytvořen.');

        return Command::SUCCESS;
    }
}
