<?php
/**
 *
 */

declare(strict_types=1);

namespace Alphaws\Larainit\Console;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Class InitCommand
 * @package Alphaws\Larainit\Console
 */
class InitCommand extends Command
{
    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('init')
            ->setDescription('Create a new Laravel application with JetStream+Livewire+Docker')
            ->addOption('name', InputArgument::REQUIRED)
            //->addOption('branch', null, InputOption::VALUE_REQUIRED, 'The branch that should be created for a new repository', $this->defaultBranch())
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Remove previous installation if exists');
    }

    /**
     * Execute the command.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        echo chr(27) . chr(91) . 'H' . chr(27) . chr(91) . 'J';
        $output->write(PHP_EOL . '<fg=magenta>

 /$$                                    /$$$$$$           /$$   /$$    
| $$                                   |_  $$_/          |__/  | $$    
| $$        /$$$$$$   /$$$$$$  /$$$$$$   | $$   /$$$$$$$  /$$ /$$$$$$  
| $$       |____  $$ /$$__  $$|____  $$  | $$  | $$__  $$| $$|_  $$_/  
| $$        /$$$$$$$| $$  \__/ /$$$$$$$  | $$  | $$  \ $$| $$  | $$    
| $$       /$$__  $$| $$      /$$__  $$  | $$  | $$  | $$| $$  | $$ /$$
| $$$$$$$$|  $$$$$$$| $$     |  $$$$$$$ /$$$$$$| $$  | $$| $$  |  $$$$/
|________/ \_______/|__/      \_______/|______/|__/  |__/|__/   \___/  
                                                                       
</>' . PHP_EOL . PHP_EOL);

        $name = $input->getOption('name');
        if (!$name) {
            $name = (new SymfonyStyle($input, $output))->ask('ProjectName?', 'NewLaravelProject');
        }

        if (!$input->getOption('force')) {
            $this->verifyApplicationDoesntExist($name);
        }

        // Check github repo
        $process = new Process(['gh', 'repo', 'view', $name]);
        $process->run();
        if ($process->isSuccessful()) {
            throw new RuntimeException('Github repository already exists!');
        }
        // @todo: delete repo if --force
        //

        $force = ' --force';

        $process = new Process(['laravel', 'new'.$force, $name]);
        if (!$process->isSuccessful()) {
            throw new RuntimeException('Cannot create project !');
        }
        $this->installJetstream($name, 'livewire', false, $input, $output);
        $this->installPackages($name);

        $output->writeln(PHP_EOL . '<comment>Application ready! Build something amazing.</comment>');
        return $process->getExitCode();
    }

    protected function installPackages($directory, InputInterface $input, OutputInterface $output)
    {
        $commands = array_filter([
            $this->findComposer() . ' require alphaws/laradocker --dev',
            $this->findComposer() . ' require laraveldaily/larastarters --dev',
            //$this->findComposer() . ' require alphaws/laradocker',
            PHP_BINARY . ' artisan laradocker:install',
            PHP_BINARY . ' artisan larastarters:install',
            //'npm install && npm run dev',
            // PHP_BINARY . ' artisan storage:link',
        ]);

        $this->runCommands($commands, $input, $output);

        $this->commitChanges('Install Packages', $directory, $input, $output);
    }

    /**
     * Return the local machine's default Git branch if set or default to `main`.
     *
     * @return string
     */
    protected function defaultBranch()
    {
        $process = new Process(['git', 'config', '--global', 'init.defaultBranch']);

        $process->run();

        $output = trim($process->getOutput());

        return $process->isSuccessful() && $output ? $output : 'main';
    }

    /**
     * Install Laravel Jetstream into the application.
     *
     * @param string $directory
     * @param string $stack
     * @param bool $teams
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return void
     */
    protected function installJetstream(string $directory, string $stack, bool $teams, InputInterface $input, OutputInterface $output)
    {
        chdir($directory);

        $commands = array_filter([
            $this->findComposer() . ' require laravel/jetstream',
            trim(sprintf(PHP_BINARY . ' artisan jetstream:install %s %s', $stack, $teams ? '--teams' : '')),
            //'npm install && npm run dev',
           // PHP_BINARY . ' artisan storage:link',
        ]);

        $this->runCommands($commands, $input, $output);

        $this->commitChanges('Install Jetstream', $directory, $input, $output);
    }

    /**
     * Create a Git repository and commit the base Laravel skeleton.
     *
     * @param string $directory
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return void
     */
    protected function createRepository(string $directory, InputInterface $input, OutputInterface $output)
    {
        chdir($directory);

        $branch = $this->defaultBranch();

        $commands = [
            'git init -q',
            'git add .',
            'git commit -q -m "Set up a fresh Laravel app"',
            "git branch -M {$branch}",
        ];

        $this->runCommands($commands, $input, $output);
    }

    /**
     * Commit any changes in the current working directory.
     *
     * @param string $message
     * @param string $directory
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return void
     */
    protected function commitChanges(string $message, string $directory, InputInterface $input, OutputInterface $output)
    {
        chdir($directory);
        $commands = [
            'git add .',
            "git commit -q -m \"$message\"",
        ];
        $this->runCommands($commands, $input, $output);
    }

    /**
     * Create a GitHub repository and push the git log to it.
     *
     * @param string $name
     * @param string $directory
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return void
     */
    protected function pushToGitHub(string $name, string $directory, InputInterface $input, OutputInterface $output)
    {
        $process = new Process(['gh', 'auth', 'status']);
        $process->run();
        if (!$process->isSuccessful()) {
            $output->writeln('Warning: make sure the "gh" CLI tool is installed and that you\'re authenticated to GitHub. Skipping...');
            return;
        }
        chdir($directory);
        $branch = $this->defaultBranch();
        $commands = [
            "gh repo create {$name} -y --private",
            "git -c credential.helper= -c credential.helper='!gh auth git-credential' push -q -u origin {$branch}",
        ];
        $this->runCommands($commands, $input, $output, ['GIT_TERMINAL_PROMPT' => 0]);
    }

    /**
     * Verify that the application does not already exist.
     *
     * @param string $directory
     * @return void
     */
    protected function verifyApplicationDoesntExist($directory)
    {
        if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
            throw new RuntimeException('Application already exists!');
        }
    }

    /**
     * Get the version that should be downloaded.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @return string
     */
    protected function getVersion(InputInterface $input)
    {
        if ($input->getOption('dev')) {
            return 'dev-master';
        }

        return '';
    }

    /**
     * Get the composer command for the environment.
     *
     * @return string
     */
    protected function findComposer()
    {
        $composerPath = getcwd() . '/composer.phar';

        if (file_exists($composerPath)) {
            return '"' . PHP_BINARY . '" ' . $composerPath;
        }

        return 'composer';
    }

    /**
     * Run the given commands.
     *
     * @param array $commands
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param array $env
     * @return \Symfony\Component\Process\Process
     */
    protected function runCommands($commands, InputInterface $input, OutputInterface $output, array $env = [])
    {
        if (!$output->isDecorated()) {
            $commands = array_map(function ($value) {
                if (substr($value, 0, 5) === 'chmod') {
                    return $value;
                }

                return $value . ' --no-ansi';
            }, $commands);
        }

        if ($input->getOption('quiet')) {
            $commands = array_map(function ($value) {
                if (substr($value, 0, 5) === 'chmod') {
                    return $value;
                }

                return $value . ' --quiet';
            }, $commands);
        }

        $process = Process::fromShellCommandline(implode(' && ', $commands), null, $env, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                $output->writeln('Warning: ' . $e->getMessage());
            }
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write('    ' . $line);
        });

        return $process;
    }

    /**
     * Replace the given string in the given file.
     *
     * @param string $search
     * @param string $replace
     * @param string $file
     * @return void
     */
    protected function replaceInFile(string $search, string $replace, string $file)
    {
        file_put_contents(
            $file,
            str_replace($search, $replace, file_get_contents($file))
        );
    }
}
