<?php

namespace Pxianyu\Iseed;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Composer;
use support\Db;
use support\Log;

class Iseed
{

    protected  $databaseName;

    /**
     * New line character for seed files.
     * Double quotes are mandatory!
     *
     * @var string
     */
    private string $newLineCharacter = PHP_EOL;

    /**
     * Desired indent for the code.
     * For tabulator use \t
     * Double quotes are mandatory!
     *
     * @var string
     */
    private string $indentCharacter = "    ";

    /**
     * @var Composer
     */
    private Composer $composer;

    protected array $config;
    public function __construct(Filesystem $filesystem = null, Composer $composer = null)
    {
        $this->files = $filesystem ?: new Filesystem;
        $this->composer = $composer ?: new Composer($this->files);
        $this->config = config('plugin.pxianyu.iseed.app');
    }

    public function readStubFile($file): string
    {
        $buffer = file($file, FILE_IGNORE_NEW_LINES);
        return implode(PHP_EOL, $buffer);
    }

    /**
     * Generates a seed file.
     * @param string $table
     * @param string|null $prefix
     * @param string|null $suffix
     * @param string|null $database
     * @param int $max
     * @param int $chunkSize
     * @param null $exclude
     * @param string|null $prerunEvent
     * @param null $postrunEvent
     * @param bool $dumpAuto
     * @param bool $indexed
     * @param null $orderBy
     * @param string $direction
     * @return bool
     * @throws FileNotFoundException
     */
    public function generateSeed(string $table, string $prefix=null, string $suffix=null, string $database = null, int $max = 0, $chunkSize = 0, $exclude = null, string $prerunEvent = null, $postrunEvent = null, $dumpAuto = true, $indexed = true, $orderBy = null, $direction = 'ASC'): bool
    {
        if (!$database) {
            $database = config('database.connections.mysql');
        }
        $this->databaseName = $database;
        // Check if table exists
        if (!$this->hasTable($table)) {
            throw new TableNotFoundException("Table $table was not found.");
        }

        // Get the data
        $data = $this->getData($table, $max, $exclude, $orderBy, $direction);

        // Repack the data
        $dataArray = $this->repackSeedData($data);

        // Generate class name
        $className = $this->generateClassName($table, $prefix, $suffix);

        // Get template for a seed file contents
        $stub = $this->readStubFile($this->getStubPath() . '/seed.stub');

        // Get a seed folder path
        $seedPath = $this->getSeedPath();

        // Get a app/database/seeds path
        $seedsPath = $this->getPath($className, $seedPath);
        Log::info('seedsPath:'.$seedsPath);
        Log::info('$className:'.$className);

        // Get a populated stub file
        $seedContent = $this->populateStub(
            $className,
            $stub,
            $table,
            $dataArray,
            $chunkSize,
            $prerunEvent,
            $postrunEvent,
            $indexed
        );

        // Save a populated stub
        $this->files->put($seedsPath, $seedContent);

        // Run composer dump-auto
        if ($dumpAuto) {
            $this->composer->dumpAutoloads();
        }

        // Update the DatabaseSeeder.php file
        return true;
    }

    /**
     * Get a seed folder path
     * @return string
     */
    public function getSeedPath(): string
    {
        return base_path() . DIRECTORY_SEPARATOR.config('plugin.pxianyu.iseed.app.paths.seeds');
    }

    /**
     * Get the Data
     * @param string $table
     * @param $max
     * @param null $exclude
     * @param null $orderBy
     * @param string $direction
     * @return Collection
     */
    public function getData(string $table, $max, $exclude = null, $orderBy = null, string $direction = 'ASC'): Collection
    {
        $result = Db::connection($this->databaseName)->table($table);

        if (!empty($exclude)) {
            $allColumns = Db::connection($this->databaseName)->getSchemaBuilder()->getColumnListing($table);
            $result = $result->select(array_diff($allColumns, $exclude));
        }

        if($orderBy) {
            $result = $result->orderBy($orderBy, $direction);
        }

        if ($max) {
            $result = $result->limit($max);
        }

        return $result->get();
    }

    /**
     * Repacks data read from the database
     * @param object|array $data
     * @return array
     */
    public function repackSeedData(object|array $data): array
    {
        if (!is_array($data)) {
            $data = $data->toArray();
        }
        $dataArray = array();
        if (!empty($data)) {
            foreach ($data as $row) {
                $rowArray = array();
                foreach ($row as $columnName => $columnValue) {
                    $rowArray[$columnName] = $columnValue;
                }
                $dataArray[] = $rowArray;
            }
        }
        return $dataArray;
    }

    /**
     * Checks if a database table exists
     * @param string $table
     * @return boolean
     */
    public function hasTable(string $table): bool
    {
        return  Db::schema()->setConnection(Db::connection($this->databaseName))->hasTable($table);
    }

    /**
     * Generates a seed class name (also used as a filename)
     * @param string $table
     * @param string|null $prefix
     * @param string|null $suffix
     * @return string
     */
    public function generateClassName(string $table, string $prefix=null, string $suffix=null): string
    {
        $tableString = '';
        $tableName = explode('_', $table);
        foreach ($tableName as $tableNameExploded) {
            $tableString .= ucfirst($tableNameExploded);
        }
        return ($prefix ?: '') . ucfirst($tableString) . 'Table' . ($suffix ?: '') . 'Seeder';
    }

    /**
     * Get the path to the stub file.
     * @return string
     */
    public function getStubPath(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'stubs';
    }

    /**
     * Populate the place-holders in the seed stub.
     * @param string $class
     * @param string $stub
     * @param string $table
     * @param array $data
     * @param int|null $chunkSize
     * @param string|null $prerunEvent
     * @param null $postrunEvent
     * @param bool $indexed
     * @return string
     */
    public function populateStub(string $class, string $stub, string $table, array $data, int $chunkSize = null, string $prerunEvent = null, $postrunEvent = null, $indexed = true): string
    {
        $chunkSize = $chunkSize ?: 500;

        $inserts = '';
        $chunks = array_chunk($data, $chunkSize);
        foreach ($chunks as $chunk) {
            $this->addNewLines($inserts);
            $this->addIndent($inserts, 2);
            $inserts .= sprintf(
                "Db::table('%s')->insert(%s);",
                $table,
                $this->prettifyArray($chunk, $indexed)
            );
        }

        $stub = str_replace('{{class}}', $class, $stub);

        $prerunEventInsert = '';
        if ($prerunEvent) {
            $prerunEventInsert .= "\$response = Event::until(new $prerunEvent());";
            $this->addNewLines($prerunEventInsert);
            $this->addIndent($prerunEventInsert, 2);
            $prerunEventInsert .= 'if ($response === false) {';
            $this->addNewLines($prerunEventInsert);
            $this->addIndent($prerunEventInsert, 3);
            $prerunEventInsert .= 'throw new Exception("Prerun event failed, seed wasn\'t executed!");';
            $this->addNewLines($prerunEventInsert);
            $this->addIndent($prerunEventInsert, 2);
            $prerunEventInsert .= '}';
        }

        $stub = str_replace(
            '{{prerun_event}}', $prerunEventInsert, $stub
        );

        if (!is_null($table)) {
            $stub = str_replace('{{table}}', $table, $stub);
        }

        $postrunEventInsert = '';
        if ($postrunEvent) {
            $postrunEventInsert .= "\$response = Event::until(new $postrunEvent());";
            $this->addNewLines($postrunEventInsert);
            $this->addIndent($postrunEventInsert, 2);
            $postrunEventInsert .= 'if ($response === false) {';
            $this->addNewLines($postrunEventInsert);
            $this->addIndent($postrunEventInsert, 3);
            $postrunEventInsert .= 'throw new Exception("Seed was executed but the postrun event failed!");';
            $this->addNewLines($postrunEventInsert);
            $this->addIndent($postrunEventInsert, 2);
            $postrunEventInsert .= '}';
        }

        $stub = str_replace(
            '{{postrun_event}}', $postrunEventInsert, $stub
        );

        $stub = str_replace('{{insert_statements}}', $inserts, $stub);

        return $stub;
    }

    /**
     * Create the full path name to the seed file.
     * @param string $name
     * @param string $path
     * @return string
     */
    public function getPath(string $name, string $path): string
    {
        return $path . '/' . $name . '.php';
    }

    /**
     * Prettify a var_export of an array
     * @param array $array
     * @param bool $indexed
     * @return string
     */
    protected function prettifyArray(array $array, bool $indexed = true): string
    {
        $content = ($indexed)
            ? var_export($array, true)
            : preg_replace("/[0-9]+ \=\>/i", '', var_export($array, true));

        $lines = explode("\n", $content);

        $inString = false;
        $tabCount = 3;
        for ($i = 1; $i < count($lines); $i++) {
            $lines[$i] = ltrim($lines[$i]);

            //Check for closing bracket
            if (str_contains($lines[$i], ')')) {
                $tabCount--;
            }

            //Insert tab count
            if ($inString === false) {
                for ($j = 0; $j < $tabCount; $j++) {
                    $lines[$i] = substr_replace($lines[$i], $this->indentCharacter, 0, 0);
                }
            }

            for ($j = 0; $j < strlen($lines[$i]); $j++) {
                //skip character right after an escape \
                if ($lines[$i][$j] == '\\') {
                    $j++;
                }
                //check string open/end
                else if ($lines[$i][$j] == '\'') {
                    $inString = !$inString;
                }
            }

            //check for openning bracket
            if (str_contains($lines[$i], '(')) {
                $tabCount++;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Adds new lines to the passed content variable reference.
     *
     * @param string $content
     * @param int $numberOfLines
     */
    private function addNewLines(string &$content, int $numberOfLines = 1): void
    {
        while ($numberOfLines > 0) {
            $content .= $this->newLineCharacter;
            $numberOfLines--;
        }
    }

    /**
     * Adds indentation to the passed content reference.
     *
     * @param string $content
     * @param int $numberOfIndents
     */
    private function addIndent(string &$content, int $numberOfIndents = 1): void
    {
        while ($numberOfIndents > 0) {
            $content .= $this->indentCharacter;
            $numberOfIndents--;
        }
    }



    /**
     * Updates the DatabaseSeeder file's run method (kudoz to: https://github.com/JeffreyWay/Laravel-4-Generators)
     * @param string $className
     * @return bool
     * @throws FileNotFoundException
     */
    public function updateDatabaseSeederRunMethod(string $className): bool
    {
        $databaseSeederPath = base_path() . DIRECTORY_SEPARATOR.'database/seeders' . "/{$className}.php";

        $content = $this->files->get($databaseSeederPath);
        if (!str_contains($content, "\$this->call($className::class)")) {
            if (
                strpos($content, '#iseed_start') &&
                strpos($content, '#iseed_end') &&
                strpos($content, '#iseed_start') < strpos($content, '#iseed_end')
            ) {
                $content = preg_replace("/(\#iseed_start.+?)(\#iseed_end)/us", "$1\$this->call($className::class);{$this->newLineCharacter}{$this->indentCharacter}{$this->indentCharacter}$2", $content);
            } else {
                $content = preg_replace("/(run\(\).+?)}/us", "$1{$this->indentCharacter}\$this->call({$className}::class);{$this->newLineCharacter}{$this->indentCharacter}}", $content);
            }
        }

        return $this->files->put($databaseSeederPath, $content) !== false;
    }
}