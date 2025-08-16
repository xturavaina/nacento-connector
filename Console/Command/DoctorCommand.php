<?php
declare(strict_types=1);

namespace Nacento\Connector\Console\Command;

use Magento\Framework\Console\Cli;
use Nacento\Connector\Model\HealthCheck;
use Magento\Framework\MessageQueue\Publisher\ConfigInterface as PublisherConfig;
use Magento\Framework\MessageQueue\Consumer\ConfigInterface as ConsumerConfig;
use Magento\AsynchronousOperations\Api\Data\OperationInterfaceFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Terminal;

class DoctorCommand extends Command
{
    public const NAME = 'nacento:connector:doctor';

    public function __construct(
        private HealthCheck $healthCheck,
        private PublisherConfig $publisherConfig,
        private ConsumerConfig $consumerConfig,
        private OperationInterfaceFactory $opFactory,
        private JsonSerializer $json
    ) {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this->setDescription('Run Nacento Connector health checks (S3/R2, MQ, DB, module).')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('no-publish', null, InputOption::VALUE_NONE, 'Skip MQ publish test')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Show full details (no truncation)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->healthCheck->run(
            !$input->getOption('no-publish'),
            $this->publisherConfig,
            $this->consumerConfig,
            $this->opFactory,
            $this->json
        );

        if ($input->getOption('json')) {
            $output->writeln($this->json->serialize($report->toArray()));
            return $report->hasFailures() ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
        }

        // === ENV en format llistat curt
        $env = $report->getEnv();
        $output->writeln('<info>Environment</info>');
        foreach ($env as $k=>$v) {
            $output->writeln(sprintf("  - %s: %s", $k, is_scalar($v)? (string)$v : json_encode($v)));
        }
        $output->writeln('');

        // Amplada del terminal per fer word-wrap sensat
        $termWidth = (new Terminal())->getWidth();
        $detailsWidth = max(40, min(120, $termWidth - 40)); // heurística

        $table = new Table($output);
        $table->setHeaders(['Check', 'Status', 'Duration (ms)', 'Details / Error']);

        $full = (bool)$input->getOption('full');

        foreach ($report->getChecks() as $c) {
            $statusStyled = match ($c['status']) {
                'ok'      => '<info>ok</info>',
                'fail'    => '<error>fail</error>',
                default   => '<comment>skipped</comment>',
            };

            $details = $c['error']
                ? 'ERR: ' . $c['error']
                : $this->formatDetails($c['details'], $full, $detailsWidth);

            $table->addRow([$c['name'], $statusStyled, number_format($c['duration_ms'], 2), $details]);
        }
        $table->render();

        $output->writeln('');
        if ($report->hasFailures()) {
            $output->writeln('<error>One or more checks FAILED.</error>');
            return Cli::RETURN_FAILURE;
        }
        $output->writeln('<info>All checks passed or were skipped.</info>');
        return Cli::RETURN_SUCCESS;
    }

    /**
     * Converteix el bloc de detalls en text compacte i amb word-wrap.
     * - Resumim arrays voluminosos (p.ex. registered_consumers) si --full no està activat.
     */
    private function formatDetails(array $details, bool $full, int $wrapWidth): string
    {
        // Compactem registered_consumers si no és mode --full
        if (!$full && isset($details['registered_consumers']) && is_array($details['registered_consumers'])) {
            $list = $details['registered_consumers'];
            $details['registered_consumers_count'] = count($list);

            // mostrem només els 3 primers com a mostra
            $preview = array_slice($list, 0, 3);
            $details['registered_consumers_preview'] = $preview;
            unset($details['registered_consumers']);
        }

        $json = json_encode(
            $details,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ) ?: '{}';

        // Word-wrap perquè la taula no s'estiri
        return wordwrap($json, $wrapWidth, PHP_EOL, true);
    }
}
