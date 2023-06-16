<?php

namespace Pxianyu\Iseed\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Pxianyu\Iseed\Iseed;
use Pxianyu\Iseed\TableNotFoundException;
use support\Db;
use support\Log;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class IseedCommand extends Command
{
    protected static $defaultName = 'iseed';

    protected function configure()
    {
        $this
            ->setDescription('Generate seed file from table')
            ->addArgument('tables', InputArgument::REQUIRED, 'comma separated string of table names')
            ->addOption('clean', null, InputOption::VALUE_NONE, 'clean iseed section', null)
            ->addOption('force', null, InputOption::VALUE_NONE, 'force overwrite of all existing seed classes', null)
            ->addOption('database', null, InputOption::VALUE_OPTIONAL, 'database connection', config('database.default'))
            ->addOption('max', null, InputOption::VALUE_OPTIONAL, 'max number of rows', null)
            ->addOption('chunksize', null, InputOption::VALUE_OPTIONAL, 'size of data chunks for each insert query', null)
            ->addOption('exclude', null, InputOption::VALUE_OPTIONAL, 'exclude columns', null)
            ->addOption('prerun', null, InputOption::VALUE_OPTIONAL, 'prerun event name', null)
            ->addOption('dumpauto', null, InputOption::VALUE_OPTIONAL, 'postrun event name', null)
            ->addOption('noindex', null, InputOption::VALUE_OPTIONAL, 'postrun event name', null)
            ->addOption('postrun', null, InputOption::VALUE_OPTIONAL, 'postrun event name', null)
            ->addOption('orderby', null, InputOption::VALUE_OPTIONAL, 'orderby desc by column', null)
            ->addOption('direction', null, InputOption::VALUE_OPTIONAL, 'orderby direction', null)
            ->addOption('classnameprefix', null, InputOption::VALUE_OPTIONAL, 'prefix for class and file name', null)
            ->addOption('classnamesuffix', null, InputOption::VALUE_OPTIONAL, 'suffix for class and file name', null)
            ->setHelp('Generate seed file from table' . PHP_EOL);

//        parent::configure();

    }

    /**
     * @throws FileNotFoundException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {

        $tables = explode(",", $input->getArgument('tables'));
        $max = intval($input->getOption('max'));
        $chunkSize = intval($input->getOption('chunksize'));
        $exclude=$input->getOption('exclude');
        if ($exclude) {
            $exclude = explode(",", $exclude);
        } else {
            $exclude = [];
        }
        $prerunEvents=$input->getOption('prerun');
        if ($prerunEvents) {
            $prerunEvents = explode(",", $prerunEvents);
        } else {
            $prerunEvents = [];
        }
        $postrunEvents=$input->getOption('postrun');
        if ($postrunEvents) {
            $postrunEvents = explode(",", $postrunEvents);
        } else {
            $postrunEvents = [];
        }
        $dumpAuto = intval($input->getOption('dumpauto')??1);
        $indexed = !$input->getOption('noindex');
        $orderBy = $input->getOption('orderby');
        $direction = $input->getOption('direction')??'desc';
        $prefix = $input->getOption('classnameprefix');
        $suffix = $input->getOption('classnamesuffix');

        if ($max < 1) {
            $max = 0;
        }

        if ($chunkSize < 1) {
            $chunkSize = null;
        }

        $tableIncrement = 0;
        foreach ($tables as $table) {
            $table = trim($table);
            $prerunEvent = null;
            if (isset($prerunEvents[$tableIncrement])) {
                $prerunEvent = trim($prerunEvents[$tableIncrement]);
            }
            $postrunEvent = null;
            if (isset($postrunEvents[$tableIncrement])) {
                $postrunEvent = trim($postrunEvents[$tableIncrement]);
            }
            $tableIncrement++;

            // generate file and class name based on name of the table
            list($fileName, $className) = $this->generateFileName($table, $prefix, $suffix);
            Log::info('fileName:'.$fileName);
            // if file does not exist or force option is turned on generate seeder

            if (!file_exists($fileName) || $input->getOption('force')) {
                $this->printResult(
                    (new Iseed())->generateSeed(
                        $table,
                        $prefix,
                        $suffix,
                        $input->getOption('database'),
                        $max,
                        $chunkSize,
                        $exclude,
                        $prerunEvent,
                        $postrunEvent,
                        $dumpAuto,
                        $indexed,
                        $orderBy,
                        $direction
                    ),
                    $table,$output
                );
                continue;
            }

//            if ($output->('File ' . $className . ' already exist. Do you wish to override it? [yes|no]')) {
//                // if user said yes overwrite old seeder
//                $this->printResult(
//                    (new Iseed())->generateSeed(
//                        $table,
//                        $prefix,
//                        $suffix,
//                        $input->getOption('database'),
//                        $max,
//                        $chunkSize,
//                        $exclude,
//                        $prerunEvent,
//                        $postrunEvent,
//                        $dumpAuto,
//                        $indexed
//                    ),
//                    $table,$output
//                );
//            }
        }

        return self::SUCCESS;
    }


    /**
     * Provide user feedback, based on success or not.
     *
     * @param boolean $successful
     * @param string $table
     * @param $output
     * @return void
     */
    protected function printResult($successful, $table,$output): void
    {
        if ($successful) {
            $output->writeln("Created a seed file from table {$table}");
            return;
        }

        $output->writeln("Could not create seed file from table {$table}");
    }

    /**
     * Generate file name, to be used in test wether seed file already exist
     *
     * @param string $table
     * @param null $prefix
     * @param null $suffix
     * @return array|string
     */
    protected function generateFileName($table, $prefix=null, $suffix=null): array|string
    {

        if (!Db::schema()->hasTable($table)) {
            throw new TableNotFoundException("Table $table was not found.");
        }

        // Generate class name and file name
        $className = (new Iseed())->generateClassName($table, $prefix, $suffix);
        Log::info("className: $className");
        $seedPath = base_path() . config('plugin.pxianyu.iseed.app.paths.seeds');
        return [$seedPath . '/' . $className . '.php', $className . '.php'];
    }
}