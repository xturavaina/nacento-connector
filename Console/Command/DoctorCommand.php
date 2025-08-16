<?php
declare(strict_types=1);

namespace Nacento\Connector\Console\Command;

use Magento\Framework\Console\Cli;
use Nacento\Connector\Model\HealthCheck;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DoctorCommand extends Command
{
    public function __construct(private HealthCheck $healthCheck, string $name = null)
    {
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('nacento:connector:doctor')
            ->setDescription('Run Nacento Connector health checks (S3/R2, MQ, DB, module).');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->healthCheck->assertReady();
            $output->writeln('<info>OK:</info> Nacento Connector environment looks healthy.');
            return Cli::RETURN_SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>ERROR:</error> ' . $e->getMessage());
            return Cli::RETURN_FAILURE;
        }
    }
}
